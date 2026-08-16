<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\WarrantyPiiMasker;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CanonicalMvpController extends Controller
{
    use ApiResponse;

    private const MASTER_TABLES = [
        'brands' => ['code' => 'brand_code', 'name' => 'brand_name'],
        'product_categories' => ['code' => 'category_code', 'name' => 'category_name'],
        'power_sources' => ['code' => 'power_source_code', 'name' => 'power_source_name'],
        'package_variants' => ['code' => 'variant_code', 'name' => 'variant_name'],
    ];

    public function listBrands(Request $request)
    {
        return $this->tablePage($request, 'brands');
    }

    public function storeBrand(Request $request)
    {
        return $this->storeMaster($request, 'brands');
    }

    public function listProductCategories(Request $request)
    {
        return $this->tablePage($request, 'product_categories');
    }

    public function storeProductCategory(Request $request)
    {
        return $this->storeMaster($request, 'product_categories');
    }

    public function listPowerSources(Request $request)
    {
        return $this->tablePage($request, 'power_sources');
    }

    public function storePowerSource(Request $request)
    {
        return $this->storeMaster($request, 'power_sources');
    }

    public function listPackageVariants(Request $request)
    {
        return $this->tablePage($request, 'package_variants');
    }

    public function storePackageVariant(Request $request)
    {
        return $this->storeMaster($request, 'package_variants');
    }

    public function listProductModels(Request $request)
    {
        return $this->tablePage($request, 'product_models');
    }

    public function storeProductModel(Request $request)
    {
        $validated = $request->validate([
            'model_code' => ['required', 'string', 'max:80', 'unique:product_models,model_code'],
            'model_name' => ['required', 'string', 'max:200'],
            'brand_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->insertAndReturn('product_models', $validated);
    }

    public function storeOutboundRequestImport(Request $request)
    {
        $validated = $request->validate([
            'import_no' => ['nullable', 'string', 'max:80', 'unique:outbound_request_imports,import_no'],
            'source_file_name' => ['required', 'string', 'max:255'],
            'source_checksum' => ['required', 'string', 'max:128'],
            'rows' => ['required', 'array', 'min:1', 'max:10000'],
            'rows.*.row_no' => ['required', 'integer', 'min:1'],
            'rows.*.recipient_name' => ['required', 'string', 'max:200'],
            'rows.*.recipient_address' => ['required', 'string', 'max:1000'],
            'rows.*.sku_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'rows.*.required_qty' => ['required', 'integer', 'min:1'],
        ]);

        $id = (string) Str::uuid();
        DB::transaction(function () use ($validated, $id): void {
            DB::table('outbound_request_imports')->insert($this->timestamps([
                'id' => $id,
                'import_no' => $validated['import_no'] ?? ('OBI-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4))),
                'source_file_name' => $validated['source_file_name'],
                'source_checksum' => $validated['source_checksum'],
                'status' => 'VALIDATED',
                'total_rows' => count($validated['rows']),
                'valid_rows' => count($validated['rows']),
                'error_rows' => 0,
                'created_by' => $this->userId(),
                'updated_by' => $this->userId(),
                'version' => 0,
            ]));

            foreach ($validated['rows'] as $row) {
                DB::table('outbound_request_import_rows')->insert([
                    'id' => (string) Str::uuid(),
                    'import_id' => $id,
                    'row_no' => $row['row_no'],
                    'raw_payload' => json_encode($row, JSON_UNESCAPED_UNICODE),
                    'normalized_payload' => json_encode($row, JSON_UNESCAPED_UNICODE),
                    'validation_status' => 'VALID',
                    'validation_errors' => json_encode([], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            }
        });

        return $this->respondCreated($this->importPayload($id), 'Outbound import validated.');
    }

    public function showOutboundRequestImport(string $id)
    {
        $payload = $this->importPayload($id);

        return $payload ? $this->respondSuccess($payload) : $this->respondNotFound('Import not found.');
    }

    public function consumeOutboundRequestImport(Request $request, string $id)
    {
        IdempotencyKeyGuard::require($request);

        $payload = DB::transaction(function () use ($id): array {
            $import = DB::table('outbound_request_imports')->where('id', $id)->lockForUpdate()->first();
            if ($import === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Import not found.');
            }
            if ($import->status !== 'VALIDATED') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Only VALIDATED import can be consumed.');
            }

            $rows = DB::table('outbound_request_import_rows')->where('import_id', $id)->orderBy('row_no')->get();
            $requestId = (string) Str::uuid();
            DB::table('outbound_requests')->insert($this->timestamps([
                'id' => $requestId,
                'request_no' => 'OBR-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'import_id' => $id,
                'recipient_name' => (string) data_get(json_decode((string) $rows->first()->normalized_payload, true), 'recipient_name', ''),
                'recipient_address' => (string) data_get(json_decode((string) $rows->first()->normalized_payload, true), 'recipient_address', ''),
                'request_date' => now()->toDateString(),
                'status' => 'DRAFT',
                'created_by' => $this->userId(),
                'updated_by' => $this->userId(),
                'version' => 0,
            ]));

            foreach ($rows->values() as $index => $row) {
                $data = json_decode((string) $row->normalized_payload, true);
                DB::table('outbound_request_lines')->insert($this->timestamps([
                    'id' => (string) Str::uuid(),
                    'outbound_request_id' => $requestId,
                    'line_no' => $index + 1,
                    'sku_id' => $data['sku_id'],
                    'required_qty' => $data['required_qty'],
                    'created_by' => $this->userId(),
                    'updated_by' => $this->userId(),
                    'version' => 0,
                ]));
            }

            DB::table('outbound_request_imports')->where('id', $id)->update(['status' => 'CONSUMED', 'updated_at' => now()]);

            return ['import_id' => $id, 'outbound_request_id' => $requestId, 'status' => 'CONSUMED'];
        });

        return $this->respondSuccess($payload, 'Outbound import consumed.');
    }

    public function listLabelSnapshots(Request $request)
    {
        return $this->tablePage($request, 'label_snapshots');
    }

    public function storeLabelSnapshot(Request $request)
    {
        $validated = $request->validate([
            'outbound_item_match_id' => ['required', 'uuid'],
            'sku_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'item_id' => ['nullable', 'uuid'],
            'label_payload' => ['required', 'array'],
        ]);

        $validated['label_payload'] = json_encode($validated['label_payload'], JSON_UNESCAPED_UNICODE);
        $validated['status'] = 'PENDING_PRINT';

        return $this->insertAndReturn('label_snapshots', $validated);
    }

    public function printLabelSnapshot(Request $request, string $id)
    {
        return $this->recordPrint($request, $id, false);
    }

    public function reprintLabelSnapshot(Request $request, string $id)
    {
        return $this->recordPrint($request, $id, true);
    }

    public function listPrinterConfigs(Request $request)
    {
        return $this->tablePage($request, 'printer_configs');
    }

    public function storePrinterConfig(Request $request)
    {
        $validated = $request->validate([
            'printer_code' => ['required', 'string', 'max:80', 'unique:printer_configs,printer_code'],
            'printer_name' => ['required', 'string', 'max:200'],
            'agent_code' => ['nullable', 'string', 'max:120'],
            'label_size' => ['nullable', 'string', 'max:80'],
        ]);

        return $this->insertAndReturn('printer_configs', $validated);
    }

    public function listComponentIssueDocuments(Request $request)
    {
        return $this->tablePage($request, 'component_issue_documents', 'created_at', [], ['warranty_case_id', 'warehouse_id']);
    }

    public function storeComponentIssueDocument(Request $request)
    {
        $validated = $request->validate([
            'component_issue_no' => ['nullable', 'string', 'max:80', 'unique:component_issue_documents,component_issue_no'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'warranty_case_id' => ['required', 'uuid', 'exists:warranty_case,warranty_case_id'],
            'document_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'lines.*.required_qty' => ['required', 'integer', 'min:1'],
        ]);

        $case = DB::table('warranty_case')->where('warranty_case_id', $validated['warranty_case_id'])->first();
        if ($case === null || in_array($case->status, ['COMPLETED', 'RETURNED', 'CANCELLED', 'CLOSED'], true)) {
            ApiError::abort(422, 'WARRANTY_CASE_NOT_ACTIVE', 'Component issue requires an active warranty case.');
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($validated, $id): void {
            DB::table('component_issue_documents')->insert($this->timestamps([
                'id' => $id,
                'component_issue_no' => $validated['component_issue_no'] ?? ('CID-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4))),
                'warehouse_id' => $validated['warehouse_id'],
                'warranty_case_id' => $validated['warranty_case_id'],
                'document_date' => $validated['document_date'],
                'status' => 'DRAFT',
                'created_by' => $this->userId(),
                'updated_by' => $this->userId(),
                'version' => 0,
            ]));

            foreach (array_values($validated['lines']) as $index => $line) {
                DB::table('component_issue_lines')->insert($this->timestamps([
                    'id' => (string) Str::uuid(),
                    'component_issue_document_id' => $id,
                    'line_no' => $index + 1,
                    'sku_id' => $line['sku_id'],
                    'required_qty' => $line['required_qty'],
                    'scanned_qty' => 0,
                    'created_by' => $this->userId(),
                    'updated_by' => $this->userId(),
                    'version' => 0,
                ]));
            }
        });

        return $this->respondCreated($this->componentIssuePayload($id), 'Component issue document created.');
    }

    public function postComponentIssueDocument(Request $request, string $id)
    {
        IdempotencyKeyGuard::require($request);
        $doc = DB::table('component_issue_documents')->where('id', $id)->first();
        if ($doc === null) {
            return $this->respondNotFound('Component issue document not found.');
        }
        if ($doc->status !== 'DRAFT') {
            ApiError::abort(409, 'STATE_CONFLICT', 'Only DRAFT component issue can be posted.');
        }

        DB::table('component_issue_documents')->where('id', $id)->update([
            'status' => 'POSTED',
            'posted_at' => now(),
            'posted_by' => $this->userId(),
            'updated_at' => now(),
            'updated_by' => $this->userId(),
        ]);

        return $this->respondSuccess($this->componentIssuePayload($id), 'Component issue posted.');
    }

    public function listWarrantyCases(Request $request)
    {
        return $this->tablePage(
            $request,
            'warranty_case',
            'created_at',
            ['customer_name', 'customer_phone', 'customer_address'],
            ['identification_status', 'defect_id'],
        );
    }

    public function storeWarrantyCase(Request $request)
    {
        $validated = $request->validate([
            'warranty_case_code' => ['nullable', 'string', 'max:80', 'unique:warranty_case,warranty_case_code'],
            'inventory_serial_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid', 'exists:sku,sku_id'],
            'defect_id' => ['nullable', 'uuid', 'exists:defect_catalog,defect_id'],
            'customer_name' => ['nullable', 'string', 'max:200'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['warranty_case_id'] = (string) Str::uuid();
        $validated['warranty_case_code'] ??= 'WC-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
        $validated['status'] = 'RECEIVED';
        $validated['identification_status'] = $validated['inventory_serial_id'] ? 'IDENTIFIED' : 'TEMP_ONLY';
        $validated['created_by'] = $this->userId();
        $validated['version'] = 1;
        $validated['created_at'] = now();
        $validated['updated_at'] = now();

        DB::table('warranty_case')->insert($validated);

        return $this->respondCreated(DB::table('warranty_case')->where('warranty_case_id', $validated['warranty_case_id'])->first(), 'Warranty case created.');
    }

    public function updateWarrantyCase(Request $request, string $id)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:RECEIVED,CHECKING,REPAIRING,COMPLETED,RETURNED,CANCELLED'],
            'condition_status' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        $case = DB::table('warranty_case')->where('warranty_case_id', $id)->first();
        if ($case === null) {
            return $this->respondNotFound('Warranty case not found.');
        }
        if ((int) $case->version !== (int) $validated['version']) {
            ApiError::abort(412, 'PRECONDITION_FAILED', 'Warranty case version mismatch.');
        }

        $from = $case->status;
        unset($validated['version']);
        $validated['version'] = ((int) $case->version) + 1;
        $validated['updated_at'] = now();

        DB::table('warranty_case')->where('warranty_case_id', $id)->update($validated);
        if (isset($validated['status']) && $validated['status'] !== $from) {
            DB::table('warranty_event')->insert([
                'warranty_event_id' => (string) Str::uuid(),
                'warranty_case_id' => $id,
                'event_type' => 'STATUS_CHANGE',
                'from_status' => $from,
                'to_status' => $validated['status'],
                'note' => $request->input('description'),
                'created_by' => $this->userId(),
                'event_at' => now(),
            ]);
        }

        return $this->respondSuccess(DB::table('warranty_case')->where('warranty_case_id', $id)->first(), 'Warranty case updated.');
    }

    public function listWarrantyEvents(Request $request, string $id)
    {
        $query = DB::table('warranty_event')
            ->where('warranty_case_id', $id);
        $this->applyKeyword($query, $request, ['event_type', 'from_status', 'to_status', 'note']);

        return $this->respondPaginated(
            $query->orderByDesc('event_at')->paginate($this->perPage($request))
        );
    }

    public function pvCatalog(Request $request)
    {
        $query = DB::table('pv_product_profile as p')
            ->join('sku as s', 's.sku_id', '=', 'p.product_id')
            ->leftJoin('inventory_balances as b', 'b.product_id', '=', 's.sku_id')
            ->where('p.published', true)
            ->selectRaw('p.profile_id, p.display_name, p.description, p.sort_order, p.last_inventory_sync_at, s.sku_id, s.sku_code, s.sku_name, COALESCE(SUM(b.available_qty), 0) as available_qty')
            ->groupBy('p.profile_id', 'p.display_name', 'p.description', 'p.sort_order', 'p.last_inventory_sync_at', 's.sku_id', 's.sku_code', 's.sku_name')
            ->orderBy('p.sort_order');
        $this->applyKeyword($query, $request, ['p.display_name', 'p.description', 's.sku_code', 's.sku_name']);

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    public function pvIndustries(Request $request)
    {
        return $this->publicActivePage($request, 'pv_industry', 'sort_order');
    }

    public function pvTags(Request $request)
    {
        return $this->publicActivePage($request, 'pv_filter_tag', 'sort_order');
    }

    public function pvRecommendations(Request $request)
    {
        $request->validate(['industry_id' => ['required', 'uuid']]);

        return $this->respondPaginated(DB::table('pv_recommendation_rule')
            ->where('industry_id', $request->input('industry_id'))
            ->where('status', 'ACTIVE')
            ->orderBy('priority')
            ->paginate($this->perPage($request)));
    }

    private function storeMaster(Request $request, string $table)
    {
        $columns = self::MASTER_TABLES[$table];
        $validated = $request->validate([
            $columns['code'] => ['required', 'string', 'max:80', "unique:{$table},{$columns['code']}"],
            $columns['name'] => ['required', 'string', 'max:200'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->insertAndReturn($table, $validated);
    }

    private function tablePage(Request $request, string $table, string $orderBy = 'created_at', array $masked = [], array $filterColumns = [])
    {
        $query = DB::table($table);
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        foreach ($filterColumns as $column) {
            if ($request->filled($column) && Schema::hasColumn($table, $column)) {
                $query->where($column, $request->input($column));
            }
        }
        $keywordColumns = $this->keywordColumns($table);
        $piiHidden = $masked !== [] && ! $request->user()?->hasPermission('warranty.pii.view');
        if ($piiHidden) {
            // §13.8: masking phải đi kèm chặn search LIKE trên cột PII, nếu không
            // keyword search trở thành oracle dò tên/SĐT đầy đủ từng ký tự.
            $keywordColumns = array_values(array_diff($keywordColumns, $masked));
        }
        $this->applyKeyword($query, $request, $keywordColumns);

        $page = $query->orderByDesc($orderBy)->paginate($this->perPage($request));
        if ($piiHidden) {
            $page->getCollection()->transform(function (object $row) use ($masked): object {
                foreach ($masked as $field) {
                    if (isset($row->{$field})) {
                        $row->{$field} = $this->maskPii($field, (string) $row->{$field});
                    }
                }

                return $row;
            });
        }

        return $this->respondPaginated($page);
    }

    private function insertAndReturn(string $table, array $data)
    {
        $id = (string) Str::uuid();
        DB::table($table)->insert($this->timestamps(['id' => $id] + $data + [
            'status' => $data['status'] ?? 'ACTIVE',
            'sort_order' => $data['sort_order'] ?? 0,
            'created_by' => $this->userId(),
            'updated_by' => $this->userId(),
            'version' => 0,
        ]));

        return $this->respondCreated(DB::table($table)->where('id', $id)->first(), 'Created.');
    }

    private function recordPrint(Request $request, string $id, bool $reprint)
    {
        $validated = $request->validate([
            'printer_config_id' => ['nullable', 'uuid'],
        ]);

        $snapshot = DB::table('label_snapshots')->where('id', $id)->first();
        if ($snapshot === null) {
            return $this->respondNotFound('Label snapshot not found.');
        }

        $eventId = (string) Str::uuid();
        DB::table('label_print_events')->insert($this->timestamps([
            'id' => $eventId,
            'label_snapshot_id' => $id,
            'printer_config_id' => $validated['printer_config_id'] ?? null,
            'status' => 'PRINTED',
            'printed_at' => now(),
            'created_by' => $this->userId(),
            'updated_by' => $this->userId(),
            'version' => 0,
        ]));
        DB::table('label_snapshots')->where('id', $id)->update(['status' => 'PRINTED', 'updated_at' => now()]);

        return $this->respondSuccess([
            'label_snapshot_id' => $id,
            'label_print_event_id' => $eventId,
            'reprint' => $reprint,
            'stock_effect' => 'NONE',
        ], $reprint ? 'Label reprinted.' : 'Label printed.');
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

    private function componentIssuePayload(string $id): ?array
    {
        $doc = DB::table('component_issue_documents')->where('id', $id)->first();
        if ($doc === null) {
            return null;
        }

        return [
            'document' => $doc,
            'lines' => DB::table('component_issue_lines')->where('component_issue_document_id', $id)->orderBy('line_no')->get(),
        ];
    }

    private function publicActivePage(Request $request, string $table, string $orderBy)
    {
        $query = DB::table($table)->where('status', 'ACTIVE');
        $this->applyKeyword($query, $request, $this->keywordColumns($table));

        return $this->respondPaginated($query->orderBy($orderBy)->paginate($this->perPage($request, 50)));
    }

    /**
     * @return array<int, string>
     */
    private function keywordColumns(string $table): array
    {
        $candidates = [
            'brands' => ['brand_code', 'brand_name'],
            'product_categories' => ['category_code', 'category_name'],
            'power_sources' => ['power_source_code', 'power_source_name'],
            'product_models' => ['model_code', 'model_name'],
            'package_variants' => ['package_code', 'package_name', 'variant_code', 'variant_name'],
            'label_snapshots' => ['status'],
            'printer_configs' => ['printer_code', 'printer_name', 'agent_code', 'label_size'],
            'component_issue_documents' => ['component_issue_no', 'status'],
            'warranty_case' => ['warranty_case_code', 'customer_name', 'customer_phone', 'temporary_item_code', 'status'],
            'pv_industry' => ['industry_code', 'industry_name', 'display_name'],
            'pv_filter_tag' => ['tag_code', 'tag_name', 'display_name'],
        ][$table] ?? [];

        return array_values(array_filter($candidates, fn (string $column): bool => Schema::hasColumn($table, $column)));
    }

    private function timestamps(array $data): array
    {
        return $data + ['created_at' => now(), 'updated_at' => now()];
    }

    private function userId(): ?string
    {
        $id = auth('api')->id();

        return is_string($id) ? $id : null;
    }

    /** §13.8: mỗi loại PII có shape mask riêng (name/phone/address). */
    private function maskPii(string $field, string $value): string
    {
        return match ($field) {
            'customer_phone' => WarrantyPiiMasker::phone($value),
            'customer_address' => WarrantyPiiMasker::address($value),
            default => WarrantyPiiMasker::name($value),
        };
    }
}
