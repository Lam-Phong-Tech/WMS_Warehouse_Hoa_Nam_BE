<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\WarrantyPiiMasker;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * GĐ1 operational reporting contract.
 *
 * - outbound: recipient type/recipient/market/region/SKU dimensions;
 * - warranty: one case may contain many defects;
 * - pagination defaults to 10 rows.
 */
class BaM1ReportController extends Controller
{
    // Canonical source duy nhất cho 4 loại đối tượng nhận hàng (GĐ1-REQ-001).
    private const RECIPIENT_TYPES = BaM1RecipientController::TYPES;
    private const WARRANTY_STATUSES = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'];

    public function outboundByRecipient(Request $request)
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
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
        $totalRecipients = (clone $query)->distinct('a.agency_id')->count('a.agency_id');
        $totalDocuments = (clone $query)->distinct('d.id')->count('d.id');

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

        return response()->json([
            'success' => true,
            'data' => [
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
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    public function warrantyDefects(Request $request)
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'status' => ['nullable', Rule::in(self::WARRANTY_STATUSES)],
            'defect_id' => ['nullable', 'uuid'],
            'defect_ids' => ['nullable', 'array'],
            'defect_ids.*' => ['uuid', 'distinct'],
            'model' => ['nullable', 'string', 'max:200'],
            'identification_status' => ['nullable', Rule::in(['IDENTIFIED', 'TEMP_ONLY'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $from = (string) ($validated['from_date'] ?? now()->subDays(30)->toDateString());
        $to = (string) ($validated['to_date'] ?? now()->toDateString());
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);
        $filterDefects = array_values(array_unique(array_filter(array_merge(
            (array) ($validated['defect_ids'] ?? []),
            ! empty($validated['defect_id']) ? [(string) $validated['defect_id']] : []
        ))));

        $query = DB::table('warranty_case as wc')
            ->leftJoin('sku as s', 's.sku_id', '=', 'wc.product_id')
            ->whereBetween(DB::raw('COALESCE(wc.received_at, wc.created_at)'), ["{$from} 00:00:00", "{$to} 23:59:59"]);
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
        $cases = $query->orderByDesc(DB::raw('COALESCE(wc.received_at, wc.created_at)'))
            ->forPage($page, $perPage)
            ->get([
                'wc.warranty_case_id', 'wc.warranty_case_code', 'wc.identification_status', 'wc.status',
                'wc.temporary_item_code', 'wc.manual_product_description', 'wc.customer_name',
                'wc.customer_phone', 'wc.customer_address', 'wc.purchase_date', 'wc.received_at',
                'wc.created_at', 'wc.updated_at', 'wc.completed_at', 'wc.returned_at',
                'wc.estimated_completion_date', 's.sku_id', 's.sku_code', 's.sku_name',
            ]);
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
            $turnaround = $closedAt ? Carbon::parse($receivedAt)->diffInDays(Carbon::parse((string) $closedAt)) : null;
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

        $summaryBase = DB::table('warranty_case as wc')
            ->whereBetween(DB::raw('COALESCE(wc.received_at, wc.created_at)'), ["{$from} 00:00:00", "{$to} 23:59:59"]);

        return response()->json([
            'success' => true,
            'data' => [
                'period' => ['from_date' => $from, 'to_date' => $to],
                'rows' => $rows,
                'summary' => [
                    'total' => (clone $summaryBase)->count(),
                    'processing' => (clone $summaryBase)->whereIn('wc.status', ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED'])->count(),
                    'returned' => (clone $summaryBase)->where('wc.status', 'RETURNED')->count(),
                    'cancelled' => (clone $summaryBase)->where('wc.status', 'CANCELLED')->count(),
                    'temp_only' => (clone $summaryBase)->where('wc.identification_status', 'TEMP_ONLY')->count(),
                ],
                'pagination' => $this->pagination($page, $perPage, $total),
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    public function defectSkuBreakdown(Request $request)
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'sku_id' => ['nullable', 'uuid'],
            'sku_ids' => ['nullable', 'array'],
            'sku_ids.*' => ['uuid', 'distinct'],
            'defect_id' => ['nullable', 'uuid'],
            'defect_ids' => ['nullable', 'array'],
            'defect_ids.*' => ['uuid', 'distinct'],
        ]);
        $from = (string) ($validated['from_date'] ?? now()->subDays(30)->toDateString());
        $to = (string) ($validated['to_date'] ?? now()->toDateString());
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
            ->join('defect_catalogs as dc', 'dc.defect_id', '=', 'wcd.defect_id')
            ->leftJoin('sku as s', 's.sku_id', '=', 'wc.product_id')
            ->whereBetween(DB::raw('COALESCE(wc.received_at, wc.created_at)'), ["{$from} 00:00:00", "{$to} 23:59:59"]);
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

        return response()->json([
            'success' => true,
            'data' => [
                'period' => ['from_date' => $from, 'to_date' => $to],
                'rows' => $rows,
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
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
