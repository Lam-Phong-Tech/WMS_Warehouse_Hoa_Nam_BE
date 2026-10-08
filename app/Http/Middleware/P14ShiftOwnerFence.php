<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use App\Support\P14ShiftService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Support\WarehouseContext;
use Symfony\Component\HttpFoundation\Response;

/** OFF by default. An explicitly identified shift fences Mini App owner commands against END. */
final class P14ShiftOwnerFence
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('p14.shift_enabled') || ! $request->is('api/v1/mini-app/*') || $request->isMethodSafe()
            || $request->is('api/v1/mini-app/work-shifts/*')) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next): Response {
            $id = strtolower((string) $request->header('X-Work-Shift-ID'));
            $requestId = strtolower((string) $request->header('X-Request-ID'));
            if (! Str::isUuid($id) || ! Str::isUuid($requestId)) {
                ApiError::abort(428, 'SHIFT_COMMAND_IDENTITY_REQUIRED', 'Cần X-Work-Shift-ID và X-Request-ID cho giao thức ca.');
            }
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
            $hash = hash_hmac('sha256', json_encode([$request->method(), $request->path(), $request->query(),
                $request->isJson() ? $request->json()->all() : $request->request->all(), $request->header('If-Match')], JSON_THROW_ON_ERROR), (string) config('app.key'));
            $existing = DB::table('p14_shift_operations')->where('shift_id', $id)->where('request_id', $requestId)->first();
            if ($existing !== null) {
                // The native owner owns its replay/receipt; P14 never fabricates one.
                ApiError::abort(409, hash_equals($existing->request_hash, $hash) ? 'OWNER_RECONCILE_REQUIRED' : 'SHIFT_REQUEST_CONFLICT',
                    'Yêu cầu đã có kết quả; đối chiếu tại đúng nghiệp vụ trước khi gửi lại.');
            }
            $response = $next($request);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
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
            $body = $response instanceof JsonResponse ? $response->getData(true) : [];
            if ($response->getStatusCode() === 202) {
                $kind = 'PENDING_OWNER_RESULT';
            }
            $business = null;
            $candidates = [data_get($body, 'data.document_id'), data_get($body, 'data.document.id'),
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
            DB::table('p14_shift_operations')->insert([
                'shift_id' => $id, 'request_id' => $requestId, 'request_hash' => $hash,
                'operation' => $kind, 'business_id' => $business, 'completed_at' => now()->utc(),
            ]);
            DB::table('mini_app_work_shifts')->where('shift_id', $id)->increment('version');

            return $response;
        });
    }
}
