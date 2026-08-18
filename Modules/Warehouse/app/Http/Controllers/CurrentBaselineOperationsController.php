<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Support\MasterSelectionGuard;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class CurrentBaselineOperationsController extends Controller
{
    use ApiResponse;

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
            return $this->respondSuccess($this->importPayload((string) $existing->id), 'Duplicate checksum replayed; no duplicate import created.');
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

        return $payload ? $this->respondSuccess($payload) : $this->respondNotFound('Import not found.');
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

        return $this->respondCreated($this->warrantyPayload($id, $request), 'Warranty case received; stock_effect=NONE.');
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

        return $this->respondSuccess($payload, 'Warranty case updated.');
    }

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

        return $this->respondCreated(DB::table('label_snapshots')->where('id', $id)->first(), 'Immutable label snapshot created.');
    }

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

        return $this->respondCreated(DB::table('printer_configs')->where('id', $id)->first(), 'Printer config created.');
    }

    /** SCR-LBL-01: cập nhật/vô hiệu hóa máy in (health/status quản lý qua API). */
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

        return $this->respondSuccess(DB::table('printer_configs')->where('id', $id)->first(), 'Printer config updated.');
    }

    /** Chi tiết một label snapshot kèm toàn bộ print events (audit In/In lại). */
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

        return $this->respondPaginated($query->paginate(min(100, max(1, (int) $request->input('per_page', 15)))));
    }

    public function printLabelSnapshot(Request $request, string $id)
    {
        return $this->dispatchLabel($request, $id, false);
    }

    public function reprintLabelSnapshot(Request $request, string $id)
    {
        return $this->dispatchLabel($request, $id, true);
    }

    /**
     * Annex C printPackingLabel: POST /outbound-documents/{id}/packing-labels/{label_id}/print.
     * Ràng buộc nhãn phải thuộc đúng phiếu xuất trước khi gửi lệnh in.
     */
    public function printPackingLabelForDocument(Request $request, string $documentId, string $labelId)
    {
        $this->assertLabelBelongsToDocument($documentId, $labelId);

        return $this->dispatchLabel($request, $labelId, false);
    }

    /** Annex C reprintPackingLabel — stock-neutral, dùng lại chính snapshot cũ. */
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

    public function pvCatalog(Request $request)
    {
        $query = DB::table('pv_product_profile as p')
            ->join('sku as s', 's.sku_id', '=', 'p.product_id')
            ->leftJoin('inventory_balances as b', function ($join): void {
                $join->on('b.product_id', '=', 's.sku_id')->where('b.stock_status', '=', 'AVAILABLE');
            })
            ->where('p.published', true)
            ->where('s.status', 'ACTIVE')
            ->selectRaw('p.profile_id, p.display_name, p.description, p.sort_order, p.last_inventory_sync_at, s.sku_id, s.sku_code, s.sku_name, COALESCE(SUM(b.quantity), 0) as available_qty')
            ->groupBy('p.profile_id', 'p.display_name', 'p.description', 'p.sort_order', 'p.last_inventory_sync_at', 's.sku_id', 's.sku_code', 's.sku_name')
            ->orderBy('p.sort_order');
        $this->applyKeyword($query, $request, ['p.display_name', 'p.description', 's.sku_code', 's.sku_name']);

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    public function pvRecommendations(Request $request)
    {
        $validated = $request->validate(['industry_id' => ['required', 'uuid']]);

        return $this->respondPaginated(DB::table('pv_recommendation_rule as r')
            ->join('sku as s', 's.sku_id', '=', 'r.product_id')
            ->join('pv_product_profile as p', 'p.product_id', '=', 's.sku_id')
            ->where('r.industry_id', $validated['industry_id'])
            ->where('r.status', 'ACTIVE')
            ->where('s.status', 'ACTIVE')
            ->where('p.published', true)
            ->orderBy('r.priority')
            ->paginate($this->perPage($request), ['r.*', 'p.profile_id', 'p.display_name']));
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
            $errors[] = $this->rowError($rowNo, 'request_no', 'REQUIRED', 'request_no is required.');
        }
        if ($recipientName === '') {
            $errors[] = $this->rowError($rowNo, 'recipient_name', 'REQUIRED', 'recipient/customer name is required.');
        }
        if ($recipientPhone !== null && ! preg_match('/^\+?[0-9]{6,20}$/', $recipientPhone)) {
            $errors[] = $this->rowError($rowNo, 'recipient_contact_phone', 'INVALID_PHONE', 'recipient_contact_phone chỉ nhận số (6-20 chữ số).');
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
            $errors[] = $this->rowError($rowNo, 'sku_code', 'SKU_NOT_ACTIVE', 'SKU not found or inactive.');
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
            $errors[] = $this->rowError($rowNo, 'required_qty', 'INVALID_QUANTITY', 'required_qty must be a positive integer.');
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
        ]);

        $snapshot = DB::table('label_snapshots')->where('id', $id)->first();
        if ($snapshot === null) {
            return $this->respondNotFound('Label snapshot not found.');
        }
        $this->assertLabelPrintable($request, $snapshot, $reprint);
        $printer = DB::table('printer_configs')->where('id', $validated['printer_config_id'])->first();
        if ($printer === null || ($printer->status ?? 'ACTIVE') !== 'ACTIVE' || empty($printer->agent_endpoint)) {
            return $this->recordPrintFailure($snapshot, $validated['printer_config_id'], 'Printer agent is not ACTIVE/configured.', $reprint);
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
                $error = 'Printer agent rejected command: HTTP '.$response->status();
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $version = ((int) ($snapshot->version ?? 0)) + 1;
        DB::transaction(function () use ($eventId, $id, $validated, $status, $error, $version): void {
            DB::table('label_print_events')->insert([
                'id' => $eventId,
                'label_snapshot_id' => $id,
                'printer_config_id' => $validated['printer_config_id'],
                'status' => $status,
                'error_message' => $error,
                'printed_at' => $status === 'PRINTED' ? now() : null,
                'created_at' => now(),
                'created_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
                'version' => 0,
            ]);
            DB::table('label_snapshots')->where('id', $id)->update([
                'status' => $status,
                'version' => $version,
                'updated_at' => now(),
            ]);
        });

        if ($status !== 'PRINTED') {
            // §5.2 + SCR-LBL-01: print failure chặn completion, tồn/movement không đổi.
            ApiError::abort(409, 'PRINT_FAILED', $error ?? 'Printer failed.', [
                'label_print_event_id' => $eventId,
                'label_status' => 'PRINT_FAILED',
                'version' => $version,
                'reprint' => $reprint,
            ]);
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

    private function recordPrintFailure(object $snapshot, string $printerId, string $message, bool $reprint)
    {
        $eventId = (string) Str::uuid();
        $version = ((int) ($snapshot->version ?? 0)) + 1;
        DB::table('label_print_events')->insert([
            'id' => $eventId,
            'label_snapshot_id' => $snapshot->id,
            'printer_config_id' => $printerId,
            'status' => 'PRINT_FAILED',
            'error_message' => $message,
            'created_at' => now(),
            'created_by' => $this->actorId(),
            'updated_at' => now(),
            'updated_by' => $this->actorId(),
            'version' => 0,
        ]);
        DB::table('label_snapshots')->where('id', $snapshot->id)->update([
            'status' => 'PRINT_FAILED',
            'version' => $version,
            'updated_at' => now(),
        ]);
        ApiError::abort(409, 'PRINT_FAILED', $message, [
            'label_print_event_id' => $eventId,
            'label_status' => 'PRINT_FAILED',
            'version' => $version,
            'reprint' => $reprint,
        ]);
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

    private function actorId(): ?string
    {
        $id = auth('api')->id();

        return $id === null ? null : (string) $id;
    }

    private function mask(string $value): string
    {
        return $value === '' ? '' : mb_substr($value, 0, 1).'***';
    }
}
