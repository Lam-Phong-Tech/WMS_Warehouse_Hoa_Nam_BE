<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;

/** Existing container register, not a location balance or an availability promise. */
final class P18BoxReadModel
{
    public const HEADER = 'X-Container-Read-Contract';

    public const CONTRACT = 'p18-single-warehouse-boxes-v1';

    public function read(Request $request, ?string $id = null): JsonResponse
    {
        if ($request->header(self::HEADER) !== self::CONTRACT) {
            ApiError::abort(409, 'CONTAINER_READ_CONTRACT_UNSUPPORTED', 'Cần đúng contract đọc hộp của kho.');
        }
        $rules = ['warehouse_id' => ['required', 'uuid']];
        if ($id === null) {
            $rules += ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
                'sku_type' => ['sometimes', Rule::in(['ALL', 'PRODUCT', 'COMPONENT'])],
                'keyword' => ['sometimes', 'string', 'max:100']];
        }
        if ($request->getContent() !== '' || array_diff(array_keys($request->query()), array_keys($rules)) !== []) {
            ApiError::abort(422, 'CONTAINER_QUERY_UNSUPPORTED', 'GET chỉ nhận query được công bố; không suy vị trí hoặc bỏ qua bộ lọc.');
        }
        $input = Validator::make($request->query(), $rules)->validate();
        if ($id !== null) {
            Validator::make(['id' => $id], ['id' => ['required', 'uuid']])->validate();
        }
        try {
            return P06ReadSnapshot::run(function (array $snapshot) use ($request, $input, $id): JsonResponse {
                $scope = $this->scope($request, strtolower($input['warehouse_id']));
                $query = DB::table('containers as c')->leftJoin('sku as s', 's.sku_id', '=', 'c.primary_sku_id')->where('c.container_type', 'BOX');
                P09WarrantyScope::isolate($query, 'containers', 'c', $scope);
                P09WarrantyScope::isolate($query, 'sku', 's', $scope);
                if ($id !== null) {
                    $query->where('c.container_id', strtolower($id));
                }
                if (($input['sku_type'] ?? 'ALL') !== 'ALL') {
                    $query->where('s.sku_type', $input['sku_type']);
                }
                if (trim($input['keyword'] ?? '') !== '') {
                    $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($input['keyword']))).'%';
                    $query->where(fn ($q) => $q->whereRaw("LOWER(c.container_code) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(s.sku_code) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(s.sku_name) LIKE ? ESCAPE '!'", [$pattern]));
                }
                $total = (clone $query)->count();
                $page = (int) ($input['page'] ?? 1);
                $perPage = (int) ($input['per_page'] ?? 20);
                $boxes = $query->orderBy('c.container_code')->orderBy('c.container_id')->forPage($page, $perPage)
                    ->get(['c.container_id', 'c.container_code', 'c.primary_sku_id', 'c.status', 'c.version', 'c.parent_container_id',
                        'c.location_hint', 's.sku_id', 's.sku_code', 's.sku_name', 's.sku_type', 's.unit', 's.is_serial_tracked', 's.status as sku_status']);
                if ($id !== null && $boxes->isEmpty()) {
                    ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hộp trong nguồn được phép đọc.');
                }
                $contents = DB::table('container_items as ci')->whereIn('ci.container_id', $boxes->pluck('container_id'))->where('ci.is_active', true);
                P09WarrantyScope::isolate($contents, 'container_items', 'ci', $scope);
                $groups = $contents->get(['ci.container_id', 'ci.sku_id', 'ci.quantity', 'ci.item_id'])->groupBy('container_id');
                $data = $boxes->map(function (object $box) use ($groups, $scope): array {
                    $rows = $groups->get($box->container_id, collect());
                    $known = $rows->isNotEmpty() && $rows->every(fn (object $r): bool => $r->sku_id === $box->sku_id
                        && is_numeric($r->quantity) && (float) $r->quantity > 0 && (float) $r->quantity === (float) (int) $r->quantity);
                    $component = $box->sku_type === 'COMPONENT';
                    $bulk = $component && ! (bool) $box->is_serial_tracked;

                    return [
                        'container_id' => $box->container_id, 'container_code' => $box->container_code, 'container_type' => 'BOX',
                        'warehouse_id' => $scope['warehouse_id'], 'warehouse_scope_basis' => 'SINGLE_WAREHOUSE_REGISTER_NOT_PHYSICAL_PLACEMENT',
                        'status' => $box->status, 'version' => (int) $box->version,
                        'sku' => $box->sku_id === null ? null : ['id' => $box->sku_id, 'code' => $box->sku_code, 'name' => $box->sku_name,
                            'type' => $box->sku_type, 'status' => $box->sku_status, 'unit' => $box->unit, 'serial_tracked' => (bool) $box->is_serial_tracked],
                        'classification' => $box->sku_id === null ? 'UNASSIGNED' : ($bulk ? 'COMPONENT_QUANTITY' : ($component ? 'COMPONENT_SERIAL' : 'PRODUCT')),
                        'individual_physical_code_required' => $box->sku_id === null ? null : ! $bulk,
                        'component_master_required' => $box->sku_id === null ? null : $component,
                        'contained_qty' => $known ? (int) $rows->sum('quantity') : null,
                        'quantity_status' => $known ? 'RECORDED_CONTAINER_CONTENTS' : ($rows->isEmpty() ? 'NOT_RECORDED' : 'SOURCE_INCONSISTENT'),
                        'quantity_source' => 'container_items.ACTIVE', 'available_qty' => null, 'reserved_qty' => null,
                        'quantity_semantics' => 'CONTAINER_CONTENTS_NOT_WAREHOUSE_BALANCE_OR_ISSUE_ELIGIBILITY',
                        'parent_container_id' => $box->parent_container_id, 'location_hint' => $box->location_hint,
                        'location_hint_semantics' => 'STORED_FREE_TEXT_NOT_HIERARCHY_OR_PLACEMENT_PROOF',
                        'capacity' => null, 'capacity_status' => 'NOT_CONFIGURED',
                        'read_target' => ['method' => 'GET', 'path' => '/api/v1/containers/'.$box->container_id,
                            'query' => ['warehouse_id' => $scope['warehouse_id']], 'headers' => [self::HEADER => self::CONTRACT]],
                    ];
                })->all();

                return response()->json(['success' => true, 'data' => $id === null ? $data : $data[0],
                    'meta' => ['contract' => self::CONTRACT, 'snapshot' => $snapshot, 'warehouse_id' => $scope['warehouse_id'],
                        'page' => $page, 'per_page' => $perPage, 'total' => $total, 'count_unit' => 'BOX',
                        'last_page' => max(1, (int) ceil($total / $perPage)), 'model' => 'SINGLE_WAREHOUSE_WITH_BOXES',
                        'physical_hierarchy' => null, 'capacity' => null, 'write_operations' => [],
                        'product_to_component_reclassification' => false]])
                    ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')
                    ->header('Vary', 'Authorization, Origin');
            });
        } catch (QueryException) {
            ApiError::abort(503, 'CONTAINER_SOURCE_UNAVAILABLE', 'Nguồn hộp tạm thời chưa sẵn sàng; không trả rỗng hoặc tổng một phần.');
        }
    }

    private function scope(Request $request, string $warehouse): array
    {
        $presented = $request->user();
        $actor = $presented instanceof User ? User::whereKey($presented->user_id)->first() : null;
        if ($actor === null || $actor->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn hoạt động.');
        }
        if (! collect(['container.view', 'putaway.view', 'putaway.manage'])->contains(fn (string $p): bool => $actor->hasPermission($p))) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Không có quyền đọc hộp.');
        }
        if (! $actor->hasPermission('sku.view')) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Không có quyền đọc thông tin phân loại linh kiện/sản phẩm của hộp.');
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        if (! $actor->canAccessWarehouse($warehouse)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho nằm ngoài quyền được cấp.');
        }
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        if (($context['warehouse_context']['status'] ?? null) !== 'READY' || $context['active_warehouse_id'] !== $warehouse) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Xác nhận đúng kho của phiên trước khi đọc hộp.');
        }
        // Even an inactive second warehouse can own historical containers.
        // A single ACTIVE row alone is not proof of global register ownership.
        $warehouses = DB::table('warehouses')->get();
        if ($warehouses->count() !== 1 || $warehouses[0]->id !== $warehouse || $warehouses[0]->status !== 'ACTIVE') {
            ApiError::abort(503, 'SINGLE_WAREHOUSE_SCOPE_UNAVAILABLE', 'Nguồn container chưa có chủ quản kho để hỗ trợ nhiều kho; không suy phạm vi.');
        }
        $dimensions = [];
        foreach (['tenant_id', 'org_id', 'organization_id'] as $field) {
            $value = $actor->getAttribute($field);
            $owner = $warehouses[0]->{$field} ?? null;
            if ($value !== null || $owner !== null) {
                if (! is_string($value) || $value === '' || $value !== $owner) {
                    ApiError::abort(403, 'CONTAINER_ORGANIZATION_MISMATCH', 'Không xác minh được phạm vi tổ chức.');
                }
                $dimensions[$field] = $value;
            }
        }

        return ['warehouse_id' => $warehouse, 'dimensions' => $dimensions];
    }
}
