<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use App\Support\DocumentTimestamp;
use App\Support\ExplicitReadFields;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;

/** Exact physical identity and verified putaway proof, never location hints. */
final class P18ComponentLocationRead
{
    public const HEADER = 'X-Component-Location-Contract';

    public const CONTRACT = 'p18-component-location-read-v1';

    public const REVISION = 'p18-component-location-read-r1';

    public function read(Request $request, string $kind, string $id): JsonResponse
    {
        $requestId = $request->attributes->get('request_id');
        if (! is_string($requestId) || $requestId === '') {
            ApiError::abort(503, 'P18_LOCATION_REQUEST_ID_UNAVAILABLE', 'Chưa có định danh đối chiếu của lượt đọc.');
        }
        if ($request->header(self::HEADER) !== self::CONTRACT) {
            ApiError::abort(409, 'P18_LOCATION_CONTRACT_UNSUPPORTED', 'Cần đúng contract đọc vị trí linh kiện.');
        }
        if ($request->header(ExplicitReadFields::HEADER) !== ExplicitReadFields::CONTRACT) {
            ApiError::abort(409, 'P18_LOCATION_REPRESENTATION_REQUIRED', 'Cần contract đọc trường thiếu rõ ràng.');
        }
        if ($request->getContent() !== '' || array_diff(array_keys($request->query()), ['warehouse_id']) !== []) {
            ApiError::abort(422, 'P18_LOCATION_QUERY_UNSUPPORTED', 'Chỉ dùng đúng ID hiện vật và warehouse_id; không suy vị trí từ SKU.');
        }
        $input = Validator::make($request->query(), ['warehouse_id' => ['required', 'uuid']])->validate();
        if (! Str::isUuid($id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hiện vật.');
        }
        $warehouse = strtolower($input['warehouse_id']);
        $observedScope = null;
        try {
            $response = P06ReadSnapshot::run(function (array $snapshot) use ($request, $kind, $id, $warehouse, $requestId, &$observedScope): JsonResponse {
                $scope = $this->scope($request, $kind, $warehouse);
                $observedScope = $scope;
                $identity = $this->identity($kind, strtolower($id), $scope);
                $sku = $identity['sku'];
                if ($sku !== null && $sku->sku_type !== 'COMPONENT') {
                    ApiError::abort(422, 'P18_COMPONENT_REQUIRED', 'Hiện vật không thuộc SKU linh kiện; không tự phân loại lại.');
                }
                $data = [
                    'warehouse_id' => $warehouse, 'warehouse_scope_basis' => 'SINGLE_WAREHOUSE_REGISTER_NOT_PHYSICAL_PLACEMENT',
                    'identity' => $identity['public'],
                    'sku_id' => $sku?->sku_id, 'sku_id_status' => $sku === null ? 'NOT_RECORDED' : 'CURRENT_DIRECTORY',
                    'sku_code' => $this->label($sku?->sku_code), 'sku_code_status' => $this->label($sku?->sku_code) === null ? 'NOT_RECORDED' : 'CURRENT_DIRECTORY',
                    'component_name' => $this->label($sku?->sku_name), 'component_name_status' => $this->label($sku?->sku_name) === null ? 'NOT_RECORDED' : 'CURRENT_DIRECTORY',
                    'unit' => $this->label($sku?->unit), 'unit_status' => $this->label($sku?->unit) === null ? 'NOT_RECORDED' : 'CURRENT_DIRECTORY',
                    'master_source' => 'sku', 'master_semantics' => 'CURRENT_DIRECTORY_NOT_HISTORICAL_SNAPSHOT',
                    'current_location' => null, 'current_location_status' => 'REDACTED_PERMISSION',
                    'hierarchy' => null, 'hierarchy_status' => 'NOT_CONFIGURED',
                    'capacity' => null, 'capacity_status' => 'NOT_CONFIGURED',
                    'stock_effect' => 'NONE',
                ];
                if ($scope['placement_allowed']) {
                    [$box, $membershipStatus, $membership] = $this->boxFor($kind, $identity['row'], $scope);
                    if ($membershipStatus !== null) {
                        $data['current_location_status'] = $membershipStatus;
                    } elseif ($box === null) {
                        $data['current_location_status'] = 'NOT_RECORDED';
                    } else {
                        [$location, $status] = $this->placement($box, $scope, $snapshot);
                        $data['current_location'] = $location;
                        $data['current_location_status'] = $status;
                        if ($location !== null && $kind === 'ITEM') {
                            $data['parent_container_identity'] = ['kind' => 'BOX', 'container_id' => $box->container_id, 'physical_code' => $box->container_code];
                            $data['current_location']['provenance']['basis'] = 'ACTIVE_ITEM_MEMBERSHIP_AND_CONFIRMED_BOX_PUTAWAY';
                            $data['current_location']['provenance']['membership_id'] = $membership->container_item_id;
                            $data['current_location']['provenance']['membership_version'] = (int) $membership->version;
                        }
                    }
                }

                return response()->json(['success' => true, 'data' => $data, 'meta' => [
                    'request_id' => $requestId, 'timestamp' => now()->toIso8601String(),
                    'contract' => self::CONTRACT, 'revision' => self::REVISION, 'snapshot' => $snapshot,
                    'timestamp_contract' => DocumentTimestamp::CONTRACT,
                    'capability' => ['supported' => true, 'model' => 'CONFIRMED_BOX_PUTAWAY', 'direct_item_placement_supported' => false, 'write_operations' => []],
                    'cache_policy' => 'NO_INTERNAL_RESULT_CACHE',
                    'tenant_policy' => $scope['dimensions'] === [] ? 'SINGLE_TENANT_DATABASE_REQUIRED' : 'TRUSTED_ACTOR_WAREHOUSE_AND_SOURCE_COLUMNS',
                ]])->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')
                    ->header('Vary', 'Authorization, Origin, '.self::HEADER.', '.ExplicitReadFields::HEADER);
            });
            // Revalidate after the snapshot so a committed IAM/context revoke
            // cannot deliver data that was authorized only by the older read.
            if ($this->scope($request, $kind, $warehouse) !== $observedScope) {
                ApiError::abort(403, 'P18_LOCATION_SCOPE_CHANGED', 'Quyền hoặc phiên đã thay đổi trong lúc đọc.');
            }

            return $response;
        } catch (QueryException) {
            ApiError::abort(503, 'P18_LOCATION_SOURCE_UNAVAILABLE', 'Nguồn vị trí chưa sẵn sàng; không suy thành chưa có vị trí.');
        }
    }

    private function scope(Request $request, string $kind, string $warehouse): array
    {
        $actor = $request->user() instanceof User ? User::whereKey($request->user()->user_id)->first() : null;
        if ($actor === null || $actor->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn hoạt động.');
        }
        // This new capability requires persisted active grants, not a special
        // role code/display label or the caller's client-side permission list.
        $roles = $actor->activeRoles()->where(fn ($q) => $q->whereNull('user_role.scope_ref')->orWhere('user_role.scope_ref', '')->orWhere('user_role.scope_ref', $warehouse))
            ->with(['permissions' => fn ($q) => $q->where('permission.status', 'ACTIVE')])->get();
        $rights = $roles->pluck('permissions')->flatten()->merge($actor->grantedPermissions()->where('permission.status', 'ACTIVE')->get())
            ->pluck('permission_key')->unique()->sort()->values()->all();
        $has = fn (string $key): bool => in_array($key, $rights, true);
        if (! $has('sku.view')) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Không có quyền đọc hiện vật và master.');
        }
        $allowed = $kind === 'ITEM' ? $has('item_instance.view')
            : collect(['container.view', 'putaway.view', 'putaway.manage'])->contains($has);
        if (! $allowed) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Không có quyền đọc định danh hiện vật.');
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        if (! $actor->canAccessWarehouse($warehouse)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho nằm ngoài quyền được cấp.');
        }
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        if (($context['warehouse_context']['status'] ?? null) !== 'READY' || $context['active_warehouse_id'] !== $warehouse) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Cần xác nhận đúng kho của phiên.');
        }
        $warehouses = DB::table('warehouses')->get();
        if ($warehouses->count() !== 1 || $warehouses[0]->id !== $warehouse || $warehouses[0]->status !== 'ACTIVE') {
            ApiError::abort(503, 'SINGLE_WAREHOUSE_SCOPE_UNAVAILABLE', 'Chưa có owner hiện vật đủ bằng chứng cho nhiều kho.');
        }
        $dimensions = [];
        foreach (['tenant_id', 'org_id', 'organization_id'] as $field) {
            $actorValue = $actor->getAttribute($field);
            $owner = $warehouses[0]->{$field} ?? null;
            if ($actorValue !== null || $owner !== null) {
                if (! is_string($actorValue) || $actorValue === '' || $actorValue !== $owner) {
                    ApiError::abort(403, 'P18_LOCATION_ORGANIZATION_MISMATCH', 'Không xác minh được cùng tổ chức.');
                }
                $dimensions[$field] = $actorValue;
            }
        }

