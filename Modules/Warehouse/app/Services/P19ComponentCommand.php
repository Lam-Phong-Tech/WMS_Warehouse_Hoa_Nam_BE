<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Warehouse\Support\P09WarrantyScope;
use Modules\Warehouse\Support\P19CommandScope as Scope;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Durable P19 CREATE/SCAN/POST receipts around existing canonical writers. */
final class P19ComponentCommand
{
    public const CONTRACT = 'p19-component-command-v1';

    public const HEADER = 'X-Component-Command-Contract';

    public const TABLE = 'p19_component_command_receipts';

    public const ROUTES = [
        'api/v1/mini-app/warranty-component-issues' => 'CREATE',
        'api/v1/mini-app/warranty-component-issues/{id}/scans' => 'SCAN',
        'api/v1/mini-app/warranty-component-issues/{id}/post' => 'POST',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (! $request->hasHeader(self::HEADER)) {
                $key = strtolower(trim((string) $request->header('Idempotency-Key', '')));
                // Dropping the protocol header must not turn a known retry into
                // an unprotected legacy CREATE/SCAN. Normal legacy keys pass on.
                if (Str::isUuid($key) && Schema::hasTable(self::TABLE)
                    && DB::table(self::TABLE)->where('request_id', $key)->exists()) {
                    ApiError::abort(409, 'P19_COMMAND_CONTRACT_REQUIRED', 'Giữ đúng contract của yêu cầu đã gửi; không chuyển sang luồng cũ.');
                }

                return $next($request);
            }

            return $this->command($request, $next);
        } catch (Throwable $error) {
            return $this->errorResponse($request, $error);
        }
    }

    private function command(Request $request, Closure $next): Response
    {
        $this->contract($request);
        $operation = self::ROUTES[$request->route()->uri()] ?? null;
        if ($operation === null || ! $request->isMethod('POST')) {
            ApiError::abort(422, 'P19_OPERATION_NOT_SUPPORTED', 'Contract này chỉ áp dụng CREATE, SCAN và POST; không dùng cho RECORD.');
        }
        if (! config('p19.commands_enabled', false)) {
            ApiError::abort(503, 'P19_COMMANDS_NOT_ENABLED', 'Luồng lệnh này chưa được mở; yêu cầu đã gửi vẫn có thể kiểm tra bằng GET.');
        }
        $this->schema();
        $scope = Scope::resolve($request);
        $key = strtolower(IdempotencyKeyGuard::require($request));
        if (! Str::isUuid($key)) {
            ApiError::abort(422, 'P19_REQUEST_ID_INVALID', 'Idempotency-Key phải là UUID của đúng yêu cầu.');
        }
        if (! $request->isJson() || $request->query() !== []) {
            ApiError::abort(422, 'P19_PAYLOAD_INVALID', 'Lệnh nhận JSON body, không nhận query.');
        }
        foreach (['X-Draft-ID', 'X-Draft-Version', 'X-Draft-Scan-Session-ID', P19LinkedComponentIssue::HEADER] as $header) {
            if ($request->hasHeader($header)) {
                ApiError::abort(422, 'P19_PROTOCOL_MIXED', 'Không trộn lệnh canonical với protocol bản nháp/RECORD.');
            }
        }
        $body = $request->json()->all();
        $allowed = match ($operation) {
            'CREATE' => ['warehouse_id', 'warranty_case_id', 'component_issue_no', 'document_date', 'purpose', 'note', 'lines'],
            'SCAN' => ['line_id', 'code_value', 'item_id', 'container_id', 'quantity'],
            'POST' => [],
        };
        if (array_diff(array_keys($body), $allowed) !== []) {
            ApiError::abort(422, 'P19_UNEXPECTED_FIELD', 'Có trường ngoài contract của lệnh.');
        }
        if ($operation === 'CREATE') {
            Validator::make($body, ['warehouse_id' => ['required', 'uuid'], 'warranty_case_id' => ['required', 'uuid'],
                'lines' => ['required', 'array', 'list', 'min:1', 'max:500'],
                'lines.*' => ['required', 'array:sku_id,required_qty,note']])->validate();
            if ($request->hasHeader('If-Match')) {
                ApiError::abort(422, 'P19_VERSION_HEADER_INVALID', 'CREATE chưa có phiên bản phiếu để gửi If-Match.');
            }
            $version = null;
            $target = null;
            $caseId = strtolower($body['warranty_case_id']);
            P09WarrantyScope::requireCase($scope, $caseId);
        } else {
            $header = trim((string) $request->header('If-Match', ''));
            if (! preg_match('/^(?:"([1-9][0-9]{0,8})"|([1-9][0-9]{0,8}))$/D', $header, $matches)) {
                ApiError::abort(428, 'PRECONDITION_REQUIRED', 'SCAN/POST cần If-Match của phiếu canonical.');
            }
            $version = (int) ($matches[1] !== '' ? $matches[1] : $matches[2]);
            $target = strtolower((string) $request->route('id'));
            $document = Scope::document($scope, $target);
            $caseId = (string) $document->warranty_case_id;
        }
        $secret = (string) config('app.key');
        if ($secret === '') {
            ApiError::abort(503, 'P19_RECOVERY_UNAVAILABLE', 'Chưa cấu hình khóa xác minh yêu cầu.');
        }
        $fingerprint = hash_hmac('sha256', json_encode($this->canonical([
            'scope' => $scope, 'operation' => $operation, 'path' => $request->path(),
            'expected_version' => $version, 'body' => $body,
        ]), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $secret);
        // Claim is durable before execution. A lost worker has UNKNOWN outcome;
        // no timeout/lease takeover may execute its command again.
        $claimed = DB::table(self::TABLE)->insertOrIgnore([
            'request_id' => $key, 'actor_id' => $scope['actor_id'], 'auth_session_id' => $scope['auth_session_id'],
            'warehouse_id' => $scope['warehouse_id'], 'scope_hash' => Scope::digest($scope),
            'operation' => $operation, 'target_document_id' => $target, 'warranty_case_id' => $caseId,
            'expected_version' => $version, 'fingerprint' => $fingerprint, 'outcome' => 'UNKNOWN',
            'created_at' => now('UTC')->toISOString(), 'updated_at' => now('UTC')->toISOString(),
        ]) === 1;
        try {
            return DB::transaction(function () use ($request, $next, $scope, $key, $fingerprint, $claimed, $operation, $target, $caseId, $version): Response {
                $row = DB::table(self::TABLE)->where('request_id', $key)->lockForUpdate()->first();
                if ($row === null || $row->actor_id !== $scope['actor_id'] || ! hash_equals($row->scope_hash, Scope::digest($scope))) {
                    ApiError::abort(409, 'P19_REQUEST_CONTEXT_CONFLICT', 'Yêu cầu không khớp chủ sở hữu hoặc phiên hiện hành.');
                }
                if (! hash_equals($row->fingerprint, $fingerprint)) {
                    ApiError::abort(409, 'IDEMPOTENCY_CONFLICT', 'Giữ đúng yêu cầu, body và phiên bản đã gửi; không tự thay khóa để gửi lại.');
                }
                // Recheck privileges/context after taking the lock, also on replay.
                if (Scope::digest(Scope::resolve($request)) !== Scope::digest($scope)) {
                    ApiError::abort(409, 'P19_REQUEST_CONTEXT_CONFLICT', 'Phạm vi phiên đã thay đổi.');
                }
                if (! $claimed) {
                    return $this->receiptResponse($row, true, true);
                }
                if ($target !== null) {
                    $before = Scope::document($scope, $target, true);
                    if ((int) $before->version !== $version) {
                        ApiError::abort(412, 'VERSION_CONFLICT', 'Phiên bản phiếu đã thay đổi; không tự gửi lại lệnh.');
                    }
                    if (! in_array($before->status, ['DRAFT', 'SCANNING'], true)) {
                        // A pre-existing POSTED document/movement cannot prove a
                        // new command. Only the original exact receipt can do so.
                        ApiError::abort(409, 'P19_DOCUMENT_NOT_WRITABLE', 'Trạng thái hiện tại không cho phép thực hiện lệnh mới.');
                    }
                } else {
                    DB::table('warehouses')->where('id', $scope['warehouse_id'])->lockForUpdate()->first();
                    P09WarrantyScope::requireCase($scope, $caseId, true);
                }
                // Namespacing avoids collisions with pre-contract Post receipts.
                $request->headers->set('Idempotency-Key', 'p19-command-'.hash('sha256', $key.'|'.$row->scope_hash));
                try {
                    $response = $next($request);
                } finally {
                    $request->headers->set('Idempotency-Key', $key);
                }
                if (! $response instanceof JsonResponse || ! $response->isSuccessful()) {
                    throw new HttpResponseException($response);
                }
                $result = $this->prove($scope, $operation, $target, $caseId, $response->getData(true));
                if ($version !== null && $result['document_version'] <= $version) {
                    ApiError::abort(502, 'P19_OWNER_RESULT_INVALID', 'Thiếu phiên bản mới xác nhận lệnh của owner.');
                }
                // Failure of this update rolls back ALL canonical writes too.
                $changed = DB::table(self::TABLE)->where('request_id', $key)->where('outcome', 'UNKNOWN')->update([
                    'outcome' => 'SUCCEEDED', 'response_status' => $operation === 'CREATE' ? 201 : 200,
                    'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
                    'recorded_at' => now('UTC')->toISOString(), 'updated_at' => now('UTC')->toISOString(),
                ]);
                if ($changed !== 1) {
                    ApiError::abort(503, 'P19_RECEIPT_NOT_STORED', 'Chưa lưu được kết quả yêu cầu.');
                }

                return $this->receiptResponse(DB::table(self::TABLE)->where('request_id', $key)->first(), false, true);
            }, 3);
        } catch (Throwable $error) {
            // Only a confirmed 4xx after rollback becomes FAILED. A 5xx, crash or
            // ambiguous commit remains UNKNOWN; no automatic replacement/retry.
            $status = $error instanceof HttpResponseException ? $error->getResponse()->getStatusCode()
                : ($error instanceof ValidationException ? 422 : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500));
            if ($claimed && $status >= 400 && $status < 500) {
                $code = $error instanceof HttpResponseException && $error->getResponse() instanceof JsonResponse
                    ? data_get($error->getResponse()->getData(true), 'error.code', 'P19_COMMAND_REJECTED') : 'P19_COMMAND_REJECTED';
                $code = is_string($code) && preg_match('/^[A-Z0-9_]{1,80}$/D', $code) ? $code : 'P19_COMMAND_REJECTED';
                try {
                    DB::table(self::TABLE)->where('request_id', $key)->where('outcome', 'UNKNOWN')->update([
                        'outcome' => 'FAILED', 'response_status' => $status, 'error_code' => $code,
                        'recorded_at' => now('UTC')->toISOString(), 'updated_at' => now('UTC')->toISOString(),
                    ]);
                } catch (Throwable) {
                    // Do not misreport failed persistence as a confirmed result.
                }
            }
            throw $error;
        }
    }

    public function check(Request $request, string $requestId): Response
    {
        try {
            $this->contract($request);
            $this->schema();
            if (array_diff(array_keys($request->query()), ['operation', 'warehouse_id']) !== [] || $request->getContent() !== '') {
                ApiError::abort(422, 'P19_QUERY_INVALID', 'Kiểm tra kết quả chỉ nhận operation và kho, không nhận body.');
            }
            $input = Validator::make($request->query(), ['operation' => ['required', 'in:CREATE,SCAN,POST']])->validate();
            $scope = Scope::resolve($request);
            if (! Str::isUuid($requestId)) {
                ApiError::abort(422, 'P19_REQUEST_ID_INVALID', 'Mã yêu cầu phải là UUID.');
            }
            $row = DB::table(self::TABLE)->where('request_id', strtolower($requestId))
                ->where('actor_id', $scope['actor_id'])->where('auth_session_id', $scope['auth_session_id'])
                ->where('warehouse_id', $scope['warehouse_id'])->where('scope_hash', Scope::digest($scope))
                ->where('operation', $input['operation'])->first();
            if ($row !== null) {
                P09WarrantyScope::requireCase($scope, (string) $row->warranty_case_id);
                if ($row->target_document_id !== null) {
                    Scope::document($scope, $row->target_document_id);
                }
            }

            return $this->receiptResponse($row, false, false);
        } catch (Throwable $error) {
            return $this->errorResponse($request, $error);
        }
    }

    private function prove(array $scope, string $operation, ?string $target, string $caseId, array $body): array
    {
        $id = $operation === 'CREATE' ? data_get($body, 'data.document.id') : $target;
        if (($body['success'] ?? null) !== true || ! is_string($id) || ! Str::isUuid($id)) {
            ApiError::abort(502, 'P19_OWNER_RESULT_INVALID', 'Không xác minh được kết quả từ owner.');
        }
        $document = Scope::document($scope, $id);
        if ($document->warranty_case_id !== $caseId || $document->status !== match ($operation) {
            'CREATE' => 'DRAFT', 'SCAN' => 'SCANNING', 'POST' => 'POSTED',
        }) {
            ApiError::abort(502, 'P19_OWNER_RESULT_INVALID', 'Kết quả không khớp phiếu và hồ sơ của lệnh.');
        }
        $result = ['business_document_id' => $id, 'warranty_case_id' => $caseId,
            'document_version' => (int) $document->version, 'status_at_command' => $document->status,
            'stock_effect' => $operation === 'POST' ? 'DECREASE' : 'NONE',
            'current_detail_url' => '/api/v1/mini-app/warranty-component-issues/'.$id];
        if ($operation === 'SCAN') {
            $entry = DB::table('component_issue_items as i')->join('component_issue_lines as l', 'l.id', '=', 'i.component_issue_line_id')
                ->where('l.component_issue_document_id', $id)->where('i.scan_event_id', data_get($body, 'data.scan_event_id'))
                ->where('i.status', 'ACTIVE')->first(['i.id', 'i.scan_event_id', 'i.quantity', 'i.item_id', 'i.container_id', 'l.id as line_id']);
            if ($entry === null || $entry->quantity === null) {
                ApiError::abort(502, 'P19_OWNER_RESULT_INVALID', 'Thiếu bằng chứng quét của lệnh.');
            }
            $result['scan'] = (array) $entry;
            $result['scan']['quantity'] = (int) $entry->quantity;
        }
        if ($operation === 'POST') {
            $movements = DB::table('stock_movements')->where('source_type', 'COMPONENT_ISSUE_DOCUMENT')
                ->where('source_id', $id)->where('warehouse_id', $scope['warehouse_id'])->where('movement_type', 'COMPONENT_ISSUE')
                ->orderBy('id')->get(['id', 'source_line_id', 'sku_id', 'qty_delta', 'occurred_at']);
            if ($document->posted_at === null || $movements->isEmpty() || $movements->contains(fn ($m) => $m->qty_delta === null || (int) $m->qty_delta >= 0)) {
                ApiError::abort(502, 'P19_OWNER_RESULT_INVALID', 'Thiếu timestamp hoặc movement xác nhận của lệnh Post.');
            }
            $result['posted_at'] = $document->posted_at;
            $result['posted_at_source'] = 'component_issue_documents.posted_at';
            $result['movements'] = $movements->map(fn ($m) => (array) $m)->all();
        }

        return $result;
    }

    private function receiptResponse(?object $row, bool $replayed, bool $write): JsonResponse
    {
        $outcome = $row?->outcome ?? 'UNKNOWN';
        $receipt = $row === null || $outcome === 'UNKNOWN' ? null : [
            'request_id' => $row->request_id, 'operation' => $row->operation,
            'actor_id' => $row->actor_id, 'auth_session_id' => $row->auth_session_id, 'warehouse_id' => $row->warehouse_id,
            'warranty_case_id' => $row->warranty_case_id, 'target_document_id' => $row->target_document_id,
            'expected_version' => $row->expected_version === null ? null : (int) $row->expected_version,
            'outcome' => $outcome, 'result' => $row->result_json === null ? null : json_decode($row->result_json, true, 512, JSON_THROW_ON_ERROR),
            'recorded_at' => $row->recorded_at === null ? null : CarbonImmutable::parse($row->recorded_at, 'UTC')->utc()->toISOString(),
            'recorded_at_source' => self::TABLE.'.recorded_at',
            'error_code' => $row->error_code, 'response_status' => (int) $row->response_status,
        ];
        $status = $write ? ($outcome === 'UNKNOWN' ? 202 : (int) $row->response_status) : 200;
        $body = ['success' => $status < 400, 'data' => ['outcome' => $outcome, 'receipt' => $receipt,
            'may_create_new_request' => false, 'retry_policy' => 'CHECK_ONLY'],
            'meta' => ['contract' => self::CONTRACT, 'result_kind' => 'HISTORICAL_COMMAND_RECEIPT',
                'observed_at' => now()->toIso8601String(), 'replayed' => $replayed, 'current_state_inferred' => false]];
        if ($status >= 400) {
            $body['error'] = ['code' => $row->error_code, 'details' => []];
            $body['error_code'] = $row->error_code;
            $body['message'] = 'Yêu cầu trước đã bị từ chối; danh sách quét và tồn kho không được ghi bởi lệnh này.';
        }
        $response = response()->json($body, $status)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
        if ($write) {
            $response->header('Idempotent-Replay', $replayed ? 'true' : 'false');
            if ($outcome === 'SUCCEEDED') {
                $response->header('ETag', '"'.$receipt['result']['document_version'].'"');
            }
        }

        return $response;
    }

    private function contract(Request $request): void
    {
        if ($request->header(self::HEADER) !== self::CONTRACT) {
            ApiError::abort(409, 'P19_COMMAND_CONTRACT_REQUIRED', 'Cần đúng contract kiểm tra lệnh P19.');
        }
    }

    private function schema(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            ApiError::abort(503, 'P19_RECOVERY_UNAVAILABLE', 'Kho lưu kết quả lệnh chưa được triển khai.');
        }
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonical(...), $value);
    }

    private function errorResponse(Request $request, Throwable $error): Response
    {
        if ($error instanceof HttpResponseException) {
            $response = $error->getResponse();
        } else {
            $handler = app(ExceptionHandler::class);
            $handler->report($error);
            $response = $handler->render($request, $error);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
