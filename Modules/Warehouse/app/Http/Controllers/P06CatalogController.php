<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Support\P06CatalogReadModel;
use Modules\Warehouse\Support\P06ReadSnapshot;
use Modules\Warehouse\Support\WarrantyWarehouseScope;

/** P06 is read-only. SKU master cards and historically scoped ITEM cards never share an identity. */
final class P06CatalogController extends Controller
{
    public const CONTRACT = 'mini-app-p06-v1';

    public function __construct(private readonly P06CatalogReadModel $readModel) {}

    public function context(Request $request): JsonResponse
    {
        $this->input($request, [], false);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request): JsonResponse {
            $actor = $this->actor($request);
            $session = app(StableAuthSession::class)->requireId($actor);
            $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
            $status = $context['active_warehouse_id'] === null ? null : DB::table('warehouses')->where('id', $context['active_warehouse_id'])->value('status');
            if (($context['warehouse_context']['error_code'] ?? null) === 'WAREHOUSE_INACTIVE') {
                $status = 'INACTIVE';
            }

            return $this->respond([
                'actor_id' => (string) $actor->user_id, 'auth_session_id' => $session, ...$context,
                'warehouse_status' => $status, 'read_only' => true,
                'permissions' => $this->permissions($actor),
                'identity_types' => ['SKU', 'ITEM'],
                'sku_scope' => 'GLOBAL_MASTER_WITH_SELECTED_WAREHOUSE_STOCK',
                'item_scope' => 'SELECTED_WAREHOUSE_HISTORY_OR_PERMITTED_WARRANTY_NOT_CURRENT_LOCATION',
                'tab_field' => 'sku_type', 'tab_values' => ['ALL', 'PRODUCT', 'COMPONENT'],
                'location_breakdown' => ['supported' => false, 'level' => 'WAREHOUSE', 'reason' => 'NO_LOCATION_BALANCE_MODEL'],
                'label_print' => ['action_exposed' => false, 'required_target_type' => 'LABEL_SNAPSHOT', 'target_id' => null],
                'address_contract' => ['format' => 'TEXT', 'dataset_version' => null, 'history_policy' => 'PRESERVE_TEXT', 'owner' => 'DEV_BA', 'decision' => 'PENDING_DEV_BA'],
            ], $snapshot);
        });
    }

    public function skus(Request $request): JsonResponse
    {
        return $this->listing($request, false);
    }

    public function items(Request $request): JsonResponse
    {
        return $this->listing($request, true);
    }

    private function listing(Request $request, bool $items): JsonResponse
    {
        $input = $this->input($request, [
            'keyword' => ['sometimes', 'string', 'max:200'],
            'serial' => ['sometimes', 'string', 'max:200'],
            'item_code' => ['sometimes', 'string', 'max:512'],
            'sku_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE', 'ALL'])],
            'sku_type' => ['sometimes', Rule::in(['PRODUCT', 'COMPONENT', 'ALL'])],
            ...($items ? ['item_status' => ['sometimes', 'string', 'max:40', 'regex:/^[A-Z_]+$/D']] : []),
            ...$this->pagingRules(),
        ]);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $input, $items): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id']);
            $actor = $scope['actor'];
            if ($items || isset($input['serial']) || isset($input['item_code'])) {
                $this->permission($actor, 'item_instance.view');
            }
            $query = $items ? $this->itemQuery($scope) : DB::table('sku as s');
            if (($input['status'] ?? 'ACTIVE') !== 'ALL') {
                $query->where('s.status', $input['status'] ?? 'ACTIVE');
            }
            if (isset($input['sku_id'])) {
                $query->where('s.sku_id', strtolower($input['sku_id']));
            }
            if ($items && isset($input['item_status'])) {
                $query->where('i.item_status', $input['item_status']);
            }
            if (trim($input['keyword'] ?? '') !== '') {
                $pattern = $this->pattern($input['keyword']);
                $query->where(function (Builder $q) use ($pattern, $items): void {
                    $q->whereRaw("LOWER(s.sku_code) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(s.sku_name) LIKE ? ESCAPE '!'", [$pattern]);
                    if ($items) {
                        $q->orWhereRaw("LOWER(COALESCE(i.item_code,'')) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("LOWER(COALESCE(i.serial_number,'')) LIKE ? ESCAPE '!'", [$pattern]);
                    }
                });
            }
            if ($items) {
                $this->identityFilters($query, $input);
            } elseif (isset($input['serial']) || isset($input['item_code'])) {
                $matches = $this->identityFilters($this->itemQuery($scope), $input)->select('i.sku_id');
                $query->whereIn('s.sku_id', $matches);
            }
            // Tab counts share every filter and the same database snapshot;
            // only the selected sku_type is deliberately excluded from all-tab counts.
            $counts = (clone $query)->selectRaw('s.sku_type, COUNT(*) AS count')->groupBy('s.sku_type')->pluck('count', 'sku_type');
            $tabs = ['ALL' => (int) $counts->sum(), 'PRODUCT' => (int) ($counts['PRODUCT'] ?? 0), 'COMPONENT' => (int) ($counts['COMPONENT'] ?? 0)];
            if (($input['sku_type'] ?? 'ALL') !== 'ALL') {
                $query->where('s.sku_type', $input['sku_type']);
            }
            $total = (clone $query)->count();
            $page = (int) ($input['page'] ?? 1);
            $perPage = (int) ($input['per_page'] ?? 20);
            $columns = ['s.sku_id', 's.sku_code', 's.sku_name', 's.sku_type', 's.status as sku_status', 's.unit', 's.version as sku_version', 's.updated_at as sku_updated_at'];
            if ($items) {
                $columns = [...$columns, 'i.item_id', 'i.item_code', 'i.serial_number', 'i.item_status', 'i.item_condition', 'i.version as item_version', 'i.updated_at as item_updated_at'];
            }
            $rows = $query->orderBy('s.sku_name')->orderBy('s.sku_id')
                ->when($items, fn (Builder $q): Builder => $q->orderBy('i.item_id'))
                ->forPage($page, $perPage)->get($columns);
            $canStock = ! $items && $actor->hasPermission('inventory.view');
            $stocks = $canStock ? $this->readModel->stocks($rows->pluck('sku_id')->all(), $scope['warehouse_id'], $snapshot) : [];
            $data = $rows->map(fn (object $row): array => [
                ...$this->card($row, $items),
                'stock' => $canStock ? $stocks[$row->sku_id] : null,
                'stock_availability' => $items ? 'USE_SKU_STOCK_ENDPOINT' : ($canStock ? 'INCLUDED' : 'FORBIDDEN'),
            ])->all();

            return $this->respond($data, $snapshot, [
                'page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage)),
                'tab_totals' => $tabs, 'count_unit' => $items ? 'ITEM' : 'SKU',
                'warehouse_id' => $scope['warehouse_id'],
                'scope_policy' => $items ? 'WAREHOUSE_HISTORY_NOT_CURRENT_LOCATION' : 'GLOBAL_SKU_MASTER_SCOPED_STOCK',
            ]);
        });
    }

    public function sku(Request $request, string $id): JsonResponse
    {
        return $this->detail($request, $id, false);
    }

    public function item(Request $request, string $id): JsonResponse
    {
        return $this->detail($request, $id, true);
    }

    private function detail(Request $request, string $id, bool $item): JsonResponse
    {
        $input = $this->input($request, []);
        $this->uuid($id);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $input, $id, $item): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id']);
            $row = $this->requireCard($scope, $id, $item);
            $canStock = ! $item && $scope['actor']->hasPermission('inventory.view');
            $stock = $canStock ? $this->readModel->stocks([$row->sku_id], $scope['warehouse_id'], $snapshot)[$row->sku_id] : null;

            return $this->respond([
                'card' => $this->card($row, $item), 'stock' => $stock,
                'stock_availability' => $item ? 'USE_SKU_STOCK_ENDPOINT' : ($canStock ? 'INCLUDED' : 'FORBIDDEN'),
                'gallery' => $this->readModel->gallery($row->sku_id), 'gallery_owner_type' => 'SKU',
                'capabilities' => $this->capabilities($scope['actor'], $item ? $id : null),
            ], $snapshot, ['warehouse_id' => $scope['warehouse_id']]);
        });
    }

    public function stock(Request $request, string $id): JsonResponse
    {
        $input = $this->input($request, []);
        $this->uuid($id);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $input, $id): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id']);
            $this->permission($scope['actor'], 'inventory.view');
            $this->requireCard($scope, $id, false);

            return $this->respond($this->readModel->stocks([$id], $scope['warehouse_id'], $snapshot)[$id], $snapshot);
        });
    }

    public function gallery(Request $request, string $id): JsonResponse
    {
        $input = $this->input($request, []);
        $this->uuid($id);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $input, $id): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id']);
            $this->requireCard($scope, $id, false);
            $data = $this->readModel->gallery($id);

            return $this->respond($data, $snapshot, ['total' => count($data), 'owner_type' => 'SKU', 'sku_id' => $id]);
        });
    }

    public function skuTimeline(Request $request, string $id): JsonResponse
    {
        return $this->timeline($request, $id, false);
    }

    public function itemTimeline(Request $request, string $id): JsonResponse
    {
        return $this->timeline($request, $id, true);
    }

    private function timeline(Request $request, string $id, bool $item): JsonResponse
    {
        $input = $this->input($request, ['source' => ['sometimes', Rule::in(['ALL', 'LEDGER', 'WARRANTY'])], ...$this->pagingRules()]);
        $this->uuid($id);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $input, $id, $item): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id']);
            $row = $this->requireCard($scope, $id, $item);
            $result = $this->readModel->timeline($scope['actor'], $scope['warehouse_id'], $row->sku_id, $item ? $id : null, $input);

            return $this->respond($result['data'], $snapshot, $result['meta'] + ['warehouse_id' => $scope['warehouse_id']]);
        });
    }

    private function itemQuery(array $scope): Builder
    {
        $warranty = $scope['actor']->hasPermission('warranty.view') || $scope['actor']->hasPermission('warranty.manage');

        return DB::table('item_instances as i')->join('sku as s', 's.sku_id', '=', 'i.sku_id')
            ->where(function (Builder $q) use ($scope, $warranty): void {
                $q->whereExists(function (Builder $m) use ($scope): void {
                    $m->selectRaw('1')->from('stock_movements as scope_m')->whereColumn('scope_m.item_id', 'i.item_id')
                        ->where('scope_m.warehouse_id', $scope['warehouse_id']);
                });
                if ($warranty) {
                    $q->orWhereExists(function (Builder $c) use ($scope): void {
                        $c->selectRaw('1')->from('warranty_case as scope_c')
                            ->join(WarrantyWarehouseScope::TABLE.' as scope_o', 'scope_o.warranty_case_id', '=', 'scope_c.warranty_case_id')
                            ->whereColumn('scope_c.item_id', 'i.item_id')->where('scope_o.warehouse_id', $scope['warehouse_id']);
                    });
                }
            });
    }

    private function identityFilters(Builder $query, array $input): Builder
    {
        if (isset($input['serial'])) {
            $query->whereRaw('LOWER(i.serial_number) = ?', [mb_strtolower(trim($input['serial']))]);
        }
        if (isset($input['item_code'])) {
            $code = mb_strtolower(trim($input['item_code']));
            $query->where(function (Builder $q) use ($code): void {
                $q->whereRaw('LOWER(i.item_code) = ?', [$code])->orWhereExists(function (Builder $p) use ($code): void {
                    $p->selectRaw('1')->from('physical_codes as pc')->where('pc.assigned_object_type', 'ITEM')
                        ->whereColumn('pc.assigned_object_id', 'i.item_id')->where('pc.status', 'ACTIVE')->whereRaw('LOWER(pc.code_value) = ?', [$code]);
                });
            });
        }

        return $query;
    }

    private function requireCard(array $scope, string $id, bool $item): object
    {
        $columns = ['s.sku_id', 's.sku_code', 's.sku_name', 's.sku_type', 's.status as sku_status', 's.unit', 's.version as sku_version', 's.updated_at as sku_updated_at'];
        if ($item) {
            $this->permission($scope['actor'], 'item_instance.view');
            $row = $this->itemQuery($scope)->where('i.item_id', $id)->first([
                ...$columns, 'i.item_id', 'i.item_code', 'i.serial_number', 'i.item_status', 'i.item_condition', 'i.version as item_version', 'i.updated_at as item_updated_at',
            ]);
        } else {
            $row = DB::table('sku as s')->where('s.sku_id', $id)->first($columns);
        }
        if ($row === null) {
            ApiError::abort(404, 'P06_RESOURCE_NOT_FOUND', 'Không tìm thấy đối tượng trong phạm vi đọc hiện tại.');
        }

        return $row;
    }

    private function card(object $row, bool $item): array
    {
        return [
            'card_type' => $item ? 'ITEM' : 'SKU', 'sku_id' => $row->sku_id, 'sku_code' => $row->sku_code,
            'sku_name' => $row->sku_name, 'sku_type' => $row->sku_type, 'sku_status' => $row->sku_status, 'unit' => $row->unit,
            'item_id' => $item ? $row->item_id : null, 'item_code' => $item ? $row->item_code : null,
            'serial_number' => $item ? $row->serial_number : null, 'item_status' => $item ? $row->item_status : null,
            'item_condition' => $item ? $row->item_condition : null,
            'version' => $item ? (int) $row->item_version : (int) $row->sku_version,
            'updated_at' => $this->readModel->instant($item ? $row->item_updated_at : $row->sku_updated_at),
            'current_location' => null, 'current_location_status' => 'NOT_ASSERTED',
        ];
    }

    /** One named permission projection for context and detail; no role-name inference. */
    private function permissions(User $actor): array
    {
        return [
            'skus' => $actor->hasPermission('sku.view'),
            'items' => $actor->hasPermission('sku.view') && $actor->hasPermission('item_instance.view'),
            'stock' => $actor->hasPermission('sku.view') && $actor->hasPermission('inventory.view'),
            'ledger' => $actor->hasPermission('inventory.trace'),
            'warranty_view' => $actor->hasPermission('warranty.view') || $actor->hasPermission('warranty.manage'),
            'warranty_manage' => $actor->hasPermission('warranty.manage'),
            'label_print_permission' => $actor->hasPermission('label.print'),
        ];
    }

    private function capabilities(User $actor, ?string $itemId): array
    {
        $permissions = $this->permissions($actor);

        return [
            'warranty' => [
                'view_permitted' => $permissions['warranty_view'],
                'manage_permitted' => $permissions['warranty_manage'],
                'target_type' => $itemId === null ? null : 'ITEM', 'target_id' => $itemId,
                'action_exposed' => false, 'reason' => 'P06_READ_ONLY_OWNER_FLOW_REVALIDATES',
            ],
            'label_print' => [
                'permission_granted' => $permissions['label_print_permission'], 'target_type' => 'LABEL_SNAPSHOT',
                'target_id' => null, 'action_exposed' => false, 'reason' => 'LABEL_TARGET_REQUIRED_NO_P06_PRINT_API',
            ],
        ];
    }

    private function scope(Request $request, string $warehouseId): array
    {
        $actor = $this->actor($request);
        $this->permission($actor, 'sku.view');
        $session = app(StableAuthSession::class)->requireId($actor);
        $id = strtolower($warehouseId);
        if (! $actor->canAccessWarehouse($id)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho nằm ngoài phạm vi được cấp.');
        }
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        if ($context['warehouse_context']['status'] !== 'READY') {
            ApiError::abort(409, $context['warehouse_context']['error_code'] ?? 'WAREHOUSE_CONTEXT_REQUIRED', 'Xác nhận lại kho làm việc trước khi đọc dữ liệu.');
        }
        if ($context['active_warehouse_id'] !== $id) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho gửi lên không khớp kho của phiên.');
        }
        $warehouse = DB::table('warehouses')->where('id', $id)->first();
        if ($warehouse === null || $warehouse->status !== 'ACTIVE') {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho không còn hoạt động.');
        }

        return ['actor' => $actor, 'warehouse_id' => $id, 'auth_session_id' => $session];
    }

    private function actor(Request $request): User
    {
        $presented = $request->user();
        if (! $presented instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }
        $actor = User::whereKey($presented->user_id)->first();
        if ($actor === null || $actor->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn hoạt động.');
        }

        return $actor;
    }

    private function permission(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            ApiError::abort(403, 'P06_PERMISSION_DENIED', 'Không đủ quyền đọc dữ liệu yêu cầu.');
        }
    }

    private function input(Request $request, array $extra, bool $warehouse = true): array
    {
        if ($request->getContent() !== '') {
            ApiError::abort(422, 'P06_QUERY_ONLY', 'P06 GET chỉ nhận query, không nhận body.');
        }
        $rules = ($warehouse ? ['warehouse_id' => ['required', 'uuid']] : []) + $extra;
        if (array_diff(array_keys($request->query()), array_keys($rules)) !== []) {
            ApiError::abort(422, 'UNEXPECTED_FIELD', 'Tham số ngoài contract P06; không bỏ qua bộ lọc không được hỗ trợ.');
        }

        return Validator::make($request->query(), $rules)->validate();
    }

    private function uuid(string $id): void
    {
        Validator::make(['id' => $id], ['id' => ['required', 'uuid']])->validate();
    }

    private function pagingRules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }

    private function pattern(string $text): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($text))).'%';
    }

    private function respond(mixed $data, array $snapshot, array $meta = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => ['contract' => self::CONTRACT, 'snapshot' => $snapshot] + $meta])
            ->header('Cache-Control', 'private, no-store');
    }
}
