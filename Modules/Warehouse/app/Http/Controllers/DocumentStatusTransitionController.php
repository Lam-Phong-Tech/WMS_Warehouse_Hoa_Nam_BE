<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\DocumentStatusTransition;
use OpenApi\Attributes as OA;

/** Quản lý danh mục tham khảo của state machine chứng từ kho (không phải nguồn enforcement). */
class DocumentStatusTransitionController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    #[OA\Get(
        path: '/api/v1/document-status-transitions',
        operationId: 'document_status_transition_list',
        summary: 'Danh sách transitions của state machine chứng từ kho',
        security: [['bearerAuth' => []]],
        tags: ['06. Warehouse'],
        parameters: [
            new OA\Parameter(name: 'doc_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['receipt', 'issue', 'transfer'])),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách transitions'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warehouse.view'),
        ]
    )]
    public function index(Request $request)
    {
        // TTL 'search' (120s) chứ không 'master': danh mục này có endpoint ghi ngay
        // trong chính controller (toggle) nên phải nhìn thấy thay đổi nhanh; toggle()
        // bump cùng namespace `stock:version` nên thực tế entry chết ngay khi bật/tắt.
        $payload = $this->cachedReadPayload(
            'warehouse:document-status-transition:list',
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
        $query = DocumentStatusTransition::query();

        if ($request->filled('doc_type')) {
            $query->where('doc_type', $request->input('doc_type'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        $this->applyKeyword($query, $request, ['from_status', 'to_status', 'action']);

        $paginator = $query->orderBy('doc_type')->orderBy('from_status')->paginate($this->perPage($request));

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

    #[OA\Patch(
        path: '/api/v1/document-status-transitions/{id}/toggle',
        operationId: 'document_status_transition_toggle',
        summary: 'Bật/tắt một transition trong danh mục tham khảo',
        security: [['bearerAuth' => []]],
        tags: ['06. Warehouse'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Đã cập nhật transition'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warehouse.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy transition'),
        ]
    )]
    public function toggle(string $id)
    {
        $transition = DocumentStatusTransition::find($id);

        if (! $transition) {
            return $this->respondNotFound('Không tìm thấy transition.');
        }

        $transition->update(['is_active' => ! $transition->is_active]);

        // Ghi là vô hiệu hoá NGAY (điều 4 của CachesReadPayload): bump sau commit để
        // index() không phục vụ lại danh mục cũ. Ngoài transaction thì afterCommit()
        // chạy luôn, nên đường ghi không bọc transaction vẫn đúng.
        DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));

        return $this->respondSuccess($transition, $transition->is_active ? 'Đã bật transition' : 'Đã tắt transition');
    }
}
