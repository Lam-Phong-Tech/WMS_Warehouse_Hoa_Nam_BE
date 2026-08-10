<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;

class CurrentBaselineContainerController extends Controller
{
    use ApiResponse;

    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'container_code' => ['sometimes', 'string', 'max:100', Rule::unique('containers', 'container_code')->ignore($id, 'container_id')],
            'container_type' => ['sometimes', Rule::in(['CARTON', 'BOX', 'TRAY'])],
            'physical_code_id' => ['sometimes', 'uuid', 'exists:physical_codes,physical_code_id', Rule::unique('containers', 'physical_code_id')->ignore($id, 'container_id')],
            'primary_sku_id' => ['nullable', 'uuid', 'exists:sku,sku_id'],
            'parent_container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'zone_code' => ['nullable', 'string', 'max:50'],
            'location_hint' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:ACTIVE,SEALED,CLOSED,INACTIVE'],
            'version' => ['nullable', 'integer', 'min:1'],
        ]);
        $expectedVersion = $this->expectedVersion($request, $validated['version'] ?? null);
        unset($validated['version']);

        $result = DB::transaction(function () use ($id, $validated, $expectedVersion, $request): array {
            $container = DB::table('containers')->where('container_id', $id)->lockForUpdate()->first();
            if ($container === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy container.');
            }
            if ((int) $container->version !== $expectedVersion) {
                ApiError::abort(412, 'PRECONDITION_FAILED', 'Container version mismatch.');
            }

            $effectiveType = strtoupper((string) ($validated['container_type'] ?? $container->container_type));
            $primarySku = array_key_exists('primary_sku_id', $validated) ? $validated['primary_sku_id'] : $container->primary_sku_id;
            if (in_array($effectiveType, ['CARTON', 'BOX'], true) && empty($primarySku)) {
                ApiError::abort(422, 'PRIMARY_SKU_REQUIRED', 'CARTON/BOX bắt buộc primary_sku_id.');
            }

            if (isset($validated['physical_code_id'])) {
                $code = DB::table('physical_codes')->where('physical_code_id', $validated['physical_code_id'])->lockForUpdate()->first();
                if ($code === null || strtoupper((string) $code->status) !== 'ACTIVE') {
                    ApiError::abort(422, 'PHYSICAL_CODE_NOT_ACTIVE', 'Physical code phải ACTIVE.');
                }
            }

            $newParentId = array_key_exists('parent_container_id', $validated)
                ? $validated['parent_container_id']
                : $container->parent_container_id;
            $isPutaway = array_key_exists('parent_container_id', $validated)
                || array_key_exists('location_hint', $validated)
                || array_key_exists('zone_code', $validated);

            if ($newParentId !== null) {
                if ($newParentId === $id) {
                    ApiError::abort(422, 'CONTAINER_CYCLE', 'Container không thể chứa chính nó.');
                }
                if ($effectiveType === 'TRAY') {
                    ApiError::abort(422, 'TRAY_CANNOT_BE_PUTAWAY_CHILD', 'TRAY là locator đích, không được putaway vào container khác trong MVP.');
                }

                $parent = DB::table('containers')->where('container_id', $newParentId)->lockForUpdate()->first();
                if ($parent === null || strtoupper((string) $parent->status) !== 'ACTIVE' || strtoupper((string) $parent->container_type) !== 'TRAY') {
                    ApiError::abort(422, 'ACTIVE_TRAY_REQUIRED', 'Putaway chỉ được gán vào TRAY ACTIVE.');
                }
                $this->assertNoContainerCycle($id, $newParentId);
            }

            $before = (array) $container;
            $updates = $validated + [
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
            ];
            $affected = DB::table('containers')
                ->where('container_id', $id)
                ->where('version', $expectedVersion)
                ->update($updates);
            if ($affected !== 1) {
                ApiError::abort(409, 'VERSION_CONFLICT', 'Container changed concurrently.');
            }

            if ($isPutaway) {
                DB::table('putaway_events')->insert([
                    'id' => (string) Str::uuid(),
                    'stock_document_id' => null,
                    'warehouse_id' => null,
                    'container_id' => $id,
                    'from_parent_container_id' => $container->parent_container_id,
                    'to_parent_container_id' => $newParentId,
                    'from_location_hint' => $container->location_hint,
                    'to_location_hint' => $validated['location_hint'] ?? $container->location_hint,
                    'status' => 'CONFIRMED',
                    'confirmed_at' => now(),
                    'created_by' => $this->actorId(),
                    'created_at' => now(),
                ]);
            }

            $after = DB::table('containers')->where('container_id', $id)->first();
            AuditLogger::record(
                action: $isPutaway ? 'PUTAWAY_CONFIRM' : 'UPDATE_CONTAINER',
                objectType: 'container',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                beforeData: $before,
                afterData: (array) $after,
            );

            return [
                'container' => $after,
                'putaway_confirmed' => $isPutaway,
                'stock_effect' => 'NONE',
                'movement_created' => false,
            ];
        }, 3);

        return $this->respondSuccess($result, $result['putaway_confirmed'] ? 'Putaway confirmed.' : 'Container updated.');
    }

    private function assertNoContainerCycle(string $containerId, string $parentId): void
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

    private function expectedVersion(Request $request, mixed $bodyVersion): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header !== '') {
            $header = preg_replace('/^W\//', '', $header) ?? $header;
            $header = trim($header, '" ');
            if (ctype_digit($header) && (int) $header > 0) {
                return (int) $header;
            }
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên dương.');
        }
        if (is_numeric($bodyVersion) && (int) $bodyVersion > 0) {
            return (int) $bodyVersion;
        }
        ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match/version là bắt buộc.');
    }

    private function actorId(): ?string
    {
        $id = auth('api')->id();

        return $id === null ? null : (string) $id;
    }
}
