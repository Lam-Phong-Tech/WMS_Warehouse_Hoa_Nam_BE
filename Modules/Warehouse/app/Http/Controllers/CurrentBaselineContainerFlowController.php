<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;

class CurrentBaselineContainerFlowController extends Controller
{
    use ApiResponse;

    private const STATUSES = [
        'EMPTY',
        'IN_STOCK',
        'PARTIAL',
        'ISSUED',
        'RECEIVED_PENDING_PUTAWAY',
        'STORED',
        'CLOSED',
    ];

    public function store(Request $request)
    {
        $validated = $request->validate([
            'container_code' => ['required', 'string', 'max:120', 'unique:containers,container_code'],
            'container_type' => ['required', Rule::in(['CARTON', 'BOX', 'TRAY'])],
            'primary_sku_id' => ['nullable', 'uuid', 'exists:sku,sku_id'],
            'parent_container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'zone_code' => ['nullable', 'string', 'max:50'],
            'location_hint' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'migration_run_id' => ['nullable', 'uuid', 'exists:migration_runs,id'],
            'physical_code_id' => ['nullable', 'uuid', 'exists:physical_codes,physical_code_id'],
        ]);

        $result = DB::transaction(function () use ($validated, $request): object {
            $type = strtoupper((string) $validated['container_type']);
            if (in_array($type, ['CARTON', 'BOX'], true) && empty($validated['primary_sku_id'])) {
                ApiError::abort(422, 'PRIMARY_SKU_REQUIRED', 'CARTON/BOX bắt buộc primary_sku_id.');
            }
            if (! empty($validated['parent_container_id'])) {
                if ($type !== 'BOX') {
                    ApiError::abort(422, 'PUTAWAY_BOX_REQUIRED', 'Trong MVP chỉ BOX được putaway vào TRAY.');
                }
                $this->assertTrayUsable((string) $validated['parent_container_id']);
            }

            $id = (string) Str::uuid();
            $physicalCodeId = $validated['physical_code_id'] ?? null;
            $code = null;
            if ($physicalCodeId !== null) {
                $code = DB::table('physical_codes')->where('physical_code_id', $physicalCodeId)->lockForUpdate()->first();
                if ($code === null || strtoupper((string) $code->status) !== 'ACTIVE') {
                    ApiError::abort(422, 'PHYSICAL_CODE_NOT_ACTIVE', 'physical_code_id phải ACTIVE.');
                }
                if (($code->item_id ?? null) !== null || ($code->container_id ?? null) !== null) {
                    ApiError::abort(409, 'PHYSICAL_CODE_ALREADY_ASSIGNED', 'Physical code đã map target khác.');
                }
            }

            DB::table('containers')->insert([
                // Trên Postgres, containers có cột id mirror (không tự set
                // như các bảng khác); trên SQLite bảng này không có cột id.
                ...(Schema::hasColumn('containers', 'id') ? ['id' => $id] : []),
                'container_id' => $id,
                'container_code' => $validated['container_code'],
                'container_type' => $type,
                'physical_code_id' => $physicalCodeId,
                'primary_sku_id' => $validated['primary_sku_id'] ?? null,
                'parent_container_id' => $validated['parent_container_id'] ?? null,
                'zone_code' => $validated['zone_code'] ?? null,
                'location_hint' => $validated['location_hint'] ?? null,
                'status' => $validated['status'] ?? 'EMPTY',
                'migration_run_id' => $validated['migration_run_id'] ?? null,
                'version' => 1,
                'created_by' => $this->actorId(),
                'updated_by' => $this->actorId(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($physicalCodeId !== null) {
                DB::table('physical_codes')->where('physical_code_id', $physicalCodeId)->update([
                    'container_id' => $id,
                    'item_id' => null,
                    'assigned_object_type' => 'CONTAINER',
                    'assigned_object_id' => $id,
                    'assigned_at' => now(),
                    'assigned_by' => $this->actorId(),
                    'version' => ((int) ($code->version ?? 0)) + 1,
                    'updated_by' => $this->actorId(),
                    'updated_at' => now(),
                ]);
            }

            if (! empty($validated['parent_container_id'])) {
                $this->recordPutaway($id, null, (string) $validated['parent_container_id'], null, $validated['location_hint'] ?? null);
            }

            $container = DB::table('containers')->where('container_id', $id)->first();
            AuditLogger::record(
                action: 'CREATE_CONTAINER',
                objectType: 'container',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                afterData: (array) $container,
            );

            return $container;
        }, 3);

        return $this->respondCreated($result, 'Container created; assign physical code through physical_codes.container_id; stock_effect=NONE.');
    }

    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'container_code' => ['sometimes', 'string', 'max:120'],
            'primary_sku_id' => ['nullable', 'uuid', 'exists:sku,sku_id'],
            'parent_container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'zone_code' => ['nullable', 'string', 'max:50'],
            'location_hint' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ]);
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $validated, $expectedVersion, $request): array {
            $container = DB::table('containers')->where('container_id', $id)->lockForUpdate()->first();
            if ($container === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy container.');
            }
            $this->assertVersion($container, $expectedVersion);
            if ($container->status === 'CLOSED') {
                ApiError::abort(409, 'CONTAINER_CLOSED', 'Container CLOSED là immutable.');
            }

            $newParent = array_key_exists('parent_container_id', $validated) ? $validated['parent_container_id'] : $container->parent_container_id;
            $putawayChanged = $newParent !== $container->parent_container_id
                || array_key_exists('location_hint', $validated)
                || array_key_exists('zone_code', $validated);
            if ($newParent !== null) {
                if ((string) $newParent === $id) {
                    ApiError::abort(422, 'CONTAINER_CYCLE', 'Container không thể chứa chính nó.');
                }
                if (strtoupper((string) $container->container_type) !== 'BOX') {
                    ApiError::abort(422, 'PUTAWAY_BOX_REQUIRED', 'Putaway vào TRAY chỉ áp dụng BOX.');
                }
                $this->assertTrayUsable((string) $newParent);
                $this->assertNoCycle($id, (string) $newParent);
            }

            $updates = $validated + [
                'version' => $expectedVersion + 1,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ];
            DB::table('containers')->where('container_id', $id)->update($updates);

            if ($putawayChanged && $newParent !== null) {
                $this->recordPutaway(
                    $id,
                    $container->parent_container_id,
                    (string) $newParent,
                    $container->location_hint,
                    $validated['location_hint'] ?? $container->location_hint
                );
                if (($validated['status'] ?? null) === null) {
                    DB::table('containers')->where('container_id', $id)->update(['status' => 'STORED']);
                }
            }

            $after = DB::table('containers')->where('container_id', $id)->first();
            AuditLogger::record(
                action: $putawayChanged ? 'PUTAWAY_CONFIRM' : 'UPDATE_CONTAINER',
                objectType: 'container',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                beforeData: (array) $container,
                afterData: (array) $after,
            );

            return ['container' => $after, 'putaway_confirmed' => $putawayChanged && $newParent !== null, 'stock_effect' => 'NONE'];
        }, 3);

        return $this->respondSuccess($result, $result['putaway_confirmed'] ? 'Putaway confirmed.' : 'Container updated.');
    }

    public function addItem(Request $request, string $id)
    {
        $validated = $request->validate([
            'item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
            'sku_id' => ['nullable', 'uuid', 'exists:sku,sku_id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'source_type' => ['nullable', Rule::in(['RECEIPT', 'PUTAWAY', 'MIGRATION', 'CORRECTION'])],
            'source_id' => ['nullable', 'uuid'],
        ]);
        $expectedVersion = $this->expectedVersion($request);

        $mapping = DB::transaction(function () use ($id, $validated, $expectedVersion, $request): object {
            $container = DB::table('containers')->where('container_id', $id)->lockForUpdate()->first();
            if ($container === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy container.');
            }
            $this->assertVersion($container, $expectedVersion);
            if (in_array($container->status, ['CLOSED', 'ISSUED'], true)) {
                ApiError::abort(409, 'CONTAINER_NOT_EDITABLE', 'CLOSED/ISSUED container không nhận thêm content.');
            }

            $hasItem = ! empty($validated['item_id']);
            $hasSku = ! empty($validated['sku_id']);
            if ($hasItem === $hasSku) {
                ApiError::abort(422, 'CONTAINER_ITEM_XOR', 'Chọn item_id hoặc sku_id+quantity, không chọn cả hai.');
            }

            $quantity = null;
            $skuId = $validated['sku_id'] ?? null;
            if ($hasItem) {
                $item = DB::table('item_instances')->where('item_id', $validated['item_id'])->lockForUpdate()->first();
                if ($item === null) {
                    ApiError::abort(422, 'ITEM_NOT_FOUND', 'Không tìm thấy item.');
                }
                $skuId = $item->sku_id;
                if (DB::table('container_items')->where('item_id', $item->item_id)->where('is_active', true)->exists()) {
                    ApiError::abort(409, 'ITEM_ALREADY_IN_CONTAINER', 'Item đã nằm trong một active container mapping khác.');
                }
            } else {
                if (empty($validated['quantity'])) {
                    ApiError::abort(422, 'QUANTITY_REQUIRED', 'Quantity-mode bắt buộc quantity > 0.');
                }
                $quantity = (int) $validated['quantity'];
            }

            if ($container->primary_sku_id !== null && (string) $container->primary_sku_id !== (string) $skuId) {
                ApiError::abort(422, 'CONTAINER_PRIMARY_SKU_MISMATCH', 'Content SKU không khớp primary_sku_id.');
            }

            $mappingId = (string) Str::uuid();
            DB::table('container_items')->insert([
                'container_item_id' => $mappingId,
                'container_id' => $id,
                'item_id' => $hasItem ? $validated['item_id'] : null,
                'sku_id' => $hasItem ? null : $validated['sku_id'],
                'quantity' => $hasItem ? null : $quantity,
                'status' => 'ACTIVE',
                'is_active' => true,
                'placed_at' => now(),
                'placed_by' => $this->actorId(),
                'source_type' => $validated['source_type'] ?? null,
                'source_id' => $validated['source_id'] ?? null,
                'version' => 1,
                'created_by' => $this->actorId(),
                'updated_by' => $this->actorId(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('containers')->where('container_id', $id)->update([
                'status' => $container->parent_container_id ? 'STORED' : 'IN_STOCK',
                'version' => $expectedVersion + 1,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);
            $created = DB::table('container_items')->where('container_item_id', $mappingId)->first();
            AuditLogger::record(
                action: 'CONTAINER_CONTENT_ADD',
                objectType: 'container_item',
                objectId: $mappingId,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                afterData: (array) $created,
            );

            return $created;
        }, 3);

        return $this->respondCreated($mapping, 'Container content added; stock_effect=NONE.');
    }

    public function removeItem(Request $request, string $id, string $containerItemId)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $containerItemId, $validated, $expectedVersion, $request): array {
            $container = DB::table('containers')->where('container_id', $id)->lockForUpdate()->first();
            if ($container === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy container.');
            }
            $this->assertVersion($container, $expectedVersion);
            if ($container->status === 'CLOSED') {
                ApiError::abort(409, 'CONTAINER_CLOSED', 'Container CLOSED là immutable.');
            }
            $mapping = DB::table('container_items')->where('container_item_id', $containerItemId)->where('container_id', $id)->lockForUpdate()->first();
            if ($mapping === null || ! (bool) $mapping->is_active) {
                ApiError::abort(404, 'CONTAINER_ITEM_NOT_ACTIVE', 'Không tìm thấy active mapping.');
            }

            DB::table('container_items')->where('container_item_id', $containerItemId)->update([
                'status' => 'REMOVED',
                'is_active' => false,
                'removed_at' => now(),
                'removed_by' => $this->actorId(),
                'version' => ((int) $mapping->version) + 1,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);
            $remaining = DB::table('container_items')->where('container_id', $id)->where('is_active', true)->exists();
            DB::table('containers')->where('container_id', $id)->update([
                'status' => $remaining ? 'PARTIAL' : 'EMPTY',
                'version' => $expectedVersion + 1,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);

            AuditLogger::record(
                action: 'CONTAINER_CONTENT_REMOVE',
                objectType: 'container_item',
                objectId: $containerItemId,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                reason: $validated['reason'],
                beforeData: (array) $mapping,
                afterData: ['status' => 'REMOVED', 'is_active' => false],
            );

            return ['container_item_id' => $containerItemId, 'status' => 'REMOVED', 'container_status' => $remaining ? 'PARTIAL' : 'EMPTY', 'version' => $expectedVersion + 1, 'stock_effect' => 'NONE'];
        }, 3);

        return $this->respondSuccess($result, 'Container content removed.');
    }

    private function recordPutaway(string $boxId, ?string $fromTrayId, string $toTrayId, ?string $fromLocation, ?string $toLocation): void
    {
        DB::table('putaway_events')->insert([
            'id' => (string) Str::uuid(),
            'stock_document_id' => null,
            'warehouse_id' => null,
            'container_id' => $boxId,
            'box_container_id' => $boxId,
            'from_parent_container_id' => $fromTrayId,
            'from_tray_id' => $fromTrayId,
            'to_parent_container_id' => $toTrayId,
            'to_tray_id' => $toTrayId,
            'from_location_hint' => $fromLocation,
            'to_location_hint' => $toLocation,
            'location_hint' => $toLocation,
            'status' => 'CONFIRMED',
            'confirmed_at' => now(),
            'confirmed_by' => $this->actorId(),
            'created_by' => $this->actorId(),
            'created_at' => now(),
            'updated_at' => now(),
            'updated_by' => $this->actorId(),
            'version' => 1,
        ]);
    }

    private function assertTrayUsable(string $trayId): void
    {
        $tray = DB::table('containers')->where('container_id', $trayId)->lockForUpdate()->first();
        if ($tray === null || strtoupper((string) $tray->container_type) !== 'TRAY' || in_array($tray->status, ['CLOSED', 'ISSUED'], true)) {
            ApiError::abort(422, 'TRAY_REQUIRED', 'Putaway yêu cầu TRAY tồn tại và chưa CLOSED/ISSUED.');
        }
    }

    private function assertNoCycle(string $containerId, string $parentId): void
    {
        $seen = [$containerId => true];
        $cursor = $parentId;
        for ($depth = 0; $depth < 64 && $cursor !== null; $depth++) {
            if (isset($seen[$cursor])) {
                ApiError::abort(422, 'CONTAINER_CYCLE', 'Container nesting tạo chu trình.');
            }
            $seen[$cursor] = true;
            $cursor = DB::table('containers')->where('container_id', $cursor)->value('parent_container_id');
        }
        if ($cursor !== null) {
            ApiError::abort(422, 'CONTAINER_DEPTH_INVALID', 'Container nesting vượt giới hạn an toàn.');
        }
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho container mutation.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên không âm.');
        }

        return (int) $header;
    }

    private function assertVersion(object $container, int $expected): void
    {
        if ((int) $container->version !== $expected) {
            ApiError::abort(412, 'STALE_VERSION', 'Container version mismatch.', [
                'expected_version' => $expected,
                'current_version' => (int) $container->version,
            ]);
        }
    }

    private function actorId(): string
    {
        $id = auth('api')->id();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Authentication required.');
        }

        return (string) $id;
    }
}
