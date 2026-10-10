<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Services\MiniAppDraftService;
use Modules\Warehouse\Services\P21ComponentResume;
use Modules\Warehouse\Support\P09WarrantyScope;
use Symfony\Component\HttpFoundation\Response;

/** Opt-in read projection; all writes remain exclusively P03/P19 owned. */
final class P21ResumeRead
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasHeader(P21ComponentResume::HEADER)) {
            return $next($request);
        }
        if ($request->header(P21ComponentResume::HEADER) !== P21ComponentResume::CONTRACT) {
            ApiError::abort(422, 'P21_CONTRACT_INVALID', 'Contract đọc P21 không được hỗ trợ.');
        }
        if (! $request->isMethod('GET')) {
            ApiError::abort(422, 'P21_READ_ONLY', 'Header P21 chỉ dành cho GET; mutation phải theo owner P03/P19.');
        }
        $service = app(P21ComponentResume::class);
        $id = $request->route('id');
        $key = $request->route('requestId');
        if ($request->path() === 'api/v1/mini-app/drafts') {
            return $service->index($request);
        }
        if (is_string($id) && is_string($key)) {
            $response = $service->reconcile($request, $id, $key);
            $data = $response->getData(true)['data'];
            if ($data['outcome'] === 'SUCCEEDED') {
                // P03 predates source tenant columns. Its native identity check
                // must never allow a future cross-tenant receipt through P21.
                $scope = P09WarrantyScope::resolve($request);
                $receipt = P09WarrantyScope::isolate(DB::table(MiniAppDraftService::RECEIPTS.' as r'), MiniAppDraftService::RECEIPTS, 'r', $scope)
                    ->where('r.actor_id', $scope['actor_id'])->where('r.auth_session_id', $scope['auth_session_id'])
                    ->where('r.warehouse_id', $scope['warehouse_id'])->where('r.document_id', strtolower($id))
                    ->where('r.request_id', strtolower($key))->where('r.scan_session_id', strtolower($request->query('scan_session_id')))
                    ->where('r.operation', 'COMPONENT_ISSUE')->where('r.command', $request->query('command'))->exists();
                if (! $receipt || ! $scope['component']) {
                    ApiError::abort(404, 'DRAFT_NOT_FOUND', 'Không đọc được biên nhận trong phạm vi hiện tại.');
                }
            }

            return $response;
        }
        if (is_string($id)) {
            return $service->show($request, $id);
        }
        ApiError::abort(422, 'P21_READ_PATH_UNSUPPORTED', 'P21 chỉ dùng GET nháp và biên nhận đã khai báo.');
    }
}
