<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Support\Concerns\ResolvesActorOrNull;

class ComponentContainerSubledgerService
{
    use ResolvesActorOrNull { actorIdOrNull as actorId; }

    private const STOCK_BEARING_BOX_STATUSES = [
        'IN_STOCK',
        'PARTIAL',
        'RECEIVED_PENDING_PUTAWAY',
        'STORED',
    ];

    public function assertContainerAvailable(string $containerId, string $skuId, int $quantity): void
    {
        $container = DB::table('containers')->where('container_id', $containerId)->lockForUpdate()->first();
        if ($container === null
            || ! in_array(strtoupper((string) $container->status), self::STOCK_BEARING_BOX_STATUSES, true)
            || strtoupper((string) $container->container_type) !== 'BOX'
            || (string) $container->primary_sku_id !== $skuId) {
            ApiError::abort(422, 'COMPONENT_BOX_INVALID', 'Container component phải là BOX còn stock, đúng primary_sku_id và chưa CLOSED/ISSUED.');
        }

        $sku = DB::table('sku')->where('sku_id', $skuId)->first();
        if ($sku === null || (bool) $sku->is_serial_tracked) {
            ApiError::abort(422, 'SERIAL_COMPONENT_REQUIRES_ITEM', 'COMPONENT serial-tracked phải xuất từng item_id, không được xuất quantity bằng BOX.');
        }

        $available = (int) DB::table('container_items')
            ->where('container_id', $containerId)
            ->where('sku_id', $skuId)
            ->where('is_active', true)
            ->sum('quantity');
        if ($available < $quantity) {
            ApiError::abort(422, 'COMPONENT_BOX_INSUFFICIENT', 'BOX không đủ quantity component yêu cầu.', [
                'container_id' => $containerId,
                'sku_id' => $skuId,
                'available_qty' => $available,
                'required_qty' => $quantity,
            ]);
        }
    }

    public function consumeDocument(string $documentId): void
    {
        $rows = DB::table('component_issue_items as ci')
            ->join('component_issue_lines as cl', 'cl.id', '=', 'ci.component_issue_line_id')
            ->where('cl.component_issue_document_id', $documentId)
            ->where('ci.status', 'ACTIVE')
            ->orderBy('cl.line_no')
            ->orderBy('ci.created_at')
            ->lockForUpdate()
            ->get([
                'ci.id', 'ci.item_id', 'ci.container_id', 'ci.quantity',
                'cl.sku_id', 'cl.id as line_id',
            ]);

        foreach ($rows as $row) {
            if ($row->item_id !== null) {
                $this->consumeSerialItem((string) $row->item_id, (string) $row->sku_id);

                continue;
            }
            if ($row->container_id === null) {
                ApiError::abort(422, 'COMPONENT_ITEM_TARGET_REQUIRED', 'Component Issue item thiếu item_id/container_id.');
            }
            $this->consumeContainerQuantity((string) $row->container_id, (string) $row->sku_id, (int) $row->quantity);
        }
    }

    private function consumeSerialItem(string $itemId, string $skuId): void
    {
        $item = DB::table('item_instances')->where('item_id', $itemId)->lockForUpdate()->first();
        if ($item === null || (string) $item->sku_id !== $skuId || $item->item_status !== 'IN_STOCK') {
            ApiError::abort(422, 'COMPONENT_ITEM_NOT_AVAILABLE', 'Serial component item không còn IN_STOCK hoặc sai SKU.');
        }

        $mapping = DB::table('container_items')->where('item_id', $itemId)->where('is_active', true)->lockForUpdate()->first();
        if ($mapping !== null) {
            DB::table('container_items')->where('container_item_id', $mapping->container_item_id)->update([
                'status' => 'REMOVED',
                'is_active' => false,
                'quantity' => 0,
                'removed_at' => now(),
                'removed_by' => $this->actorId(),
                'version' => ((int) ($mapping->version ?? 0)) + 1,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);
            $this->refreshContainerState((string) $mapping->container_id);
        }
    }

    private function consumeContainerQuantity(string $containerId, string $skuId, int $quantity): void
    {
        $this->assertContainerAvailable($containerId, $skuId, $quantity);
        $remaining = $quantity;
        $mappings = DB::table('container_items')
            ->where('container_id', $containerId)
            ->where('sku_id', $skuId)
            ->where('is_active', true)
            ->where('quantity', '>', 0)
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($mappings as $mapping) {
            if ($remaining <= 0) {
                break;
            }
            $current = (int) $mapping->quantity;
            $take = min($current, $remaining);
            $after = $current - $take;
            DB::table('container_items')->where('container_item_id', $mapping->container_item_id)->update([
                'quantity' => $after,
                'status' => $after === 0 ? 'REMOVED' : 'ACTIVE',
                'is_active' => $after > 0,
                'removed_at' => $after === 0 ? now() : $mapping->removed_at,
                'removed_by' => $after === 0 ? $this->actorId() : $mapping->removed_by,
                'version' => ((int) ($mapping->version ?? 0)) + 1,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);
            $remaining -= $take;
        }

        if ($remaining !== 0) {
            ApiError::abort(409, 'CONTAINER_SUBLEDGER_CHANGED', 'Container subledger thay đổi đồng thời; không thể hoàn tất Component Issue.');
        }
        $this->refreshContainerState($containerId);
    }

    private function refreshContainerState(string $containerId): void
    {
        $remaining = (int) DB::table('container_items')->where('container_id', $containerId)->where('is_active', true)->sum('quantity');
        $container = DB::table('containers')->where('container_id', $containerId)->lockForUpdate()->first();
        if ($container === null || $container->status === 'CLOSED') {
            return;
        }
        DB::table('containers')->where('container_id', $containerId)->update([
            'status' => $remaining > 0 ? 'PARTIAL' : 'EMPTY',
            'version' => ((int) $container->version) + 1,
            'updated_by' => $this->actorId(),
            'updated_at' => now(),
        ]);
    }

}
