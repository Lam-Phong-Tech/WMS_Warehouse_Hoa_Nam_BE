<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Support\P07NfcPolicy;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fence a proven pre-writer SKU rejection. Never infer an old outcome from HTTP,
 * an absent receipt, current mapping, SKU activation, or a physical NFC readback.
 * Existing success writer/receipt/permission middleware remains authoritative.
 */
final class P07NfcRecoveryGuard
{
    public const TABLE = 'p07_nfc_rejections';

    public const CONTRACT = 'nfc-confirm-recovery-v1';

    private const HOLD = 'wms:nfc:write-reservation:';

    public function handle(Request $request, Closure $next): Response
    {
        $mode = $request->header('X-NFC-Recovery-Contract');
        if ($mode !== null && $mode !== self::CONTRACT) {
            ApiError::abort(422, 'NFC_RECOVERY_CONTRACT_UNSUPPORTED', 'Contract phục hồi NFC chưa được hỗ trợ.');
        }
        $id = (string) $request->route('itemId');
        $scope = $this->scope($request, $id);
        if (! Schema::hasTable(self::TABLE)) {
            ApiError::abort(503, 'NFC_RECOVERY_MIGRATION_REQUIRED', 'Chưa triển khai schema biên nhận từ chối NFC.');
        }
        if ($request->isMethod('GET')) {
            $key = IdempotencyKeyGuard::require($request);
            $row = $this->receipt($scope, $key);
            if ($row === null) {
                // Includes the historical incident: no retrospective synthetic receipt.
                return $next($request);
            }
            $this->assertScope($row, $scope, $id);
            if ($mode !== self::CONTRACT) {
                return $this->json(['state' => 'UNKNOWN', 'response' => null, 'may_create_new_request' => false,
                    'retry_policy' => 'CHECK_ONLY', 'recovery_contract_required' => self::CONTRACT]);
            }
            $recovery = $this->recovery($row, $scope);

            return $this->json(['state' => 'REJECTED', 'http_status' => 422,
                'response' => $this->rejection($row)->getData(true), 'receipt' => $this->projection($row),
                'may_create_new_request' => $recovery['prepare_allowed'] && $row->recovery_prepared_at === null, 'retry_policy' => 'NEW_PREPARE_ONLY',
                'recovery' => $recovery]);
        }

        // Same item lock for prepare, confirm and release. Different keys cannot
        // consume a rejected hold while another recovery is replacing that hold.
        try {
            return Cache::lock('wms:nfc:recovery:'.$id, 60)->block(5, function () use ($request, $next, $id): Response {
                if ($request->isMethod('PATCH')) {
                    // A recovery attempt fence commits BEFORE invoking the writer. A
                    // failed/ambiguous outer commit cannot re-open a consumed permit.
                    $this->bindRecoveryAttempt($request, $this->scope($request, $id), $id);
                }

                return DB::transaction(function () use ($request, $next, $id): Response {
                    DB::table('app_user')->where('user_id', $request->user()->user_id)->lockForUpdate()->first();
                    $scope = $this->scope($request, $id);
                    $item = DB::table('item_instances')->where('item_id', $id)->lockForUpdate()->first();
                    $sku = DB::table('sku')->where('sku_id', $item->sku_id)->lockForUpdate()->first();
                    if ($request->isMethod('PATCH')) {
                        return $this->confirm($request, $next, $scope, $item, $sku);
                    }
                    if (str_ends_with($request->path(), '/release')) {
                        // Release is not proof of failed confirm and never erases a receipt.
                        return $next($request);
                    }

                    return $this->prepare($request, $next, $scope, $item, $sku);
                });
            });
        } catch (LockTimeoutException) {
            ApiError::abort(409, 'NFC_RECOVERY_BUSY', 'Đang xử lý NFC cho hiện vật; đối chiếu trước khi tiếp tục.');
        }
    }

