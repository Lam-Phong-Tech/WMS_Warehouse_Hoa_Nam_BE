<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;

/** Opt-in links only: never a stock-document identity or a write receipt. */
final class P06ComponentIssueLinks
{
    public const HEADER = 'X-P06-Timeline-Link-Contract';
    public const CONTRACT = 'p06-component-issue-link-v1';
    private const ROUTES = ['api/v1/mini-app/catalog/skus/{id}/timeline', 'api/v1/mini-app/catalog/items/{id}/timeline'];

    public static function requested(Request $request): bool
    {
        if (! $request->hasHeader(self::HEADER)) {
            return false;
        }
        if ($request->header(self::HEADER) !== self::CONTRACT) {
            ApiError::abort(422, 'P06_TIMELINE_LINK_CONTRACT_UNSUPPORTED', 'Contract liên kết phiếu linh kiện không được hỗ trợ.');
        }
        if (! $request->isMethod('GET') || ! in_array($request->route()?->uri(), self::ROUTES, true)) {
            ApiError::abort(422, 'P06_TIMELINE_LINK_ROUTE_UNSUPPORTED', 'Chỉ yêu cầu liên kết trên GET lịch sử SKU hoặc hiện vật P06.');
        }
        return true;
    }

    /** Called after paging, inside the existing P06 response snapshot. */
    public function enrich(Request $request, User $actor, string $warehouse, string $sku, ?string $item, array $result): array
    {
        $permitted = ($actor->hasPermission('warranty.view') || $actor->hasPermission('warranty.manage'))
            && $actor->hasPermission('documents.read') && $actor->hasPermission('warranty.component_issue');
        $candidates = array_values(array_filter($result['data'], fn (array $event): bool => $this->candidate($event)));
        $links = [];
        if ($permitted && $candidates !== []) {
            $scope = P09WarrantyScope::resolve($request);
            if ($scope['warehouse_id'] !== $warehouse) {
                ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho liên kết không khớp kho của phiên.');
            }
            foreach ([
                'stock_movements' => ['id', 'source_type', 'source_id', 'source_line_id', 'warehouse_id', 'product_id', 'sku_id', 'movement_type', 'qty_delta', 'stock_document_id', 'item_id'],
                'component_issue_documents' => ['id', 'warehouse_id', 'warranty_case_id', 'status', 'component_issue_no'],
                'component_issue_lines' => ['id', 'component_issue_document_id', 'sku_id'],
            ] as $table => $columns) {
                if (array_diff($columns, Schema::getColumnListing($table)) !== []) {
                    ApiError::abort(503, 'P06_COMPONENT_LINK_SOURCE_UNAVAILABLE', 'Nguồn liên kết phiếu linh kiện chưa đủ schema để đối chiếu.');
                }
            }
            $cases = P09WarrantyScope::cases($scope)->select('c.warranty_case_id');
            $query = DB::table('stock_movements as p06_component_movement')
                ->join('component_issue_documents as p06_component_document', 'p06_component_document.id', '=', 'p06_component_movement.source_id')
                ->join('component_issue_lines as p06_component_line', 'p06_component_line.id', '=', 'p06_component_movement.source_line_id')
                ->joinSub($cases, 'p06_component_case', 'p06_component_case.warranty_case_id', '=', 'p06_component_document.warranty_case_id')
                ->whereIn('p06_component_movement.id', array_column($candidates, 'source_event_id'))
                ->where('p06_component_movement.warehouse_id', $warehouse)->where('p06_component_document.warehouse_id', $warehouse)
                ->where('p06_component_movement.product_id', $sku)->where('p06_component_movement.sku_id', $sku)
                ->where('p06_component_line.sku_id', $sku)
                ->whereColumn('p06_component_line.component_issue_document_id', 'p06_component_document.id')
                ->where('p06_component_movement.source_type', 'COMPONENT_ISSUE_DOCUMENT')
                ->where('p06_component_movement.movement_type', 'COMPONENT_ISSUE')->where('p06_component_movement.qty_delta', '<', 0)
                ->whereNull('p06_component_movement.stock_document_id')->where('p06_component_document.status', 'POSTED')
                ->when($item !== null, fn ($q) => $q->where('p06_component_movement.item_id', $item));
            foreach (['stock_movements' => 'p06_component_movement', 'component_issue_documents' => 'p06_component_document', 'component_issue_lines' => 'p06_component_line'] as $table => $alias) {
                P09WarrantyScope::isolate($query, $table, $alias, $scope);
            }
            $rows = $query->get(['p06_component_movement.id as movement_id', 'p06_component_document.id', 'p06_component_document.warranty_case_id',
                'p06_component_document.warehouse_id', 'p06_component_document.component_issue_no']);
            foreach ($rows as $row) {
                if (! Str::isUuid($row->id) || ! Str::isUuid($row->warranty_case_id) || ! Str::isUuid($row->warehouse_id)) {
                    ApiError::abort(503, 'P06_COMPONENT_LINK_IDENTITY_INVALID', 'Định danh nguồn phiếu linh kiện không hợp lệ.');
                }
                $number = is_string($row->component_issue_no) && trim($row->component_issue_no) !== '' ? $row->component_issue_no : null;
                $links[$row->movement_id] = ['kind' => 'COMPONENT_ISSUE_DOCUMENT', 'id' => $row->id,
                    'warranty_case_id' => $row->warranty_case_id, 'warehouse_id' => $row->warehouse_id,
                    'number' => $number, 'number_status' => $number === null ? 'NOT_RECORDED' : 'RECORDED',
                    'current_status' => 'POSTED', 'can_open' => true, 'identity_source' => 'PERSISTED_STOCK_MOVEMENT_SOURCE'];
            }
        }
        $result['data'] = array_map(function (array $event) use ($permitted, $links): array {
            $candidate = $this->candidate($event);
            $link = $candidate && $permitted ? ($links[$event['source_event_id']] ?? null) : null;
            return $event + ['component_issue_link' => $link,
                'component_issue_link_status' => ! $candidate ? 'NOT_APPLICABLE' : (! $permitted ? 'FORBIDDEN' : ($link === null ? 'NOT_ESTABLISHED' : 'AVAILABLE'))];
        }, $result['data']);
        $result['meta'] += ['timeline_link_contract' => self::CONTRACT, 'component_issue_links' => [
            'supported' => true, 'read_permitted' => $permitted, 'target_owner' => 'P20', 'owner_revalidation_required' => true]];
        return $result;
    }

    private function candidate(array $event): bool
    {
        return $event['source'] === 'LEDGER' && $event['event_type'] === 'COMPONENT_ISSUE';
    }
}
