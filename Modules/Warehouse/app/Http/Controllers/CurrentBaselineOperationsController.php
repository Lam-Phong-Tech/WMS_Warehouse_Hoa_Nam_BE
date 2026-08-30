<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\ConflictCode;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Support\Concerns\ResolvesActorOrNull;
use Modules\Warehouse\Support\MasterSelectionGuard;
use OpenApi\Attributes as OA;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class CurrentBaselineOperationsController extends Controller
{
    use ApiResponse;
    use ResolvesActorOrNull { actorIdOrNull as actorId; }

    /**
     * WWHN-247: bản sao thứ hai của state machine bảo hành. Nó KHÔNG được route nào trỏ
     * tới (updateWarrantyCase/storeWarrantyCase ở lớp này là handler chết — route thật đi
     * qua CurrentBaselineWarrantyController), nhưng vẫn phải trùng khít ma trận đã chốt:
     * hai bản khác nhau là cái bẫy khiến lần sửa sau "sửa đúng file sai" và status enum
     * lại lệch giữa FE/BE.
     *
     * Nguồn chốt: DEC-WAR-STATE-001 + SRS §6, khoá bằng
     * Wwhn247WarrantyStateMachineParityTest::test_duplicate_matrix_matches_the_routed_one.
     */
    private const WARRANTY_TRANSITIONS = [
        'RECEIVED' => ['CHECKING', 'CANCELLED'],
        'CHECKING' => ['REPAIRING', 'COMPLETED', 'CANCELLED'],
        'REPAIRING' => ['COMPLETED', 'CANCELLED'],
        'COMPLETED' => ['RETURNED', 'CANCELLED'],
        'RETURNED' => [],
        'CANCELLED' => [],
    ];

    #[OA\Post(
        path: '/api/v1/outbound-request-imports/manual',
        operationId: 'outbound_request_import_manual_create',
        summary: 'Import toa xuất thủ công (file Excel xlsx/xls hoặc rows JSON đã chuẩn hoá)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Gửi MỘT trong hai: `file` (multipart, xlsx/xls, tối đa 20480 KB, required_without:rows) hoặc `rows` (JSON, required_without:file). Trùng source_checksum thì replay import cũ, không tạo bản ghi mới.',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary', nullable: true, description: 'File Excel xlsx/xls, tối đa 20480 KB (gửi qua multipart/form-data); required_without:rows', example: 'outbound_requests.xlsx'),
                    new OA\Property(
                        property: 'rows',
                        type: 'array',
                        minItems: 1,
                        maxItems: 10000,
                        nullable: true,
                        description: 'Danh sách dòng toa đã chuẩn hoá; required_without:file',
                        items: new OA\Items(
                            required: ['request_no', 'required_qty'],
                            properties: [
                                new OA\Property(property: 'request_no', type: 'string', maxLength: 80, example: 'OUT-000123'),
                                new OA\Property(property: 'request_date', type: 'string', maxLength: 20, nullable: true, example: '2026-08-21'),
                                new OA\Property(property: 'recipient_name', type: 'string', maxLength: 200, nullable: true, example: 'Nguyễn Văn A'),
                                new OA\Property(property: 'customer_name', type: 'string', maxLength: 200, nullable: true, example: 'Nguyễn Văn A'),
                                new OA\Property(property: 'recipient_address', type: 'string', maxLength: 1000, nullable: true, example: '123 Đường Lê Lợi, Quận 1, TP.HCM'),
                                new OA\Property(property: 'customer_address', type: 'string', maxLength: 1000, nullable: true, example: '123 Đường Lê Lợi, Quận 1, TP.HCM'),
                                new OA\Property(property: 'recipient_contact_phone', type: 'string', maxLength: 50, nullable: true, example: '0912345678'),
                                new OA\Property(property: 'customer_phone', type: 'string', maxLength: 50, nullable: true, example: '0912345678'),
                                new OA\Property(property: 'sku_code', type: 'string', maxLength: 100, nullable: true, example: 'SKU-000123'),
                                new OA\Property(property: 'sku_id', type: 'string', format: 'uuid', nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                                new OA\Property(property: 'agency_id', type: 'string', format: 'uuid', nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                                new OA\Property(property: 'required_qty', type: 'integer', minimum: 1, example: 5),
                                new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true, example: 'Giao trong giờ hành chính'),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Trùng checksum: replay import cũ, không tạo import mới'),
            new OA\Response(response: 201, description: 'Import được tạo (VALIDATED nếu không có lỗi dòng, REJECTED nếu có)'),
            new OA\Response(response: 400, description: 'INVALID_EXCEL_FILE: không đọc được file Excel'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 422, description: 'EMPTY_EXCEL_FILE / INVALID_EXCEL_TEMPLATE / dữ liệu không hợp lệ'),
        ]
    )]
    public function storeOutboundRequestImport(Request $request)
    {
        $validated = $request->validate([
            'file' => ['nullable', 'required_without:rows', 'file', 'mimes:xlsx,xls', 'max:20480'],
            'rows' => ['nullable', 'required_without:file', 'array', 'min:1', 'max:10000'],
            'rows.*.request_no' => ['required_with:rows', 'string', 'max:80'],
            'rows.*.request_date' => ['nullable', 'string', 'max:20'],
            'rows.*.recipient_name' => ['nullable', 'string', 'max:200'],
            'rows.*.customer_name' => ['nullable', 'string', 'max:200'],
            'rows.*.recipient_address' => ['nullable', 'string', 'max:1000'],
            'rows.*.customer_address' => ['nullable', 'string', 'max:1000'],
            // §5.2 + Annex D SCR-OUT-01: SĐT người nhận optional ngay từ khâu tiếp nhận toa.
            'rows.*.recipient_contact_phone' => ['nullable', 'string', 'max:50'],
            'rows.*.customer_phone' => ['nullable', 'string', 'max:50'],
            'rows.*.sku_code' => ['nullable', 'string', 'max:100'],
            'rows.*.sku_id' => ['nullable', 'uuid'],
            // BR-AGY-01 được kiểm ở Confirm nên agency_id phải đi qua validate.
            'rows.*.agency_id' => ['nullable', 'uuid'],
            'rows.*.required_qty' => ['required_with:rows', 'integer', 'min:1'],
            'rows.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        [$rows, $fileName, $checksum] = isset($validated['file'])
            ? $this->readOutboundWorkbook($validated['file']->getRealPath(), $validated['file']->getClientOriginalName())
            : [$validated['rows'], 'api-normalized-rows.json', hash('sha256', json_encode($validated['rows'], JSON_UNESCAPED_UNICODE))];

        $existing = DB::table('outbound_request_imports')->where('source_checksum', $checksum)->first();
        if ($existing !== null) {
            return $this->respondSuccess($this->importPayload((string) $existing->id), 'File trùng nội dung với lần import trước; trả lại kết quả cũ, không tạo import mới.');
        }

        $normalized = [];
        $errorCount = 0;
        foreach (array_values($rows) as $index => $row) {
            [$data, $errors] = $this->validateOutboundRow((array) $row, $index + 2);
            if ($errors !== []) {
                $errorCount++;
            }
            $normalized[] = ['row_no' => $index + 2, 'raw' => (array) $row, 'data' => $data, 'errors' => $errors];
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $fileName, $checksum, $normalized, $errorCount): void {
            DB::table('outbound_request_imports')->insert([
                'id' => $id,
                'import_no' => 'OBI-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'source_file_name' => $fileName,
                'source_checksum' => $checksum,
                'source_key' => 'CHECKSUM:'.$checksum,
                'status' => $errorCount === 0 ? 'VALIDATED' : 'REJECTED',
                'total_rows' => count($normalized),
                'valid_rows' => count($normalized) - $errorCount,
                'error_rows' => $errorCount,
                'created_at' => now(),
                'created_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
                'version' => 0,
            ]);

            foreach ($normalized as $row) {
                DB::table('outbound_request_import_rows')->insert([
                    'id' => (string) Str::uuid(),
                    'import_id' => $id,
                    'row_no' => $row['row_no'],
                    'raw_payload' => json_encode($row['raw'], JSON_UNESCAPED_UNICODE),
                    'normalized_payload' => json_encode($row['data'], JSON_UNESCAPED_UNICODE),
                    'validation_status' => $row['errors'] === [] ? 'VALID' : 'INVALID',
                    'validation_errors' => json_encode($row['errors'], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            }
        }, 3);

        AuditLogger::record(
            action: 'OUTBOUND_IMPORT_VALIDATE',
            objectType: 'outbound_request_import',
            objectId: $id,
            actorId: $this->actorId(),
            requestId: $request->attributes->get('request_id'),
            afterData: ['status' => $errorCount === 0 ? 'VALIDATED' : 'REJECTED', 'error_rows' => $errorCount],
        );

        return $this->respondCreated($this->importPayload($id), $errorCount === 0 ? 'Excel validated.' : 'Excel rejected with row-level errors.');
    }

    public function showOutboundRequestImport(string $id)
    {
        $payload = $this->importPayload($id);

        return $payload ? $this->respondSuccess($payload) : $this->respondNotFound('Không tìm thấy Import.');
    }

    public function consumeOutboundRequestImport(Request $request, string $id)
    {
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);
        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            "consume_outbound_import_{$id}",
            $this->actorId(),
            $hash,
            function () use ($id): array {
                $result = DB::transaction(function () use ($id): array {
                    $import = DB::table('outbound_request_imports')->where('id', $id)->lockForUpdate()->first();
                    if ($import === null) {
                        ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Import not found.');
                    }
                    if ($import->status !== 'VALIDATED') {
                        ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ import VALIDATED mới được consume.');
                    }

                    $rows = DB::table('outbound_request_import_rows')
                        ->where('import_id', $id)
                        ->where('validation_status', 'VALID')
                        ->orderBy('row_no')
                        ->get()
                        ->map(fn (object $row): array => json_decode((string) $row->normalized_payload, true));

                    $requestIds = [];
                    foreach ($rows->groupBy('request_no') as $requestNo => $group) {
                        $first = $group->first();
                        $requestId = (string) Str::uuid();
                        DB::table('outbound_requests')->insert([
                            'id' => $requestId,
                            'request_no' => (string) $requestNo,
                            'import_id' => $id,
                            'agency_id' => $first['agency_id'] ?? null,
                            'recipient_name' => $first['recipient_name'],
                            'recipient_address' => $first['recipient_address'],
                            'request_date' => now()->toDateString(),
                            'status' => 'DRAFT',
                            'created_at' => now(),
                            'created_by' => $this->actorId(),
                            'updated_at' => now(),
                            'updated_by' => $this->actorId(),
                            'version' => 0,
                        ]);

                        foreach ($group->values() as $index => $row) {
                            DB::table('outbound_request_lines')->insert([
                                'id' => (string) Str::uuid(),
                                'outbound_request_id' => $requestId,
                                'line_no' => $index + 1,
                                'sku_id' => $row['sku_id'],
                                'required_qty' => (int) $row['required_qty'],
                                'created_at' => now(),
                                'created_by' => $this->actorId(),
                                'updated_at' => now(),
                                'updated_by' => $this->actorId(),
                                'version' => 0,
                            ]);
                        }
                        $requestIds[] = $requestId;
                    }

                    DB::table('outbound_request_imports')->where('id', $id)->update([
                        'status' => 'CONSUMED',
                        'updated_at' => now(),
                        'updated_by' => $this->actorId(),
                    ]);

                    return ['import_id' => $id, 'status' => 'CONSUMED', 'outbound_request_ids' => $requestIds];
                }, 3);

                return [200, $this->envelope('Outbound import consumed.', $result)];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    public function storeWarrantyCase(Request $request)
    {
        $validated = $request->validate([
            'warranty_case_code' => ['nullable', 'string', 'max:80', 'unique:warranty_case,warranty_case_code'],
            'item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
            'defect_id' => ['nullable', 'uuid', 'exists:defect_catalog,defect_id'],
            'customer_name' => ['required', 'string', 'max:200'],
            'customer_phone' => ['required', 'string', 'max:50'],
            'customer_address' => ['nullable', 'string', 'max:1000'],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'missing_code_reason' => ['nullable', 'string', 'max:500'],
            'manual_product_description' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        // BR-DEF-01..04: bệnh/lỗi INACTIVE không được chọn cho hồ sơ mới.
        MasterSelectionGuard::assertDefectSelectable($validated['defect_id'] ?? null);

        $id = DB::transaction(function () use ($validated): string {
            $item = null;
            if (! empty($validated['item_id'])) {
                $item = DB::table('item_instances')->where('item_id', $validated['item_id'])->lockForUpdate()->first();
                if ($item === null || ! in_array($item->item_status, ['ISSUED', 'RETURNED_TO_CUSTOMER'], true)) {
                    ApiError::abort(422, 'WARRANTY_ITEM_NOT_ELIGIBLE', 'Item phải đã xuất cho khách trước khi tiếp nhận bảo hành.');
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

            $caseId = (string) Str::uuid();
            DB::table('warranty_case')->insert([
                'warranty_case_id' => $caseId,
                'warranty_case_code' => $validated['warranty_case_code'] ?? ('WC-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4))),
                'item_id' => $item?->item_id,
                'inventory_serial_id' => null,
                'product_id' => $item?->sku_id,
                'temporary_item_code' => $item ? null : ('TEMP-'.Str::upper(Str::random(10))),
                'identification_status' => $item ? 'IDENTIFIED' : 'TEMP_ONLY',
                'sales_trace_status' => $item ? 'TRACEABLE' : 'NOT_TRACEABLE',
                'missing_code_reason' => $validated['missing_code_reason'] ?? null,
                'manual_product_description' => $validated['manual_product_description'] ?? null,
                'defect_id' => $validated['defect_id'] ?? null,
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'customer_address' => $validated['customer_address'] ?? null,
                'purchase_date' => $validated['purchase_date'] ?? null,
                'status' => 'RECEIVED',
                'description' => $validated['description'] ?? null,
                'created_by' => $this->actorId(),
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
                'note' => $validated['description'] ?? null,
                'created_by' => $this->actorId(),
                'event_at' => now(),
            ]);

            return $caseId;
        }, 3);

        return $this->respondCreated($this->warrantyPayload($id, $request), 'Đã tiếp nhận ca bảo hành; tồn kho chưa thay đổi (stock_effect=NONE).');
    }

    public function updateWarrantyCase(Request $request, string $id)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:RECEIVED,CHECKING,REPAIRING,COMPLETED,RETURNED,CANCELLED'],
            'condition_status' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:2000'],
            'version' => ['nullable', 'integer', 'min:1'],
        ]);
        $expectedVersion = $this->expectedVersion($request, $validated['version'] ?? null);

        $payload = DB::transaction(function () use ($id, $validated, $expectedVersion, $request): array {
            $case = DB::table('warranty_case')->where('warranty_case_id', $id)->lockForUpdate()->first();
            if ($case === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Warranty case not found.');
            }
            if ((int) $case->version !== $expectedVersion) {
                ApiError::abort(412, 'STALE_VERSION', 'Warranty case version mismatch.');
            }

            $target = $validated['status'];
            if ($target !== $case->status && ! in_array($target, self::WARRANTY_TRANSITIONS[$case->status] ?? [], true)) {
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
                    'created_by' => $this->actorId(),
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
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                beforeData: ['status' => $case->status, 'version' => $case->version],
                afterData: ['status' => $target, 'version' => $expectedVersion + 1],
            );

            return $this->warrantyPayload($id, $request);
        }, 3);

        return $this->respondSuccess($payload, 'Cập nhật ca bảo hành thành công.');
    }

    // KHÔNG thêm #[OA\Post(path: '/api/v1/label-snapshots', ...)] ở đây: route
    // này đã bị Flow06AlignmentServiceProvider ghi đè sang
    // AlignedOutboundFlowController::storePackingLabel() (đã có annotation riêng
    // cho đúng path này) — hàm dưới đây giờ là dead code. Thêm annotation trùng
    // path+method ở đây sẽ lặp lại lỗi "Unable to merge @OA\Post()".
    public function storeLabelSnapshot(Request $request)
    {
        $validated = $request->validate([
            'outbound_item_match_id' => ['required', 'uuid', 'exists:outbound_item_matches,id'],
            'sku_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
            'label_payload' => ['required', 'array'],
        ]);
        if ($this->containsPhoneField($validated['label_payload'])) {
            ApiError::abort(422, 'LABEL_PII_FORBIDDEN', 'Nhãn outbound không được chứa SĐT/customer_phone.');
        }

        $id = (string) Str::uuid();
        DB::table('label_snapshots')->insert([
            'id' => $id,
            'outbound_item_match_id' => $validated['outbound_item_match_id'],
            'sku_id' => $validated['sku_id'],
            'item_id' => $validated['item_id'] ?? null,
            'label_payload' => json_encode($validated['label_payload'], JSON_UNESCAPED_UNICODE),
            'status' => 'PENDING_PRINT',
            'created_at' => now(),
            'created_by' => $this->actorId(),
            'updated_at' => now(),
            'updated_by' => $this->actorId(),
            'version' => 0,
        ]);

        return $this->respondCreated(DB::table('label_snapshots')->where('id', $id)->first(), 'Đã tạo snapshot nhãn (bất biến).');
    }

    #[OA\Post(
        path: '/api/v1/printer-configs',
        operationId: 'printer_config_create',
        summary: 'Tạo cấu hình máy in nhãn (printer agent endpoint)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['printer_code', 'printer_name', 'agent_endpoint'],
                properties: [
                    new OA\Property(property: 'printer_code', type: 'string', maxLength: 80, example: 'PRN-0001'),
                    new OA\Property(property: 'printer_name', type: 'string', maxLength: 200, example: 'Máy in Zebra kho HN1'),
                    new OA\Property(property: 'agent_code', type: 'string', maxLength: 120, nullable: true, example: 'AGENT-HN1-01'),
                    new OA\Property(property: 'agent_endpoint', type: 'string', format: 'uri', maxLength: 500, example: 'http://192.168.1.50:9100/print'),
                    new OA\Property(property: 'label_size', type: 'string', maxLength: 80, nullable: true, example: '100x150mm'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo printer config thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: printer_code đã tồn tại, agent_endpoint không phải URL)'),
        ]
    )]
    public function storePrinterConfig(Request $request)
    {
        $validated = $request->validate([
            'printer_code' => ['required', 'string', 'max:80', 'unique:printer_configs,printer_code'],
            'printer_name' => ['required', 'string', 'max:200'],
            'agent_code' => ['nullable', 'string', 'max:120'],
            'agent_endpoint' => ['required', 'url', 'max:500'],
            'label_size' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);

        $id = (string) Str::uuid();
        DB::table('printer_configs')->insert([
            'id' => $id,
            'printer_code' => $validated['printer_code'],
            'printer_name' => $validated['printer_name'],
            'agent_code' => $validated['agent_code'] ?? null,
            'agent_endpoint' => $validated['agent_endpoint'],
            'label_size' => $validated['label_size'] ?? null,
            'status' => $validated['status'] ?? 'ACTIVE',
            'created_at' => now(),
            'created_by' => $this->actorId(),
            'updated_at' => now(),
            'updated_by' => $this->actorId(),
            'version' => 0,
        ]);

        return $this->respondCreated(DB::table('printer_configs')->where('id', $id)->first(), 'Đã tạo cấu hình máy in.');
    }

    /** SCR-LBL-01: cập nhật/vô hiệu hóa máy in (health/status quản lý qua API). */
    #[OA\Patch(
        path: '/api/v1/printer-configs/{id}',
        operationId: 'printer_config_update',
        summary: 'Cập nhật/vô hiệu hoá cấu hình máy in (SCR-LBL-01)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'printer_name', type: 'string', maxLength: 200, example: 'Máy in Zebra kho HN1'),
                new OA\Property(property: 'agent_code', type: 'string', maxLength: 120, nullable: true, example: 'AGENT-HN1-01'),
                new OA\Property(property: 'agent_endpoint', type: 'string', format: 'uri', maxLength: 500, example: 'http://192.168.1.50:9100/print'),
                new OA\Property(property: 'label_size', type: 'string', maxLength: 80, nullable: true, example: '100x150mm'),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE', 'UNHEALTHY'], example: 'ACTIVE'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật printer config thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 404, description: 'Không tìm thấy printer config'),
            new OA\Response(response: 422, description: 'NO_UPDATABLE_FIELDS hoặc dữ liệu không hợp lệ'),
        ]
    )]
    public function updatePrinterConfig(Request $request, string $id)
    {
        $validated = $request->validate([
            'printer_name' => ['sometimes', 'string', 'max:200'],
            'agent_code' => ['nullable', 'string', 'max:120'],
            'agent_endpoint' => ['sometimes', 'url', 'max:500'],
            'label_size' => ['nullable', 'string', 'max:80'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE,UNHEALTHY'],
        ]);
        if ($validated === []) {
            ApiError::abort(422, 'NO_UPDATABLE_FIELDS', 'Không có field hợp lệ để cập nhật printer config.');
        }

        $printer = DB::table('printer_configs')->where('id', $id)->first();
        if ($printer === null) {
            return $this->respondNotFound('Không tìm thấy printer config.');
        }

        DB::table('printer_configs')->where('id', $id)->update([
            ...$validated,
            'version' => ((int) ($printer->version ?? 0)) + 1,
            'updated_at' => now(),
            'updated_by' => $this->actorId(),
        ]);

        AuditLogger::record(
            action: 'UPDATE_PRINTER_CONFIG',
            objectType: 'printer_config',
            objectId: $id,
            actorId: $this->actorId(),
            requestId: $request->attributes->get('request_id'),
            beforeData: ['status' => $printer->status ?? null],
            afterData: $validated,
        );

        return $this->respondSuccess(DB::table('printer_configs')->where('id', $id)->first(), 'Đã cập nhật cấu hình máy in.');
    }

    /** Chi tiết một label snapshot kèm toàn bộ print events (audit In/In lại). */
    #[OA\Get(
        path: '/api/v1/label-snapshots/{id}',
        operationId: 'label_snapshot_detail',
        summary: 'Chi tiết label snapshot kèm toàn bộ print events',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Label snapshot + print_events'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy label snapshot'),
        ]
    )]
    public function showLabelSnapshot(string $id)
    {
        $snapshot = DB::table('label_snapshots')->where('id', $id)->first();
        if ($snapshot === null) {
            return $this->respondNotFound('Không tìm thấy label snapshot.');
        }

        return $this->respondSuccess([
            ...(array) $snapshot,
            'print_events' => DB::table('label_print_events')
                ->where('label_snapshot_id', $id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (object $event): array => (array) $event)
                ->all(),
        ]);
    }

    /** Nhật ký in nhãn (báo cáo Label/Print mục 12: attempts, failure/reprint). */
    #[OA\Get(
        path: '/api/v1/label-print-events',
        operationId: 'label_print_event_list',
        summary: 'Nhật ký in nhãn (phân trang, filter theo label_snapshot_id/status)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'label_snapshot_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['PENDING_PRINT', 'PRINTED', 'PRINT_FAILED'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách nhật ký in nhãn'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function listLabelPrintEvents(Request $request)
    {
        $request->validate([
            'label_snapshot_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:PENDING_PRINT,PRINTED,PRINT_FAILED'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('label_print_events')->orderByDesc('created_at');
        if ($request->filled('label_snapshot_id')) {
            $query->where('label_snapshot_id', (string) $request->input('label_snapshot_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->input('status')));
        }

        $paginator = $query->paginate(min(100, max(1, (int) $request->input('per_page', 10))));
        // Phẳng hoá lý do in lại (lưu trong metadata jsonb) thành field `reason`
        // để FE đọc trực tiếp, không phải tự parse metadata.
        $paginator->through(function ($row) {
            $meta = is_string($row->metadata ?? null) ? json_decode((string) $row->metadata, true) : ($row->metadata ?? null);
            $row->reason = is_array($meta) ? ($meta['reason'] ?? null) : null;

            return $row;
        });

        return $this->respondPaginated($paginator);
    }

    #[OA\Post(
        path: '/api/v1/label-snapshots/{id}/print',
        operationId: 'label_snapshot_print',
        summary: 'In label snapshot (READY_TO_PRINT/PRINT_FAILED → PRINTED, stock_effect=NONE)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['printer_config_id'],
                properties: [new OA\Property(property: 'printer_config_id', type: 'string', format: 'uuid', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Nhãn PRINTED; kèm label_print_event_id, version mới, stock_effect=NONE'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 404, description: 'Không tìm thấy label snapshot'),
            new OA\Response(response: 409, description: 'LABEL_STATE_CONFLICT / PRINT_FAILED / xung đột concurrency'),
            new OA\Response(response: 412, description: 'STALE_VERSION: If-Match không khớp version'),
            new OA\Response(response: 422, description: 'printer_config_id không hợp lệ hoặc INVALID_VERSION'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key hoặc If-Match'),
        ]
    )]
    public function printLabelSnapshot(Request $request, string $id)
    {
        return $this->dispatchLabel($request, $id, false);
    }

    #[OA\Post(
        path: '/api/v1/label-snapshots/{id}/reprint',
        operationId: 'label_snapshot_reprint',
        summary: 'In lại label snapshot đã PRINTED (stock-neutral, dùng lại chính snapshot cũ)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['printer_config_id'],
                properties: [
                    new OA\Property(property: 'printer_config_id', type: 'string', format: 'uuid', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                    new OA\Property(property: 'reason', type: 'string', maxLength: 500, nullable: true, description: 'Lý do in lại (hết mực / lỗi máy in / hỏng nhãn...)', example: 'Máy in hết mực'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Nhãn in lại thành công; kèm label_print_event_id, version mới, stock_effect=NONE'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 404, description: 'Không tìm thấy label snapshot'),
            new OA\Response(response: 409, description: 'LABEL_STATE_CONFLICT (chỉ in lại nhãn PRINTED/PRINT_FAILED) / PRINT_FAILED / xung đột concurrency'),
            new OA\Response(response: 412, description: 'STALE_VERSION: If-Match không khớp version'),
            new OA\Response(response: 422, description: 'printer_config_id không hợp lệ hoặc INVALID_VERSION'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key hoặc If-Match'),
        ]
    )]
    public function reprintLabelSnapshot(Request $request, string $id)
    {
        return $this->dispatchLabel($request, $id, true);
    }

    /**
     * Annex C printPackingLabel: POST /outbound-documents/{id}/packing-labels/{label_id}/print.
     * Ràng buộc nhãn phải thuộc đúng phiếu xuất trước khi gửi lệnh in.
     */
    #[OA\Post(
        path: '/api/v1/outbound-documents/{id}/packing-labels/{labelId}/print',
        operationId: 'packing_label_print',
        summary: 'In Packing Label của phiếu xuất (Annex C printPackingLabel; READY_TO_PRINT/PRINT_FAILED → PRINTED)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'labelId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['printer_config_id'],
                properties: [new OA\Property(property: 'printer_config_id', type: 'string', format: 'uuid', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Nhãn PRINTED; kèm label_print_event_id, version mới, stock_effect=NONE'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 404, description: 'LABEL_NOT_FOUND: nhãn không tồn tại hoặc không thuộc phiếu xuất này'),
            new OA\Response(response: 409, description: 'LABEL_STATE_CONFLICT / PRINT_FAILED / xung đột concurrency'),
            new OA\Response(response: 412, description: 'STALE_VERSION: If-Match không khớp version'),
            new OA\Response(response: 422, description: 'printer_config_id không hợp lệ hoặc INVALID_VERSION'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key hoặc If-Match'),
        ]
    )]
    public function printPackingLabelForDocument(Request $request, string $documentId, string $labelId)
    {
        $this->assertLabelBelongsToDocument($documentId, $labelId);

        return $this->dispatchLabel($request, $labelId, false);
    }

    /** Annex C reprintPackingLabel — stock-neutral, dùng lại chính snapshot cũ. */
    #[OA\Post(
        path: '/api/v1/outbound-documents/{id}/packing-labels/{labelId}/reprint',
        operationId: 'packing_label_reprint',
        summary: 'In lại Packing Label đã PRINTED của phiếu xuất (Annex C reprintPackingLabel; stock-neutral)',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'labelId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['printer_config_id'],
                properties: [
                    new OA\Property(property: 'printer_config_id', type: 'string', format: 'uuid', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                    new OA\Property(property: 'reason', type: 'string', maxLength: 500, nullable: true, description: 'Lý do in lại (hết mực / lỗi máy in / hỏng nhãn...)', example: 'Máy in hết mực'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Nhãn in lại thành công; kèm label_print_event_id, version mới, stock_effect=NONE'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền'),
            new OA\Response(response: 404, description: 'LABEL_NOT_FOUND: nhãn không tồn tại hoặc không thuộc phiếu xuất này'),
            new OA\Response(response: 409, description: 'LABEL_STATE_CONFLICT (chỉ in lại nhãn PRINTED/PRINT_FAILED) / PRINT_FAILED / xung đột concurrency'),
            new OA\Response(response: 412, description: 'STALE_VERSION: If-Match không khớp version'),
            new OA\Response(response: 422, description: 'printer_config_id không hợp lệ hoặc INVALID_VERSION'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key hoặc If-Match'),
        ]
    )]
    public function reprintPackingLabelForDocument(Request $request, string $documentId, string $labelId)
    {
        $this->assertLabelBelongsToDocument($documentId, $labelId);

        return $this->dispatchLabel($request, $labelId, true);
    }

    private function assertLabelBelongsToDocument(string $documentId, string $labelId): void
    {
        $snapshot = DB::table('label_snapshots')->where('id', $labelId)->first();
        if ($snapshot === null || (string) $snapshot->outbound_document_id !== $documentId) {
            ApiError::abort(404, 'LABEL_NOT_FOUND', 'Không tìm thấy Packing Label của phiếu xuất này.');
        }
    }

    /**
     * WWHN-252: `/pv/catalog` là path lịch sử; giữ nguyên handler để không phá
     * pin route của DocumentationBaselineContractTest, nhưng thân hàm chuyển
     * hẳn sang read model canonical của Annex C (`listPvProducts`) để hai path
     * không bao giờ trả hai sự thật khác nhau.
     *
     * Ba lỗi hợp đồng của bản cũ được đóng nhờ chuyển hướng này:
     * 1. INNER JOIN pv_product_profile → SKU đã publish nhưng chưa có hồ sơ PV
     *    biến mất khỏi catalog, trong khi REQ-SRS-036/DEC-CATALOG-001 nói publish
     *    không phụ thuộc bất cứ dữ liệu nào khác.
     * 2. Cộng `inventory_balances.quantity` (mirror của on_hand_qty) thay vì
     *    `available_qty` mà REQ-SRS-018 chỉ đích danh.
     * 3. Không phát stock_status/stock_label/data_as_of (DEC-CATALOG-001, NFR-SRS-016).
     */
    public function pvCatalog(Request $request)
    {
        return app(PvPublicCatalogController::class)->listProducts($request);
    }

    /**
     * WWHN-252: bản cũ JOIN thẳng r.product_id = s.sku_id nên MỌI rule chọn theo
     * category_id hoặc tag_id (ERD bảng 57 cho phép cả ba selector) bị loại âm
     * thầm, và response trả `r.*` — tức nội dung rule nội bộ — thay vì sản phẩm
     * được gợi ý. Chuyển sang read model canonical (Annex C listPvRecommendations).
     */
    public function pvRecommendations(Request $request)
    {
        return app(PvPublicCatalogController::class)->listRecommendations($request);
    }

    private function readOutboundWorkbook(string $path, string $fileName): array
    {
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $raw = $sheet->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            ApiError::abort(400, 'INVALID_EXCEL_FILE', 'Không đọc được file Excel: '.$e->getMessage());
        }

        if (count($raw) < 2) {
            ApiError::abort(422, 'EMPTY_EXCEL_FILE', 'Excel phải có header và ít nhất một dòng dữ liệu.');
        }

        $headers = array_map(fn ($value): string => Str::snake(trim((string) $value)), array_shift($raw));
        $required = ['request_no', 'sku_code', 'required_qty'];
        foreach ($required as $column) {
            if (! in_array($column, $headers, true)) {
                ApiError::abort(422, 'INVALID_EXCEL_TEMPLATE', "Thiếu cột bắt buộc {$column}.");
            }
        }
        if (! in_array('recipient_name', $headers, true) && ! in_array('customer_name', $headers, true)) {
            ApiError::abort(422, 'INVALID_EXCEL_TEMPLATE', 'Thiếu recipient_name/customer_name.');
        }
        // SRS v1.12 §15 + REQ-SRS-014: recipient_address/customer_address nullable —
        // file toa mẫu không có cột địa chỉ vẫn hợp lệ, không được chặn ở header.

        $rows = [];
        foreach ($raw as $values) {
            if (collect($values)->every(fn ($value): bool => $value === null || trim((string) $value) === '')) {
                continue;
            }
            $values = array_pad($values, count($headers), null);
            $rows[] = array_combine($headers, array_slice($values, 0, count($headers)));
        }

        return [$rows, $fileName, hash_file('sha256', $path)];
    }

    /**
     * Chuẩn hoá + validate một dòng toa. `protected` để bước Confirm (Annex C
     * OutboundRequestImportConfirmRequest) validate lại sau khi người dùng map SKU.
     */
    protected function validateOutboundRow(array $row, int $rowNo): array
    {
        $errors = [];
        $requestNo = trim((string) ($row['request_no'] ?? ''));
        $recipientName = trim((string) ($row['recipient_name'] ?? $row['customer_name'] ?? ''));
        $recipientAddress = trim((string) ($row['recipient_address'] ?? $row['customer_address'] ?? ''));
        // §5.2 + Annex D SCR-OUT-01: SĐT người nhận nullable, chuẩn hoá khi có.
        $recipientPhone = self::normalizePhone(
            $row['recipient_contact_phone'] ?? $row['customer_phone'] ?? $row['recipient_phone'] ?? null
        );
        $skuId = $row['sku_id'] ?? null;
        $skuCode = trim((string) ($row['sku_code'] ?? ''));
        $requiredQty = $row['required_qty'] ?? null;
        [$requestDate, $dateError] = $this->normalizeRequestDate($row['request_date'] ?? null, $rowNo);
        if ($dateError !== null) {
            $errors[] = $dateError;
        }

        if ($requestNo === '') {
            $errors[] = $this->rowError($rowNo, 'request_no', 'REQUIRED', 'request_no là bắt buộc.');
        }
        if ($recipientName === '') {
            $errors[] = $this->rowError($rowNo, 'recipient_name', 'REQUIRED', 'Tên người nhận/khách hàng là bắt buộc.');
        }
        // DEC-PHONE-VN-001: SĐT người nhận, khi có, phải đúng định dạng VN (10 chữ số,
        // bắt đầu bằng 0) — cùng quy tắc với StoreAgencyRequest/StoreWarehouseRequest.
        if ($recipientPhone !== null && ! preg_match('/^0\d{9}$/', $recipientPhone)) {
            $errors[] = $this->rowError($rowNo, 'recipient_contact_phone', 'INVALID_PHONE', 'Số điện thoại phải gồm đúng 10 chữ số và bắt đầu bằng số 0.');
        }
        // SRS v1.12 §15 + REQ-SRS-014: recipient_address/phone nullable — không chặn
        // Confirm khi toa nguồn (vd. toa mẫu khách hàng) không có địa chỉ.

        $sku = null;
        if ($skuId) {
            $sku = DB::table('sku')->where('sku_id', $skuId)->first();
        } elseif ($skuCode !== '') {
            $sku = DB::table('sku')->where('sku_code', $skuCode)->first();
        }
        if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE') {
            $errors[] = $this->rowError($rowNo, 'sku_code', 'SKU_NOT_ACTIVE', 'Không tìm thấy SKU hoặc SKU đang ngừng hoạt động.');
        } elseif (strtoupper((string) $sku->sku_type) !== 'PRODUCT') {
            // §5.4.1: COMPONENT chỉ xuất qua Component Issue gắn warranty case active.
            $errors[] = $this->rowError(
                $rowNo,
                'sku_code',
                'COMPONENT_OUTBOUND_FORBIDDEN',
                'COMPONENT SKU chỉ được xuất qua Component Issue liên kết warranty case active.'
            );
        }
        if (filter_var($requiredQty, FILTER_VALIDATE_INT) === false || (int) $requiredQty <= 0) {
            $errors[] = $this->rowError($rowNo, 'required_qty', 'INVALID_QUANTITY', 'required_qty phải là số nguyên dương.');
        }

        return [[
            'request_no' => $requestNo,
            'request_date' => $requestDate,
            'recipient_name' => $recipientName,
            'recipient_address' => $recipientAddress,
            'recipient_contact_phone' => $recipientPhone,
            'agency_id' => $row['agency_id'] ?? null,
            'sku_id' => $sku?->sku_id,
            'sku_code' => $sku?->sku_code ?? $skuCode,
            'required_qty' => (int) $requiredQty,
            'note' => isset($row['note']) && trim((string) $row['note']) !== '' ? trim((string) $row['note']) : null,
        ], $errors];
    }

    /** §5.2: chuẩn hoá SĐT người nhận (giữ dấu +, bỏ khoảng trắng/dấu phân cách). */
    protected static function normalizePhone(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        $normalized = preg_replace('/(?!^\+)[^0-9]/', '', $raw) ?? $raw;

        return $normalized === '' ? null : $normalized;
    }

    /**
     * §5.2: toa mang "số toa - ngày - người nhận". Ngày toa nullable (fallback ngày
     * Confirm) nhưng nếu có phải parse được và không ở tương lai.
     *
     * @return array{0: ?string, 1: ?array<string,mixed>}
     */
    private function normalizeRequestDate(mixed $value, int $rowNo): array
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return [null, null];
        }

        // Excel serial date (PhpSpreadsheet trả number khi cell là kiểu date).
        if (is_numeric($raw) && (float) $raw > 0 && ! str_contains($raw, '-') && ! str_contains($raw, '/')) {
            try {
                return [
                    Date::excelToDateTimeObject((float) $raw)->format('Y-m-d'),
                    null,
                ];
            } catch (\Throwable) {
                // rơi xuống parser chuỗi bên dưới
            }
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $raw);
            if ($parsed !== false) {
                if ($parsed > new \DateTimeImmutable('today 23:59:59')) {
                    return [null, $this->rowError($rowNo, 'request_date', 'INVALID_REQUEST_DATE', 'request_date không được ở tương lai.')];
                }

                return [$parsed->format('Y-m-d'), null];
            }
        }

        return [null, $this->rowError($rowNo, 'request_date', 'INVALID_REQUEST_DATE', 'request_date phải theo định dạng YYYY-MM-DD hoặc DD/MM/YYYY.')];
    }

    private function rowError(int $row, string $field, string $code, string $message): array
    {
        return ['row' => $row, 'field' => $field, 'error_code' => $code, 'message' => $message];
    }

    private function importPayload(string $id): ?array
    {
        $import = DB::table('outbound_request_imports')->where('id', $id)->first();
        if ($import === null) {
            return null;
        }

        return [
            'import' => $import,
            'rows' => DB::table('outbound_request_import_rows')->where('import_id', $id)->orderBy('row_no')->get(),
        ];
    }

    private function warrantyPayload(string $id, Request $request): array
    {
        $case = DB::table('warranty_case')->where('warranty_case_id', $id)->first();
        if ($case === null) {
            return [];
        }

        if (! $request->user()?->hasPermission('warranty.pii.view')) {
            $case->customer_name = $this->mask((string) $case->customer_name);
            $case->customer_phone = $this->mask((string) $case->customer_phone);
            $case->customer_address = $this->mask((string) ($case->customer_address ?? ''));
        }

        return (array) $case;
    }

    /**
     * Annex C printPackingLabel/reprintPackingLabel — Idempotency-Key + If-Match.
     * SRS §6: chỉ in từ READY_TO_PRINT (hoặc retry sau PRINT_FAILED); reprint dùng
     * lại đúng snapshot đã PRINTED và stock-neutral kể cả sau completion.
     */
    private function dispatchLabel(Request $request, string $id, bool $reprint)
    {
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);
        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            ($reprint ? 'reprint_packing_label_' : 'print_packing_label_').$id,
            $this->actorId(),
            $hash,
            function () use ($request, $id, $reprint): array {
                $response = $this->dispatchLabelOnce($request, $id, $reprint);

                return [$response->getStatusCode(), $response->getData(true)];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function dispatchLabelOnce(Request $request, string $id, bool $reprint)
    {
        $validated = $request->validate([
            'printer_config_id' => ['required', 'uuid', 'exists:printer_configs,id'],
            // Lý do in lại (hết mực / lỗi máy in / hỏng nhãn / in lại chứng từ...).
            // Nullable ở tầng validate để không phá các client cũ; UI enforce bắt
            // buộc chọn lý do trước khi reprint. Lưu vào metadata (không đổi schema).
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : null;
        $reason = ($reason === '') ? null : $reason;

        $snapshot = DB::table('label_snapshots')->where('id', $id)->first();
        if ($snapshot === null) {
            return $this->respondNotFound('Không tìm thấy snapshot nhãn.');
        }
        $this->assertLabelPrintable($request, $snapshot, $reprint);
        $printer = DB::table('printer_configs')->where('id', $validated['printer_config_id'])->first();
        if ($printer === null || ($printer->status ?? 'ACTIVE') !== 'ACTIVE' || empty($printer->agent_endpoint)) {
            return $this->recordPrintFailure($snapshot, $validated['printer_config_id'], 'Máy in chưa được cấu hình hoặc đang ở trạng thái không hoạt động.', $reason);
        }

        $eventId = (string) Str::uuid();
        $status = 'PRINT_FAILED';
        $error = null;
        try {
            $response = Http::acceptJson()->timeout(5)->post((string) $printer->agent_endpoint, [
                'printer_code' => $printer->printer_code,
                'label_size' => $printer->label_size,
                'label' => json_decode((string) $snapshot->label_payload, true),
                'request_id' => $request->attributes->get('request_id'),
                'reprint' => $reprint,
            ]);
            if ($response->successful() && (bool) $response->json('success', true)) {
                $status = 'PRINTED';
            } else {
                $error = 'Máy in phản hồi lỗi khi nhận lệnh in (HTTP '.$response->status().').';
            }
        } catch (ConnectionException) {
            // Không tới được agent máy in (chưa bật/chưa cắm mạng/sai địa chỉ) hoặc
            // hết thời gian chờ (timeout ở trên) — cùng loại exception này ở
            // Laravel HTTP client. Thông điệp kỹ thuật gốc (cURL/host/port) không
            // có ích cho người dùng cuối nên KHÔNG lộ ra UI; giữ nguyên câu rõ ràng,
            // có hướng xử lý, thay vì để rơi xuống bản dịch chung chung ở
            // UserFacingMessageLocalizer.
            $error = 'Không thể kết nối tới máy in. Kiểm tra máy in đã bật và đã kết nối mạng, sau đó thử lại.';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // WWHN-234 F-06: $snapshot được đọc ở đầu hàm, NGOÀI lock (còn phải gọi
        // printer agent qua HTTP ở giữa — không thể giữ FOR UPDATE xuyên suốt một
        // lời gọi mạng ra ngoài). $expectedVersion neo đúng giá trị đã qua
        // assertLabelPrintable() ở trên; UPDATE dưới đây phải là CAS
        // (WHERE version = $expectedVersion), không phải ghi đè vô điều kiện —
        // nếu không, hai lệnh print/reprint đồng thời cùng nhãn sẽ mất update:
        // request thắng ghi version N+1, request thua ghi đè lại cũng N+1 (không
        // phải N+2) và ai chạy sau cùng quyết định status cuối, không cảnh báo gì.
        $expectedVersion = (int) ($snapshot->version ?? 0);
        $version = $expectedVersion + 1;
        $metadata = $reason !== null ? json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE) : null;
        DB::transaction(function () use ($eventId, $id, $validated, $status, $error, $version, $expectedVersion, $metadata): void {
            DB::table('label_print_events')->insert([
                'id' => $eventId,
                'label_snapshot_id' => $id,
                'printer_config_id' => $validated['printer_config_id'],
                'status' => $status,
                'error_message' => $error,
                'printed_at' => $status === 'PRINTED' ? now() : null,
                'metadata' => $metadata,
                'created_at' => now(),
                'created_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
                'version' => 0,
            ]);
            $updated = DB::table('label_snapshots')
                ->where('id', $id)
                ->where('version', $expectedVersion)
                ->update([
                    'status' => $status,
                    'version' => $version,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                ApiError::abort(409, ConflictCode::CONCURRENCY, 'Label snapshot đã bị thay đổi bởi lệnh in khác; tải lại rồi thử lại.');
            }
        });

        if ($status !== 'PRINTED') {
            // §5.2 + SCR-LBL-01: print failure chặn completion, tồn/movement không đổi.
            //
            // `details` cố ý để RỖNG: label_print_event_id/version/reprint là siêu
            // dữ liệu audit — FE không đọc lại field nào trong số này (chỉ refetch
            // nguyên document/label qua GET sau mọi lần in). ApiError::abort()
            // Việt hoá TỪNG GIÁ TRỊ trong `details` rồi FE hiển thị chúng như câu
            // lỗi field-by-field (đúng cho lỗi validate 422) — nhét metadata không
            // phải câu chữ vào đây khiến toast hiện một chuỗi UUID/số/bool lẫn lộn
            // thay vì đúng một câu $error rõ ràng.
            ApiError::abort(409, 'PRINT_FAILED', $error ?? 'Không thể gửi lệnh in tới máy in.');
        }

        return $this->respondSuccess([
            'label_snapshot_id' => $id,
            'label_print_event_id' => $eventId,
            'status' => 'PRINTED',
            'version' => $version,
            'reprint' => $reprint,
            'stock_effect' => 'NONE',
        ], $reprint ? 'Label reprinted.' : 'Label printed.');
    }

    /**
     * SRS §6 Packing_Label: DRAFT → PREVIEWED → READY_TO_PRINT → PRINTED,
     * print failure → PRINT_FAILED (retry dùng lại chính snapshot đó).
     * Reprint chỉ áp dụng cho nhãn đã PRINTED và được phép sau completion.
     */
    private function assertLabelPrintable(Request $request, object $snapshot, bool $reprint): void
    {
        $expected = $this->expectedLabelVersion($request);
        if ((int) ($snapshot->version ?? 0) !== $expected) {
            ApiError::abort(412, 'STALE_VERSION', 'Packing Label version mismatch.', [
                'expected_version' => $expected,
                'current_version' => (int) ($snapshot->version ?? 0),
            ]);
        }

        $current = (string) $snapshot->status;
        $allowed = $reprint ? ['PRINTED', 'PRINT_FAILED'] : ['READY_TO_PRINT', 'PRINT_FAILED'];
        if (! in_array($current, $allowed, true)) {
            ApiError::abort(409, 'LABEL_STATE_CONFLICT', $reprint
                ? "Chỉ in lại được Packing Label đã PRINTED; trạng thái hiện tại {$current}."
                : "Packing Label phải READY_TO_PRINT trước khi in; trạng thái hiện tại {$current}.");
        }
    }

    private function expectedLabelVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho lệnh in/in lại Packing Label.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên không âm.');
        }

        return (int) $header;
    }

    private function recordPrintFailure(object $snapshot, string $printerId, string $message, ?string $reason = null)
    {
        $eventId = (string) Str::uuid();
        // WWHN-234 F-06: cùng CAS như nhánh thành công trong dispatchLabelOnce() —
        // xem chú thích ở đó.
        $expectedVersion = (int) ($snapshot->version ?? 0);
        $version = $expectedVersion + 1;
        $metadata = $reason !== null ? json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE) : null;
        DB::transaction(function () use ($eventId, $snapshot, $printerId, $message, $version, $expectedVersion, $metadata): void {
            DB::table('label_print_events')->insert([
                'id' => $eventId,
                'label_snapshot_id' => $snapshot->id,
                'printer_config_id' => $printerId,
                'status' => 'PRINT_FAILED',
                'error_message' => $message,
                'metadata' => $metadata,
                'created_at' => now(),
                'created_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
                'version' => 0,
            ]);
            $updated = DB::table('label_snapshots')
                ->where('id', $snapshot->id)
                ->where('version', $expectedVersion)
                ->update([
                    'status' => 'PRINT_FAILED',
                    'version' => $version,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                ApiError::abort(409, ConflictCode::CONCURRENCY, 'Label snapshot đã bị thay đổi bởi lệnh in khác; tải lại rồi thử lại.');
            }
        });
        // `details` để rỗng — xem chú thích ở nhánh PRINT_FAILED khác trong
        // dispatchLabelOnce(): metadata này không phải câu chữ và FE không đọc
        // lại, nhét vào đây chỉ khiến toast hiện chuỗi UUID/số/bool lẫn lộn.
        ApiError::abort(409, 'PRINT_FAILED', $message);
    }

    private function containsPhoneField(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            if (str_contains($normalized, 'phone') || str_contains($normalized, 'tel') || str_contains($normalized, 'sdt')) {
                return true;
            }
            if (is_array($value) && $this->containsPhoneField($value)) {
                return true;
            }
        }

        return false;
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

    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => ['request_id' => request()?->attributes->get('request_id'), 'timestamp' => now()->toIso8601String()],
        ];
    }

    private function mask(string $value): string
    {
        return $value === '' ? '' : mb_substr($value, 0, 1).'***';
    }
}
