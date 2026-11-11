<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** PII-free command receipts. Domain effects and SUCCEEDED receipt commit together. */
final class P09WarrantyRequests
{
    public const TABLE = 'p09_warranty_requests';

    public static function execute(Request $request, array $scope, string $operation, ?string $caseId, callable $command): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        $requestId = strtolower(trim((string) $request->header('X-Request-ID')));
        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $key) || ! Str::isUuid($requestId)) {
            ApiError::abort(422, 'WARRANTY_REQUEST_ID_REQUIRED', 'Gửi Idempotency-Key (8–100 ký tự) và X-Request-ID dạng UUID; giữ nguyên cả hai khi đối chiếu.');
        }
        $secret = (string) config('app.key');
        $scopeHash = P09WarrantyPolicy::digest(P09WarrantyScope::key($scope), $secret);
        $keyHash = hash_hmac('sha256', $key, $secret);
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $hash = P09WarrantyPolicy::digest(['operation' => $operation, 'case' => $caseId, 'body' => $body,
            'query' => $request->query(), 'if_match' => $request->header('If-Match')], $secret);
        $id = hash('sha256', $scopeHash.'|'.$keyHash);
        $created = DB::table(self::TABLE)->insertOrIgnore(['id' => $id, 'scope_hash' => $scopeHash,
            'key_hash' => $keyHash, 'request_id' => $requestId, 'request_hash' => $hash,
            'operation' => $operation, 'warranty_case_id' => $caseId, 'state' => 'PROCESSING',
            'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table(self::TABLE)->where('id', $id)->first();
        if ($row === null || $row->request_id !== $requestId || ! hash_equals($row->request_hash, $hash)) {
            ApiError::abort(409, 'IDEMPOTENCY_CONFLICT', 'Request ID hoặc Idempotency-Key đã được dùng cho yêu cầu khác.');
        }
        if (! $created) {
            // No stale-lease takeover and no automatic resubmission after a timeout.
            return self::reply($row, $scope, true);
        }
        try {
            DB::transaction(function () use ($id, $command, $request, $scope, $requestId): void {
                $row = DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
                if ($row->state !== 'PROCESSING') {
                    return;
                }
                $fresh = P09WarrantyScope::resolve($request, true);
                if (P09WarrantyScope::key($fresh) !== P09WarrantyScope::key($scope)) {
                    ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Ngữ cảnh thay đổi trước khi ghi nhận yêu cầu.');
                }
                // Canonical WMS code owns validation/transitions; do not cache its PII body.
                $response = $command($requestId);
                $payload = $response->getData(true);
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    throw new HttpResponseException($response);
                }
                $data = $payload['data'] ?? [];
                if (! Str::isUuid((string) ($data['warranty_case_id'] ?? '')) || ! isset($data['version'], $data['status'])) {
                    throw new RuntimeException('P09_CANONICAL_RECEIPT_INVALID');
                }
                DB::table(self::TABLE)->where('id', $id)->update(['state' => 'SUCCEEDED',
                    'warranty_case_id' => $data['warranty_case_id'], 'committed_version' => (int) $data['version'],
                    'committed_status' => $data['status'], 'http_status' => $response->getStatusCode(),
                    'completed_at' => now(), 'updated_at' => now()]);
            });
        } catch (Throwable $error) {
            // A failed COMMIT acknowledgement is not proof of rollback. Read the
            // durable receipt first; unknown DB failures never become FAILED.
            try {
                $stored = DB::table(self::TABLE)->where('id', $id)->first();
                if ($stored !== null && $stored->state === 'SUCCEEDED') {
                    return self::reply($stored, $scope, true);
                }
                $http = $error instanceof ValidationException ? 422
                    : ($error instanceof HttpResponseException ? $error->getResponse()->getStatusCode()
                        : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 0));
                if ($http >= 400 && $http < 500) {
                    $code = $error instanceof ValidationException ? 'VALIDATION_ERROR' : 'WARRANTY_REQUEST_REJECTED';
                    if ($error instanceof HttpResponseException && $error->getResponse() instanceof JsonResponse) {
                        $body = $error->getResponse()->getData(true);
                        $candidate = $body['error_code'] ?? null;
                        if (is_string($candidate) && preg_match('/^[A-Z][A-Z0-9_]{0,99}$/D', $candidate)) {
                            $code = $candidate;
                        }
                    }
                    DB::table(self::TABLE)->where('id', $id)->where('state', 'PROCESSING')->update([
                        'state' => 'FAILED', 'http_status' => $http, 'error_code' => $code,
                        'completed_at' => now(), 'updated_at' => now()]);

                    return self::reply(DB::table(self::TABLE)->where('id', $id)->first(), $scope, false);
                }
            } catch (Throwable) {
                // No raw exception / SQL / input / credentials in the response.
            }

            return response()->json(['success' => false, 'error_code' => 'WARRANTY_RESULT_UNKNOWN',
                'data' => ['receipt' => ['request_id' => $requestId, 'state' => 'UNKNOWN', 'may_create_new_request' => false]],
                'message' => 'Chưa xác định kết quả; đối chiếu request, không gửi lại yêu cầu mới.'], 503)
                ->header('Cache-Control', 'private, no-store');
        }

        return self::reply(DB::table(self::TABLE)->where('id', $id)->first(), $scope, false);
    }

    public static function reconcile(array $scope, string $requestId): JsonResponse
    {
        $hash = P09WarrantyPolicy::digest(P09WarrantyScope::key($scope), (string) config('app.key'));
        $row = DB::table(self::TABLE)->where('scope_hash', $hash)->where('request_id', strtolower($requestId))->first();
        if ($row === null) {
            return response()->json(['success' => true, 'data' => ['receipt' => ['request_id' => strtolower($requestId),
                'state' => 'UNKNOWN', 'may_create_new_request' => false]], 'meta' => P09WarrantyScope::metadata($scope)])
                ->header('Cache-Control', 'private, no-store');
        }

        return self::reply($row, $scope, true, true);
    }

    private static function reply(object $row, array $scope, bool $replay, bool $read = false): JsonResponse
    {
        $caseCode = null;
        if ($row->state === 'SUCCEEDED') {
            $case = P09WarrantyScope::requireCase($scope, $row->warranty_case_id);
            $caseCode = isset($case->warranty_case_code) && is_string($case->warranty_case_code) ? $case->warranty_case_code : null;
        }
        $state = $row->state;
        if ($state === 'PROCESSING' && Carbon::parse($row->created_at)->lt(now()->subMinutes(5))) {
            $state = 'UNKNOWN'; // Age never grants permission to retry a command.
        }
        $receipt = ['request_id' => $row->request_id, 'state' => $state, 'operation' => $row->operation,
            'warranty_case_id' => $row->warranty_case_id, 'warranty_case_code' => $caseCode, 'case_no' => $caseCode,
            'version' => $row->committed_version === null ? null : (int) $row->committed_version,
            'status' => $row->committed_status, 'http_status' => $row->http_status === null ? null : (int) $row->http_status,
            'error_code' => $row->error_code, 'completed_at' => $row->completed_at,
            'may_create_new_request' => $state === 'FAILED', 'stock_effect' => 'NONE', 'movement_created' => false];
        $code = $read ? 200 : (in_array($state, ['PROCESSING', 'UNKNOWN'], true) ? 202 : (int) $row->http_status);
        $body = ['success' => $read || $state !== 'FAILED', 'data' => ['receipt' => $receipt],
            'meta' => P09WarrantyScope::metadata($scope) + ['request_id' => request()->attributes->get('request_id')]];
        // Additive identity compatibility for the existing P03/P06/P14 owner
        // adapters. Only a durable successful receipt plus current case ACL
        // can expose this ID. Unknown/processing/failed never become a record.
        if ($state === 'SUCCEEDED') {
            $body['data']['warranty_case_id'] = $row->warranty_case_id;
            $body['data']['warranty_case_code'] = $caseCode;
            $body['data']['case_no'] = $caseCode;
        }
        if (! $read && $state === 'FAILED') {
            $body['error_code'] = $row->error_code;
            $body['error'] = ['code' => $row->error_code, 'details' => []];
            $body['errors'] = [];
            $body['message'] = 'Yêu cầu không được thực hiện. Kiểm tra dữ liệu, quyền và phiên bản trước khi tạo request mới.';
        }
        $response = response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false')
            ->header('Cache-Control', 'private, no-store');
        if ($state === 'SUCCEEDED') {
            $response->headers->set('ETag', '"'.$row->committed_version.'"');
        }

        return $response;
    }
}
