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

    /**
     * WWHN-193 (DEV193-P1-05R) — trạng thái duy nhất được phép khai lúc TẠO container.
     *
     * Mọi giá trị còn lại trong self::STATUSES là *kết quả* của một sự kiện nghiệp vụ đã
     * xảy ra (Post Receipt -> RECEIVED_PENDING_PUTAWAY, Putaway confirm -> STORED,
     * add/remove content -> IN_STOCK/PARTIAL/EMPTY, Post Issue -> ISSUED). Cho client khai
     * thẳng những trạng thái đó ở POST /containers đồng nghĩa dựng ra container "đã xếp
     * kho"/"đã xuất" mà không có phiếu, không putaway_event và không audit của sự kiện
     * tương ứng — đúng lỗ hổng DEV193-P1-05R.
     */
    private const CREATABLE_STATUSES = ['EMPTY'];

    /**
     * Trạng thái vòng đời chỉ được đặt bởi luồng canonical sở hữu sự kiện, không được set
     * qua endpoint CRUD chung (POST hoặc PATCH /containers).
     */
    private const LIFECYCLE_MANAGED_STATUSES = [
        'RECEIVED_PENDING_PUTAWAY',
        'STORED',
        'ISSUED',
    ];

    /**
     * POST /containers — tạo vỏ chứa rỗng. KHÔNG phải đường tạo putaway.
     *
     * WWHN-193 (DEV193-P1-05R): trước đây endpoint này nhận `parent_container_id` rồi
     * INSERT thẳng một `putaway_events` status = CONFIRMED (không DRAFT, không If-Match,
     * không eligibility BOX, không PUTAWAY_DRAFT_EXISTS guard, không kiểm Posted Receipt),
     * đồng thời nhận cả `status` vòng đời như STORED/RECEIVED_PENDING_PUTAWAY. Đó là một
     * đường vòng đầy đủ qua mọi guard của POST /putaway-tasks + confirm. PATCH đã chặn từ
     * trước; POST thì chưa. Nay cả hai method đều bắt buộc đi qua luồng canonical.
     */
    public function store(Request $request)
    {
        $this->assertNoPutawaySideEffectRequested($request, 'POST /containers');
        $this->assertCreatableStatus($request);

        $validated = $request->validate([
            'container_code' => ['required', 'string', 'max:120', 'unique:containers,container_code'],
            'container_type' => ['required', Rule::in(['CARTON', 'BOX', 'TRAY'])],
            'primary_sku_id' => ['nullable', 'uuid', 'exists:sku,sku_id'],
            'zone_code' => ['nullable', 'string', 'max:50'],
            'location_hint' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(self::CREATABLE_STATUSES)],
            'migration_run_id' => ['nullable', 'uuid', 'exists:migration_runs,id'],
            'physical_code_id' => ['nullable', 'uuid', 'exists:physical_codes,physical_code_id'],
        ]);

        $result = DB::transaction(function () use ($validated, $request): object {
            $type = strtoupper((string) $validated['container_type']);
            if (in_array($type, ['CARTON', 'BOX'], true) && empty($validated['primary_sku_id'])) {
                ApiError::abort(422, 'PRIMARY_SKU_REQUIRED', 'CARTON/BOX bắt buộc primary_sku_id.');
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
                // Container mới luôn đứng độc lập; gắn vào TRAY là thao tác putaway.
                'parent_container_id' => null,
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

            // parent_container_id (BOX -> TRAY placement) chỉ được thay đổi qua vòng đời
            // canonical POST /putaway/tasks + confirm — nơi enforce receipt eligibility,
            // DRAFT state, PUTAWAY_DRAFT_EXISTS guard và stock-neutral invariant. Endpoint
            // update chung này KHÔNG được phép tạo side-effect putaway tương đương.
            if (array_key_exists('parent_container_id', $validated)
                && (string) $validated['parent_container_id'] !== (string) $container->parent_container_id) {
                ApiError::abort(422, 'PUTAWAY_MUST_USE_TASK_FLOW', 'Đổi parent_container_id là thao tác putaway; dùng POST /putaway/tasks rồi confirm, không dùng PATCH /containers/{id}.');
            }

            // WWHN-193 (DEV193-P1-05R) — cùng lý do với parent_container_id: STORED /
            // RECEIVED_PENDING_PUTAWAY / ISSUED là kết quả của Putaway confirm, Post Receipt
            // và Post Issue. Đặt tay qua PATCH sẽ tạo container "đã xếp kho"/"đã xuất" mà
            // không có putaway_event, không có chứng từ và không có audit của sự kiện đó,
            // nên vẫn qua mặt được eligibility check của POST /putaway-tasks.
            if (array_key_exists('status', $validated)
                && $validated['status'] !== null
                && in_array(strtoupper((string) $validated['status']), self::LIFECYCLE_MANAGED_STATUSES, true)
                && strtoupper((string) $validated['status']) !== strtoupper((string) $container->status)) {
                ApiError::abort(
                    422,
                    'CONTAINER_STATUS_LIFECYCLE_MANAGED',
                    'Trạng thái vòng đời của container do luồng nghiệp vụ sở hữu sự kiện đặt (Post Receipt / Putaway confirm / Post Issue), không set qua PATCH /containers/{id}.',
                    [
                        'requested_status' => strtoupper((string) $validated['status']),
                        'lifecycle_managed_statuses' => self::LIFECYCLE_MANAGED_STATUSES,
                    ],
                );
            }

            $updates = $validated + [
                'version' => $expectedVersion + 1,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ];
            DB::table('containers')->where('container_id', $id)->update($updates);

            $after = DB::table('containers')->where('container_id', $id)->first();
            AuditLogger::record(
                action: 'UPDATE_CONTAINER',
                objectType: 'container',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                beforeData: (array) $container,
                afterData: (array) $after,
            );

            return ['container' => $after, 'stock_effect' => 'NONE'];
        }, 3);

        return $this->respondSuccess($result, 'Container updated.');
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

    /**
     * WWHN-193 (DEV193-P1-05R) — chặn mọi mưu toan đặt placement BOX -> TRAY qua endpoint
     * CRUD chung. Từ chối ngay cả khi client gửi `parent_container_id: null` tường minh là
     * không cần thiết (null = đứng độc lập = mặc định), nên chỉ chặn giá trị non-null.
     */
    private function assertNoPutawaySideEffectRequested(Request $request, string $surface): void
    {
        if (! $request->has('parent_container_id')) {
            return;
        }
        $requested = $request->input('parent_container_id');
        if ($requested === null || $requested === '') {
            return;
        }

        ApiError::abort(
            422,
            'PUTAWAY_MUST_USE_TASK_FLOW',
            'Gắn container vào TRAY là thao tác putaway; dùng POST /putaway-tasks rồi confirm (If-Match), không dùng '.$surface.'.',
            [
                'surface' => $surface,
                'canonical_flow' => ['POST /api/v1/putaway-tasks', 'POST /api/v1/putaway-tasks/{id}/confirm'],
            ],
        );
    }

    /**
     * WWHN-193 (DEV193-P1-05R) — chỉ EMPTY được khai lúc tạo. Trả mã nghiệp vụ riêng thay
     * vì VALIDATION_ERROR chung để QA/FE truy được đúng nguyên nhân từ chối.
     */
    private function assertCreatableStatus(Request $request): void
    {
        $requested = $request->input('status');
        if ($requested === null || $requested === '') {
            return;
        }
        if (in_array(strtoupper((string) $requested), self::CREATABLE_STATUSES, true)) {
            return;
        }

        ApiError::abort(
            422,
            'CONTAINER_STATUS_NOT_CREATABLE',
            'Container mới chỉ được tạo ở trạng thái EMPTY; các trạng thái còn lại là kết quả của Post Receipt / Putaway confirm / thao tác content / Post Issue.',
            [
                'requested_status' => strtoupper((string) $requested),
                'creatable_statuses' => self::CREATABLE_STATUSES,
                'lifecycle_managed_statuses' => self::LIFECYCLE_MANAGED_STATUSES,
            ],
        );
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
