<?php

namespace Modules\Warehouse\Support;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;

/** Captured only inside the existing owner write, before audit/outbox persistence. */
final class P22NfcAuditSnapshot
{
    public const FIELD = 'nfc_history_v1';

    public static function enrich(string $action, string $objectType, ?string $objectId, ?string $actorId, array $after): array
    {
        if ($objectType !== 'physical_code' || ! in_array($action, ['ASSIGN_ITEM_NFC', 'REVOKE_ITEM_NFC'], true)) {
            return $after;
        }
        // Never accept a caller-supplied snapshot, including a deferred/CLI one.
        unset($after[self::FIELD]);
        $request = request();
        $expected = $action === 'ASSIGN_ITEM_NFC'
            ? 'api/v1/mini-app/items/{itemId}/nfc'
            : 'api/v1/mini-app/nfc-tags/{physicalCodeId}/deactivate';
        if ($request->route()?->uri() !== $expected || DB::transactionLevel() < 1) {
            return $after;
        }
        $presented = $request->user();
        if (! $presented instanceof User || (string) $presented->user_id !== $actorId) {
            return $after;
        }
        $actor = User::whereKey($actorId)->first();
        if ($actor === null || $actor->status !== 'ACTIVE' || ! $actor->hasPermission('physical_code.assign_rfid')) {
            return $after;
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        $warehouseId = $context['active_warehouse_id'] ?? null;
        if (($context['warehouse_context']['status'] ?? null) !== 'READY' || ! is_string($warehouseId) || ! $actor->canAccessWarehouse($warehouseId)) {
            return $after;
        }
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->where('status', 'ACTIVE')->first();
        $tag = DB::table('physical_codes')->where('physical_code_id', $objectId)->where('carrier_type', 'NFC')->first();
        if ($warehouse === null || $tag === null || empty($tag->item_id)
            || (! empty($tag->nfc_warehouse_id) && $tag->nfc_warehouse_id !== $warehouseId)
            || ($action === 'REVOKE_ITEM_NFC' && empty($tag->nfc_warehouse_id))) {
            return $after;
        }
        if ($action === 'ASSIGN_ITEM_NFC' && (($after['item_id'] ?? null) !== $tag->item_id
            || ($after['hardware_uid'] ?? null) !== $tag->hardware_uid)) {
            return $after;
        }
        $dimensions = [];
        foreach (['tenant_id', 'org_id', 'organization_id'] as $field) {
            $value = $actor->getAttribute($field);
            $owner = $warehouse->{$field} ?? null;
            if ($value !== null || $owner !== null) {
                if (! is_string($value) || $value === '' || $value !== $owner) {
                    return $after;
                }
                $dimensions[$field] = $value;
            }
        }
        $item = DB::table('item_instances')->where('item_id', $tag->item_id)->first();
        $sku = $item === null ? null : DB::table('sku')->where('sku_id', $item->sku_id)->first();
        $previousId = $after['replaced_physical_code_id'] ?? null;
        $previous = is_string($previousId) ? DB::table('physical_codes')->where('physical_code_id', $previousId)->first() : null;
        $verified = $previous !== null && $previous->carrier_type === 'NFC'
            && $previous->item_id === $tag->item_id && $previous->nfc_warehouse_id === $warehouseId
            && $previous->replaced_by_code_id === $objectId && $previous->hardware_uid !== $tag->hardware_uid;
        $after[self::FIELD] = [
            'schema' => 'nfc-audit-snapshot-v1',
            'physical_code_id' => $objectId,
            'carrier_type' => 'NFC',
            'warehouse_id' => $warehouseId,
            'warehouse_code' => $warehouse->code,
            'warehouse_name' => $warehouse->name,
            'actor_id' => $actorId,
            'actor_name' => $actor->display_name,
            'hardware_uid' => $tag->hardware_uid,
            'item_id' => $tag->item_id,
            'serial_number' => $item?->serial_number,
            'sku_id' => $sku?->sku_id,
            'sku_code' => $sku?->sku_code,
            'sku_name' => $sku?->sku_name,
            'tag_status_at_event' => $tag->status,
            'replacement_requested' => $previousId !== null,
            'replacement_verified' => $verified,
            'previous_physical_code_id' => $verified ? $previousId : null,
            'previous_hardware_uid' => $verified ? $previous->hardware_uid : null,
            'previous_item_id' => $verified ? $previous->item_id : null,
            'previous_warehouse_id' => $verified ? $previous->nfc_warehouse_id : null,
            ...$dimensions,
        ];

        return $after;
    }
}
