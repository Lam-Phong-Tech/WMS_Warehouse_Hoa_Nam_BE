<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;

class CurrentBaselineWarrantyController extends CurrentBaselineOperationsController
{
    private const TRANSITIONS = [
        'RECEIVED' => ['CHECKING', 'CANCELLED'],
        'CHECKING' => ['REPAIRING', 'CANCELLED'],
        'REPAIRING' => ['COMPLETED', 'CANCELLED'],
        'COMPLETED' => ['RETURNED', 'CANCELLED'],
        'RETURNED' => [],
        'CANCELLED' => [],
    ];

    public function storeWarrantyCase(Request $request)
    {
        $validated = $request->validate([
            'warranty_case_code' => ['nullable', 'string', 'max:80'],
            'case_no' => ['nullable', 'string', 'max:80'],
            'item_id' => ['nullable', 'uuid'],
            'item_code' => ['nullable', 'string', 'max:200'],
            'defect_id' => ['nullable', 'uuid'],
            'defect_code' => ['nullable', 'string', 'max:80'],
            'customer_name' => ['required', 'string', 'max:200'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string', 'max:1000'],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:2000'],
            'missing_code_reason' => ['nullable', 'string', 'max:500'],
            'manual_product_description' => ['nullable', 'string', 'max:2000'],
        ]);

        $caseId = DB::transaction(function () use ($validated, $request): string {
            $item = $this->resolveWarrantyItem($validated);
            $defect = $this->resolveDefect($validated);

            if ($item !== null) {
                if (! in_array((string) $item->item_status, ['ISSUED', 'RETURNED_TO_CUSTOMER'], true)) {
                    ApiError::abort(422, 'WARRANTY_ITEM_NOT_ELIGIBLE', 'Item phải đã xuất cho khách trước khi tiếp nhận bảo hành.', [
                        'item_id' => (string) $item->item_id,
                        'item_status' => (string) $item->item_status,
                    ]);
                }
                $active = DB::table('warranty_case')
                    ->where('item_id', $item->item_id)
                    ->whereNotIn('status', ['RETURNED', 'CANCELLED'])
                    ->exists();
                if ($active) {
                    ApiError::abort(409, 'ACTIVE_WARRANTY_EXISTS', 'Item đã có warranty case đang hoạt động.');
                }
            } elseif (empty($validated['missing_code_reason']) || empty($validated['manual_product_description'])) {
                ApiError::abort(422, 'TEMP_ONLY_EVIDENCE_REQUIRED', 'TEMP_ONLY bắt buộc missing_code_reason và manual_product_description.');
            }

            $caseCode = trim((string) ($validated['warranty_case_code'] ?? $validated['case_no'] ?? ''));
            if ($caseCode === '') {
                $caseCode = 'WC-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
            }
            if (DB::table('warranty_case')->where('warranty_case_code', $caseCode)->exists()) {
                ApiError::abort(422, 'WARRANTY_CASE_CODE_EXISTS', 'Mã phiếu bảo hành đã tồn tại.');
            }

            $caseId = (string) Str::uuid();
            DB::table('warranty_case')->insert([
                'warranty_case_id' => $caseId,
                'warranty_case_code' => $caseCode,
                'item_id' => $item?->item_id,
                'inventory_serial_id' => null,
                'product_id' => $item?->sku_id,
                'temporary_item_code' => $item ? null : ('TEMP-'.Str::upper(Str::random(10))),
                'identification_status' => $item ? 'IDENTIFIED' : 'TEMP_ONLY',
                'sales_trace_status' => $item ? 'TRACEABLE' : 'NOT_TRACEABLE',
                'missing_code_reason' => $validated['missing_code_reason'] ?? null,
                'manual_product_description' => $validated['manual_product_description'] ?? null,
                'defect_id' => $defect?->defect_id,
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'customer_address' => $validated['customer_address'] ?? null,
                'purchase_date' => $validated['purchase_date'] ?? null,
                'status' => 'RECEIVED',
                'description' => $validated['description'],
                'created_by' => $this->currentActorId(),
                'version' => 1,
                'legal_hold_flag' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($item !== null) {
                DB::table('item_instances')->where('item_id', $item->item_id)->update([
                    'item_status' => 'IN_WARRANTY',
                    'updated_at' => now(),
                ]);
            }

            DB::table('warranty_event')->insert([
                'warranty_event_id' => (string) Str::uuid(),
                'warranty_case_id' => $caseId,
                'event_type' => 'RECEIVED',
                'from_status' => null,
                'to_status' => 'RECEIVED',
                'note' => $validated['description'],
                'created_by' => $this->currentActorId(),
                'event_at' => now(),
            ]);

            AuditLogger::record(
                action: 'WARRANTY_CASE_RECEIVED',
                objectType: 'warranty_case',
                objectId: $caseId,
                actorId: $this->currentActorId(),
                requestId: $request->attributes->get('request_id'),
                afterData: [
                    'status' => 'RECEIVED',
                    'version' => 1,
                    'identification_status' => $item ? 'IDENTIFIED' : 'TEMP_ONLY',
                    'item_id' => $item?->item_id,
                    'defect_id' => $defect?->defect_id,
                    'stock_effect' => 'NONE',
                ],
            );

            return $caseId;
        }, 3);

        return $this->respondCreated($this->casePayload($caseId, $request), 'Warranty case received; stock_effect=NONE.');
    }

    public function showWarrantyCase(Request $request, string $id)
    {
        $payload = $this->casePayload($id, $request);
        if ($payload === []) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Warranty case not found.');
        }

        return $this->respondSuccess($payload);
    }

    public function updateWarrantyCase(Request $request, string $id)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:RECEIVED,CHECKING,REPAIRING,COMPLETED,RETURNED,CANCELLED'],
            'condition_status' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($validated === []) {
            ApiError::abort(422, 'EMPTY_UPDATE', 'Phải gửi status hoặc description để cập nhật.');
        }
        $expectedVersion = $this->expectedVersion($request);

        DB::transaction(function () use ($id, $validated, $expectedVersion, $request): void {
            $case = DB::table('warranty_case')->where('warranty_case_id', $id)->lockForUpdate()->first();
            if ($case === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Warranty case not found.');
            }
            if ((int) $case->version !== $expectedVersion) {
                ApiError::abort(412, 'PRECONDITION_FAILED', 'Warranty case version mismatch.', [
                    'expected_version' => $expectedVersion,
                    'current_version' => (int) $case->version,
                ]);
            }

            $target = (string) ($validated['status'] ?? $case->status);
            if ($target !== $case->status && ! in_array($target, self::TRANSITIONS[$case->status] ?? [], true)) {
                ApiError::abort(409, 'INVALID_WARRANTY_TRANSITION', "Không cho phép {$case->status} -> {$target}.");
            }

            DB::table('warranty_case')->where('warranty_case_id', $id)->update([
                'status' => $target,
                'condition_status' => $validated['condition_status'] ?? $case->condition_status,
                'description' => $validated['description'] ?? $case->description,
                'version' => $expectedVersion + 1,
                'closed_at' => in_array($target, ['RETURNED', 'CANCELLED'], true) ? now() : $case->closed_at,
                'updated_at' => now(),
            ]);

            if ($target !== $case->status) {
                DB::table('warranty_event')->insert([
                    'warranty_event_id' => (string) Str::uuid(),
                    'warranty_case_id' => $id,
                    'event_type' => 'STATUS_CHANGE',
                    'from_status' => $case->status,
                    'to_status' => $target,
                    'note' => $validated['description'] ?? null,
                    'created_by' => $this->currentActorId(),
                    'event_at' => now(),
                ]);
            }

            if ($target === 'RETURNED' && $case->item_id !== null) {
                DB::table('item_instances')->where('item_id', $case->item_id)->update([
                    'item_status' => 'RETURNED_TO_CUSTOMER',
                    'updated_at' => now(),
                ]);
            }

            AuditLogger::record(
                action: 'WARRANTY_STATUS_CHANGE',
                objectType: 'warranty_case',
                objectId: $id,
                actorId: $this->currentActorId(),
                requestId: $request->attributes->get('request_id'),
                beforeData: ['status' => $case->status, 'version' => $case->version],
                afterData: ['status' => $target, 'version' => $expectedVersion + 1, 'stock_effect' => 'NONE'],
            );
        }, 3);

        return $this->respondSuccess($this->casePayload($id, $request), 'Warranty case updated; stock_effect=NONE.');
    }

