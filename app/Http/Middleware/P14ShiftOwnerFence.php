<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use App\Support\IdempotencyStore;
use App\Support\P14ShiftLifecycle;
use App\Support\P14ShiftOwnerEvidence;
use App\Support\P14ShiftService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Services\AttachmentUploadReceipts;
use Modules\Warehouse\Services\P19ComponentCommand;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** OFF by default. An explicitly identified shift fences Mini App owner commands against END. */
final class P14ShiftOwnerFence
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/mini-app/*') || $request->isMethodSafe()
            || $request->is('api/v1/mini-app/work-shifts/*') || $this->checkOnly($request)
            || ! P14ShiftLifecycle::fenceRequired($request)) {
            return $next($request);
        }

        $id = strtolower((string) $request->header('X-Work-Shift-ID'));
        $requestId = strtolower((string) $request->header('X-Request-ID'));
        if (! Str::isUuid($id) || ! Str::isUuid($requestId)) {
            ApiError::abort(428, 'SHIFT_COMMAND_IDENTITY_REQUIRED', 'Cần X-Work-Shift-ID và X-Request-ID cho giao thức ca.');
        }
        $upload = $request->isMethod('POST') && isset(AttachmentUploadReceipts::UPLOAD_ROUTES[$request->route()?->uri() ?? '']);
        if ($upload) {
            if (! $request->hasHeader('X-Upload-Contract')) {
                ApiError::abort(428, 'UPLOAD_IDENTITY_REQUIRED', 'Upload trong ca tracked bắt buộc receipt contract và identity gốc.');
            }
            app(AttachmentUploadReceipts::class)->prepareForShift($request, fn () => $this->activeShift($request, $id));
        }

        try {
            return DB::transaction(function () use ($request, $next, $id, $requestId): Response {
                [$actor, $shift] = $this->activeShift($request, $id);
                // Capture before a typed owner adds internal handoff headers.
                $hash = $this->fingerprint($request);
                $p18Upload = $request->isMethod('POST')
                    && isset(AttachmentUploadReceipts::UPLOAD_ROUTES[$request->route()?->uri() ?? ''])
                    && $request->header('X-Upload-Contract') === AttachmentUploadReceipts::CONTRACT;
                $p03Handoff = $request->hasHeader('X-Draft-ID')
                    && $request->hasHeader('X-Draft-Scan-Session-ID')
                    && $request->hasHeader('X-Draft-Version')
                    && $request->hasHeader('Idempotency-Key');
                $p09Owner = in_array($request->route()?->uri(), [
                    'api/v1/mini-app/warranty-cases', 'api/v1/mini-app/warranty-cases/{id}',
                ], true) && in_array($request->method(), ['PATCH', 'POST'], true)
                    && $request->hasHeader('Idempotency-Key');
                $p19Owner = $request->header(P19ComponentCommand::HEADER) === P19ComponentCommand::CONTRACT
                    && isset(P19ComponentCommand::ROUTES[$request->route()?->uri() ?? ''])
                    && $request->hasHeader('Idempotency-Key');
                $nativeOwnerReplay = $p18Upload || $p03Handoff || $p09Owner || $p19Owner;
                $nfcHash = $request->isMethod('PATCH') && $request->is('api/v1/mini-app/items/*/nfc')
                    ? IdempotencyStore::requestHash($request) : null;
                $existing = DB::table('p14_shift_operations')->where('shift_id', $id)->where('request_id', $requestId)->first();
                if ($existing !== null) {
                    // P18 owns the durable upload receipt. Let it replay a terminal
                    // owner response instead of treating the same upload intent as
                    // a duplicate shift command.
                    if ($nativeOwnerReplay && hash_equals($existing->request_hash, $hash)) {
                        return $next($request);
                    }
                    ApiError::abort(409, hash_equals($existing->request_hash, $hash) ? 'OWNER_RECONCILE_REQUIRED' : 'SHIFT_REQUEST_CONFLICT',
                        'Yêu cầu đã có kết quả; đối chiếu tại đúng nghiệp vụ trước khi gửi lại.');
                }
                if ($request->hasHeader('Idempotency-Key') && DB::table('p14_shift_operations as o')
                    ->join('mini_app_work_shifts as s', 's.shift_id', '=', 'o.shift_id')
                    ->where('s.actor_id', $actor->user_id)->where('s.auth_session_id', $shift->auth_session_id)
                    ->where('s.warehouse_id', $shift->warehouse_id)->where('o.request_hash', $hash)->exists()) {
                    if ($nativeOwnerReplay) {
                        return $next($request);
                    }
                    ApiError::abort(409, 'OWNER_RECONCILE_REQUIRED', 'Đổi mã trace hoặc ca không tạo ý định owner mới; đối chiếu yêu cầu gốc.');
                }
                $response = $next($request);
                $body = $response instanceof JsonResponse ? $response->getData(true) : [];
                $uploadProof = P14ShiftOwnerEvidence::p18($request, $body, $shift, $response->getStatusCode());
                $warrantyProof = $p09Owner && $response->getStatusCode() !== 202
                    ? P14ShiftOwnerEvidence::p09($request, $body, $shift, $response->getStatusCode()) : null;
                if (($response->getStatusCode() < 200 || $response->getStatusCode() >= 300)
                    && ($uploadProof['native_outcome'] ?? null) !== 'REJECTED'
                    && ($warrantyProof['native_outcome'] ?? null) !== 'FAILED') {
                    throw new HttpResponseException($response);
                }
                $kind = match ($request->method().' '.$request->path()) {
                    'POST api/v1/mini-app/inbound/record' => 'INBOUND',
                    'POST api/v1/mini-app/outbound/record' => 'OUTBOUND',
                    'POST api/v1/mini-app/warranty-cases' => 'WARRANTY',
                    'POST api/v1/mini-app/warranty-component-issues/record',
                    'POST api/v1/mini-app/component-issue-documents/record' => 'COMPONENT_ISSUE',
                    default => 'OWNER_REQUEST',
                };
                if (in_array($request->method(), ['PUT', 'PATCH'], true) && ! $request->is('api/v1/mini-app/drafts/*')) {
                    $kind = 'UNSUPPORTED_OWNER_SAVE';
                }
                $proof = null;
                if ($response->getStatusCode() === 202) {
                    $kind = 'PENDING_OWNER_RESULT';
                } else {
                    $proof = $uploadProof ?? $warrantyProof ?? P14ShiftOwnerEvidence::p03($request, $body, $shift)
                        ?? P14ShiftOwnerEvidence::nfc($request, $body, $shift, $nfcHash)
                        ?? P14ShiftOwnerEvidence::p19($request, $body, $shift);
                    if ($proof !== null) {
                        $kind = $proof['journal_kind'];
                    }
                }
                $business = null;
                $candidates = $proof !== null ? [$proof['business_id']] : [data_get($body, 'data.document_id'), data_get($body, 'data.document.id'),
                    data_get($body, 'data.id'), data_get($body, 'data.warranty_case_id'), data_get($body, 'data.component_issue_document_id')];
                foreach ($candidates as $candidate) {
                    if (! is_string($candidate) || ! Str::isUuid($candidate)) {
                        continue;
                    }
                    $valid = match ($kind) {
                        'INBOUND' => DB::table('stock_documents')->where('id', $candidate)->where('doc_type', 'receipt')->where('dst_warehouse_id', $shift->warehouse_id)->exists(),
                        'OUTBOUND' => DB::table('stock_documents')->where('id', $candidate)->where('doc_type', 'issue')->where('src_warehouse_id', $shift->warehouse_id)->exists(),
                        'WARRANTY' => DB::table('warranty_case_warehouse_owners')->where('warranty_case_id', $candidate)->where('warehouse_id', $shift->warehouse_id)->exists(),
                        'COMPONENT_ISSUE' => DB::table('component_issue_documents')->where('id', $candidate)->where('warehouse_id', $shift->warehouse_id)->exists(),
                        default => false,
                    };
                    if ($valid) {
                        $business = strtolower($candidate);
                        break;
                    }
                }
                if (in_array($kind, ['INBOUND', 'OUTBOUND', 'WARRANTY', 'COMPONENT_ISSUE'], true) && $business === null) {
                    ApiError::abort(502, 'SHIFT_OWNER_RECEIPT_INVALID', 'Nghiệp vụ chưa xác nhận được business ID; giao dịch được hoàn tác.');
                }
                if ($proof !== null) {
                    P14ShiftOwnerEvidence::audit($proof, $shift, $requestId);
                }
                DB::table('p14_shift_operations')->insert([
                    'shift_id' => $id, 'request_id' => $requestId, 'request_hash' => $hash,
                    'operation' => $kind, 'business_id' => $business, 'completed_at' => now()->utc(),
                ]);
                DB::table('mini_app_work_shifts')->where('shift_id', $id)->increment('version');

                return $response;
            });
        } catch (Throwable $error) {
            // This runs AFTER the outer rollback; the precommitted native
            // intent survives even if the worker exits before reaching here.
            if ($upload) {
                app(AttachmentUploadReceipts::class)->afterShiftRollback($request);
            }
            throw $error;
        }
    }

    private function activeShift(Request $request, string $id): array
    {
        [$actor, $shift] = app(P14ShiftService::class)->owned($request, $id, true, true, false);
        if ($shift->ended_at !== null || $shift->tracking_contract !== P14ShiftService::CONTRACT) {
            ApiError::abort(409, 'SHIFT_NOT_ACTIVE', 'Ca không còn hoạt động hoặc chưa thuộc giao thức kết thúc ca.');
        }
        $context = app(WarehouseContext::class)->payload($actor, $shift->auth_session_id, 'AUTH_SESSION_REQUIRED');
        if ($context['warehouse_context']['status'] !== 'READY' || $context['active_warehouse_id'] !== $shift->warehouse_id) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho đang chọn không còn phù hợp với ca.');
        }
        foreach (['warehouse_id', 'src_warehouse_id', 'dst_warehouse_id'] as $field) {
            if ($request->filled($field) && strtolower((string) $request->input($field)) !== $shift->warehouse_id) {
                ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho của thao tác không khớp ca.');
            }
        }

        return [$actor, $shift];
    }

    /** POST transport used for pure validation/resolution; no owner command receipt exists or is needed. */
    private function checkOnly(Request $request): bool
    {
        if (! $request->isMethod('POST')) {
            return false;
        }

        return in_array($request->route()?->uri(), [
            'api/v1/mini-app/inbound/drafts/{id}/validate-code',
            'api/v1/mini-app/inbound/drafts/{id}/review',
            'api/v1/mini-app/inbound/resolve-code',
            'api/v1/mini-app/outbound/resolve-code',
            'api/v1/mini-app/warranty/resolve-code',
            'api/v1/mini-app/warranty-component-issues/resolve-code',
            'api/v1/mini-app/component-issue-documents/resolve-code',
            'api/v1/mini-app/nfc/resolve-code',
            'api/v1/mini-app/nfc/resolve-tag',
        ], true);
    }

    private function fingerprint(Request $request): string
    {
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            if (in_array($key, ['idempotency-key', 'if-match', 'x-draft-id', 'x-draft-scan-session-id', 'x-draft-version'], true)
                || (str_starts_with($key, 'x-') && str_contains($key, 'contract'))) {
                $headers[$key] = $values;
            }
        }
        ksort($headers);
        $files = [];
        $walk = function (array $nodes, string $prefix = '') use (&$walk, &$files): void {
            foreach ($nodes as $key => $node) {
                $name = $prefix.(string) $key;
                if (is_array($node)) {
                    $walk($node, $name.'.');
                } elseif ($node instanceof UploadedFile) {
                    $digest = $node->isValid() ? hash_file('sha256', $node->getPathname()) : false;
                    $files[$name] = [$digest, $node->getClientOriginalName(), $node->getClientMimeType()];
                }
            }
        };
        $walk($request->allFiles());
        ksort($files);

        return hash_hmac('sha256', json_encode([$request->method(), $request->path(), $request->query(),
            $request->isJson() ? $request->json()->all() : $request->request->all(), $headers, $files], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