    private function prepare(Request $request, Closure $next, array $scope, object $item, ?object $sku): Response
    {
        if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE') {
            // No reservation is acquired or extended for inactive/unknown SKU.
            ApiError::abort(422, $sku === null ? 'SKU_NOT_FOUND' : 'SKU_INACTIVE', 'SKU phải ACTIVE trước khi giữ chỗ ghi chip.');
        }
        $key = $request->header('X-NFC-Recovery-Key');
        $pending = DB::table(self::TABLE)->where('item_id', $item->item_id)->whereNull('recovered_at')->exists();
        if ($key === null && $pending) {
            ApiError::abort(409, 'NFC_RECOVERY_REQUIRED', 'Đối chiếu lượt bị từ chối rồi prepare phục hồi có định danh.');
        }
        $row = null;
        if ($key !== null) {
            if ($request->header('X-NFC-Recovery-Contract') !== self::CONTRACT) {
                ApiError::abort(422, 'NFC_RECOVERY_CONTRACT_REQUIRED', 'Cần contract phục hồi trước khi bắt đầu lượt mới.');
            }
            $row = $this->receipt($scope, $key);
            if ($row === null) {
                ApiError::abort(409, 'NFC_RECOVERY_NOT_PROVEN', 'Không có biên nhận từ chối xác định; không tự chuyển UNKNOWN thành thất bại.');
            }
            $this->assertScope($row, $scope, $item->item_id);
            if (! $this->recovery($row, $scope)['prepare_allowed']) {
                ApiError::abort(409, 'NFC_RECOVERY_BLOCKED', 'Chưa đủ điều kiện phục hồi; đọc lại receipt, phiên, kho và mapping.');
            }
            $held = Cache::get(self::HOLD.$item->item_id);
            if (is_array($held) && $this->digest((string) ($held['token'] ?? '')) === $row->reservation_hash) {
                // Explicit recovery consumes ONLY this actor/session's rejected hold.
                // No other hold is released and no chip/mapping is deleted.
                Cache::forget(self::HOLD.$item->item_id);
            }
        }
        $response = $next($request);
        if ($response instanceof JsonResponse && $response->isSuccessful() && $row !== null) {
            $held = Cache::get(self::HOLD.$item->item_id);
            if (is_array($held) && P07NfcPolicy::owns($held, $scope)) {
                if ($row->recovery_prepared_at === null) {
                    DB::table(self::TABLE)->where('id', $row->id)->update([
                        'recovery_prepared_at' => now()->utc(),
                        'recovery_reservation_hash' => $this->digest((string) $held['token']),
                    ]);
                }
                $held['p07_recovery_receipt'] = $row->receipt_id;
                $ttl = strtotime((string) $held['expires_at']) - time();
                if ($ttl > 0) {
                    Cache::put(self::HOLD.$item->item_id, $held, $ttl);
                }
            }
            $body = $response->getData(true);
            $body['data']['recovery'] = ['receipt_id' => $row->receipt_id, 'blank_new_tag_required' => true,
                'old_tag_reuse_allowed' => false, 'new_confirm_key_required' => true];
            $response->setData($body);
        }