    private function resolveWarrantyItem(array $validated): ?object
    {
        $itemId = trim((string) ($validated['item_id'] ?? ''));
        $itemCode = trim((string) ($validated['item_code'] ?? ''));

        if ($itemId !== '') {
            $item = DB::table('item_instances')->where('item_id', $itemId)->lockForUpdate()->first();
            if ($item === null) {
                ApiError::abort(422, 'WARRANTY_ITEM_NOT_FOUND', 'Không tìm thấy item_id.');
            }

            return $item;
        }
        if ($itemCode === '') {
            return null;
        }

        $item = DB::table('item_instances')
            ->where('serial_number', $itemCode)
            ->lockForUpdate()
            ->first();
        if ($item !== null) {
            return $item;
        }

        $mappedId = DB::table('physical_codes')
            ->where('code_value', $itemCode)
            ->where('status', 'ACTIVE')
            ->where('assigned_object_type', 'ITEM')
            ->value('assigned_object_id');
        if ($mappedId !== null) {
            $item = DB::table('item_instances')->where('item_id', $mappedId)->lockForUpdate()->first();
            if ($item !== null) {
                return $item;
            }
        }

        ApiError::abort(422, 'WARRANTY_ITEM_NOT_FOUND', 'Không resolve được item từ barcode/serial/physical code.', [
            'item_code' => $itemCode,
        ]);
    }

