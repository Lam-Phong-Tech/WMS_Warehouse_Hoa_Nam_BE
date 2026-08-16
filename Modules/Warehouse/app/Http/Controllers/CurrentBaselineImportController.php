<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
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
        $sheet->fromArray(
            ['request_no', 'sku_code', 'required_qty', 'recipient_name', 'recipient_address', 'note'],
            null,
            'A1'
        );
        $sheet->fromArray(
            ['REQ-20260813-001', 'DCH-DRILL-DJZ10A-220V-V1', 2, 'Nguyễn Văn A', 'Số 12 Hoàng Quốc Việt, Hà Nội', ''],
            null,
            'A2'
        );
        foreach (range('A', 'F') as $column) {
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
            foreach ($rows as $row) {
                $normalized = json_decode((string) $row->normalized_payload, true) ?: [];
                $errors = json_decode((string) $row->validation_errors, true) ?: [];
                $skuId = $normalized['sku_id'] ?? null;
                $sku = $skuId ? DB::table('sku')->where('sku_id', $skuId)->first() : null;
                if ($sku !== null && strtoupper((string) $sku->sku_type) !== 'PRODUCT') {
                    $errors[] = [
                        'row' => (int) $row->row_no,
                        'field' => 'sku_code',
                        'error_code' => 'COMPONENT_OUTBOUND_FORBIDDEN',
                        'message' => 'COMPONENT SKU chỉ được xuất qua Component Issue liên kết warranty case active.',
                    ];
                }
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

    public function consumeOutboundRequestImport(Request $request, string $id)
    {
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);
        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            "consume_outbound_import_{$id}",
            $this->actorIdCurrent(),
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
                    if (DB::table('outbound_request_import_rows')->where('import_id', $id)->where('validation_status', '!=', 'VALID')->exists()) {
                        ApiError::abort(409, 'IMPORT_HAS_BLOCKING_ERRORS', 'Import có row lỗi nên không được consume.');
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

                        $customerName = (string) ($first['customer_name'] ?? $first['recipient_name'] ?? '');
                        // SRS v1.12 §15 + REQ-SRS-014: receiver_name bắt buộc trước Confirm;
                        // address/phone nullable (toa mẫu khách hàng không có địa chỉ).
                        $customerAddress = trim((string) ($first['customer_address'] ?? $first['recipient_address'] ?? ''));
                        $customerAddress = $customerAddress === '' ? null : $customerAddress;
                        if ($customerName === '') {
                            ApiError::abort(422, 'OUTBOUND_RECIPIENT_REQUIRED', 'customer_name (người nhận) bắt buộc trước khi consume toa xuất.');
                        }

                        $requestId = (string) Str::uuid();
                        DB::table('outbound_requests')->insert([
                            'id' => $requestId,
                            'request_no' => (string) $requestNo,
                            'import_id' => $id,
                            'agency_id' => $first['agency_id'] ?? null,
                            'customer_name' => $customerName,
                            'customer_address' => $customerAddress,
                            // Compatibility fields remain populated while legacy readers are phased out.
                            'recipient_name' => $customerName,
                            'recipient_address' => $customerAddress,
                            'request_date' => now()->toDateString(),
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
