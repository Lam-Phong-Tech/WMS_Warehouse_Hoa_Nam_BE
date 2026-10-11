<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Services\P19ComponentCommand;
use Modules\Warehouse\Support\P09WarrantyScope;
use Modules\Warehouse\Support\P19CommandScope;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Read-only revalidation for P24 local quantity/reason panels, not a scan. */
final class P19ScannerReadGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasHeader(P19ComponentCommand::HEADER)) {
            return $next($request);
        }
        try {
            if ($request->header(P19ComponentCommand::HEADER) !== P19ComponentCommand::CONTRACT) {
                ApiError::abort(409, 'P19_COMMAND_CONTRACT_REQUIRED', 'Cần đúng contract P19.');
            }
            $scope = P19CommandScope::resolve($request);
            $input = $request->validate(['warranty_case_id' => ['required', 'uuid']]);
            P09WarrantyScope::requireCase($scope, $input['warranty_case_id']);
            $response = $next($request);
            if (! $response instanceof JsonResponse || ! $response->isSuccessful()) {
                return $response;
            }
            $body = $response->getData(true);
            $data = $body['data'];
            // Never retain cached case closure or BOX stock from a prior resolve.
            $case = P09WarrantyScope::requireCase($scope, $input['warranty_case_id']);
            $data['warranty_case_id'] = (string) $case->warranty_case_id;
            $data['warranty_case_status'] = $case->status;
            $data['case_allows_issue'] = in_array($case->status, ['CHECKING', 'REPAIRING'], true);
            if (($data['resolved'] ?? false) === true) {
                $item = ($data['item_type'] ?? null) === 'ITEM';
                $id = $data[$item ? 'item_id' : 'container_id'] ?? null;
                $table = $item ? 'item_instances' : 'containers';
                $column = $item ? 'item_id' : 'container_id';
                $query = DB::table($table.' as t')->where('t.'.$column, $id);
                P09WarrantyScope::isolate($query, $table, 't', $scope);
                $target = $query->first('t.*');
                $physical = DB::table('physical_codes')->where('code_value', $data['normalized_code_value'])->where('status', 'ACTIVE')->first();
                $currentId = $physical === null ? null : ($physical->{$column}
                    ?? (($physical->assigned_object_type ?? null) === ($item ? 'ITEM' : 'CONTAINER') ? $physical->assigned_object_id : null));
                $directBox = ! $item && $physical === null && $target !== null
                    && $target->container_code === $data['normalized_code_value'];
                if ($target === null || (! $directBox && $currentId !== $id)
                    || (isset($target->warehouse_id) && $target->warehouse_id !== $scope['warehouse_id'])) {
                    ApiError::abort(409, 'P19_RESOLVE_IDENTITY_CHANGED', 'Nhận diện mã đã thay đổi; không xác nhận từ kết quả cũ.');
                }
                $skuId = $item ? $target->sku_id : $target->primary_sku_id;
                if ($skuId !== ($data['sku_id'] ?? null)) {
                    ApiError::abort(409, 'P19_RESOLVE_IDENTITY_CHANGED', 'SKU của mã đã thay đổi; không xác nhận từ kết quả cũ.');
                }
                $skuQuery = DB::table('sku as s')->where('s.sku_id', $skuId);
                P09WarrantyScope::isolate($skuQuery, 'sku', 's', $scope);
                $sku = $skuQuery->first('s.*');
                // This guard may narrow an owner decision, never broaden it.
                $eligible = ($data['eligible_for_component_issue'] ?? false) === true
                    && $sku !== null && $sku->status === 'ACTIVE' && $sku->sku_type === 'COMPONENT';
                $data['available_qty'] = null;
                $quantityStatus = $item ? 'NOT_APPLICABLE_ITEM' : 'UNKNOWN';
                if ($item) {
                    $eligible = $eligible && $target->item_status === 'IN_STOCK'
                        && ! DB::table('component_issue_items')->where('item_id', $id)->where('status', 'ACTIVE')->exists();
                    $data['item_status'] = $target->item_status;
                } else {
                    $quantities = DB::table('container_items as ci')->where('ci.container_id', $id)
                        ->where('ci.sku_id', $skuId)->where('ci.is_active', true);
                    P09WarrantyScope::isolate($quantities, 'container_items', 'ci', $scope);
                    $values = $quantities->pluck('ci.quantity');
                    $known = $sku !== null && ! $values->contains(fn ($q) => $q === null || ! is_numeric($q)
                        || (float) $q < 0 || (float) $q !== (float) (int) $q);
                    $data['available_qty'] = $known ? (int) $values->sum() : null;
                    $quantityStatus = $known ? 'CONFIRMED_CONTAINER_QUANTITY' : 'UNKNOWN';
                    $eligible = $eligible && ! (bool) $sku?->is_serial_tracked && $target->container_type === 'BOX'
                        && in_array($target->status, ['IN_STOCK', 'PARTIAL', 'RECEIVED_PENDING_PUTAWAY', 'STORED'], true)
                        && $known && $data['available_qty'] > 0;
                    $data['container_status'] = $target->status;
                }
                $data['eligible_for_issue'] = $eligible;
                $data['eligible_for_component_issue'] = $eligible;
                $data['eligibility_code'] = $eligible ? 'ELIGIBLE' : ($quantityStatus === 'UNKNOWN' ? 'COMPONENT_QUANTITY_UNKNOWN' : 'COMPONENT_NOT_ELIGIBLE');
                $data['eligibility_message'] = $eligible ? null : 'Mã chưa đủ điều kiện xuất; giữ nguyên danh sách và kiểm tra lại dữ liệu nguồn.';
                $data['quantity_status'] = $quantityStatus;
            }
            $body['data'] = $data;
            $body['meta']['p19_scanner'] = [
                'contract' => P19ComponentCommand::CONTRACT,
                'can_confirm_local' => ($data['resolved'] ?? false) === true
                    && ($data['eligible_for_issue'] ?? false) === true && $data['case_allows_issue'] === true,
                'reason_kind' => ($data['resolved'] ?? false) !== true ? 'UNKNOWN'
                    : match ($data['item_type'] ?? null) { 'ITEM' => 'UNIT', 'BOX' => 'BOX', default => 'UNKNOWN' },
                'confirmation_is_scan' => false, 'write_revalidation_required' => true,
                'observed_at' => now()->toIso8601String(), 'stock_effect' => 'NONE',
            ];

            return $response->setData($body)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
        } catch (Throwable $error) {
            $response = $error instanceof HttpResponseException ? $error->getResponse() : app(ExceptionHandler::class)->render($request, $error);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }
    }
}
