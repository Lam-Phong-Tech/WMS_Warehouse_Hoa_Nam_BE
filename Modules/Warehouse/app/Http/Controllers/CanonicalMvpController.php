<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Database\SchemaCache;
use App\Support\IdempotencyKeyGuard;
use App\Support\Validation\InputLimits;
use App\Support\WarrantyPiiMasker;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\ListSortOrder;
use Modules\Warehouse\Support\SkuImageLookup;
use Modules\Warehouse\Support\WarrantyCasePii;
use Modules\Warehouse\Support\WarrantyOverdue;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use OpenApi\Attributes as OA;

/**
 * DEV handover QA 2026-08-20 (HN-BE-API-005, đợt 3) — chỉ 6 trong số các public
 * method dưới đây có route thật đang publish (xác nhận bằng `php artisan
 * route:list`): pvTags, listLabelSnapshots, listPrinterConfigs,
 * listComponentIssueDocuments, listWarrantyCases, listWarrantyEvents. Các
 * method còn lại (listBrands/storeBrand/storeOutboundRequestImport/pvCatalog/...)
 * không được route nào trỏ tới nên KHÔNG khai #[OA\...] cho chúng —
 * RouteSyncProcessor tự loại annotation không khớp route table thật.
 */
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
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:'.InputLimits::SORT_ORDER_MAX],
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
            // row_no ghi vào cột integer của Postgres — trần là ràng buộc kỹ thuật (int4), không phải nghiệp vụ.
            'rows.*.row_no' => ['required', 'integer', 'min:1', 'max:'.InputLimits::VERSION_MAX],
            'rows.*.recipient_name' => ['required', 'string', 'max:200'],
            'rows.*.recipient_address' => ['required', 'string', 'max:1000'],
            'rows.*.sku_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'rows.*.required_qty' => ['required', 'integer', 'min:1', 'max:'.InputLimits::QUANTITY_MAX],
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

        return $this->respondCreated($this->importPayload($id), 'Đã kiểm tra Import toa xuất.');
    }

    public function showOutboundRequestImport(string $id)
    {
        $payload = $this->importPayload($id);

        return $payload ? $this->respondSuccess($payload) : $this->respondNotFound('Không tìm thấy Import.');
    }

    public function consumeOutboundRequestImport(Request $request, string $id)
    {
        IdempotencyKeyGuard::require($request);

        $payload = DB::transaction(function () use ($id): array {
            $import = DB::table('outbound_request_imports')->where('id', $id)->lockForUpdate()->first();
            if ($import === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy Import.');
            }
            if ($import->status !== 'VALIDATED') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ Import ở trạng thái VALIDATED mới được xử lý.');
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

        return $this->respondSuccess($payload, 'Đã xử lý Import toa xuất.');
    }

    #[OA\Get(
        path: '/api/v1/label-snapshots',
        operationId: 'canonical_mvp_list_label_snapshots',
        summary: 'Danh sách snapshot nhãn',
        security: [['bearerAuth' => []]],
        tags: ['19. Label / Printer'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trang danh sách label_snapshots'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền label.print'),
        ]
    )]
    public function listLabelSnapshots(Request $request)
    {
        // `outbound_document_id` là bộ lọc BỔ SUNG, không bắt buộc — client nào
        // không gửi thì nhận đúng kết quả như trước, hợp đồng không đổi.
        //
        // Vì sao thêm: màn chi tiết phiếu xuất chỉ cần nhãn CỦA ĐÚNG phiếu đang
        // mở, nhưng trước đó FE phải kéo NGUYÊN bảng label_snapshots về (vòng lặp
        // phân trang TUẦN TỰ, tối đa 20 trang × 100 dòng) rồi tự `find()` lấy một
        // dòng. Số nhãn chỉ tăng theo thời gian, nên mỗi lần mở modal lại đắt hơn
        // lần trước — đúng loại chi phí không ai nhìn thấy cho tới lúc dữ liệu lớn.
        return $this->tablePage($request, 'label_snapshots', 'created_at', [], ['outbound_document_id']);
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

    #[OA\Get(
        path: '/api/v1/printer-configs',
        operationId: 'canonical_mvp_list_printer_configs',
        summary: 'Danh sách cấu hình máy in',
        security: [['bearerAuth' => []]],
        tags: ['19. Label / Printer'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trang danh sách printer_configs'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền label.print'),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/component-issue-documents',
        operationId: 'canonical_mvp_list_component_issue_documents',
        summary: 'Danh sách phiếu xuất linh kiện bảo hành',
        security: [['bearerAuth' => []]],
        tags: ['14. Component Issue'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warranty_case_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trang danh sách component_issue_documents'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warranty.component_issue'),
        ]
    )]
    #[OA\Get(
        path: '/api/v1/warranty-component-issues',
        operationId: 'canonical_mvp_list_warranty_component_issues',
        summary: 'Danh sách phiếu xuất linh kiện bảo hành (Annex C)',
        security: [['bearerAuth' => []]],
        tags: ['14. Component Issue'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warranty_case_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', description: 'Inclusive document_date lower bound', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', description: 'Inclusive document_date upper bound', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trang danh sách component_issue_documents (Annex C alias)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warranty.component_issue'),
        ]
    )]
    public function listComponentIssueDocuments(Request $request)
    {
        $dateToRules = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('date_from')) {
            $dateToRules[] = 'after_or_equal:date_from';
        }
        $dates = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => $dateToRules,
        ]);

        return $this->tablePage($request, 'component_issue_documents', 'created_at', [], ['warranty_case_id', 'warehouse_id'],
            filterExtension: static function ($query) use ($dates): void {
                if (! empty($dates['date_from'])) {
                    $query->whereDate('document_date', '>=', $dates['date_from']);
                }
                if (! empty($dates['date_to'])) {
                    $query->whereDate('document_date', '<=', $dates['date_to']);
                }
            });
    }

    // storeComponentIssueDocument/postComponentIssueDocument/storeWarrantyCase/
    // updateWarrantyCase đã được gỡ: không route nào trỏ tới, đồng thời chúng mang
    // nghiệp vụ lệch docs (Post không sinh Inventory_Movement, thiếu guard SKU
    // COMPONENT, coi COMPLETED là case không active). Bề mặt duy nhất của luồng 04 là
    // CurrentBaselineWarrantyController + CurrentBaselineComponentFlowController.

    #[OA\Get(
        path: '/api/v1/warranty-cases',
        operationId: 'canonical_mvp_list_warranty_cases',
        summary: 'Danh sách hồ sơ bảo hành',
        security: [['bearerAuth' => []]],
        tags: ['13. Warranty'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', description: 'Một trạng thái, hoặc nhiều trạng thái phân tách bằng dấu phẩy — ví dụ `CHECKING,REPAIRING` cho màn xuất linh kiện.', schema: new OA\Schema(type: 'string', example: 'CHECKING,REPAIRING')),
            new OA\Parameter(name: 'identification_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['IDENTIFIED', 'TEMP_ONLY'])),
            new OA\Parameter(name: 'defect_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'overdue', in: 'query', description: '1 = chỉ hồ sơ chưa kết thúc có estimated_completion_date trước hôm nay (Asia/Ho_Chi_Minh), giống Dashboard. Không gửi, 0 hoặc rỗng = tất cả.', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'keyword', in: 'query', description: 'Khớp mã hồ sơ / mã tạm, serial + mã vật lý của item gắn case, mã hoặc tên SKU sản phẩm, mô tả sản phẩm nhập tay (TEMP_ONLY), và customer_name/customer_phone qua blind index — chỉ khi có quyền warranty.pii.view (tên khớp trọn từ không dấu, SĐT khớp chính xác; địa chỉ không tìm được sau WWHN-SEC-06)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'sort_order', in: 'query', required: false, description: 'Sắp xếp theo thời điểm tạo/sửa gần nhất (COALESCE(updated_at, created_at)): desc = Mới nhất trước, asc = Cũ nhất trước. Không gửi = giữ thứ tự mặc định cũ (created_at desc). Giá trị khác trả 422.', schema: new OA\Schema(type: 'string', enum: ['desc', 'asc'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trang danh sách warranty_case; mỗi item kèm cờ pii_masked (§13.8)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warranty.view hoặc warranty.manage'),
            new OA\Response(response: 422, description: 'overdue không phải boolean 0/1, sort_order lạ, hoặc bộ lọc đã gửi không dùng được trên schema hiện tại (FILTER_NOT_SUPPORTED) — KHÔNG bao giờ trả danh sách chưa lọc dưới vỏ 200'),
        ]
    )]
    public function listWarrantyCases(Request $request)
    {
        $request->validate(['overdue' => ['nullable', 'boolean']]);

        return $this->tablePage(
            $request,
            'warranty_case',
            'created_at',
            ['customer_name', 'customer_phone', 'customer_address'],
            ['identification_status'],
            // Wireframe WAR-01 cho tìm cả "mã máy": serial của item gắn case và mọi
            // Physical_Code ACTIVE map vào item đó. Cả hai đều không phải cột của
            // warranty_case nên phải bơm thêm nhánh OR bằng subquery item_id.
            function ($query, string $keyword, string $operator) use ($request): void {
                $query->orWhereIn('item_id', function ($sub) use ($keyword, $operator): void {
                    $sub->select('item_id')->from('item_instances')
                        ->where('serial_number', $operator, "%{$keyword}%");
                });
                $query->orWhereIn('item_id', function ($sub) use ($keyword, $operator): void {
                    $sub->select('assigned_object_id')->from('physical_codes')
                        ->where('assigned_object_type', 'ITEM')
                        ->where('status', 'ACTIVE')
                        ->where('code_value', $operator, "%{$keyword}%");
                });

                // Cột "Sản phẩm" của WAR-01 hiển thị sku_code (hoặc sku_name khi
                // thiếu mã), nên ô tìm kiếm phải khớp được đúng thứ người dùng đang
                // nhìn. warranty_case chỉ giữ product_id (uuid) — cùng lý do với
                // serial/Physical_Code ở trên, phải đi bằng subquery chứ không JOIN:
                // cả `sku` lẫn `warranty_case` đều có cột `status`, JOIN sẽ làm mọi
                // `where('status', ...)` phía ngoài thành ambiguous column trên
                // PostgreSQL.
                $query->orWhereIn('product_id', function ($sub) use ($keyword, $operator): void {
                    $sub->select('sku_id')->from('sku')
                        ->where('sku_code', $operator, "%{$keyword}%")
                        ->orWhere('sku_name', $operator, "%{$keyword}%");
                });

                // WWHN-SEC-06: PII đã mã hoá nên không LIKE được nữa; tìm qua
                // blind index. Ngữ nghĩa mới: SĐT khớp CHÍNH XÁC sau chuẩn hoá,
                // tên khớp TRỌN TỪ không dấu, địa chỉ không còn tìm được.
                //
                // Vẫn tôn trọng §13.8: thiếu `warranty.pii.view` thì KHÔNG được
                // tìm theo PII — nếu không, ô tìm kiếm trở thành oracle để dò
                // từng số điện thoại mà không cần quyền đọc.
                if ($request->user()?->hasPermission('warranty.pii.view')) {
                    WarrantyCasePii::applyKeyword($query, $keyword);
                }
            },
            // FE cột "Sản phẩm" (WAR-01) đọc row.sku/row.sku_code/row.sku_name — nhưng
            // warranty_case chỉ lưu product_id (uuid). Không JOIN thẳng vào query
            // chính vì cả 2 bảng đều có cột `status` (JOIN sẽ làm mọi
            // `$query->where('status', ...)` phía trên ném lỗi ambiguous column trên
            // PostgreSQL) — enrich SAU KHI đã phân trang, gom 1 query duy nhất theo
            // các product_id có mặt trong trang hiện tại (không phải N+1).
            function ($rows): void {
                // WWHN-SEC-06: giải mã NGAY đầu enricher — tablePage() chạy lớp
                // mask §13.8 SAU enricher, nên đây là chỗ duy nhất còn kịp để lớp
                // mask nhận được giá trị thật thay vì ciphertext.
                WarrantyCasePii::decodeRows($rows);
                WarrantyWarehouseScope::enrich($rows);

                $productIds = $rows->pluck('product_id')->filter()->unique()->values()->all();
                $imageBySku = SkuImageLookup::forSkuIds($productIds);
                if ($productIds !== []) {
                    $skus = DB::table('sku')->whereIn('sku_id', $productIds)
                        ->get(['sku_id', 'sku_code', 'sku_name'])
                        ->keyBy('sku_id');
                    foreach ($rows as $row) {
                        $sku = $row->product_id !== null ? $skus->get($row->product_id) : null;
                        $row->sku_code = $sku->sku_code ?? null;
                        $row->sku_name = $sku->sku_name ?? null;
                        $row->image_url = $row->product_id !== null
                            ? ($imageBySku[(string) $row->product_id] ?? null)
                            : null;
                    }
                } else {
                    foreach ($rows as $row) {
                        $row->image_url = null;
                    }
                }

                // GĐ1-REQ-009: WAR-01 hiển thị đủ multi-defect — enrich sau phân trang,
                // gom một query warranty_case_defects × defect_catalogs cho trang hiện tại.
                $caseIds = $rows->pluck('warranty_case_id')->filter()->unique()->values()->all();
                $defectsByCase = $caseIds === []
                    ? collect()
                    : DB::table('warranty_case_defects as wcd')
                        ->join('defect_catalogs as dc', 'dc.defect_id', '=', 'wcd.defect_id')
                        ->whereIn('wcd.warranty_case_id', $caseIds)
                        ->orderByDesc('wcd.is_primary')
                        ->orderBy('dc.defect_code')
                        ->get(['wcd.warranty_case_id', 'wcd.defect_id', 'wcd.is_primary', 'dc.defect_code', 'dc.defect_name'])
                        ->groupBy('warranty_case_id');
                foreach ($rows as $row) {
                    $defects = collect($defectsByCase->get((string) $row->warranty_case_id) ?? [])
                        ->map(fn (object $defect): array => [
                            'defect_id' => (string) $defect->defect_id,
                            'defect_code' => (string) $defect->defect_code,
                            'defect_name' => (string) $defect->defect_name,
                            'is_primary' => (bool) $defect->is_primary,
                        ])->values();
                    $row->defects = $defects->all();
                    $row->defect_ids = $defects->pluck('defect_id')->values()->all();
                }
            },
            // UAT TC_FUNC_02 — lọc "Bệnh/lỗi" phải khớp CẢ bệnh phụ: cột
            // defect_id của view chỉ giữ bệnh CHÍNH, trong khi WAR-01 hiển thị
            // đủ multi-defect (GĐ1-REQ-009). Lọc theo cột đơn bỏ sót hồ sơ có
            // bệnh này ở vị trí phụ — người dùng đối chiếu với cột "Bệnh/lỗi"
            // trên màn sẽ thấy "lọc không ăn".
            function ($query, Request $request): void {
                if ($request->boolean('overdue')) {
                    WarrantyOverdue::apply($query);
                }
                if (! $request->filled('defect_id')) {
                    return;
                }
                $defectId = (string) $request->input('defect_id');
                $query->where(function ($nested) use ($defectId): void {
                    $nested->where('defect_id', $defectId)
                        ->orWhereIn('warranty_case_id', function ($sub) use ($defectId): void {
                            $sub->select('warranty_case_id')
                                ->from('warranty_case_defects')
                                ->where('defect_id', $defectId);
                        });
                });
            },
        );
    }

    #[OA\Get(
        path: '/api/v1/warranty-cases/{id}/events',
        operationId: 'canonical_mvp_list_warranty_events',
        summary: 'Lịch sử sự kiện của hồ sơ bảo hành',
        security: [['bearerAuth' => []]],
        tags: ['13. Warranty'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'keyword', in: 'query', description: 'Khớp event_type/from_status/to_status/note', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trang danh sách warranty_event của hồ sơ, sắp xếp event_at giảm dần'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warranty.view hoặc warranty.manage'),
        ]
    )]
    public function listWarrantyEvents(Request $request, string $id)
    {
        // FE hiển thị performed_by trên timeline (WarrantyDetailModal) — thiếu join này
        // thì FE chỉ còn created_by (UUID actor thô) để rơi vào, hiện thẳng UUID cho
        // người dùng đọc thay vì tên nhân viên.
        $query = DB::table('warranty_event as e')
            ->leftJoin('app_user as u', 'u.user_id', '=', 'e.created_by')
            ->where('e.warranty_case_id', $id)
            ->select(['e.*', 'u.display_name as performed_by_name']);
        $this->applyKeyword($query, $request, ['e.event_type', 'e.from_status', 'e.to_status', 'e.note']);

        return $this->respondPaginated(
            $query->orderByDesc('e.event_at')->paginate($this->perPage($request))
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

    #[OA\Get(
        path: '/api/v1/pv/tags',
        operationId: 'canonical_mvp_pv_tags',
        summary: 'Danh sách tag PV',
        security: [],
        tags: ['21. Public Catalog'],
        parameters: [
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trang danh sách pv_filter_tag đang ACTIVE'),
            new OA\Response(response: 429, description: 'Vượt rate limit pv-public'),
        ]
    )]
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
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:'.InputLimits::SORT_ORDER_MAX],
        ]);

        return $this->insertAndReturn($table, $validated);
    }

    private function tablePage(Request $request, string $table, string $orderBy = 'created_at', array $masked = [], array $filterColumns = [], ?callable $keywordExtension = null, ?callable $collectionEnricher = null, ?callable $filterExtension = null)
    {
        $query = $table === 'warranty_case'
            ? WarrantyWarehouseScope::query($request)
            : DB::table($table);
        if ($request->filled('status')) {
            // Nhận cả một giá trị lẫn danh sách phân tách bằng dấu phẩy
            // (`?status=CHECKING,REPAIRING`). Màn "Xuất linh kiện" cần đúng hai
            // trạng thái Đang kiểm tra + Đang sửa chữa; trước đây phải gọi hai lần
            // rồi tự trộn ở client — vừa sai phân trang, vừa sai tổng số dòng.
            // Không enum nào của WMS chứa dấu phẩy nên tách như vậy là an toàn.
            $statuses = array_values(array_filter(array_map(
                static fn (string $value): string => trim($value),
                explode(',', (string) $request->input('status'))
            ), static fn (string $value): bool => $value !== ''));

            if (count($statuses) > 1) {
                $query->whereIn('status', $statuses);
            } else {
                $query->where('status', $statuses[0] ?? $request->input('status'));
            }
        }
        // hasRelationColumn chứ không hasColumn: `warranty_case` là VIEW trên
        // PostgreSQL và hasColumn() trả false cho mọi cột của view — bộ lọc sẽ
        // biến mất không một tiếng động. Xem SchemaCache::relationColumns().
        //
        // UAT TC_FUNC_02 (WWHN-340): khi client ĐÃ GỬI một bộ lọc mà quan hệ lại
        // thiếu đúng cột đó thì KHÔNG được lặng lẽ bỏ qua. Bỏ qua nghĩa là trả về
        // TOÀN BỘ danh sách chưa lọc dưới cái vỏ 200 OK — người dùng không có cách
        // nào biết mình đang nhìn dữ liệu sai, và đó chính là cách lỗi này sống
        // sót tới tận UAT. Thiếu cột ở đây luôn là lệch schema/deploy chứ không
        // phải input sai, nên trả lỗi to hơn nhiều so với nói dối im lặng.
        foreach ($filterColumns as $column) {
            if (! $request->filled($column)) {
                continue;
            }
            if (! SchemaCache::hasRelationColumn($table, $column)) {
                ApiError::abort(422, 'FILTER_NOT_SUPPORTED', "Bộ lọc `{$column}` không dùng được trên dữ liệu hiện tại.", [
                    'filter' => $column,
                    'relation' => $table,
                ]);
            }
            $query->where($column, $request->input($column));
        }
        if ($filterExtension !== null) {
            $filterExtension($query, $request);
        }
        $keywordColumns = $this->keywordColumns($table);
        $piiHidden = $masked !== [] && ! $request->user()?->hasPermission('warranty.pii.view');
        if ($piiHidden) {
            // §13.8: masking phải đi kèm chặn search LIKE trên cột PII, nếu không
            // keyword search trở thành oracle dò tên/SĐT đầy đủ từng ký tự.
            $keywordColumns = array_values(array_diff($keywordColumns, $masked));
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keywordExtension !== null && $keyword !== '') {
            // Gom cả cột thường và nhánh bổ sung vào MỘT nhóm where lồng nhau để OR
            // không tràn ra ngoài và vô hiệu các filter status/identification_status.
            $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($nested) use ($keywordColumns, $keyword, $operator, $keywordExtension): void {
                foreach ($keywordColumns as $column) {
                    $nested->orWhere($column, $operator, "%{$keyword}%");
                }
                $keywordExtension($nested, $keyword, $operator);
            });
        } else {
            $this->applyKeyword($query, $request, $keywordColumns);
        }

        // "Sắp xếp theo" (WAR-01 và các danh sách tablePage khác): có sort_order
        // thì xếp theo tạo/sửa gần nhất, vắng mặt thì giữ nguyên desc cũ.
        $sortOrder = ListSortOrder::fromRequest($request);
        if ($sortOrder === null) {
            $query->orderByDesc($orderBy);
            // P02: deterministic creation-order pagination for warranty receipts.
            // Does not change sort_order's existing update-recency semantics.
            if ($table === 'warranty_case') {
                $query->orderByDesc('warranty_case_id');
            }
        } else {
            // hasRelationColumn chứ không hasColumn — `warranty_case` là VIEW
            // (xem chú thích filterColumns ở trên). Bảng/view thiếu updated_at
            // thì rơi về đúng cột $orderBy, chỉ đổi chiều.
            if (SchemaCache::hasRelationColumn($table, 'updated_at')) {
                $query->orderByRaw("COALESCE(updated_at, {$orderBy}) {$sortOrder}");
            }
            $query->orderBy($orderBy, $sortOrder);
            // Tie-break bằng khoá chính cho phân trang ổn định khi timestamp
            // trùng nhau: bảng thường có `id`, view warranty_case dùng
            // `warranty_case_id`.
            foreach (['id', "{$table}_id"] as $primaryKey) {
                if (SchemaCache::hasRelationColumn($table, $primaryKey)) {
                    $query->orderBy($primaryKey, $sortOrder);
                    break;
                }
            }
        }
        $page = $query->paginate($this->perPage($request));
        if ($collectionEnricher !== null) {
            $collectionEnricher($page->getCollection());
        }
        if ($masked !== []) {
            // §13.8 · WWHN-247: bề mặt đọc danh sách phải khai báo trạng thái mask
            // giống bề mặt chi tiết (`casePayload.pii_masked`). Thiếu cờ này, client
            // không có cách nào biết giá trị đang cầm là thô hay đã mask nên buộc
            // phải đoán từ danh sách quyền phía mình — đúng lớp lỗi mà §13.8 cấm.
            $page->getCollection()->transform(function (object $row) use ($masked, $piiHidden): object {
                if ($piiHidden) {
                    foreach ($masked as $field) {
                        if (isset($row->{$field})) {
                            $row->{$field} = $this->maskPii($field, (string) $row->{$field});
                        }
                    }
                }
                $row->pii_masked = $piiHidden;

                return $row;
            });
        }

        $response = $this->respondPaginated($page);
        if ($table === 'warranty_case') {
            $body = $response->getData(true);
            $body['meta']['warehouse_scope'] = WarrantyWarehouseScope::metadata($request);
            $response->setData($body);
        }

        return $response;
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
            return $this->respondNotFound('Không tìm thấy snapshot nhãn.');
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
        ], $reprint ? 'Đã in lại nhãn.' : 'Đã in nhãn.');
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
            // WWHN-SEC-06: customer_name/customer_phone ĐÃ RỜI danh sách này. Hai
            // cột đó nay là ciphertext — `LIKE '%kw%'` trên chúng không bao giờ
            // khớp và chỉ tạo ra một nhánh OR vô dụng trong mọi truy vấn. Tìm
            // kiếm theo PII đi qua blind index ở `listWarrantyCases()`.
            // KHÔNG có 'status' ở đây (khác các bảng trên): WAR-01 đã có dropdown
            // trạng thái riêng, còn LIKE trên enum máy biến mọi từ khoá chứa
            // "can"/"re" thành "trả về toàn bộ hồ sơ CANCELLED / RETURNED..." —
            // đúng những chữ hay nằm trong tên hàng DongCheng ("Cần siết lực").
            // Trước đây nhánh này chết im trên PostgreSQL vì lỗi view ở
            // hasRelationColumn nên không ai thấy; đừng thêm lại.
            'warranty_case' => ['warranty_case_code', 'temporary_item_code', 'manual_product_description'],
            'pv_industry' => ['industry_code', 'industry_name', 'display_name'],
            'pv_filter_tag' => ['tag_code', 'tag_name', 'display_name'],
        ][$table] ?? [];

        // hasRelationColumn: xem chú thích ở tablePage() — `warranty_case` là
        // view nên hasColumn() sẽ quét sạch danh sách này thành mảng rỗng, và
        // ô tìm kiếm mất hẳn nhánh mã hồ sơ / mã tạm.
        return array_values(array_filter($candidates, fn (string $column): bool => SchemaCache::hasRelationColumn($table, $column)));
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
