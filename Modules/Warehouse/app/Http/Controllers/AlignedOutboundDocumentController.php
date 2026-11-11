<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Support\ListSortOrder;
use OpenApi\Attributes as OA;

/** Read-side alignment: Packing Label progress is 0/1 per outbound document; RFID is optional metadata. */
class AlignedOutboundDocumentController extends OutboundDocumentController
{
    #[OA\Get(
        path: '/api/v1/outbound-documents',
        operationId: 'aligned_outbound_document_index',
        summary: 'Danh sách phiếu xuất kèm tiến độ matching/packing label',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'keyword', in: 'query', description: 'Khớp doc_no/note', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'sort_order', in: 'query', required: false, description: 'Sắp xếp theo thời điểm tạo/sửa gần nhất (COALESCE(updated_at, created_at)): desc = Mới nhất trước, asc = Cũ nhất trước. Không gửi = giữ thứ tự mặc định cũ (created_at desc). Giá trị khác trả 422.', schema: new OA\Schema(type: 'string', enum: ['desc', 'asc'])),
            new OA\Parameter(name: 'from_date', in: 'query', required: false, description: 'Từ ngày tạo phiếu (Y-m-d, bao gồm đầu ngày)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: false, description: 'Đến ngày tạo phiếu (Y-m-d, bao gồm cuối ngày)', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trang danh sách phiếu xuất, mỗi item kèm required_total/matched_count/label_printed_count/packing_label_count/packing_label_applied_count/rfid_mapped_count'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền outbound.view hoặc ngoài warehouse data scope'),
        ]
    )]
    public function index(Request $request)
    {
        // Validate before a read-cache hit; retain the existing permission/scope middleware.
        $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (
            $request->filled('from_date')
            && $request->filled('to_date')
            && (string) $request->input('from_date') > (string) $request->input('to_date')
        ) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'from_date phải nhỏ hơn hoặc bằng to_date.');
        }

        // Cùng chính sách với OutboundDocumentController::index() — TTL 'search'
        // (120s, chứng từ đang lưu thông) trên version `stock:version`. Namespace
        // RIÊNG vì payload khác (thêm packing_label_*): dùng chung namespace sẽ trả
        // nhầm hình dạng của route kia. Trait CachesReadPayload kế thừa từ lớp cha.
        $payload = $this->cachedReadPayload(
            'warehouse:outbound-document:aligned-list:p02-scope-sort-v1',
            // Phân trang chạy TRONG SQL ⇒ page/per_page BẮT BUỘC nằm trong khoá.
            $this->readFiltersWithPaging($request),
            fn (): array => $this->buildAlignedIndexPayload($request),
            'search',
            'stock:version',
        );

        return $this->respondPaginated($this->paginatorFromReadCache($payload));
    }

    /**
     * Phần chạy SQL của index(), tách ra để cache được (mảng thuần).
     *
     * @return array<string, mixed>
     */
    private function buildAlignedIndexPayload(Request $request): array
    {
        $query = StockDocument::query()
            ->with('lines')
            ->where('doc_type', $this->docType())
            ->when($request->filled('warehouse_id'), fn ($filtered) => $filtered->where(
                'src_warehouse_id',
                $request->input('warehouse_id'),
            ));

        // WWHN-SEC-09 (BOLA): lọc theo warehouse data-scope như bản danh sách gốc
        // (OutboundDocumentController::buildIndexPayload) — khoá cache đã mang scope.
        $user = $request->user();
        if ($user !== null && method_exists($user, 'warehouseScopeIds')) {
            $warehouseScopeIds = $user->warehouseScopeIds();
            if (is_array($warehouseScopeIds)) {
                $query->whereIn('src_warehouse_id', $warehouseScopeIds);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // OUT-01 "Từ ngày / Đến ngày": lọc NGAY TRONG SQL theo ngày tạo phiếu,
        // bao gồm cả hai đầu — cùng luật với IN-01. Không lọc sau phân trang để
        // số dòng/trang và tổng bản ghi vẫn đúng.
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', (string) $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', (string) $request->input('to_date'));
        }

        $this->applyKeyword($query, $request, ['doc_no', 'note']);

        // "Sắp xếp theo" của OUT-01 — sort_order nằm trong query string nên khoá
        // cache (readFiltersWithPaging giữ nguyên toàn bộ query) tự tách entry
        // theo chiều sắp xếp, không cần đổi gì ở tầng cache.
        $sortOrder = ListSortOrder::fromRequest($request);
        if ($sortOrder === null) {
            $query->orderByDesc('created_at')->orderByDesc('id');
        } else {
            ListSortOrder::applyRecency($query, $sortOrder);
        }

        $paginator = $query->paginate($this->perPage($request));
        $ids = collect($paginator->items())->map(fn (StockDocument $doc): string => (string) $doc->id)->all();
        $stats = $this->alignedProgressStats($ids);
        $requestNoById = $this->requestNoByOutboundRequestId(
            collect($paginator->items())->pluck('outbound_request_id')->filter()->unique()->all()
        );

        $paginator->getCollection()->transform(function (StockDocument $doc) use ($stats, $requestNoById): array {
            $stat = $stats[(string) $doc->id] ?? null;

            return [
                ...$doc->toArray(),
                'required_total' => (int) $doc->lines->sum('qty_planned'),
                'matched_count' => (int) ($stat->matched_count ?? 0),
                'label_printed_count' => (int) ($stat->packing_label_printed ?? 0),
                'packing_label_count' => (int) ($stat->packing_label_count ?? 0),
                'packing_label_applied_count' => (int) ($stat->packing_label_applied ?? 0),
                'rfid_mapped_count' => (int) ($stat->rfid_mapped_count ?? 0),
                'rfid_required' => false,
                // Cột "Yêu cầu xuất" ở danh sách phải hiện mã toa đọc được
                // (request_no), không phải UUID của outbound_request_id.
                'request_no' => $requestNoById[(string) $doc->outbound_request_id] ?? null,
            ];
        });

        return [
            'items' => $paginator->items(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ];
    }

    /** @return array<string, object> */
    private function alignedProgressStats(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $matches = DB::table('stock_document_lines as l')
            ->leftJoin('outbound_item_matches as m', function ($join): void {
                $join->on('m.outbound_line_id', '=', 'l.id')
                    ->where(function ($query): void {
                        $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
                    });
            })
            ->leftJoin('physical_codes as pc', function ($join): void {
                $join->on('pc.assigned_object_id', '=', 'm.item_id')
                    ->where('pc.assigned_object_type', 'ITEM')
                    ->where('pc.carrier_type', 'RFID')
                    ->where('pc.status', 'ACTIVE')
                    ->whereNotNull('pc.hardware_uid');
            })
            ->whereIn('l.stock_document_id', $documentIds)
            ->groupBy('l.stock_document_id')
            ->selectRaw('l.stock_document_id')
            ->selectRaw('COUNT(DISTINCT m.id) AS matched_count')
            ->selectRaw('COUNT(DISTINCT pc.physical_code_id) AS rfid_mapped_count')
            ->get()->keyBy('stock_document_id');

        $labels = DB::table('label_snapshots')
            ->whereIn('outbound_document_id', $documentIds)
            ->groupBy('outbound_document_id')
            ->selectRaw('outbound_document_id')
            ->selectRaw('COUNT(*) AS packing_label_count')
            ->selectRaw("SUM(CASE WHEN status = 'PRINTED' THEN 1 ELSE 0 END) AS packing_label_printed")
            ->selectRaw('SUM(CASE WHEN applied_at IS NOT NULL THEN 1 ELSE 0 END) AS packing_label_applied')
            ->get()->keyBy('outbound_document_id');

        $result = [];
        foreach ($documentIds as $id) {
            $m = $matches[$id] ?? null;
            $l = $labels[$id] ?? null;
            $result[$id] = (object) [
                'matched_count' => (int) ($m->matched_count ?? 0),
                'rfid_mapped_count' => (int) ($m->rfid_mapped_count ?? 0),
                'packing_label_count' => (int) ($l->packing_label_count ?? 0),
                'packing_label_printed' => (int) ($l->packing_label_printed ?? 0),
                'packing_label_applied' => (int) ($l->packing_label_applied ?? 0),
            ];
        }

        return $result;
    }
}