    private function resolveDefect(array $validated): ?object
    {
        $defectId = trim((string) ($validated['defect_id'] ?? ''));
        $defectCode = trim((string) ($validated['defect_code'] ?? ''));
        if ($defectId === '' && $defectCode === '') {
            return null;
        }

        $query = DB::table('defect_catalogs')->where('status', 'ACTIVE');
        $defect = $defectId !== ''
            ? $query->where('defect_id', $defectId)->first()
            : $query->where('defect_code', $defectCode)->first();
        if ($defect === null) {
            ApiError::abort(422, 'WARRANTY_DEFECT_NOT_FOUND', 'Không tìm thấy bệnh/lỗi ACTIVE.', [
                'defect_id' => $defectId ?: null,
                'defect_code' => $defectCode ?: null,
            ]);
        }

        return $defect;
    }

    private function casePayload(string $id, Request $request): array
    {
        $case = DB::table('warranty_case')->where('warranty_case_id', $id)->first();
        if ($case === null) {
            return [];
        }

        $item = $case->item_id
            ? DB::table('item_instances')->where('item_id', $case->item_id)->first()
            : null;
        $skuId = $case->product_id ?? $item?->sku_id;
        $sku = $skuId ? DB::table('sku')->where('sku_id', $skuId)->first() : null;
        $defect = $case->defect_id
            ? DB::table('defect_catalogs')->where('defect_id', $case->defect_id)->first()
            : null;

        $customerName = (string) ($case->customer_name ?? '');
        $customerPhone = (string) ($case->customer_phone ?? '');
        $customerAddress = (string) ($case->customer_address ?? '');
        if (! $request->user()?->hasPermission('warranty.pii.view')) {
            $customerName = $this->mask($customerName);
            $customerPhone = $this->mask($customerPhone);
            $customerAddress = $this->mask($customerAddress);
        }

        return [
            'id' => (string) $case->warranty_case_id,
            'warranty_case_id' => (string) $case->warranty_case_id,
            'case_code' => (string) $case->warranty_case_code,
            'warranty_case_code' => (string) $case->warranty_case_code,
            'item_id' => $case->item_id,
            'item_code' => $item?->serial_number,
            'serial_number' => $item?->serial_number,
            'item_status' => $item?->item_status,
            'product_id' => $skuId,
            'sku_code' => $sku?->sku_code,
            'sku_name' => $sku?->sku_name,
            'temporary_item_code' => $case->temporary_item_code,
            'identification_status' => $case->identification_status,
            'sales_trace_status' => $case->sales_trace_status,
            'missing_code_reason' => $case->missing_code_reason,
            'manual_product_description' => $case->manual_product_description,
            'defect_id' => $case->defect_id,
            'defect_code' => $defect?->defect_code,
            'defect_name' => $defect?->defect_name,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'customer_address' => $customerAddress,
            'purchase_date' => $case->purchase_date,
            'status' => (string) $case->status,
            'condition_status' => $case->condition_status,
            'description' => $case->description,
            'version' => (int) $case->version,
            'legal_hold_flag' => (bool) $case->legal_hold_flag,
            'created_by' => $case->created_by,
            'created_at' => $case->created_at,
            'updated_at' => $case->updated_at,
            'closed_at' => $case->closed_at,
            'stock_effect' => 'NONE',
            'movement_created' => false,
        ];
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header) || (int) $header < 1) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên dương.');
        }

        return (int) $header;
    }

    private function currentActorId(): ?string
    {
        $id = auth('api')->id();

        return $id === null ? null : (string) $id;
    }

    private function mask(string $value): string
    {
        return $value === '' ? '' : mb_substr($value, 0, 1).'***';
    }
}
