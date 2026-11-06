<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Validation\InputLimits;
use App\Support\WarrantyPiiMasker;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Http\Controllers\Concerns\CachesReportPayload;
use Modules\Warehouse\Support\WarrantyCasePii;
use Modules\Warehouse\Support\WarrantyReportTime;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use OpenApi\Attributes as OA;

/**
 * GĐ1 operational reporting contract.
 *
 * - outbound: recipient type/recipient/market/region/SKU dimensions;
 * - warranty: one case may contain many defects;
 * - pagination defaults to 10 rows.
 */
class BaM1ReportController extends Controller
{
    use CachesReportPayload;

    // Canonical source duy nhất cho 4 loại đối tượng nhận hàng (GĐ1-REQ-001).
    private const RECIPIENT_TYPES = BaM1RecipientController::TYPES;

    private const WARRANTY_STATUSES = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'];

    #[OA\Get(
        path: '/api/v1/reports/outbound-by-recipient',
        operationId: 'report_outbound_by_recipient',
        summary: 'GĐ1 — báo cáo xuất kho theo đối tượng nhận',
        description: 'Chỉ tính phiếu xuất POSTED có doc_date trong kỳ; mặc định kỳ là 30 ngày gần nhất và 10 dòng/trang. `by_sku` và `by_recipient` gộp trên TOÀN BỘ tập đã lọc, không theo trang. SĐT nơi nhận chỉ trả dạng che (`contact_masked`).',
        security: [['bearerAuth' => []]],
        tags: ['12. Dashboard / Report'],
        parameters: [
            new OA\Parameter(name: 'from_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: false, description: 'Phải lớn hơn hoặc bằng from_date.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'recipient_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: self::RECIPIENT_TYPES)),
            new OA\Parameter(name: 'recipient_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'agency_id', in: 'query', required: false, description: 'Bí danh tương thích của recipient_id.', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'market', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 120)),
            new OA\Parameter(name: 'region', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 120)),
            new OA\Parameter(name: 'sku_code', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 100)),
            new OA\Parameter(name: 'keyword', in: 'query', required: false, description: 'Tìm theo mã/tên nơi nhận, thị trường, khu vực, mã/tên SKU hoặc số phiếu.', schema: new OA\Schema(type: 'string', maxLength: 200)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'period, rows, summary, pagination, recipient_types, by_sku, by_recipient')]
    )]
    public function outboundByRecipient(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->cachedReportPayload(
                'warehouse:report:outbound-by-recipient',
                // Phân trang nằm TRONG SQL -> page/per_page BẮT BUỘC ở trong key,
                // nếu không trang 3 sẽ nhận lại payload của trang 1.
                $this->reportFiltersWithPaging($request),
                $this->reportScopeIds(),
                fn (): array => $this->outboundByRecipientData($request)
            ),
            // meta nằm NGOÀI cache: request_id phải mới mỗi lần gọi thì log truy vết
            // mới trỏ đúng request, không trỏ về request đã dựng cache.
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    /**
     * Xuất theo người nhận: join stock_documents -> lines -> agencies, cộng thêm 2 truy vấn tổng hợp by_sku/by_recipient trên toàn kỳ.
     *
     * @return array<string, mixed>
     */
    private function outboundByRecipientData(Request $request): array
    {
        $validated = $request->validate([
            // date() chứ không pastDate(): client vẫn gửi to_date = ngày mai làm mốc chặn trên của kỳ.
            'from_date' => array_merge(['nullable'], InputLimits::date()),
            'to_date' => array_merge(['nullable'], InputLimits::date(), ['after_or_equal:from_date']),
            'recipient_type' => ['nullable', Rule::in(self::RECIPIENT_TYPES)],
            'recipient_id' => ['nullable', 'uuid'],
            'agency_id' => ['nullable', 'uuid'],
            'market' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'sku_code' => ['nullable', 'string', 'max:100'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $from = (string) ($validated['from_date'] ?? now()->subDays(30)->toDateString());
        $to = (string) ($validated['to_date'] ?? now()->toDateString());
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);
        $recipientId = $validated['recipient_id'] ?? $validated['agency_id'] ?? null;

        $query = DB::table('stock_documents as d')
            ->join('stock_document_lines as l', 'l.stock_document_id', '=', 'd.id')
            // leftJoin: dòng có product_id không match danh mục SKU vẫn phải hiện
            // (thiếu tên) thay vì biến mất khỏi báo cáo.
            ->leftJoin('sku as s', 's.sku_id', '=', 'l.product_id')
            ->leftJoin('agencies as a', 'a.agency_id', '=', 'd.agency_id')
            ->where('d.doc_type', 'issue')
            ->where('d.status', 'POSTED')
            ->whereBetween('d.doc_date', [$from, $to]);

        if (! empty($validated['recipient_type'])) {
            $query->where('a.recipient_type', $validated['recipient_type']);
        }
        if ($recipientId) {
            $query->where('a.agency_id', $recipientId);
        }
        foreach (['market', 'region'] as $field) {
            if (! empty($validated[$field])) {
                $query->where("a.{$field}", $validated[$field]);
            }
        }
        if (! empty($validated['sku_code'])) {
            $query->where('s.sku_code', $validated['sku_code']);
        }
        if (! empty($validated['keyword'])) {
            $like = '%'.mb_strtolower(trim((string) $validated['keyword'])).'%';
            $query->where(function ($nested) use ($like): void {
                $nested->whereRaw("LOWER(COALESCE(a.agency_code, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(a.agency_name, d.recipient_name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(a.market, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(a.region, '')) LIKE ?", [$like])
                    ->orWhereRaw('LOWER(s.sku_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(s.sku_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(d.doc_no) LIKE ?', [$like]);
            });
        }

        $total = (clone $query)->count();
        $totalQty = (float) ((clone $query)->sum(DB::raw('COALESCE(l.scanned_qty, l.qty_actual, 0)')) ?? 0);
        $totalDocuments = (clone $query)->distinct('d.id')->count('d.id');

        // "SL xuất theo SKU" + "Top nơi nhận" (tab Theo từng thị trường): gộp trên
        // TOÀN BỘ tập đã lọc (clone trước forPage), không suy ra từ 1 trang.
        $bySku = (clone $query)
            ->groupBy('s.sku_id', 's.sku_code', 's.sku_name')
            ->orderByDesc(DB::raw('SUM(COALESCE(l.scanned_qty, l.qty_actual, 0))'))
            ->get([
                's.sku_id',
                's.sku_code as product_code',
                's.sku_name as product_name',
                DB::raw('SUM(COALESCE(l.scanned_qty, l.qty_actual, 0)) as issued_qty'),
            ])
            ->map(fn (object $row): array => [
                'sku_id' => (string) $row->sku_id,
                'product_code' => (string) $row->product_code,
                'product_name' => (string) $row->product_name,
                'issued_qty' => (float) $row->issued_qty,
            ])->values()->all();

        $byRecipient = (clone $query)
            ->groupBy('a.agency_id', 'a.agency_code', 'a.agency_name', 'd.recipient_name')
            ->orderByDesc(DB::raw('SUM(COALESCE(l.scanned_qty, l.qty_actual, 0))'))
            ->get([
                'a.agency_id',
                'a.agency_code',
                DB::raw("COALESCE(a.agency_name, d.recipient_name, 'Chưa xác định') as agency_name"),
                DB::raw('COUNT(DISTINCT d.id) as document_count'),
                DB::raw('SUM(COALESCE(l.scanned_qty, l.qty_actual, 0)) as issued_qty'),
            ])
            ->map(fn (object $row): array => [
                'recipient_id' => $row->agency_id !== null ? (string) $row->agency_id : null,
                'agency_code' => $row->agency_code,
                'agency_name' => $row->agency_name,
                'document_count' => (int) $row->document_count,
                'issued_qty' => (float) $row->issued_qty,
            ])->values()->all();
        // Đếm theo cùng nhóm đã dựng ở $byRecipient (agency_id, hoặc fallback
        // agency_name/recipient_name khi phiếu không link master data thật) —
        // COUNT(DISTINCT a.agency_id) trả 0 sai khi mọi dòng đều agency_id NULL
        // dù có nhiều nơi nhận phân biệt qua recipient_name.
        $totalRecipients = count($byRecipient);

        $rows = $query
            ->orderByDesc('d.doc_date')
            ->orderBy('d.doc_no')
            ->orderBy('l.line_no')
            ->forPage($page, $perPage)
            ->get([
                'd.id as document_id',
                'd.doc_no as outbound_no',
                'd.doc_date as document_date',
                'd.outbound_request_id',
                'd.agency_id as recipient_id',
                'd.recipient_name as recipient_name_snapshot',
                'd.recipient_address as recipient_address_snapshot',
                'a.agency_code',
                'a.agency_name',
                'a.recipient_type',
                'a.market',
                'a.region',
                'a.address',
                'a.phone',
                's.sku_id',
                's.sku_code as product_code',
                's.sku_name as product_name',
                'l.qty_planned as required_qty',
                'l.scanned_qty',
            ])
            ->map(function (object $row): array {
                $recipientName = trim((string) ($row->agency_name ?? $row->recipient_name_snapshot ?? ''));

                return [
                    'document_id' => (string) $row->document_id,
                    'outbound_no' => (string) $row->outbound_no,
                    'document_date' => (string) $row->document_date,
                    'source_request_no' => null,
                    'recipient_id' => $row->recipient_id !== null ? (string) $row->recipient_id : null,
                    'agency_id' => $row->recipient_id !== null ? (string) $row->recipient_id : null,
                    'agency_code' => $row->agency_code,
                    'agency_name' => $recipientName !== '' ? $recipientName : 'Chưa xác định',
                    'recipient_name' => $recipientName !== '' ? $recipientName : 'Chưa xác định',
                    'recipient_type' => $row->recipient_type ?? 'AGENCY',
                    'market' => $row->market,
                    'region' => $row->region,
                    'address' => $row->address ?? $row->recipient_address_snapshot,
                    'contact_masked' => $row->phone ? WarrantyPiiMasker::phone((string) $row->phone) : null,
                    'sku_id' => (string) $row->sku_id,
                    'product_code' => (string) $row->product_code,
                    'product_name' => (string) $row->product_name,
                    'required_qty' => (int) $row->required_qty,
                    'scanned_qty' => (int) $row->scanned_qty,
                    'issued_qty' => (int) $row->scanned_qty,
                ];
            })->values();

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'rows' => $rows,
            'summary' => [
                'total_recipients' => $totalRecipients,
                // Compatibility name used by existing FE.
                'total_agencies' => $totalRecipients,
                'total_documents' => $totalDocuments,
                'total_issued_qty' => $totalQty,
            ],
            'pagination' => $this->pagination($page, $perPage, $total),
            'recipient_types' => self::RECIPIENT_TYPES,
            'by_sku' => $bySku,
            'by_recipient' => $byRecipient,
        ];
    }

    public function warrantyDefects(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->cachedReportPayload(
                'warehouse:report:warranty-defects:instant-v2',
                // Phân trang nằm TRONG SQL -> page/per_page BẮT BUỘC ở trong key,
                // nếu không trang 3 sẽ nhận lại payload của trang 1.
                $this->reportFiltersWithPaging($request),
                $this->reportScopeIds(),
                fn (): array => $this->warrantyDefectsData($request)
            ),
            // meta nằm NGOÀI cache: request_id phải mới mỗi lần gọi thì log truy vết
            // mới trỏ đúng request, không trỏ về request đã dựng cache.
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    /**
     * Bảo hành theo lỗi: quét warranty_case cả kỳ, mỗi trang còn kéo thêm defects và component issue theo từng hồ sơ, cộng 5 truy vấn đếm cho phần summary.
     *
     * @return array<string, mixed>
     */
    private function warrantyDefectsData(Request $request): array
    {
        $validated = $request->validate([
            // date() chứ không pastDate(): client vẫn gửi to_date = ngày mai làm mốc chặn trên của kỳ.
            'from_date' => array_merge(['nullable'], InputLimits::date()),
            'to_date' => array_merge(['nullable'], InputLimits::date(), ['after_or_equal:from_date']),
            'status' => ['nullable', Rule::in(self::WARRANTY_STATUSES)],
            'defect_id' => ['nullable', 'uuid'],
            'defect_ids' => ['nullable', 'array', 'max:500'],
            'defect_ids.*' => ['uuid', 'distinct'],
            'model' => ['nullable', 'string', 'max:200'],
            'identification_status' => ['nullable', Rule::in(['IDENTIFIED', 'TEMP_ONLY'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $from = (string) ($validated['from_date'] ?? WarrantyReportTime::today()->subDays(30)->toDateString());
        $to = (string) ($validated['to_date'] ?? WarrantyReportTime::today()->toDateString());
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);
        $filterDefects = array_values(array_unique(array_filter(array_merge(
            (array) ($validated['defect_ids'] ?? []),
            ! empty($validated['defect_id']) ? [(string) $validated['defect_id']] : []
        ))));

        $query = WarrantyWarehouseScope::query($request, 'wc')
            ->leftJoin('sku as s', 's.sku_id', '=', 'wc.product_id')
            ->where(WarrantyReportTime::range($from, $to));
        if (! empty($validated['status'])) {
            $query->where('wc.status', $validated['status']);
        }
        if (! empty($validated['identification_status'])) {
            $query->where('wc.identification_status', $validated['identification_status']);
        }
        if ($filterDefects !== []) {
            $query->whereExists(function ($sub) use ($filterDefects): void {
                $sub->selectRaw('1')
                    ->from('warranty_case_defects as wcd_filter')
                    ->whereColumn('wcd_filter.warranty_case_id', 'wc.warranty_case_id')
                    ->whereIn('wcd_filter.defect_id', $filterDefects);
            });
        }
        if (! empty($validated['model'])) {
            $like = '%'.mb_strtolower(trim((string) $validated['model'])).'%';
            $query->where(function ($nested) use ($like): void {
                $nested->whereRaw("LOWER(COALESCE(s.sku_code, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(s.sku_name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(wc.manual_product_description, '')) LIKE ?", [$like]);
            });
        }

        $total = (clone $query)->count();
        $cases = $query->orderByDesc(DB::raw(WarrantyReportTime::received()))
            ->forPage($page, $perPage)
            ->get(array_merge([
                'wc.warranty_case_id', 'wc.warranty_case_code', 'wc.identification_status', 'wc.status',
                'wc.temporary_item_code', 'wc.manual_product_description',
                'wc.purchase_date', 'wc.received_at',
                'wc.created_at', 'wc.updated_at', 'wc.completed_at', 'wc.returned_at',
                'wc.estimated_completion_date', 's.sku_id', 's.sku_code', 's.sku_name',
            ], WarrantyCasePii::selectColumns('wc')));

        // WWHN-SEC-06: giải mã trước khi WarrantyPiiMasker chạy. Bỏ bước này thì
        // báo cáo hiển thị bản MASK CỦA CIPHERTEXT — vẫn ra chuỗi trông "hợp lệ",
        // nên lỗi sẽ không lộ ra ở test smoke mà chỉ lộ khi người dùng đọc.
        WarrantyCasePii::decodeRows($cases);

        $caseIds = $cases->pluck('warranty_case_id')->map(fn ($id) => (string) $id)->all();

        $defectsByCase = collect();
        if ($caseIds !== []) {
            $defectsByCase = DB::table('warranty_case_defects as wcd')
                ->join('defect_catalogs as dc', 'dc.defect_id', '=', 'wcd.defect_id')
                ->whereIn('wcd.warranty_case_id', $caseIds)
                ->orderByDesc('wcd.is_primary')
                ->orderBy('dc.defect_code')
                ->get(['wcd.warranty_case_id', 'wcd.defect_id', 'wcd.is_primary', 'dc.defect_code', 'dc.defect_name'])
                ->groupBy('warranty_case_id');
        }

        $issuedByCase = collect();
        if ($caseIds !== []) {
            $issuedByCase = DB::table('component_issue_documents as cid')
                ->join('component_issue_lines as cil', 'cil.component_issue_document_id', '=', 'cid.id')
                ->join('sku as cs', 'cs.sku_id', '=', 'cil.sku_id')
                ->where('cid.status', 'POSTED')
                ->whereIn('cid.warranty_case_id', $caseIds)
                ->groupBy('cid.warranty_case_id', 'cs.sku_code')
                ->selectRaw('cid.warranty_case_id, cs.sku_code, SUM(cil.scanned_qty) AS issued_qty')
                ->get()->groupBy('warranty_case_id');
        }

        $rows = $cases->map(function (object $case) use ($defectsByCase, $issuedByCase): array {
            $defects = collect($defectsByCase->get((string) $case->warranty_case_id) ?? [])
                ->map(fn (object $row): array => [
                    'defect_id' => (string) $row->defect_id,
                    'defect_code' => (string) $row->defect_code,
                    'defect_name' => (string) $row->defect_name,
                    'is_primary' => (bool) $row->is_primary,
                ])->values();
            $defectText = $defects->map(fn (array $d): string => $d['defect_code'].' · '.$d['defect_name'])->implode('; ');
            $issuedText = collect($issuedByCase->get((string) $case->warranty_case_id) ?? [])
                ->map(fn (object $row): string => $row->sku_code.' × '.(int) $row->issued_qty)
                ->implode('; ');
            $receivedAt = (string) ($case->received_at ?? $case->created_at);
            $closedAt = $case->returned_at ?? $case->completed_at;
            // Carbon 3: diffInDays() trả float (1 giây = 1/86400 ngày). Ép về số ngày tròn
            // để turnaround_days luôn là int và khớp file export (AlignedWarrantyReportController).
            $turnaround = WarrantyReportTime::turnaround($receivedAt, $closedAt);
            $model = $case->identification_status === 'TEMP_ONLY' && $case->sku_code === null
                ? ($case->manual_product_description ?: 'UNKNOWN_MODEL')
                : ($case->sku_code ?? 'UNKNOWN_MODEL');

            return [
                'warranty_case_id' => (string) $case->warranty_case_id,
                'case_no' => (string) $case->warranty_case_code,
                'identification_status' => (string) $case->identification_status,
                'temporary_item_code' => $case->temporary_item_code,
                'sku_id' => $case->sku_id,
                'model' => $model,
                'sku_name' => $case->sku_name,
                'defect' => $defectText !== '' ? $defectText : 'Chưa phân loại',
                'defects' => $defects,
                'defect_ids' => $defects->pluck('defect_id')->values(),
                'components_issued' => $issuedText !== '' ? $issuedText : 'Chưa xuất',
                'status' => (string) $case->status,
                'purchase_date' => $case->purchase_date,
                'received_at' => $receivedAt,
                'estimated_completion_date' => $case->estimated_completion_date,
                'returned_at' => $case->returned_at,
                'turnaround_days' => $turnaround,
                'updated_at' => (string) ($case->updated_at ?? $case->created_at),
                'customer_name' => WarrantyPiiMasker::name((string) ($case->customer_name ?? '')),
                'customer_phone' => WarrantyPiiMasker::phone((string) ($case->customer_phone ?? '')),
                'customer_address' => WarrantyPiiMasker::address((string) ($case->customer_address ?? '')),
                'pii_masked' => true,
            ];
        })->values();

        // Khối tổng hợp phải chạy trên ĐÚNG tập đã lọc như bảng bên dưới.
        //
        // Trước bản vá này, `$summaryBase` chỉ mang điều kiện KHOẢNG NGÀY: lọc
        // "Trạng thái = RECEIVED" cho ra bảng 1 dòng nhưng ô tổng vẫn ghi 2. Đó
        // là kiểu sai nguy hiểm nhất của một báo cáo — nó không báo lỗi, chỉ đưa
        // ra một con số sai mà người đọc không có cách nào nghi ngờ.
        // `ReportFlow06IntegrationTest::test_warranty_defects_filters_by_status`
        // đã khai đúng hành vi mong đợi từ trước; chỉ phần cài đặt chưa theo kịp.
        $summaryBase = WarrantyWarehouseScope::query($request, 'wc')
            ->leftJoin('sku as s', 's.sku_id', '=', 'wc.product_id')
            ->where(WarrantyReportTime::range($from, $to));

        if (! empty($validated['status'])) {
            $summaryBase->where('wc.status', $validated['status']);
        }
        if (! empty($validated['identification_status'])) {
            $summaryBase->where('wc.identification_status', $validated['identification_status']);
        }
        if ($filterDefects !== []) {
            // whereExists chứ không join: một case nhiều bệnh/lỗi sẽ bị JOIN nhân
            // dòng lên và ô tổng đếm trùng.
            $summaryBase->whereExists(function ($sub) use ($filterDefects): void {
                $sub->selectRaw('1')
                    ->from('warranty_case_defects as wcd_summary')
                    ->whereColumn('wcd_summary.warranty_case_id', 'wc.warranty_case_id')
                    ->whereIn('wcd_summary.defect_id', $filterDefects);
            });
        }
        if (! empty($validated['model'])) {
            $summaryLike = '%'.mb_strtolower(trim((string) $validated['model'])).'%';
            $summaryBase->where(function ($nested) use ($summaryLike): void {
                $nested->whereRaw("LOWER(COALESCE(s.sku_code, '')) LIKE ?", [$summaryLike])
                    ->orWhereRaw("LOWER(COALESCE(s.sku_name, '')) LIKE ?", [$summaryLike])
                    ->orWhereRaw("LOWER(COALESCE(wc.manual_product_description, '')) LIKE ?", [$summaryLike]);
            });
        }

        // RPT-WAR-DEFECT-BREAKDOWN: chart "Top bệnh/lỗi" ở FE. Đi qua
        // warranty_case_defects (N:N) như $query ở trên chứ KHÔNG group theo
        // wc.defect_id (cột legacy 1 bệnh/case) — nếu không sẽ đếm thiếu case
        // nhiều lỗi, đúng lý do BaM1ReportController thay AlignedWarrantyReportController
        // làm nguồn sống cho route này (xem comment ở routes/api.php).
        $byDefectQuery = DB::table('warranty_case_defects as wcd')
            ->join('warranty_case as wc', 'wc.warranty_case_id', '=', 'wcd.warranty_case_id')
            ->whereIn('wc.warranty_case_id', WarrantyWarehouseScope::query($request)->select('warranty_case.warranty_case_id'))
            ->join('defect_catalogs as dc', 'dc.defect_id', '=', 'wcd.defect_id')
            ->leftJoin('sku as s', 's.sku_id', '=', 'wc.product_id')
            ->where(WarrantyReportTime::range($from, $to));
        if (! empty($validated['status'])) {
            $byDefectQuery->where('wc.status', $validated['status']);
        }
        if (! empty($validated['identification_status'])) {
            $byDefectQuery->where('wc.identification_status', $validated['identification_status']);
        }
        if ($filterDefects !== []) {
            // Cùng ngữ nghĩa whereIn trên wcd.defect_id như defectSkuBreakdown():
            // đã chọn bệnh/lỗi nào thì chart chỉ hiện đúng các bệnh/lỗi đó.
            $byDefectQuery->whereIn('wcd.defect_id', $filterDefects);
        }
        if (! empty($validated['model'])) {
            $like = '%'.mb_strtolower(trim((string) $validated['model'])).'%';
            $byDefectQuery->where(function ($nested) use ($like): void {
                $nested->whereRaw("LOWER(COALESCE(s.sku_code, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(s.sku_name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(wc.manual_product_description, '')) LIKE ?", [$like]);
            });
        }
        $byDefect = $byDefectQuery
            ->groupBy('wcd.defect_id', 'dc.defect_code', 'dc.defect_name')
            ->selectRaw('wcd.defect_id, dc.defect_code, dc.defect_name')
            ->selectRaw('COUNT(DISTINCT wc.warranty_case_id) AS total')
            ->selectRaw("COUNT(DISTINCT CASE WHEN wc.status IN ('RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED') THEN wc.warranty_case_id END) AS processing")
            ->selectRaw("COUNT(DISTINCT CASE WHEN wc.status = 'RETURNED' THEN wc.warranty_case_id END) AS returned")
            ->selectRaw("COUNT(DISTINCT CASE WHEN wc.status = 'CANCELLED' THEN wc.warranty_case_id END) AS cancelled")
            ->selectRaw("COUNT(DISTINCT CASE WHEN wc.identification_status = 'TEMP_ONLY' THEN wc.warranty_case_id END) AS temp_only")
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'defect_id' => (string) $row->defect_id,
                'defect_code' => (string) $row->defect_code,
                'defect_name' => (string) $row->defect_name,
                'defect' => trim($row->defect_code.' · '.$row->defect_name),
                'total' => (int) $row->total,
                'processing' => (int) $row->processing,
                'returned' => (int) $row->returned,
                'cancelled' => (int) $row->cancelled,
                'temp_only' => (int) $row->temp_only,
            ])->values()->all();

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'rows' => $rows,
            'summary' => [
                'total' => (clone $summaryBase)->count(),
                'processing' => (clone $summaryBase)->whereIn('wc.status', ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED'])->count(),
                'returned' => (clone $summaryBase)->where('wc.status', 'RETURNED')->count(),
                'cancelled' => (clone $summaryBase)->where('wc.status', 'CANCELLED')->count(),
                'temp_only' => (clone $summaryBase)->where('wc.identification_status', 'TEMP_ONLY')->count(),
            ],
            'by_defect' => $byDefect,
            // §13.8: mọi dòng luôn qua WarrantyPiiMasker (không có nhánh actor
            // xem PII thô ở endpoint đọc này — chỉ export mới xét quyền/reason),
            // nên cờ tóm tắt ở top-level luôn true, khớp pii_masked từng dòng.
            'pii_included' => false,
            'pii_masked' => true,
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    #[OA\Get(
        path: '/api/v1/reports/warranty-defect-sku-breakdown',
        operationId: 'report_warranty_defect_sku_breakdown',
        summary: 'GĐ1 — phân rã bệnh/lỗi bảo hành theo SKU',
        description: 'Đếm số hồ sơ bảo hành theo cặp (SKU, bệnh/lỗi), lọc theo COALESCE(received_at, created_at) trong kỳ; mặc định kỳ là 30 ngày gần nhất, không phân trang. GĐ1-REQ-022: lọc đa chọn qua sku_ids[]/defect_ids[]; sku_id/defect_id là bí danh tương thích.',
        security: [['bearerAuth' => []]],
        tags: ['12. Dashboard / Report'],
        parameters: [
            new OA\Parameter(name: 'from_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: false, description: 'Phải lớn hơn hoặc bằng from_date.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sku_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sku_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', maxItems: 500, items: new OA\Items(type: 'string', format: 'uuid'))),
            new OA\Parameter(name: 'defect_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'defect_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', maxItems: 500, items: new OA\Items(type: 'string', format: 'uuid'))),
        ],
        responses: [new OA\Response(response: 200, description: 'period, rows[] gồm sku_id, sku_code, sku_name, defect_id, defect_code, defect_name, case_count')]
    )]
    public function defectSkuBreakdown(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->cachedReportPayload(
                'warehouse:report:warranty-defect-sku-breakdown:instant-v2',
                // Phân trang nằm TRONG SQL -> page/per_page BẮT BUỘC ở trong key,
                // nếu không trang 3 sẽ nhận lại payload của trang 1.
                $this->reportFiltersWithPaging($request),
                $this->reportScopeIds(),
                fn (): array => $this->defectSkuBreakdownData($request)
            ),
            // meta nằm NGOÀI cache: request_id phải mới mỗi lần gọi thì log truy vết
            // mới trỏ đúng request, không trỏ về request đã dựng cache.
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    /**
     * Phân rã lỗi theo SKU: nhóm warranty_case_defects theo SKU trên toàn kỳ.
     *
     * @return array<string, mixed>
     */
    private function defectSkuBreakdownData(Request $request): array
    {
        $validated = $request->validate([
            // date() chứ không pastDate(): client vẫn gửi to_date = ngày mai làm mốc chặn trên của kỳ.
            'from_date' => array_merge(['nullable'], InputLimits::date()),
            'to_date' => array_merge(['nullable'], InputLimits::date(), ['after_or_equal:from_date']),
            'sku_id' => ['nullable', 'uuid'],
            'sku_ids' => ['nullable', 'array', 'max:500'],
            'sku_ids.*' => ['uuid', 'distinct'],
            'defect_id' => ['nullable', 'uuid'],
            'defect_ids' => ['nullable', 'array', 'max:500'],
            'defect_ids.*' => ['uuid', 'distinct'],
        ]);
        $from = (string) ($validated['from_date'] ?? WarrantyReportTime::today()->subDays(30)->toDateString());
        $to = (string) ($validated['to_date'] ?? WarrantyReportTime::today()->toDateString());
        // GĐ1-REQ-022: bộ lọc đa chọn; scalar sku_id/defect_id giữ làm alias tương thích.
        $filterSkus = array_values(array_unique(array_filter(array_merge(
            (array) ($validated['sku_ids'] ?? []),
            ! empty($validated['sku_id']) ? [(string) $validated['sku_id']] : []
        ))));
        $filterDefects = array_values(array_unique(array_filter(array_merge(
            (array) ($validated['defect_ids'] ?? []),
            ! empty($validated['defect_id']) ? [(string) $validated['defect_id']] : []
        ))));

        $query = DB::table('warranty_case_defects as wcd')
            ->join('warranty_case as wc', 'wc.warranty_case_id', '=', 'wcd.warranty_case_id')
            ->whereIn('wc.warranty_case_id', WarrantyWarehouseScope::query($request)->select('warranty_case.warranty_case_id'))
            ->join('defect_catalogs as dc', 'dc.defect_id', '=', 'wcd.defect_id')
            ->leftJoin('sku as s', 's.sku_id', '=', 'wc.product_id')
            ->where(WarrantyReportTime::range($from, $to));
        if ($filterSkus !== []) {
            $query->whereIn('wc.product_id', $filterSkus);
        }
        if ($filterDefects !== []) {
            $query->whereIn('wcd.defect_id', $filterDefects);
        }
        $rows = $query
            ->groupBy('wc.product_id', 's.sku_code', 's.sku_name', 'wcd.defect_id', 'dc.defect_code', 'dc.defect_name')
            ->orderByDesc('case_count')
            ->selectRaw('wc.product_id AS sku_id, s.sku_code, s.sku_name, wcd.defect_id, dc.defect_code, dc.defect_name, COUNT(DISTINCT wc.warranty_case_id) AS case_count')
            ->get();

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'rows' => $rows,
        ];
    }

    /**
     * GĐ1 chart FE "Top linh kiện sử dụng trong bảo hành" — trước đây chỉ có
     * `components_issued` dạng chuỗi text nối bằng "; " trên mỗi case
     * (warrantyDefects(), field `components_issued`), không parse tin cậy được
     * thành số liệu. Đi thẳng component_issue_documents/lines (POSTED) join
     * warranty_case theo cùng quy ước COALESCE(received_at, created_at) như
     * warrantyDefects()/defectSkuBreakdown() ở trên.
     */
    #[OA\Get(path: '/api/v1/reports/warranty-component-usage', operationId: 'report_warranty_component_usage', summary: 'GĐ1 chart FE — top linh kiện sử dụng trong sửa chữa bảo hành', description: 'Gộp theo SKU linh kiện từ component_issue_documents/lines đã POSTED, join warranty_case theo COALESCE(received_at, created_at) — cùng quy ước ngày với /reports/warranty-defects và /reports/warranty-defect-sku-breakdown. Không phân trang, luôn trả toàn bộ tập lọc trong kỳ (an toàn để vẽ chart Top-N).', security: [['bearerAuth' => []]], tags: ['12. Dashboard / Report'], parameters: [new OA\Parameter(name: 'from_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to_date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'defect_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Top linh kiện theo tổng SL đã xuất, sắp giảm dần')])]
    public function componentUsage(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->cachedReportPayload(
                'warehouse:report:warranty-component-usage:instant-v2',
                // Phân trang nằm TRONG SQL -> page/per_page BẮT BUỘC ở trong key,
                // nếu không trang 3 sẽ nhận lại payload của trang 1.
                $this->reportFiltersWithPaging($request),
                $this->reportScopeIds(),
                fn (): array => $this->componentUsageData($request)
            ),
            // meta nằm NGOÀI cache: request_id phải mới mỗi lần gọi thì log truy vết
            // mới trỏ đúng request, không trỏ về request đã dựng cache.
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    /**
     * Linh kiện đã dùng: nhóm component_issue_documents theo SKU trên toàn kỳ.
     *
     * @return array<string, mixed>
     */
    private function componentUsageData(Request $request): array
    {
        $validated = $request->validate([
            // date() chứ không pastDate(): client vẫn gửi to_date = ngày mai làm mốc chặn trên của kỳ.
            'from_date' => array_merge(['nullable'], InputLimits::date()),
            'to_date' => array_merge(['nullable'], InputLimits::date(), ['after_or_equal:from_date']),
            'defect_id' => ['nullable', 'uuid'],
            'defect_ids' => ['nullable', 'array', 'max:500'],
            'defect_ids.*' => ['uuid', 'distinct'],
        ]);
        $from = (string) ($validated['from_date'] ?? WarrantyReportTime::today()->subDays(30)->toDateString());
        $to = (string) ($validated['to_date'] ?? WarrantyReportTime::today()->toDateString());
        $filterDefects = array_values(array_unique(array_filter(array_merge(
            (array) ($validated['defect_ids'] ?? []),
            ! empty($validated['defect_id']) ? [(string) $validated['defect_id']] : []
        ))));

        $query = DB::table('component_issue_documents as cid')
            ->join('component_issue_lines as cil', 'cil.component_issue_document_id', '=', 'cid.id')
            ->join('sku as cs', 'cs.sku_id', '=', 'cil.sku_id')
            ->join('warranty_case as wc', 'wc.warranty_case_id', '=', 'cid.warranty_case_id')
            ->whereIn('wc.warranty_case_id', WarrantyWarehouseScope::query($request)->select('warranty_case.warranty_case_id'))
            ->where('cid.status', 'POSTED')
            ->where(WarrantyReportTime::range($from, $to));
        if ($filterDefects !== []) {
            $query->whereExists(function ($sub) use ($filterDefects): void {
                $sub->selectRaw('1')
                    ->from('warranty_case_defects as wcd')
                    ->whereColumn('wcd.warranty_case_id', 'wc.warranty_case_id')
                    ->whereIn('wcd.defect_id', $filterDefects);
            });
        }

        $rows = $query
            ->groupBy('cs.sku_id', 'cs.sku_code', 'cs.sku_name')
            ->orderByDesc('total_issued_qty')
            ->selectRaw('cs.sku_id, cs.sku_code, cs.sku_name, SUM(cil.scanned_qty) AS total_issued_qty, COUNT(DISTINCT cid.warranty_case_id) AS case_count')
            ->get();

        return [
            'period' => ['from_date' => $from, 'to_date' => $to],
            'rows' => $rows,
        ];
    }

    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