        return ['warehouse_id' => $warehouse, 'actor_id' => (string) $actor->user_id, 'actor_version' => (int) $actor->version,
            'auth_session_id' => $session, 'dimensions' => $dimensions, 'rights' => $rights,
            'placement_allowed' => $has('putaway.view') || $has('putaway.manage')];
    }

    private function source(string $table, string $alias, array $scope): Builder
    {
        $query = DB::table($table.' as '.$alias);
        P09WarrantyScope::isolate($query, $table, $alias, $scope);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull($alias.'.deleted_at');
        }

        return $query;
    }

    private function identity(string $kind, string $id, array $scope): array
    {
        $item = $kind === 'ITEM';
        $row = $this->source($item ? 'item_instances' : 'containers', 'i', $scope)
            ->where('i.'.($item ? 'item_id' : 'container_id'), $id)
            ->when(! $item, fn (Builder $q): Builder => $q->where('i.container_type', 'BOX'))->first();
        if ($row === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy đúng hiện vật trong phạm vi được phép.');
        }
        $skuId = $item ? $row->sku_id : $row->primary_sku_id;
        $sku = $skuId === null ? null : $this->source('sku', 's', $scope)->where('s.sku_id', $skuId)->first();
        if ($skuId !== null && $sku === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Master của hiện vật không nằm trong phạm vi đọc.');
        }
        $code = $item ? null : $this->label($row->container_code);
        $status = 'NOT_RECORDED';
        if ($item && $code === null) {
            $codes = $this->source('physical_codes', 'p', $scope)->where('p.assigned_object_type', 'ITEM')->where('p.assigned_object_id', $id)
                ->where('p.status', 'ACTIVE')->whereIn('p.carrier_type', ['QR', 'BARCODE'])->get(['p.code_value']);
            $code = $codes->count() === 1 ? $this->label($codes[0]->code_value) : null;
            $status = $codes->count() > 1 ? 'SOURCE_INCONSISTENT' : 'NOT_RECORDED';
        }

        return ['row' => $row, 'sku' => $sku, 'public' => ['kind' => $kind, $item ? 'item_id' : 'container_id' => $id,
            'physical_code' => $code, 'physical_code_status' => $code === null ? $status : 'RECORDED_PHYSICAL_CODE']];
    }

    private function boxFor(string $kind, object $row, array $scope): array
    {
        if ($kind === 'BOX') {
            return [$row, null, null];
        }
        $rows = $this->source('container_items', 'm', $scope)->where('m.item_id', $row->item_id)->where('m.is_active', true)->get();
        $parent = $row->container_id ?? null;
        if ($rows->isEmpty() && $parent === null) {
            return [null, 'NOT_RECORDED', null];
        }
        if ($rows->count() !== 1 || $parent === null || $rows[0]->container_id !== $parent || $rows[0]->sku_id !== null
            || $rows[0]->quantity !== null || $rows[0]->status !== 'ACTIVE' || (int) $rows[0]->version < 1 || $row->item_status !== 'IN_STOCK') {
            return [null, 'SOURCE_INCONSISTENT', null];
        }
        $box = $this->source('containers', 'b', $scope)->where('b.container_id', $parent)->where('b.container_type', 'BOX')->first();
        if ($box === null || $box->primary_sku_id !== $row->sku_id) {
            return [null, 'SOURCE_INCONSISTENT', null];
        }

        return [$box, null, $rows[0]];
    }

    private function placement(object $box, array $scope, array $snapshot): array
    {
        $events = $this->source('putaway_events', 'p', $scope)->where('p.box_container_id', $box->container_id)
            ->where('p.status', 'CONFIRMED')->orderByDesc('p.confirmed_at')->orderByDesc('p.id')->get();
        if ($events->isEmpty()) {
            return [null, 'NOT_RECORDED'];
        }
        if ($events->contains(fn (object $e): bool => $e->warehouse_id !== $scope['warehouse_id'])) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy placement trong phạm vi kho.');
        }
        try {
            $event = $events[0];
            $effective = DocumentTimestamp::utc($event->confirmed_at);
            $latest = DocumentTimestamp::instant($event->confirmed_at);
            if ($latest === null || $latest > DocumentTimestamp::instant($snapshot['observed_at']) || (int) $event->version < 1
                || $events->slice(1)->contains(fn (object $e): bool => DocumentTimestamp::instant($e->confirmed_at) === null
                    || DocumentTimestamp::instant($e->confirmed_at) >= $latest)) {
                return [null, 'SOURCE_INCONSISTENT'];
            }
        } catch (InvalidArgumentException) {
            return [null, 'SOURCE_INCONSISTENT'];
        }
        if (! in_array($box->status, ['STORED', 'PARTIAL'], true) || $event->container_id !== $box->container_id || $event->to_tray_id === null
            || $event->to_tray_id !== $box->parent_container_id || $event->to_parent_container_id !== $event->to_tray_id) {
            return [null, 'SOURCE_INCONSISTENT'];
        }
        $tray = $this->source('containers', 't', $scope)->where('t.container_id', $event->to_tray_id)->where('t.container_type', 'TRAY')->first();
        $receipt = $this->source('stock_documents', 'd', $scope)->where('d.id', $event->stock_document_id)
            ->where('d.doc_type', 'receipt')->where('d.status', 'POSTED')->where('d.dst_warehouse_id', $scope['warehouse_id'])->first();
        if ($tray === null || $this->label($tray->container_code) === null || in_array($tray->status, ['CLOSED', 'ISSUED'], true)
            || $receipt === null || (int) $box->version < 1 || (int) $tray->version < 1) {
            return [null, 'SOURCE_INCONSISTENT'];
        }

        return [[
            'location_id' => $tray->container_id, 'location_code' => $tray->container_code, 'location_label' => $tray->container_code,
            'location_kind' => 'TRAY', 'label_semantics' => 'ACTUAL_CONTAINER_CODE_NOT_SHELF_OR_BIN', 'warehouse_id' => $scope['warehouse_id'],
            'placement_version' => (int) $event->version, 'box_version' => (int) $box->version, 'location_version' => (int) $tray->version,
            'effective_at' => $effective, 'observed_at' => $snapshot['observed_at'],
            'provenance' => ['basis' => 'CONFIRMED_BOX_PUTAWAY_AND_CURRENT_PARENT', 'event_id' => $event->id,
                'event_source' => 'putaway_events', 'effective_time_source' => 'putaway_events.confirmed_at',
                'parent_source' => 'containers.parent_container_id', 'location_source' => 'containers.container_id/container_code',
                'receipt_id' => $receipt->id],
        ], 'RECORDED'];
    }

    private function label(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }
}
