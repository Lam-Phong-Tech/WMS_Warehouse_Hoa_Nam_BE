<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Read API cho toa yêu cầu xuất (outbound_requests) theo ERD bảng 37/38.
 *
 * Toa được tạo duy nhất qua flow consume Excel/nhập tay (BP-WMS-04); controller
 * này chỉ phục vụ tra cứu/đối soát toa (báo cáo "Đối soát toa" mục 12) — không
 * có write endpoint để giữ nguyên nguồn tạo toa duy nhất.
 */
#[OA\Get(
    path: '/api/v1/outbound-requests',
    operationId: 'outbound_request_list',
    summary: 'Danh sách toa yêu cầu xuất (phân trang, filter status/keyword)',
    security: [['bearerAuth' => []]],
    tags: ['10. Stock Out (Outbound)'],
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['OPEN', 'CONSUMED', 'CANCELLED'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Danh sách toa yêu cầu xuất'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền outbound.view'),
    ]
)]
#[OA\Get(
    path: '/api/v1/outbound-requests/{id}',
    operationId: 'outbound_request_detail',
    summary: 'Chi tiết toa yêu cầu xuất kèm lines và outbound documents liên kết',
    security: [['bearerAuth' => []]],
    tags: ['10. Stock Out (Outbound)'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Chi tiết toa yêu cầu xuất'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy toa'),
    ]
)]
class OutboundRequestController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    public function index(Request $request)
    {
        // Validate NẰM NGOÀI closure: 422 phải trả về ngay và không được cache; để
        // trong closure thì CacheService bắt exception rồi chạy resolver lần hai.
        $request->validate([
            'status' => ['nullable', 'in:OPEN,CONSUMED,CANCELLED'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // TTL 'search' (120s): toa yêu cầu xuất là chứng từ đang lưu thông (OPEN →
        // CONSUMED). Version `stock:version` nên Post/Confirm phiếu xuất làm entry
        // cũ mồ côi ngay.
        $payload = $this->cachedReadPayload(
            'warehouse:outbound-request:list',
            // Phân trang chạy TRONG SQL ⇒ page/per_page BẮT BUỘC nằm trong khoá.
            $this->readFiltersWithPaging($request),
            fn (): array => $this->buildIndexPayload($request),
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
    private function buildIndexPayload(Request $request): array
    {
        $query = DB::table('outbound_requests')->orderByDesc('created_at');
        if ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->input('status')));
        }
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $likeOperator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($sub) use ($keyword, $likeOperator): void {
                $sub->where('request_no', $likeOperator, "%{$keyword}%")
                    ->orWhere('recipient_name', $likeOperator, "%{$keyword}%");
            });
        }

        $paginator = $query->paginate(min(100, max(1, (int) $request->input('per_page', 10))));
        $requestIds = collect($paginator->items())->pluck('id')->map(fn ($id): string => (string) $id);

        $lineCounts = $requestIds->isEmpty() ? collect() : DB::table('outbound_request_lines')
            ->whereIn('outbound_request_id', $requestIds)
            ->selectRaw('outbound_request_id, COUNT(*) AS line_count, SUM(required_qty) AS required_total')
            ->groupBy('outbound_request_id')
            ->get()
            ->keyBy('outbound_request_id');
        $documents = $requestIds->isEmpty() ? collect() : DB::table('stock_documents')
            ->whereIn('outbound_request_id', $requestIds)
            ->where('doc_type', 'issue')
            ->orderByDesc('created_at')
            ->get(['id', 'doc_no', 'status', 'outbound_request_id'])
            ->groupBy('outbound_request_id');

        $paginator->setCollection(collect($paginator->items())->map(function (object $row) use ($lineCounts, $documents): array {
            $id = (string) $row->id;

            return [
                ...(array) $row,
                'line_count' => (int) ($lineCounts[$id]->line_count ?? 0),
                'required_total' => (int) ($lineCounts[$id]->required_total ?? 0),
                'outbound_documents' => ($documents[$id] ?? collect())->map(fn (object $doc): array => [
                    'id' => (string) $doc->id,
                    'doc_no' => (string) $doc->doc_no,
                    'status' => (string) $doc->status,
                ])->values()->all(),
            ];
        }));

        return [
            'items' => $paginator->items(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ];
    }

    /**
     * Dựng lại paginator từ payload đã cache để `respondPaginated()` phát ra ĐÚNG
     * envelope cũ. `meta.request_id`/`meta.timestamp` sinh trong respondPaginated()
     * nên luôn tươi; options trùng `Builder::paginate()` nên `links` không đổi.
     *
     * @param  array<string, mixed>  $payload
     */
    private function paginatorFromReadCache(array $payload): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $payload['items'] ?? [],
            (int) ($payload['total'] ?? 0),
            max(1, (int) ($payload['per_page'] ?? 10)),
            max(1, (int) ($payload['current_page'] ?? 1)),
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    public function show(string $id)
    {
        // Kiểm tra tồn tại NẰM NGOÀI cache: không cache 404.
        $requestRow = DB::table('outbound_requests')->where('id', $id)->first();
        if ($requestRow === null) {
            return $this->respondNotFound('Không tìm thấy toa yêu cầu xuất.');
        }

        // TTL 'search' (120s): chứng từ đang lưu thông; `status` trong khoá để toa
        // vừa bị consume không phục vụ lại bản OPEN cũ.
        $payload = $this->cachedReadPayload(
            'warehouse:outbound-request:detail',
            ['id' => $id, 'status' => (string) ($requestRow->status ?? '')],
            fn (): array => $this->buildShowPayload($id, $requestRow),
            'search',
            'stock:version',
        );

        return $this->respondSuccess($payload);
    }

    /**
     * Phần chạy SQL của show(), tách ra để cache được (mảng thuần).
     *
     * @return array<string, mixed>
     */
    private function buildShowPayload(string $id, object $requestRow): array
    {
        $lines = DB::table('outbound_request_lines as l')
            ->leftJoin('sku as s', 's.sku_id', '=', 'l.sku_id')
            ->where('l.outbound_request_id', $id)
            ->orderBy('l.line_no')
            ->get(['l.id', 'l.line_no', 'l.sku_id', 's.sku_code', 's.sku_name', 'l.required_qty', 'l.note']);

        $documents = DB::table('stock_documents')
            ->where('outbound_request_id', $id)
            ->where('doc_type', 'issue')
            ->orderByDesc('created_at')
            ->get(['id', 'doc_no', 'status', 'posted_at', 'cancelled_at']);

        return [
            ...(array) $requestRow,
            'lines' => $lines->map(fn (object $line): array => (array) $line)->all(),
            'outbound_documents' => $documents->map(fn (object $doc): array => (array) $doc)->all(),
        ];
    }
}