        return $response;
    }

    private function confirm(Request $request, Closure $next, array $scope, object $item, ?object $sku): Response
    {
        $key = IdempotencyKeyGuard::require($request);
        $row = $this->receipt($scope, $key);
        $hash = $this->requestHash($request);
        if ($row !== null) {
            $this->assertScope($row, $scope, $item->item_id);
            if (! hash_equals($row->request_hash, $hash)) {
                ApiError::abort(409, 'IDEMPOTENCY_CONFLICT', 'Key cũ đã gắn với nội dung khác; không ghi lại chip.');
            }

            return $this->rejection($row)->header('Idempotency-Replayed', 'true');
        }
        $legacy = DB::table(IdempotencyStore::TABLE)->where('operation', 'mini_app_nfc_confirm')
            ->where('user_id', $scope['actor']->user_id)->where('idem_key', $key)->first();
        if ($legacy !== null) {
            if ($legacy->status !== 'done') {
                ApiError::abort(409, 'NFC_CONFIRMATION_UNKNOWN', 'Lượt cũ chưa rõ kết quả; không tiếp quản lease để ghi lại.');
            }

            // A committed success must replay even if SKU was later deactivated.
            return $next($request);
        }
        $input = $request->validate(['hardware_uid' => ['required', 'string', 'max:150', 'regex:/\S/u'],
            'written_payload' => ['required', 'string', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
            'reservation_token' => ['required', 'string', 'max:100'], 'replace_existing' => ['nullable', 'boolean']]);
        if (! empty($input['replace_existing'])) {
            ApiError::abort(422, 'NFC_REPLACE_DISABLED', 'P07 không cho phép thay thế chip đang liên kết.');
        }
        $uid = strtoupper(trim($input['hardware_uid']));
        $tokenHash = $this->digest($input['reservation_token']);
        if (DB::table(self::TABLE)->where('reservation_hash', $tokenHash)->exists()) {
            ApiError::abort(409, 'NFC_REJECTED_RESERVATION', 'Reservation thuộc lượt đã bị từ chối; phải prepare phục hồi.');
        }
        if (DB::table(self::TABLE)->where('hardware_uid', $uid)->exists()) {
            ApiError::abort(409, 'NFC_REJECTED_TAG_REUSE', 'Chip thuộc lượt đã ghi nhưng bị từ chối; dùng thẻ trắng mới sau khi phục hồi.');
        }
        $held = Cache::get(self::HOLD.$item->item_id);
        $error = P07NfcPolicy::reservationError(is_array($held) ? $held : null, $scope, $input['reservation_token'], time());
        if ($error !== null) {
            ApiError::abort(409, $error, 'Giữ chỗ không còn hợp lệ; đối chiếu trước khi tiếp tục.');
        }
        if (! isset($held['nfc_payload']) || ! hash_equals($held['nfc_payload'], $input['written_payload'])) {
            ApiError::abort(422, 'NFC_PAYLOAD_ITEM_MISMATCH', 'Nội dung đọc lại không khớp payload đã chuẩn bị.');
        }
        $pending = DB::table(self::TABLE)->where('item_id', $item->item_id)->whereNull('recovered_at')->exists();
        if ($pending && empty($held['p07_recovery_receipt'])) {
            ApiError::abort(409, 'NFC_RECOVERY_REQUIRED', 'Cần prepare phục hồi trước khi xác nhận lượt mới.');
        }
        if ($sku !== null && strtoupper((string) $sku->status) !== 'ACTIVE') {
            // Explicit pre-writer branch, not a catch-all for HTTP 422 or DB errors.
            // The canonical writer is NEVER invoked on this branch. Receipt and
            // permanent key/token/UID fences commit atomically in this transaction.
            $trace = (string) $request->attributes->get('request_id', '');
            $data = ['id' => $this->receiptId($scope, $key), 'receipt_id' => (string) Str::uuid(),
                'actor_id' => $scope['actor']->user_id, 'auth_session_id' => $scope['auth_session_id'],
                'warehouse_id' => $scope['warehouse_id'], 'item_id' => $item->item_id, 'sku_id' => $item->sku_id,
                'key_hash' => $this->digest($key), 'request_hash' => $hash, 'hardware_uid' => $uid,
                'reservation_hash' => $tokenHash, 'payload_sha256' => hash('sha256', $input['written_payload']),
                'original_request_id' => Str::isUuid($trace) ? strtolower($trace) : null,
                'reason_code' => 'SKU_INACTIVE', 'evidence_type' => 'SERVER_PREWRITE_SKU_CHECK',
                'rejected_at' => now()->utc(), 'recovered_at' => null, 'recovered_by_code_id' => null,
                'recovery_prepared_at' => null, 'recovery_reservation_hash' => null, 'recovery_key_hash' => null,
                'recovery_request_hash' => null, 'recovery_state' => null];
            DB::table(self::TABLE)->insert($data);
            if (isset($held['p07_recovery_receipt'])) {
                DB::table(self::TABLE)->where('receipt_id', $held['p07_recovery_receipt'])
                    ->where('recovery_key_hash', $this->digest($key))->update(['recovery_state' => 'REJECTED']);
            }

            return $this->rejection((object) $data)->header('Idempotency-Replayed', 'false');
        }
        // A read of the SKU row is locked until the existing atomic writer commits.
        // Any unexpected exception/5xx is still UNKNOWN; never mint a rejection.
        $response = $next($request);
        if ($response instanceof JsonResponse && $response->isSuccessful() && isset($held['p07_recovery_receipt'])) {
            $body = $response->getData(true);
            $tagId = $body['data']['nfc']['physical_code_id'] ?? null;
            if (($body['data']['confirmation']['state'] ?? null) === 'SUCCEEDED' && Str::isUuid((string) $tagId)) {
                DB::table(self::TABLE)->where('item_id', $item->item_id)->where('actor_id', $scope['actor']->user_id)
                    ->where('auth_session_id', $scope['auth_session_id'])->where('warehouse_id', $scope['warehouse_id'])
                    ->whereNull('recovered_at')->update(['recovered_at' => now()->utc(), 'recovered_by_code_id' => $tagId, 'recovery_state' => 'SUCCEEDED']);
            }
        }

        return $response;
    }

    /** Bind at most one new confirm identity to a prepared recovery, durably. */
    private function bindRecoveryAttempt(Request $request, array $scope, string $itemId): void
    {
        $key = IdempotencyKeyGuard::require($request);
        if ($this->receipt($scope, $key) !== null) {
            return; // Existing definitive rejection is checked/replayed in confirm().
        }
        $legacyDone = DB::table(IdempotencyStore::TABLE)->where('operation', 'mini_app_nfc_confirm')
            ->where('user_id', $scope['actor']->user_id)->where('idem_key', $key)->where('status', 'done')->exists();
        if ($legacyDone) {
            return; // Existing canonical success still verifies request fingerprint.
        }
        $bound = DB::table(self::TABLE)->where('actor_id', $scope['actor']->user_id)
            ->where('recovery_key_hash', $this->digest($key))->first();
        if ($bound !== null) {
            $this->assertScope($bound, $scope, $itemId);
            if (! hash_equals($bound->recovery_request_hash, $this->requestHash($request))) {
                ApiError::abort(409, 'IDEMPOTENCY_CONFLICT', 'Nội dung lượt phục hồi không khớp request gốc.');
            }
            ApiError::abort(409, 'NFC_CONFIRMATION_UNKNOWN', 'Lượt phục hồi chưa có receipt cuối; chỉ đối chiếu, không takeover.');
        }
        $held = Cache::get(self::HOLD.$itemId);
        if (! is_array($held) || empty($held['p07_recovery_receipt'])) {
            return;
        }
        $input = $request->validate(['hardware_uid' => ['required', 'string', 'max:150', 'regex:/\S/u'],
            'written_payload' => ['required', 'string', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)],
            'reservation_token' => ['required', 'string', 'max:100'], 'replace_existing' => ['nullable', 'boolean']]);
        if (! empty($input['replace_existing'])) {
            ApiError::abort(422, 'NFC_REPLACE_DISABLED', 'P07 không cho phép thay thế chip đang liên kết.');
        }
        if (! isset($held['nfc_payload']) || ! hash_equals($held['nfc_payload'], $input['written_payload'])) {
            ApiError::abort(422, 'NFC_PAYLOAD_ITEM_MISMATCH', 'Nội dung đọc lại không khớp payload đã chuẩn bị.');
        }
        if (DB::table(self::TABLE)->where('hardware_uid', strtoupper(trim($input['hardware_uid'])))->exists()) {
            ApiError::abort(409, 'NFC_REJECTED_TAG_REUSE', 'Không tái dùng chip của lượt đã bị từ chối.');
        }
        $token = $input['reservation_token'];
        $error = P07NfcPolicy::reservationError($held, $scope, $token, time());
        if ($error !== null) {
            ApiError::abort(409, $error, 'Không có reservation phục hồi hợp lệ.');
        }
        // This is a standalone transaction, NOT nested under the mapping writer.
        DB::transaction(function () use ($request, $scope, $itemId, $held, $key, $token): void {
            $row = DB::table(self::TABLE)->where('receipt_id', $held['p07_recovery_receipt'])->lockForUpdate()->first();
            if ($row === null) {
                ApiError::abort(409, 'NFC_RECOVERY_NOT_PROVEN', 'Không có permit phục hồi đã lưu.');
            }
            $this->assertScope($row, $scope, $itemId);
            if ($row->recovery_key_hash !== null || $row->recovered_at !== null
                || $row->recovery_reservation_hash !== $this->digest($token)) {
                ApiError::abort(409, 'NFC_RECOVERY_ATTEMPT_LOCKED', 'Permit đã có lượt xác nhận; không tạo key/chip thay thế khi chưa rõ kết quả.');
            }
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'recovery_key_hash' => $this->digest($key), 'recovery_request_hash' => $this->requestHash($request),
                'recovery_state' => 'PROCESSING',
            ]);
        });
    }

    private function scope(Request $request, string $id): array
    {
        $request->validate(['warehouse_id' => ['sometimes', 'uuid']]);
        $user = $request->user();
        $actor = $user instanceof User ? User::whereKey($user->user_id)->first() : null;
        if ($actor === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }
        if ($actor->status !== 'ACTIVE' || ! $actor->hasPermission('physical_code.assign_rfid')) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không có quyền gán NFC.');
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        if (($context['warehouse_context']['status'] ?? null) !== 'READY' || empty($context['active_warehouse_id'])) {
            ApiError::abort(409, $context['warehouse_context']['error_code'] ?? 'WAREHOUSE_CONTEXT_REQUIRED', 'Xác nhận lại kho làm việc.');
        }
        $warehouse = $context['active_warehouse_id'];
        if ($request->filled('warehouse_id') && strtolower((string) $request->input('warehouse_id')) !== $warehouse) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho không khớp phiên.');
        }
        if (! $actor->canAccessWarehouse($warehouse)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho ngoài phạm vi được cấp.');
        }
        if (! DB::table('warehouses')->where('id', $warehouse)->where('status', 'ACTIVE')->exists()) {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho không hoạt động.');
        }
        if (! Str::isUuid($id) || ! DB::table('item_instances')->where('item_id', $id)->exists()
            || ! DB::table('stock_movements')->where('item_id', $id)->where('warehouse_id', $warehouse)->exists()) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy hiện vật trong kho.');
        }

        return ['actor' => $actor, 'warehouse_id' => $warehouse, 'auth_session_id' => $session];
    }

    private function receipt(array $scope, string $key): ?object
    {
        return DB::table(self::TABLE)->where('id', $this->receiptId($scope, $key))->first();
    }

    private function receiptId(array $scope, string $key): string
    {
        return hash('sha256', $scope['actor']->user_id.'|'.$this->digest(trim($key)));
    }

    private function digest(string $value): string
    {
        return hash('sha256', $value);
    }

    private function requestHash(Request $request): string
    {
        // Same canonical digest as owner idempotency; ignore only its legacy MD5 suffix.
        return explode('|', IdempotencyStore::requestHash($request), 2)[0];
    }

    private function assertScope(object $row, array $scope, string $id): void
    {
        if ($row->item_id !== $id || $row->auth_session_id !== $scope['auth_session_id'] || $row->warehouse_id !== $scope['warehouse_id']) {
            ApiError::abort(409, 'NFC_CONFIRMATION_CONTEXT_MISMATCH', 'Receipt không thuộc hiện vật, phiên và kho này.');
        }
    }

    private function recovery(object $row, array $scope): array
    {
        $item = DB::table('item_instances')->where('item_id', $row->item_id)->first();
        $sku = $item === null ? null : DB::table('sku')->where('sku_id', $item->sku_id)->first();
        $held = Cache::get(self::HOLD.$row->item_id);
        $holdAllowed = $row->recovery_prepared_at === null
            ? (! is_array($held) || (P07NfcPolicy::owns($held, $scope)
                && $this->digest((string) ($held['token'] ?? '')) === $row->reservation_hash))
            : (is_array($held) && P07NfcPolicy::owns($held, $scope)
                && strtotime((string) ($held['expires_at'] ?? '')) > time()
                && ($held['p07_recovery_receipt'] ?? null) === $row->receipt_id
                && $this->digest((string) ($held['token'] ?? '')) === $row->recovery_reservation_hash);
        $pendingOwner = DB::table(IdempotencyStore::TABLE)->where('operation', 'mini_app_nfc_confirm')
            ->where('user_id', $scope['actor']->user_id)->where('status', '!=', 'done')->exists();
        $blocked = $row->recovered_at !== null || $row->recovery_key_hash !== null || $item === null || $sku === null || $item->sku_id !== $row->sku_id || strtoupper((string) $sku->status) !== 'ACTIVE'
            || in_array($item->item_status, ['LOST', 'ADJUSTED_OUT'], true) || ! $holdAllowed || $pendingOwner
            || DB::table('physical_codes')->where('hardware_uid', $row->hardware_uid)->exists()
            || DB::table('physical_codes')->where('item_id', $row->item_id)->where('carrier_type', 'NFC')->where('status', 'ACTIVE')->exists()
            || DB::table(self::TABLE)->where('item_id', $row->item_id)->whereNull('recovered_at')
                ->where(function ($q) use ($scope): void {
                    $q->where('actor_id', '!=', $scope['actor']->user_id)->orWhere('auth_session_id', '!=', $scope['auth_session_id'])
                        ->orWhere('warehouse_id', '!=', $scope['warehouse_id']);
                })->exists();

        return ['prepare_allowed' => ! $blocked, 'next_action' => $blocked ? 'BLOCKED' : 'PREPARE_WITH_RECOVERY_KEY',
            'blank_new_tag_required' => true, 'old_tag_reuse_allowed' => false, 'same_key_retry_allowed' => false,
            'chip_write_rolled_back' => false, 'reservation_released_by_check' => false,
            'completed' => $row->recovered_at !== null, 'permit_consumed' => $row->recovery_prepared_at !== null,
            'confirm_dispatched' => $row->recovery_key_hash !== null];
    }

    private function projection(object $row): array
    {
        return ['receipt_id' => $row->receipt_id, 'state' => 'REJECTED', 'operation' => 'CONFIRM',
            'item_id' => $row->item_id, 'sku_id' => $row->sku_id, 'hardware_uid' => $row->hardware_uid,
            'actor_id' => $row->actor_id, 'auth_session_id' => $row->auth_session_id, 'warehouse_id' => $row->warehouse_id,
            'original_request_id' => $row->original_request_id, 'request_key_sha256' => $row->key_hash, 'payload_sha256' => $row->payload_sha256,
            'reason_code' => $row->reason_code, 'evidence_type' => $row->evidence_type,
            'rejected_at' => (string) $row->rejected_at, 'mapping_created_by_request' => false, 'stock_effect' => 'NONE'];
    }

    private function rejection(object $row): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $row->reason_code,
            'error' => ['code' => $row->reason_code, 'details' => []], 'errors' => [],
            'message' => 'Lượt xác nhận bị từ chối trước khi ghi mapping vì SKU không ACTIVE. Đối chiếu để phục hồi; không gửi lại key cũ.',
            'data' => ['confirmation' => $this->projection($row)],
            'meta' => ['contract' => 'mini-app-p07-v1', 'recovery_contract' => self::CONTRACT]], 422)
            ->header('Cache-Control', 'private, no-store');
    }

    private function json(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data,
            'meta' => ['contract' => 'mini-app-p07-v1', 'recovery_contract' => self::CONTRACT]])
            ->header('Cache-Control', 'private, no-store');
    }
}
