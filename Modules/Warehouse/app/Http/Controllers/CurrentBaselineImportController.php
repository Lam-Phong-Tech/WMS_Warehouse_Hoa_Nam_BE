<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Support\MasterSelectionGuard;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CurrentBaselineImportController extends CurrentBaselineOperationsController
{
    /** Danh sách batch import toa xuất (phục vụ báo cáo Excel Import/Match mục 12). */
    public function indexOutboundRequestImports(Request $request)
    {
        $request->validate([
            'status' => ['nullable', 'in:UPLOADED,VALIDATED,REJECTED,CONSUMED'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('outbound_request_imports')->orderByDesc('created_at');
        if ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->input('status')));
        }
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(function ($sub) use ($keyword): void {
                $sub->where('import_no', 'ilike', "%{$keyword}%")
                    ->orWhere('source_file_name', 'ilike', "%{$keyword}%")
                    ->orWhere('source_key', 'ilike', "%{$keyword}%");
            });
        }

        return $this->respondPaginated($query->paginate(min(100, max(1, (int) $request->input('per_page', 15)))));
    }

    /** Template Excel toa yêu cầu xuất — file mẫu bắt buộc cho Ready-for-UAT. */
    public function downloadOutboundRequestTemplate()
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('outbound_request');
        // SRS §5.2: toa mang "số toa - ngày - người nhận" + line hàng;
        // recipient_address/recipient_contact_phone nullable (toa mẫu có thể để trống).
        $sheet->fromArray(
            ['request_no', 'request_date', 'sku_code', 'required_qty', 'recipient_name', 'recipient_address', 'recipient_contact_phone', 'note'],
            null,
            'A1'
        );
        $sheet->fromArray(
            ['REQ-20260813-001', '2026-08-13', 'DCH-DRILL-DJZ10A-220V-V1', 2, 'Nguyễn Văn A', 'Số 12 Hoàng Quốc Việt, Hà Nội', '0901234567', ''],
            null,
            'A2'
        );
        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            'toa-yeu-cau-xuat-template.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function storeOutboundRequestImport(Request $request)
    {
        $sourceKey = trim((string) ($request->input('source_key') ?? $request->header('X-Source-Key')));
        if ($sourceKey !== '') {
            $existingByKey = DB::table('outbound_request_imports')->where('source_key', $sourceKey)->first();
            if ($existingByKey !== null) {
                return $this->respondSuccess($this->importPayloadCurrent((string) $existingByKey->id), 'Source key replayed; no duplicate import created.');
            }
        }

        $response = parent::storeOutboundRequestImport($request);
        $payload = method_exists($response, 'getData') ? $response->getData(true) : [];
        $importId = data_get($payload, 'data.import.id');
        if (! is_string($importId) || $importId === '') {
            return $response;
        }

        DB::transaction(function () use ($importId, $sourceKey): void {
            $import = DB::table('outbound_request_imports')->where('id', $importId)->lockForUpdate()->first();
            if ($import === null) {
                return;
            }

            $canonicalSourceKey = $sourceKey !== '' ? $sourceKey : 'CHECKSUM:'.(string) $import->source_checksum;
            $conflict = DB::table('outbound_request_imports')
                ->where('source_key', $canonicalSourceKey)
                ->where('id', '!=', $importId)
                ->first();
            if ($conflict !== null) {
                if ((string) $conflict->source_checksum === (string) $import->source_checksum) {
                    return;
                }
                ApiError::abort(409, 'SOURCE_KEY_CONFLICT', 'source_key đã được dùng cho payload/file khác.');
            }
            DB::table('outbound_request_imports')->where('id', $importId)->update(['source_key' => $canonicalSourceKey, 'updated_at' => now()]);

            $invalid = 0;
            $rows = DB::table('outbound_request_import_rows')->where('import_id', $importId)->lockForUpdate()->get();
            $recipientByRequest = [];
            foreach ($rows as $row) {
                $normalized = json_decode((string) $row->normalized_payload, true) ?: [];
                $requestNo = trim((string) ($normalized['request_no'] ?? ''));
                if ($requestNo === '') {
                    continue;
                }
                $recipientByRequest[$requestNo] ??= [
                    'recipient_name' => trim((string) ($normalized['recipient_name'] ?? '')),
                    'recipient_address' => trim((string) ($normalized['recipient_address'] ?? '')),
                    'recipient_contact_phone' => trim((string) ($normalized['recipient_contact_phone'] ?? '')),
                    'request_date' => trim((string) ($normalized['request_date'] ?? '')),
                ];
            }

            foreach ($rows as $row) {
                $normalized = json_decode((string) $row->normalized_payload, true) ?: [];
                $errors = json_decode((string) $row->validation_errors, true) ?: [];
                // COMPONENT_OUTBOUND_FORBIDDEN được kiểm ngay trong validateOutboundRow
                // để bước Confirm re-validate cũng áp dụng cùng một luật (§5.4.1).
                $errors = array_merge(
                    $errors,
                    $this->recipientConsistencyErrors($normalized, $recipientByRequest, (int) $row->row_no),
                    $this->duplicateRequestNoErrors($normalized, (int) $row->row_no),
                );
                if ($errors !== []) {
                    $invalid++;
                    $firstError = $errors[0] ?? [];
                    DB::table('outbound_request_import_rows')->where('id', $row->id)->update([
                        'validation_status' => 'ERROR',
                        'error_field' => $firstError['field'] ?? null,
                        'error_code' => $firstError['error_code'] ?? 'ROW_VALIDATION_ERROR',
                        'error_message' => $firstError['message'] ?? 'Row validation failed.',
                        'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
                        'validation_errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }

            if ($invalid > 0) {
                DB::table('outbound_request_imports')->where('id', $importId)->update([
                    'status' => 'REJECTED',
                    'valid_rows' => count($rows) - $invalid,
                    'error_rows' => $invalid,
                    'validated_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('outbound_request_imports')->where('id', $importId)->update([
                    'status' => 'VALIDATED',
                    'validated_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);

        return $this->respondCreated($this->importPayloadCurrent($importId), 'Excel validation completed against PRODUCT-only outbound baseline.');
    }

    /**
     * Annex C confirmOutboundRequestImport (Idempotency-Key + If-Match).
     *
     * §8.1: "ConfirmRequest resolves every source line to SKU + normalized required_qty
     * and may override recipient fields; effective receiver_name is required,
     * address/phone optional. Confirm creates exactly one outbound DRAFT."
     * SCR-REQ-OUT-01 vì thế cho phép map lại SKU/số lượng ngay ở bước review thay vì
     * bắt người dùng upload lại file.
     */
    public function consumeOutboundRequestImport(Request $request, string $id)
    {
        $payload = $this->confirmPayload($request);
        $expectedVersion = $this->expectedImportVersion($request);
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);
        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            "consume_outbound_import_{$id}",
            $this->actorIdCurrent(),
            $hash,
            function () use ($id, $payload, $expectedVersion): array {
                $result = DB::transaction(function () use ($id, $payload, $expectedVersion): array {
                    $import = DB::table('outbound_request_imports')->where('id', $id)->lockForUpdate()->first();
                    if ($import === null) {
                        ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Import not found.');
                    }
                    if ((int) ($import->version ?? 0) !== $expectedVersion) {
                        ApiError::abort(412, 'STALE_VERSION', 'Import version mismatch.', [
                            'expected_version' => $expectedVersion,
                            'current_version' => (int) ($import->version ?? 0),
                        ]);
                    }
                    if (! in_array((string) $import->status, ['VALIDATED', 'REJECTED'], true)) {
                        ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ import VALIDATED/REJECTED (đã resolve) mới được Confirm.');
                    }

                    // Resolve mapping/recipient do người dùng nhập ở màn review trước khi
                    // đánh giá lại blocking error.
                    $blocking = $this->applyConfirmResolutions($id, $payload);
                    if ($blocking !== []) {
                        // Transaction rollback sẽ xoá kết quả re-validate, nên trả nguyên
                        // danh sách lỗi còn lại để FE hiển thị đúng row/field cần sửa.
                        ApiError::abort(409, 'IMPORT_HAS_BLOCKING_ERRORS', 'Import còn row lỗi chưa resolve nên không được Confirm.', [
                            'rows' => $blocking,
                        ]);
                    }

                    $warehouses = DB::table('warehouses')->where('status', 'ACTIVE')->lockForUpdate()->get(['id']);
                    if ($warehouses->count() !== 1) {
                        ApiError::abort(422, 'ACTIVE_WAREHOUSE_BASELINE_VIOLATION', 'MVP yêu cầu chính xác một kho Hoa Nam ACTIVE trước khi consume toa xuất.');
                    }
                    $warehouseId = (string) $warehouses->first()->id;

                    $rows = DB::table('outbound_request_import_rows')
                        ->where('import_id', $id)
                        ->where('validation_status', 'VALID')
                        ->orderBy('row_no')
                        ->get()
                        ->map(fn (object $row): array => json_decode((string) $row->normalized_payload, true) ?: []);
                    if ($rows->isEmpty()) {
                        ApiError::abort(422, 'IMPORT_HAS_NO_VALID_ROWS', 'Import không có row hợp lệ để consume.');
                    }

                    $requestIds = [];
                    $documentIds = [];
                    foreach ($rows->groupBy('request_no') as $requestNo => $group) {
                        $first = $group->first();
                        foreach ($group as $row) {
                            $sku = DB::table('sku')->where('sku_id', $row['sku_id'] ?? null)->first();
                            if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE' || strtoupper((string) $sku->sku_type) !== 'PRODUCT') {
                                ApiError::abort(422, 'PRODUCT_SKU_REQUIRED', 'Toa outbound chỉ được consume khi mọi SKU là PRODUCT ACTIVE.');
                            }
                        }

                        $customerName = trim((string) ($first['customer_name'] ?? $first['recipient_name'] ?? ''));
                        // SRS v1.12 §15 + REQ-SRS-014: receiver_name bắt buộc trước Confirm;
                        // address/phone nullable (toa mẫu khách hàng không có địa chỉ).
                        $customerAddress = trim((string) ($first['customer_address'] ?? $first['recipient_address'] ?? ''));
                        $customerAddress = $customerAddress === '' ? null : $customerAddress;
                        $customerPhone = self::normalizePhone($first['recipient_contact_phone'] ?? $first['customer_phone'] ?? null);
                        if ($customerName === '') {
                            ApiError::abort(422, 'OUTBOUND_RECIPIENT_REQUIRED', 'customer_name (người nhận) bắt buộc trước khi consume toa xuất.');
                        }
                        // AC-GWT-OUTBOUND-REQUEST-001: "một toa chỉ một recipient".
                        foreach ($group as $row) {
                            $rowName = trim((string) ($row['customer_name'] ?? $row['recipient_name'] ?? ''));
                            if ($rowName !== '' && $rowName !== $customerName) {
                                ApiError::abort(422, 'OUTBOUND_REQUEST_MULTIPLE_RECIPIENTS', 'Một toa chỉ được có một người nhận.', [
                                    'request_no' => (string) $requestNo,
                                    'conflicting_recipient' => $rowName,
                                ]);
                            }
                        }
                        // BR-AGY-01: đại lý INACTIVE không được chọn cho chứng từ mới
                        // (guard này trước nằm ở storeOutbound đã gỡ theo §8.1).
                        MasterSelectionGuard::assertAgencySelectable($first['agency_id'] ?? null);
                        // §5.2: "duplicate source/checksum/request no bị chặn" — trả 409 rõ ràng
                        // thay vì để unique index ném 23505/500.
                        if (DB::table('outbound_requests')->where('request_no', (string) $requestNo)->exists()) {
                            ApiError::abort(409, 'DUPLICATE_OUTBOUND_REQUEST_NO', 'Số toa đã tồn tại trong hệ thống.', [
                                'request_no' => (string) $requestNo,
                            ]);
                        }

                        $requestDate = trim((string) ($first['request_date'] ?? '')) !== ''
                            ? (string) $first['request_date']
                            : now()->toDateString();

                        $requestId = (string) Str::uuid();
                        DB::table('outbound_requests')->insert([
                            'id' => $requestId,
                            'request_no' => (string) $requestNo,
                            'import_id' => $id,
                            'agency_id' => $first['agency_id'] ?? null,
                            'customer_name' => $customerName,
                            'customer_address' => $customerAddress,
                            'customer_phone' => $customerPhone,
                            // Compatibility fields remain populated while legacy readers are phased out.
                            'recipient_name' => $customerName,
                            'recipient_address' => $customerAddress,
                            'request_date' => $requestDate,
                            'status' => 'OPEN',
                            'source_checksum' => (string) $import->source_checksum,
                            'created_at' => now(),
                            'created_by' => $this->actorIdCurrent(),
                            'updated_at' => now(),
                            'updated_by' => $this->actorIdCurrent(),
                            'version' => 1,
                        ]);

                        $requestLineIds = [];
                        foreach ($group->values() as $index => $row) {
                            $requestLineId = (string) Str::uuid();
                            DB::table('outbound_request_lines')->insert([
                                'id' => $requestLineId,
                                'outbound_request_id' => $requestId,
                                'line_no' => $index + 1,
                                'sku_id' => $row['sku_id'],
                                'required_qty' => (int) $row['required_qty'],
                                'note' => $row['note'] ?? null,
                                'created_at' => now(),
                                'created_by' => $this->actorIdCurrent(),
                                'updated_at' => now(),
                                'updated_by' => $this->actorIdCurrent(),
                                'version' => 1,
                            ]);
                            $requestLineIds[] = [$requestLineId, $row];
                        }

                        $documentId = (string) Str::uuid();
                        DB::table('stock_documents')->insert([
                            'id' => $documentId,
                            'doc_type' => 'issue',
                            'doc_no' => 'PX-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
                            'doc_date' => now()->toDateString(),
                            'status' => 'DRAFT',
                            'src_warehouse_id' => $warehouseId,
                            'dst_warehouse_id' => null,
                            'outbound_request_id' => $requestId,
                            'agency_id' => $first['agency_id'] ?? null,
                            'recipient_name' => $customerName,
                            'recipient_address' => $customerAddress,
                            'recipient_contact_phone' => $customerPhone,
                            'version' => 1,
                            'created_by' => $this->actorIdCurrent(),
                            'updated_by' => $this->actorIdCurrent(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        foreach ($requestLineIds as $index => [$requestLineId, $row]) {
                            DB::table('stock_document_lines')->insert([
                                'id' => (string) Str::uuid(),
                                'stock_document_id' => $documentId,
                                'outbound_request_line_id' => $requestLineId,
                                'line_no' => $index + 1,
                                'product_id' => $row['sku_id'],
                                'qty_planned' => (int) $row['required_qty'],
                                'qty_actual' => (int) $row['required_qty'],
                                'scanned_qty' => 0,
                                'note' => $row['note'] ?? null,
                                'version' => 1,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        // ERD bảng 37: toa chuyển OPEN -> CONSUMED ngay khi phiếu xuất
                        // được sinh từ toa; Cancel phiếu sẽ trả toa về OPEN.
                        DB::table('outbound_requests')->where('id', $requestId)->update([
                            'status' => 'CONSUMED',
                            'version' => 2,
                            'updated_at' => now(),
                            'updated_by' => $this->actorIdCurrent(),
                        ]);

                        $requestIds[] = $requestId;
                        $documentIds[] = $documentId;
                    }

                    DB::table('outbound_request_imports')->where('id', $id)->update([
                        'status' => 'CONSUMED',
                        'consumed_at' => now(),
                        'consumed_by' => $this->actorIdCurrent(),
                        'updated_at' => now(),
                        'updated_by' => $this->actorIdCurrent(),
                    ]);

                    AuditLogger::record(
                        action: 'OUTBOUND_IMPORT_CONSUME',
                        objectType: 'outbound_request_import',
                        objectId: $id,
                        actorId: $this->actorIdCurrent(),
                        requestId: request()?->attributes->get('request_id'),
                        afterData: ['status' => 'CONSUMED', 'outbound_request_ids' => $requestIds, 'outbound_document_ids' => $documentIds],
                    );

                    return [
                        'import_id' => $id,
                        'status' => 'CONSUMED',
                        'outbound_request_ids' => $requestIds,
                        'outbound_document_ids' => $documentIds,
                    ];
                }, 3);

                return [200, $this->envelopeCurrent('Outbound import consumed; CONSUMED outbound requests and DRAFT outbound documents created.', $result)];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    /**
     * OutboundRequestImportConfirmRequest — mapping từng source line + override
     * người nhận (§8.1). Tất cả field optional để Confirm "sạch" vẫn hoạt động.
     *
     * @return array{lines: array<int,array<string,mixed>>, recipient: array<string,mixed>, requests: array<string,array<string,mixed>>}
     */
    private function confirmPayload(Request $request): array
    {
        $validated = $request->validate([
            'lines' => ['nullable', 'array', 'max:10000'],
            'lines.*.row_no' => ['required_with:lines', 'integer', 'min:1'],
            'lines.*.sku_id' => ['nullable', 'uuid'],
            'lines.*.sku_code' => ['nullable', 'string', 'max:100'],
            'lines.*.required_qty' => ['nullable', 'integer', 'min:1'],
            'receiver_name' => ['nullable', 'string', 'max:200'],
            'receiver_address' => ['nullable', 'string', 'max:1000'],
            'receiver_contact_phone' => ['nullable', 'string', 'max:50'],
            'request_date' => ['nullable', 'date_format:Y-m-d'],
            'requests' => ['nullable', 'array', 'max:500'],
            'requests.*.request_no' => ['required_with:requests', 'string', 'max:80'],
            'requests.*.receiver_name' => ['nullable', 'string', 'max:200'],
            'requests.*.receiver_address' => ['nullable', 'string', 'max:1000'],
            'requests.*.receiver_contact_phone' => ['nullable', 'string', 'max:50'],
            'requests.*.request_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $lines = [];
        foreach ($validated['lines'] ?? [] as $line) {
            $lines[(int) $line['row_no']] = $line;
        }
        $requests = [];
        foreach ($validated['requests'] ?? [] as $override) {
            $requests[trim((string) $override['request_no'])] = $override;
        }

        return [
            'lines' => $lines,
            'recipient' => array_filter([
                'receiver_name' => $validated['receiver_name'] ?? null,
                'receiver_address' => $validated['receiver_address'] ?? null,
                'receiver_contact_phone' => $validated['receiver_contact_phone'] ?? null,
                'request_date' => $validated['request_date'] ?? null,
            ], static fn ($value): bool => $value !== null),
            'requests' => $requests,
        ];
    }

    /**
     * Ghi mapping/override vào từng row rồi validate lại toàn bộ import: row nào
     * hết lỗi thì chuyển VALID, import hết lỗi thì quay lại VALIDATED.
     *
     * @return array<int,array{row_no:int, errors:array<int,array<string,mixed>>}> row còn blocking error
     */
    private function applyConfirmResolutions(string $importId, array $payload): array
    {
        $rows = DB::table('outbound_request_import_rows')
            ->where('import_id', $importId)
            ->orderBy('row_no')
            ->lockForUpdate()
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $resolved = [];
        foreach ($rows as $row) {
            $raw = json_decode((string) $row->raw_payload, true) ?: [];
            $normalized = json_decode((string) $row->normalized_payload, true) ?: [];
            $rowNo = (int) $row->row_no;
            $merged = array_merge($raw, array_filter($normalized, static fn ($value): bool => $value !== null && $value !== ''));

            $lineOverride = $payload['lines'][$rowNo] ?? [];
            if (! empty($lineOverride['sku_id'])) {
                $merged['sku_id'] = (string) $lineOverride['sku_id'];
                unset($merged['sku_code']);
            } elseif (! empty($lineOverride['sku_code'])) {
                $merged['sku_code'] = (string) $lineOverride['sku_code'];
                unset($merged['sku_id']);
            }
            if (! empty($lineOverride['required_qty'])) {
                $merged['required_qty'] = (int) $lineOverride['required_qty'];
            }

            $requestNo = trim((string) ($merged['request_no'] ?? ''));
            $override = $payload['requests'][$requestNo] ?? $payload['recipient'];
            if (! empty($override['receiver_name'])) {
                $merged['recipient_name'] = (string) $override['receiver_name'];
                unset($merged['customer_name']);
            }
            if (array_key_exists('receiver_address', $override)) {
                $merged['recipient_address'] = (string) $override['receiver_address'];
                unset($merged['customer_address']);
            }
            if (array_key_exists('receiver_contact_phone', $override)) {
                $merged['recipient_contact_phone'] = (string) $override['receiver_contact_phone'];
                unset($merged['customer_phone']);
            }
            if (! empty($override['request_date'])) {
                $merged['request_date'] = (string) $override['request_date'];
            }

            [$data, $errors] = $this->validateOutboundRow($merged, $rowNo);
            $resolved[] = ['row' => $row, 'data' => $data, 'errors' => $errors];
        }

        $recipientByRequest = [];
        foreach ($resolved as $entry) {
            $requestNo = trim((string) ($entry['data']['request_no'] ?? ''));
            if ($requestNo === '') {
                continue;
            }
            $recipientByRequest[$requestNo] ??= [
                'recipient_name' => trim((string) ($entry['data']['recipient_name'] ?? '')),
                'recipient_address' => trim((string) ($entry['data']['recipient_address'] ?? '')),
                'recipient_contact_phone' => trim((string) ($entry['data']['recipient_contact_phone'] ?? '')),
                'request_date' => trim((string) ($entry['data']['request_date'] ?? '')),
            ];
        }

        $invalid = 0;
        $blocking = [];
        foreach ($resolved as $entry) {
            $rowNo = (int) $entry['row']->row_no;
            $errors = array_merge(
                $entry['errors'],
                $this->recipientConsistencyErrors($entry['data'], $recipientByRequest, $rowNo),
                $this->duplicateRequestNoErrors($entry['data'], $rowNo),
            );
            $firstError = $errors[0] ?? [];
            if ($errors !== []) {
                $invalid++;
                $blocking[] = ['row_no' => $rowNo, 'errors' => $errors];
            }
            DB::table('outbound_request_import_rows')->where('id', $entry['row']->id)->update([
                'normalized_payload' => json_encode($entry['data'], JSON_UNESCAPED_UNICODE),
                'validation_status' => $errors === [] ? 'VALID' : 'ERROR',
                'error_field' => $firstError['field'] ?? null,
                'error_code' => $firstError['error_code'] ?? null,
                'error_message' => $firstError['message'] ?? null,
                'errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
                'validation_errors' => json_encode($errors, JSON_UNESCAPED_UNICODE),
            ]);
        }

        DB::table('outbound_request_imports')->where('id', $importId)->update([
            'status' => $invalid === 0 ? 'VALIDATED' : 'REJECTED',
            'valid_rows' => count($resolved) - $invalid,
            'error_rows' => $invalid,
            'validated_at' => now(),
            'updated_at' => now(),
            'updated_by' => $this->actorIdCurrent(),
        ]);

        return $blocking;
    }

    /**
     * §5.2 + AC: một toa chỉ một người nhận/một ngày toa.
     *
     * @param  array<string,array<string,string>>  $recipientByRequest
     * @return array<int,array<string,mixed>>
     */
    private function recipientConsistencyErrors(array $normalized, array $recipientByRequest, int $rowNo): array
    {
        $requestNo = trim((string) ($normalized['request_no'] ?? ''));
        $reference = $recipientByRequest[$requestNo] ?? null;
        if ($requestNo === '' || $reference === null) {
            return [];
        }

        $errors = [];
        foreach ($reference as $field => $expected) {
            $actual = trim((string) ($normalized[$field] ?? ''));
            if ($actual !== '' && $expected !== '' && $actual !== $expected) {
                $errors[] = [
                    'row' => $rowNo,
                    'field' => $field,
                    'error_code' => 'OUTBOUND_REQUEST_MULTIPLE_RECIPIENTS',
                    'message' => "Một toa chỉ được có một {$field}; giá trị khác nhau giữa các dòng cùng request_no.",
                ];
            }
        }

        return $errors;
    }

    /**
     * §5.2: "duplicate source/checksum/request no bị chặn" — bắt ngay ở bước preview.
     *
     * @return array<int,array<string,mixed>>
     */
    private function duplicateRequestNoErrors(array $normalized, int $rowNo): array
    {
        $requestNo = trim((string) ($normalized['request_no'] ?? ''));
        if ($requestNo === '' || ! DB::table('outbound_requests')->where('request_no', $requestNo)->exists()) {
            return [];
        }

        return [[
            'row' => $rowNo,
            'field' => 'request_no',
            'error_code' => 'DUPLICATE_OUTBOUND_REQUEST_NO',
            'message' => 'Số toa đã tồn tại trong hệ thống; không tạo phiếu xuất trùng.',
        ]];
    }

    /** Annex C confirmOutboundRequestImport yêu cầu If-Match; import mới có version = 0. */
    private function expectedImportVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc khi Confirm toa xuất.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên không âm.');
        }

        return (int) $header;
    }

    private function importPayloadCurrent(string $id): ?array
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

    private function envelopeCurrent(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => ['request_id' => request()?->attributes->get('request_id'), 'timestamp' => now()->toIso8601String()],
        ];
    }

    private function actorIdCurrent(): string
    {
        $id = auth('api')->id();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Authentication required.');
        }

        return (string) $id;
    }
}
