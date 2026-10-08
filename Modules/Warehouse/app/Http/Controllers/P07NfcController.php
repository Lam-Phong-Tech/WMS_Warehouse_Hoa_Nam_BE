<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Support\P06ReadSnapshot;
use Modules\Warehouse\Support\P07NfcPolicy;
use Throwable;

/**
 * P07 hardening adapter. The existing atomic NFC writer and audit/cache hooks
 * remain authoritative; this class adds session/scope/readback/reconciliation.
 * LOCKED is a UI bucket, not a promise that LOST/DAMAGED tags can be reactivated.
 */
final class P07NfcController extends MiniAppNfcController
{
    private const HOLD = 'wms:nfc:write-reservation:';
    private const OPERATION = 'mini_app_nfc_confirm';
    private const TAB_SQL = "CASE WHEN pc.status = 'ACTIVE' AND pc.item_id IS NOT NULL THEN 'ASSIGNED' WHEN pc.status = 'ACTIVE' AND pc.item_id IS NULL THEN 'UNASSIGNED' WHEN pc.status IN ('INACTIVE','LOST','DAMAGED') THEN 'LOCKED' ELSE 'UNKNOWN' END";

    private function context(Request $request): array
    {
        $request->validate(['warehouse_id' => ['sometimes', 'uuid']]);
        $presented = $request->user();
        if (! $presented instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }
        $actor = User::whereKey($presented->user_id)->first();
        if ($actor === null || $actor->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn hoạt động.');
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        if (! Schema::hasColumn('physical_codes', 'nfc_warehouse_id') || ! Schema::hasColumn('physical_codes', 'nfc_auth_session_id')) {
            ApiError::abort(503, 'NFC_P07_MIGRATION_REQUIRED', 'Chưa hoàn tất migration P07; tạm dừng thao tác NFC.');
        }
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        if (($context['warehouse_context']['status'] ?? null) !== 'READY' || empty($context['active_warehouse_id'])) {
            ApiError::abort(409, $context['warehouse_context']['error_code'] ?? 'WAREHOUSE_CONTEXT_REQUIRED', 'Xác nhận lại kho làm việc.');
        }
        $id = (string) $context['active_warehouse_id'];
        if ($request->filled('warehouse_id') && strtolower((string) $request->input('warehouse_id')) !== $id) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho yêu cầu không khớp kho của phiên.');
        }
        if (! $actor->canAccessWarehouse($id)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho nằm ngoài phạm vi được cấp.');
        }
        if (! DB::table('warehouses')->where('id', $id)->where('status', 'ACTIVE')->exists()) {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho không còn hoạt động.');
        }

        return ['actor' => $actor, 'warehouse_id' => $id, 'auth_session_id' => $session];
    }

    /** Scope is evidenced by item ledger history, never inferred from SKU/creator. */
    private function scopedItems(array $scope): Builder
    {
        return DB::table('item_instances as i')->whereExists(function (Builder $m) use ($scope): void {
            $m->selectRaw('1')->from('stock_movements as p07_m')->whereColumn('p07_m.item_id', 'i.item_id')
                ->where('p07_m.warehouse_id', $scope['warehouse_id']);
        });
    }

    private function requireItem(array $scope, string $id): object
    {
        if (! Str::isUuid($id) || ($item = $this->scopedItems($scope)->where('i.item_id', $id)->first(['i.*'])) === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hiện vật trong phạm vi kho.');
        }

        return $item;
    }

