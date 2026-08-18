<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\ConflictCode;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                ApiError::abort(412, 'STALE_VERSION', 'Container version mismatch.');
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

            // parent_container_id (BOX -> TRAY placement) chỉ được thay đổi qua vòng đời
            // canonical POST /putaway/tasks + confirm — endpoint update chung này KHÔNG
            // được phép tạo side-effect putaway tương đương (xem WWHN-193 P1 finding).
            if (array_key_exists('parent_container_id', $validated)
                && (string) $validated['parent_container_id'] !== (string) $container->parent_container_id) {
                ApiError::abort(422, 'PUTAWAY_MUST_USE_TASK_FLOW', 'Đổi parent_container_id là thao tác putaway; dùng POST /putaway/tasks rồi confirm, không dùng generic container update.');
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
                ApiError::abort(409, ConflictCode::CONCURRENCY, 'Container changed concurrently.');
            }

            $after = DB::table('containers')->where('container_id', $id)->first();
            AuditLogger::record(
                action: 'UPDATE_CONTAINER',
                objectType: 'container',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                beforeData: $before,
                afterData: (array) $after,
            );

            return [
                'container' => $after,
                'stock_effect' => 'NONE',
                'movement_created' => false,
            ];
        }, 3);

        return $this->respondSuccess($result, 'Container updated.');
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
