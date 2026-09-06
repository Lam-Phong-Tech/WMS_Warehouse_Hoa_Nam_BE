<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Modules\Warehouse\Models\DocumentStatusHistory;
use OpenApi\Attributes as OA;

/** Lịch sử chuyển trạng thái chứng từ (append-only, read-only). */
class DocumentStatusHistoryController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    #[OA\Get(
        path: '/api/v1/document-status-history',
        operationId: 'document_status_history_list',
        summary: 'Lịch sử chuyển trạng thái chứng từ (inbound/outbound documents)',
        security: [['bearerAuth' => []]],
        tags: ['06. Warehouse'],
        parameters: [
            new OA\Parameter(name: 'stock_document_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách lịch sử chuyển trạng thái'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function index(Request $request)
    {
        // TTL 'search' (120s): đây là "chứng từ đang lưu thông" — lịch sử chỉ đổi ở
        // mỗi bước chuyển trạng thái, không đổi theo từng lần quét. Version namespace
        // `stock:version` nên mọi mutation tồn kho làm entry cũ mồ côi tức thì.
        $payload = $this->cachedReadPayload(
            'warehouse:document-status-history:list',
            // Phân trang chạy TRONG SQL ⇒ page/per_page BẮT BUỘC nằm trong khoá.
            $this->readFiltersWithPaging($request),
            fn (): array => $this->buildIndexPayload($request),
            'search',
            'stock:version',
        );

        return $this->respondPaginated($this->paginatorFromReadCache($payload));
    }

    /**
     * Phần chạy SQL của index(), tách ra để cache được. Trả về mảng thuần (điều 3
     * của CachesReadPayload) gồm các dòng đã phân trang + số liệu phân trang.
     *
     * @return array<string, mixed>
     */
    private function buildIndexPayload(Request $request): array
    {
        $query = DocumentStatusHistory::query();

        if ($request->filled('stock_document_id')) {
            $query->where('stock_document_id', $request->input('stock_document_id'));
        }
        if ($request->filled('status')) {
            $query->where('to_status', $request->input('status'));
        }
        $this->applyKeyword($query, $request, ['from_status', 'to_status', 'action', 'note']);

        $paginator = $query->orderByDesc('created_at')->paginate($this->perPage($request));

        return [
            'items' => $paginator->items(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ];
    }

    /**
     * Dựng lại paginator từ payload đã cache để `respondPaginated()` phát ra ĐÚNG
     * envelope cũ (data/meta/links). `meta.request_id` + `meta.timestamp` được sinh
     * bên trong respondPaginated() nên vẫn tươi mỗi request — không bao giờ bị cache.
     * Options giống hệt `Builder::paginate()` (path + pageName) nên `links` không đổi.
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
}