    private function writePermission(array $scope): void
    {
        if (! $scope['actor']->hasPermission('physical_code.assign_rfid')) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Thiếu quyền physical_code.assign_rfid.');
        }
    }

    private function tags(array $scope): Builder
    {
        return DB::table('physical_codes as pc')->where('pc.carrier_type', 'NFC')
            ->where(function (Builder $q) use ($scope): void {
                $q->where('pc.nfc_warehouse_id', $scope['warehouse_id'])->orWhere(function (Builder $legacy) use ($scope): void {
                    $legacy->whereNull('pc.nfc_warehouse_id')->whereIn('pc.item_id', $this->scopedItems($scope)->select('i.item_id'));
                });
            });
    }

    private function project(object $tag): array
    {
        $data = (array) $tag;
        unset($data['nfc_auth_session_id']);
        $tab = P07NfcPolicy::tab($tag->status, $tag->item_id ?? null);
        $data['tab_status'] = $tab;
        $data['status_label'] = $tab === null ? null : P07NfcPolicy::LABELS[$tab];
        $data['raw_status'] = $tag->status;
        $data['linker'] = empty($tag->assigned_by) ? null : ['user_id' => $tag->assigned_by, 'display_name' => $tag->linker_name ?? null];
        // Historical rows do not persist an assignment warehouse. Do not pretend
        // the current session warehouse proves where/when an old assignment occurred.
        $data['assigned_warehouse'] = empty($tag->nfc_warehouse_id) ? null : ['warehouse_id' => $tag->nfc_warehouse_id, 'code' => $tag->assignment_warehouse_code ?? null, 'name' => $tag->assignment_warehouse_name ?? null];
        $data['assigned_warehouse_source'] = empty($tag->nfc_warehouse_id) ? 'NOT_RECORDED' : 'NFC_ASSIGNMENT';
        $data['can_reactivate'] = false;
        $data['replace_existing_allowed'] = false;

        return $data;
    }

    private function tagColumns(Builder $query): Builder
    {
        return $query->leftJoin('item_instances as i', 'i.item_id', '=', 'pc.item_id')
            ->leftJoin('sku as s', 's.sku_id', '=', 'i.sku_id')
            ->leftJoin('app_user as u', 'u.user_id', '=', 'pc.assigned_by')
            ->leftJoin('warehouses as w', 'w.id', '=', 'pc.nfc_warehouse_id')
            ->select(['pc.*', 'i.serial_number', 'i.item_status', 's.sku_id', 's.sku_code', 's.sku_name', 'u.display_name as linker_name', 'w.code as assignment_warehouse_code', 'w.name as assignment_warehouse_name']);
    }

    private function totals(Builder $query): array
    {
        $counts = (clone $query)->selectRaw(self::TAB_SQL.' AS tab_status, COUNT(*) AS aggregate')->groupByRaw(self::TAB_SQL)->pluck('aggregate', 'tab_status');

        return ['ALL' => (int) $counts->sum(), 'ASSIGNED' => (int) ($counts['ASSIGNED'] ?? 0),
            'UNASSIGNED' => (int) ($counts['UNASSIGNED'] ?? 0), 'LOCKED' => (int) ($counts['LOCKED'] ?? 0), 'UNKNOWN' => (int) ($counts['UNKNOWN'] ?? 0)];
    }

    public function listTags(Request $request): JsonResponse
    {
        $input = $request->validate([
            'keyword' => ['nullable', 'string', 'max:200'], 'search' => ['nullable', 'string', 'max:200'],
            'item_id' => ['nullable', 'uuid'], 'physical_code_id' => ['nullable', 'uuid'],
            'hardware_uid' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'LOST', 'DAMAGED'])],
            'tab_status' => ['nullable', Rule::in(['ALL', 'ASSIGNED', 'UNASSIGNED', 'LOCKED'])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (isset($input['keyword'], $input['search']) && trim($input['keyword']) !== trim($input['search'])) {
            ApiError::abort(422, 'CONFLICTING_SEARCH_FIELDS', 'Chỉ gửi keyword hoặc search, không gửi hai giá trị khác nhau.');
        }

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $input): JsonResponse {
            $scope = $this->context($request);
            $query = $this->tags($scope);
            foreach (['item_id', 'physical_code_id', 'status'] as $key) {
                if (! empty($input[$key])) {
                    $query->where('pc.'.$key, $input[$key]);
                }
            }
            if (! empty($input['hardware_uid'])) {
                $query->where('pc.hardware_uid', strtoupper(trim($input['hardware_uid'])));
            }
            $keyword = trim($input['keyword'] ?? $input['search'] ?? '');
            if ($keyword !== '') {
                $pattern = P07NfcPolicy::pattern($keyword);
                $query->where(function (Builder $q) use ($pattern): void {
                    $q->whereRaw("LOWER(pc.hardware_uid) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(pc.code_value) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereIn('pc.item_id', DB::table('item_instances as si')->join('sku as ss', 'ss.sku_id', '=', 'si.sku_id')
                            ->where(function (Builder $s) use ($pattern): void {
                                $s->whereRaw("LOWER(si.serial_number) LIKE ? ESCAPE '!'", [$pattern])
                                    ->orWhereRaw("LOWER(ss.sku_code) LIKE ? ESCAPE '!'", [$pattern])
                                    ->orWhereRaw("LOWER(ss.sku_name) LIKE ? ESCAPE '!'", [$pattern]);
                            })->select('si.item_id'));
                });
            }
            $counts = $this->totals($query);
            if (($input['tab_status'] ?? 'ALL') !== 'ALL') {
                $query->whereRaw(self::TAB_SQL.' = ?', [$input['tab_status']]);
            }
            $total = (clone $query)->count();
            $page = (int) ($input['page'] ?? 1);
            $perPage = (int) ($input['per_page'] ?? 20);
            $rows = $this->tagColumns($query)->orderByDesc('pc.assigned_at')->orderBy('pc.physical_code_id')
                ->forPage($page, $perPage)->get()->map(fn (object $tag): array => $this->project($tag))->all();

            return $this->reply($rows, $scope, ['current_page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)), 'tab_totals' => $counts, 'summary' => $counts,
                'count_unit' => 'NFC_TAG', 'snapshot' => $snapshot,
                'unscoped_unlinked_tags_included' => false, 'tab_labels' => P07NfcPolicy::LABELS]);
        });
    }

    public function showTag(Request $request, string $physicalCodeId): JsonResponse
    {
        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $physicalCodeId): JsonResponse {
            $scope = $this->context($request);
            $tag = $this->requireTag($scope, $physicalCodeId);

            return $this->reply($this->project($tag), $scope, ['snapshot' => $snapshot]);
        });
    }

    private function requireTag(array $scope, string $id): object
    {
        if (! Str::isUuid($id) || ($tag = $this->tagColumns($this->tags($scope))->where('pc.physical_code_id', $id)->first()) === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chip trong phạm vi kho.');
        }

        return $tag;
    }

    public function listItems(Request $request): JsonResponse
    {
        if ($request->exists('search')) {
            ApiError::abort(422, 'NFC_ITEMS_USE_KEYWORD', 'Danh sách hiện vật dùng keyword, không dùng search.');
        }
        $input = $request->validate([
            'keyword' => ['nullable', 'string', 'max:200'], 'sku_id' => ['nullable', 'uuid'], 'container_id' => ['nullable', 'uuid'],
            'item_status' => ['nullable', 'string', 'max:40'], 'nfc_status' => ['nullable', Rule::in(['ALL', 'ASSIGNED', 'UNASSIGNED'])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $input): JsonResponse {
            $scope = $this->context($request);
            $query = $this->scopedItems($scope)->join('sku as s', 's.sku_id', '=', 'i.sku_id');
            $status = strtoupper(trim($input['item_status'] ?? 'IN_STOCK'));
            if ($status !== 'ALL') {
                $query->where('i.item_status', $status);
            }
            foreach (['sku_id', 'container_id'] as $key) {
                if (! empty($input[$key])) {
                    $query->where('i.'.$key, $input[$key]);
                }
            }
            if (trim($input['keyword'] ?? '') !== '') {
                $pattern = P07NfcPolicy::pattern($input['keyword']);
                $query->where(function (Builder $q) use ($pattern): void {
                    $q->whereRaw("LOWER(i.serial_number) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(s.sku_code) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(s.sku_name) LIKE ? ESCAPE '!'", [$pattern]);
                });
            }
            $active = fn (): Builder => DB::table('physical_codes as a')->where('a.carrier_type', 'NFC')->where('a.status', 'ACTIVE')->select('a.item_id');
            $all = (clone $query)->count();
            $assigned = (clone $query)->whereIn('i.item_id', $active())->count();
            if (($input['nfc_status'] ?? 'ALL') === 'ASSIGNED') {
                $query->whereIn('i.item_id', $active());
            } elseif (($input['nfc_status'] ?? 'ALL') === 'UNASSIGNED') {
                $query->whereNotIn('i.item_id', $active()->whereNotNull('a.item_id'));
            }
            $total = (clone $query)->count();
            $page = (int) ($input['page'] ?? 1);
            $perPage = (int) ($input['per_page'] ?? 20);
            $rows = $query->orderBy('s.sku_code')->orderBy('i.item_id')->forPage($page, $perPage)
                ->get(['i.*', 's.sku_code', 's.sku_name', 's.unit']);
            $codes = DB::table('physical_codes')->whereIn('item_id', $rows->pluck('item_id'))->where('status', 'ACTIVE')
                ->orderByDesc('is_primary')->orderByDesc('assigned_at')->orderBy('physical_code_id')->get()->groupBy('item_id');
            $data = $rows->map(function (object $item) use ($codes, $scope): array {
                $itemCodes = $codes->get($item->item_id, collect());
                $tag = $itemCodes->firstWhere('carrier_type', 'NFC');
                $tagVisible = $tag !== null && (empty($tag->nfc_warehouse_id) || $tag->nfc_warehouse_id === $scope['warehouse_id']);
                $unique = strtoupper(trim((string) $item->serial_number));
                if ($unique === '') {
                    foreach ($itemCodes as $code) {
                        if (in_array($code->carrier_type, ['QR', 'BARCODE'], true) && ! app(\Modules\Warehouse\Services\ProductQrPayloadParser::class)->looksComposite($code->code_value)) {
                            $unique = strtoupper(trim($code->code_value));
                            if ($unique !== '') {
                                break;
                            }
                        }
                    }
                }
                $prefix = strtoupper((string) config('warehouse.product_qr.composite_prefix', 'HN'));
                $version = (int) (config('warehouse.product_qr.supported_versions', [1])[0] ?? 1);

                return ['item_id' => $item->item_id, 'serial_number' => $item->serial_number, 'item_status' => $item->item_status,
                    'item_condition' => $item->item_condition, 'container_id' => $item->container_id,
                    'sku' => ['sku_id' => $item->sku_id, 'sku_code' => $item->sku_code, 'sku_name' => $item->sku_name, 'unit' => $item->unit],
                    'nfc_status' => $tag === null ? 'UNASSIGNED' : 'ASSIGNED', 'nfc' => $tagVisible ? $this->project($tag) : null, 'nfc_details_available' => $tagVisible,
                    'nfc_payload' => $unique === '' ? null : $prefix.$version.'|SKU='.strtoupper($item->sku_code).'|ITEM='.$unique];
            })->all();

            return $this->reply($data, $scope, ['current_page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)), 'count_unit' => 'ITEM', 'snapshot' => $snapshot,
                'tab_totals' => ['ALL' => $all, 'ASSIGNED' => $assigned, 'UNASSIGNED' => $all - $assigned]]);
        });
    }

    public function prepare(Request $request, string $itemId): JsonResponse
    {
        $scope = $this->context($request);
        $this->writePermission($scope);
        $this->requireItem($scope, $itemId);
        if ($request->boolean('replace_existing')) {
            ApiError::abort(422, 'NFC_REPLACE_DISABLED', 'P07 không cho phép thay thế chip đang liên kết.');
        }

        return $this->guard($itemId, function () use ($request, $itemId, $scope): JsonResponse {
            $held = Cache::get(self::HOLD.$itemId);
            if (is_array($held) && ! P07NfcPolicy::owns($held, $scope)) {
                ApiError::abort(409, 'NFC_WRITE_RESERVED_BY_OTHER', 'Hiện vật đang được phiên khác giữ chỗ.');
            }
            if (DB::table('physical_codes')->where('item_id', $itemId)->where('carrier_type', 'NFC')->where('status', 'ACTIVE')->exists()) {
                ApiError::abort(409, 'ITEM_ALREADY_HAS_NFC', 'Hiện vật đã có chip hoạt động; P07 không cho phép thay thế.');
            }
            $response = parent::prepare($request, $itemId);
            $body = $response->getData(true);
            $held = Cache::get(self::HOLD.$itemId);
            if (! is_array($held)) {
                ApiError::abort(409, 'NFC_RESERVATION_EXPIRED', 'Không còn giữ chỗ; chưa được ghi chip.');
            }
            $held['auth_session_id'] = $scope['auth_session_id'];
            $held['warehouse_id'] = $scope['warehouse_id'];
            $held['nfc_payload'] = $body['data']['nfc_payload'];
            Cache::put(self::HOLD.$itemId, $held, max(1, strtotime($held['expires_at']) - time()));
            $body['data']['warehouse_id'] = $scope['warehouse_id'];
            $body['data']['auth_session_id'] = $scope['auth_session_id'];
            $body['data']['replace_existing_allowed'] = false;

            return $response->setData($body)->header('Cache-Control', 'private, no-store');
        });
    }

    public function confirm(Request $request, string $itemId): JsonResponse
    {
        $scope = $this->context($request);
        $this->writePermission($scope);
        $this->requireItem($scope, $itemId);
        $input = $request->validate([
            'hardware_uid' => ['required', 'string', 'max:150', 'regex:/\S/u'],
            'written_payload' => ['required', 'string', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
            'reservation_token' => ['required', 'string', 'max:100'], 'replace_existing' => ['nullable', 'boolean'],
        ]);
        if (! empty($input['replace_existing'])) {
            ApiError::abort(422, 'NFC_REPLACE_DISABLED', 'P07 không cho phép thay thế chip đang liên kết.');
        }
        $key = IdempotencyKeyGuard::require($request);
        if (($replay = $this->replayCommitted($request, $scope, $itemId, $key)) !== null) {
            return $replay;
        }

        return $this->guard('idem:'.hash('sha256', (string) $scope['actor']->user_id.'|'.$key), fn (): JsonResponse =>
            $this->guard($itemId, function () use ($request, $itemId, $scope, $input, $key): JsonResponse {
            // A request may have completed while this caller waited for the lock.
            if (($replay = $this->replayCommitted($request, $scope, $itemId, $key)) !== null) {
                return $replay;
            }
            $held = Cache::get(self::HOLD.$itemId);
            $error = P07NfcPolicy::reservationError(is_array($held) ? $held : null, $scope, $input['reservation_token'], time());
            if ($error !== null) {
                ApiError::abort(409, $error, 'Giữ chỗ hết hạn hoặc không thuộc phiên/kho hiện tại; đối chiếu trước khi thao tác tiếp.');
            }
            if (! isset($held['nfc_payload']) || ! hash_equals($held['nfc_payload'], $input['written_payload'])) {
                ApiError::abort(422, 'NFC_PAYLOAD_ITEM_MISMATCH', 'Nội dung đọc lại không khớp chính xác payload đã chuẩn bị.');
            }
            try {
                return DB::transaction(function () use ($request, $itemId, $scope, $input, $key): JsonResponse {
                    // Serialize different keys/UIDs competing for one item, even if no
                    // active physical_code row exists yet (locking only tags is not enough).
                    DB::table('app_user')->where('user_id', $scope['actor']->user_id)->lockForUpdate()->first();
                    $fresh = $this->context($request);
                    $this->writePermission($fresh);
                    if ($fresh['warehouse_id'] !== $scope['warehouse_id'] || $fresh['auth_session_id'] !== $scope['auth_session_id']) {
                        ApiError::abort(409, 'NFC_RESERVATION_CONTEXT_MISMATCH', 'Phiên hoặc kho đã thay đổi trước khi xác nhận.');
                    }
                    DB::table('item_instances')->where('item_id', $itemId)->lockForUpdate()->first();
                    $item = $this->requireItem($fresh, $itemId);
                    $live = Cache::get(self::HOLD.$itemId);
                    $error = P07NfcPolicy::reservationError(is_array($live) ? $live : null, $fresh, $input['reservation_token'], time());
                    if ($error !== null) {
                        ApiError::abort(409, $error, 'Giữ chỗ không còn hiệu lực sau khi chờ khóa.');
                    }
                    if (in_array($item->item_status, ['LOST', 'ADJUSTED_OUT'], true)) {
                        ApiError::abort(422, 'ITEM_STATUS_NOT_TAGGABLE', 'Trạng thái hiện vật không cho phép gán chip.');
                    }
                    $uid = strtoupper(trim($input['hardware_uid']));
                    $tag = DB::table('physical_codes')->where('hardware_uid', $uid)->first();
                    if ($tag !== null && ((string) ($tag->item_id ?? $tag->assigned_object_id ?? '') !== $itemId
                        || (! empty($tag->nfc_warehouse_id) && $tag->nfc_warehouse_id !== $scope['warehouse_id']))) {
                        ApiError::abort(409, 'NFC_TAG_ALREADY_ASSIGNED', 'Chip đã được đăng ký cho đối tượng khác.');
                    }
                    if ($tag !== null && $tag->carrier_type !== 'NFC') {
                        ApiError::abort(409, 'NFC_UID_CONFLICT', 'UID đã được đăng ký cho loại mã vật lý khác.');
                    }
                    if (DB::table('physical_codes')->where('item_id', $itemId)->where('carrier_type', 'NFC')->where('status', 'ACTIVE')->where('hardware_uid', '!=', $uid)->exists()) {
                        ApiError::abort(409, 'ITEM_ALREADY_HAS_NFC', 'Hiện vật đã có chip hoạt động; không được tự thay thế.');
                    }
                    $response = parent::confirm($request, $itemId);
                    if ($response->getStatusCode() >= 400) {
                        return $response;
                    }
                    $body = $response->getData(true);
                    $tagId = $body['data']['nfc']['physical_code_id'];
                    if ($response->getStatusCode() === 201) {
                        DB::table('physical_codes')->where('physical_code_id', $tagId)->update([
                            'nfc_warehouse_id' => $scope['warehouse_id'], 'nfc_auth_session_id' => $scope['auth_session_id'],
                        ]);
                    }
                    $body['data']['nfc'] = $this->project($this->requireTag($scope, $tagId));
                    $body['data']['confirmation'] = ['state' => 'SUCCEEDED', 'item_id' => $itemId, 'physical_code_id' => $tagId,
                        'actor_id' => (string) $scope['actor']->user_id, 'auth_session_id' => $scope['auth_session_id'],
                        'warehouse_id' => $scope['warehouse_id'], 'request_id' => $body['meta']['request_id'] ?? null];
                    $body['data']['summary'] = $this->totals($this->tags($scope));
                    $body['data']['summary_scope'] = 'WAREHOUSE_VISIBLE_NFC_TAGS';
                    // Persist enrichment in the SAME transaction as the canonical writer.
                    // No audit event or cache entry is used as proof of commit.
                    $this->receiptQuery($scope, $key)->where('status', 'done')->update([
                        'response_body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);

                    return $response->setData($body)->header('Cache-Control', 'private, no-store');
                }, 3);
            } catch (Throwable $exception) {
                // The legacy writer releases its cache hold before the outer commit.
                // Restore only our still-valid hold on rollback; never extend its TTL.
                $ttl = strtotime($held['expires_at']) - time();
                if ($ttl > 0) {
                    Cache::add(self::HOLD.$itemId, $held, $ttl);
                }
                if ($exception instanceof \Illuminate\Database\QueryException && ($exception->errorInfo[0] ?? null) === '23505') {
                    ApiError::abort(409, 'NFC_UID_OR_CODE_CONFLICT', 'UID hoặc mã chip vừa được đăng ký bởi yêu cầu khác; đọc lại và đối chiếu trước khi tiếp tục.');
                }
                throw $exception;
            }
        }));
    }

    private function receiptQuery(array $scope, string $key): Builder
    {
        return DB::table(IdempotencyStore::TABLE)->where('idem_key', $key)->where('operation', self::OPERATION)
            ->where('user_id', (string) $scope['actor']->user_id);
    }

    private function receiptBody(object $row, array $scope, string $itemId): array
    {
        $body = is_string($row->response_body) ? json_decode($row->response_body, true, 512, JSON_THROW_ON_ERROR) : (array) $row->response_body;
        $confirmation = $body['data']['confirmation'] ?? [];
        if (($confirmation['item_id'] ?? null) !== $itemId || ($confirmation['warehouse_id'] ?? null) !== $scope['warehouse_id']
            || ($confirmation['auth_session_id'] ?? null) !== $scope['auth_session_id']) {
            ApiError::abort(409, 'NFC_CONFIRMATION_CONTEXT_MISMATCH', 'Kết quả không thuộc phiên/kho/hiện vật hiện tại hoặc là receipt cũ chưa đủ dữ liệu đối chiếu.');
        }

        return $body;
    }

    private function replayCommitted(Request $request, array $scope, string $itemId, string $key): ?JsonResponse
    {
        $row = $this->receiptQuery($scope, $key)->first();
        if ($row === null || $row->status !== 'done') {
            return null;
        }
        $this->receiptBody($row, $scope, $itemId);
        [$code, $body] = IdempotencyStore::handle($key, self::OPERATION, (string) $scope['actor']->user_id,
            IdempotencyStore::requestHash($request), function (): array {
                ApiError::abort(409, 'NFC_CONFIRMATION_UNKNOWN', 'Receipt không còn khả dụng; không tự tạo yêu cầu mới.');
            });

        return response()->json($body, $code)->header('Idempotency-Replayed', $code < 400 ? 'true' : 'false')->header('Cache-Control', 'private, no-store');
    }

    public function confirmation(Request $request, string $itemId): JsonResponse
    {
        $scope = $this->context($request);
        $this->writePermission($scope);
        $this->requireItem($scope, $itemId);
        $key = IdempotencyKeyGuard::require($request);
        $row = $this->receiptQuery($scope, $key)->first();
        if ($row !== null && $row->status === 'done') {
            $body = $this->receiptBody($row, $scope, $itemId);

            return $this->reply(['state' => 'SUCCEEDED', 'http_status' => (int) $row->response_code, 'response' => $body,
                'may_create_new_request' => false], $scope);
        }

        return $this->reply(['state' => $row !== null && $row->status === 'processing' ? 'PROCESSING' : 'UNKNOWN',
            'response' => null, 'may_create_new_request' => false, 'retry_policy' => 'SAME_KEY_SAME_PAYLOAD_ONLY_AFTER_RECONCILIATION'], $scope);
    }

    public function release(Request $request, string $itemId): JsonResponse
    {
        $scope = $this->context($request);
        $this->writePermission($scope);
        $this->requireItem($scope, $itemId);
        $input = $request->validate(['reservation_token' => ['required', 'string', 'max:100']]);

        return $this->guard($itemId, function () use ($scope, $itemId, $input): JsonResponse {
            $held = Cache::get(self::HOLD.$itemId);
            if (! is_array($held)) {
                return $this->reply(['released' => false, 'reservation_state' => 'ABSENT_OR_EXPIRED', 'stock_effect' => 'NONE'], $scope);
            }
            $error = P07NfcPolicy::reservationError($held, $scope, $input['reservation_token'], time());
            if ($error !== null) {
                ApiError::abort(409, $error, 'Không được giải phóng giữ chỗ của phiên khác.');
            }
            Cache::forget(self::HOLD.$itemId);

            return $this->reply(['released' => true, 'stock_effect' => 'NONE', 'mapping_removed' => false], $scope);
        });
    }

    public function resolveCode(Request $request): JsonResponse
    {
        $scope = $this->context($request);
        $response = parent::resolveCode($request);
        $id = $response->getData(true)['data']['item']['item_id'] ?? null;
        if ($id !== null) {
            $this->requireItem($scope, $id);
        }

        return $response->header('Cache-Control', 'private, no-store');
    }

    public function resolveTag(Request $request): JsonResponse
    {
        $scope = $this->context($request);
        $response = parent::resolveTag($request);
        $body = $response->getData(true);
        $id = $body['data']['item']['item_id'] ?? null;
        if ($id !== null) {
            $this->requireItem($scope, $id);
        }
        $tagId = $body['data']['nfc']['physical_code_id'] ?? null;
        if ($tagId !== null) {
            $this->requireTag($scope, $tagId);
        }
        // P07 is not an authorization shortcut into outbound/warranty read models.
        $body['data']['last_issue'] = null;
        $body['data']['warranty'] = null;
        $body['data']['related_details_policy'] = 'USE_SEPARATELY_AUTHORIZED_OWNER_ENDPOINTS';

        return $response->setData($body)->header('Cache-Control', 'private, no-store');
    }

    public function deactivate(Request $request, string $physicalCodeId): JsonResponse
    {
        $scope = $this->context($request);
        $this->writePermission($scope);
        $tag = $this->requireTag($scope, $physicalCodeId);

        return $this->guard((string) $tag->item_id, fn (): JsonResponse => parent::deactivate($request, $physicalCodeId));
    }

    public function tagEvents(Request $request, string $physicalCodeId): JsonResponse
    {
        $scope = $this->context($request);
        $this->requireTag($scope, $physicalCodeId);

        return parent::tagEvents($request, $physicalCodeId)->header('Cache-Control', 'private, no-store');
    }

    private function guard(string $itemId, callable $callback): JsonResponse
    {
        try {
            return Cache::lock('wms:p07:nfc-guard:'.$itemId, 30)->block(3, $callback);
        } catch (LockTimeoutException) {
            ApiError::abort(409, 'NFC_OPERATION_IN_PROGRESS', 'Một thao tác NFC đang xử lý; đối chiếu kết quả trước khi thử lại.');
        }
    }

    private function reply(mixed $data, array $scope, array $meta = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => $meta + [
            'contract' => 'mini-app-p07-v1', 'warehouse_id' => $scope['warehouse_id'],
            'warehouse_scope' => 'EXPLICIT_NFC_ASSIGNMENT_OR_LEGACY_ITEM_LEDGER_HISTORY',
            'request_id' => request()->attributes->get('request_id'), 'timestamp' => now()->toIso8601String(),
        ]])->header('Cache-Control', 'private, no-store');
    }
}
