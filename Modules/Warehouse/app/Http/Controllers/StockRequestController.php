<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Http\Requests\StoreStockRequestRequest;
use Modules\Warehouse\Http\Requests\UpdateStockRequestRequest;
use Modules\Warehouse\Models\StockRequest;
use Modules\Warehouse\Services\StockRequestService;
use OpenApi\Attributes as OA;

/** Yêu cầu xuất/nhập/điều chuyển kho — bước tiền duyệt trước khi tạo stock_documents thật. */
#[OA\Get(
    path: '/api/v1/stock-requests',
    operationId: 'stock_request_list',
    summary: 'Danh sách yêu cầu xuất/nhập/điều chuyển kho',
    security: [['bearerAuth' => []]],
    tags: ['07. Inventory / Stock'],
    parameters: [
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'pending', 'approved', 'rejected', 'converted', 'cancelled'])),
        new OA\Parameter(name: 'request_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['receipt', 'issue', 'transfer'])),
        new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Danh sách yêu cầu'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_request.view'),
    ]
)]
#[OA\Post(
    path: '/api/v1/stock-requests',
    operationId: 'stock_request_create',
    summary: 'Tạo yêu cầu mới (nháp)',
    security: [['bearerAuth' => []]],
    tags: ['07. Inventory / Stock'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['request_type', 'request_date', 'lines'],
            properties: [
                new OA\Property(property: 'request_type', type: 'string', enum: ['receipt', 'issue', 'transfer'], example: 'issue'),
                new OA\Property(property: 'request_no', type: 'string', nullable: true, example: 'SR-2026-0001'),
                new OA\Property(property: 'request_date', type: 'string', format: 'date', example: '2026-08-21'),
                new OA\Property(property: 'src_warehouse_id', type: 'string', format: 'uuid', nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                new OA\Property(property: 'dst_warehouse_id', type: 'string', format: 'uuid', nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                new OA\Property(property: 'note', type: 'string', nullable: true, example: 'Đề nghị cấp thêm hàng cho đại lý'),
                new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                        new OA\Property(property: 'qty_planned', type: 'number', format: 'float', example: 10),
                        new OA\Property(property: 'unit_cost', type: 'number', format: 'float', nullable: true, example: 150000),
                        new OA\Property(property: 'note', type: 'string', nullable: true, example: 'Hàng đóng thùng nguyên kiện'),
                    ]
                )),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 201, description: 'Tạo thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền stock_request.create'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
    ]
)]
#[OA\Get(
    path: '/api/v1/stock-requests/{id}',
    operationId: 'stock_request_show',
    summary: 'Chi tiết yêu cầu',
    security: [['bearerAuth' => []]],
    tags: ['07. Inventory / Stock'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Chi tiết yêu cầu'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy yêu cầu'),
    ]
)]
#[OA\Patch(
    path: '/api/v1/stock-requests/{id}',
    operationId: 'stock_request_update',
    summary: 'Cập nhật yêu cầu (chỉ khi còn draft)',
    security: [['bearerAuth' => []]],
    tags: ['07. Inventory / Stock'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Cập nhật thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy yêu cầu'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
    ]
)]
#[OA\Delete(
    path: '/api/v1/stock-requests/{id}',
    operationId: 'stock_request_delete',
    summary: 'Xoá yêu cầu nháp',
    security: [['bearerAuth' => []]],
    tags: ['07. Inventory / Stock'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Xoá thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy yêu cầu'),
        new OA\Response(response: 409, description: 'Chỉ xoá được yêu cầu ở trạng thái nháp'),
    ]
)]
#[OA\Post(
    path: '/api/v1/stock-requests/{id}/submit',
    operationId: 'stock_request_submit',
    summary: 'Gửi duyệt yêu cầu',
    security: [['bearerAuth' => []]],
    tags: ['07. Inventory / Stock'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Gửi duyệt thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy yêu cầu'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
    ]
)]
#[OA\Post(
    path: '/api/v1/stock-requests/{id}/approve',
    operationId: 'stock_request_approve',
    summary: 'Duyệt yêu cầu',
    security: [['bearerAuth' => []]],
    tags: ['07. Inventory / Stock'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Duyệt thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy yêu cầu'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
    ]
)]
#[OA\Post(
    path: '/api/v1/stock-requests/{id}/reject',
    operationId: 'stock_request_reject',
    summary: 'Từ chối duyệt yêu cầu',
    security: [['bearerAuth' => []]],
    tags: ['07. Inventory / Stock'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
        new OA\Property(property: 'reason', type: 'string', example: 'Thiếu thông tin toa hàng, cần bổ sung trước khi duyệt'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Từ chối thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy yêu cầu'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
        new OA\Response(response: 422, description: 'Thiếu lý do từ chối'),
    ]
)]
#[OA\Post(
    path: '/api/v1/stock-requests/{id}/convert',
    operationId: 'stock_request_convert',
    summary: 'Chuyển đổi yêu cầu đã duyệt thành phiếu nghiệp vụ (stock_documents)',
    security: [['bearerAuth' => []]],
    tags: ['07. Inventory / Stock'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Chuyển đổi thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy yêu cầu'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại hoặc đã chuyển đổi trước đó'),
        new OA\Response(response: 422, description: 'request_type=transfer chưa hỗ trợ tự động convert'),
    ]
)]
#[OA\Post(
    path: '/api/v1/stock-requests/{id}/cancel',
    operationId: 'stock_request_cancel',
    summary: 'Hủy yêu cầu kho',
    security: [['bearerAuth' => []]],
    tags: ['07. Inventory / Stock'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'Không còn nhu cầu điều chuyển'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Hủy thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy yêu cầu'),
        new OA\Response(response: 409, description: 'Sai trạng thái hiện tại'),
    ]
)]
class StockRequestController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    public function __construct(private readonly StockRequestService $service) {}

    private function relations(): array
    {
        return ['lines.product', 'srcWarehouse', 'dstWarehouse', 'convertedDocument'];
    }

    private function userId(): string
    {
        return (string) auth()->id();
    }

    public function index(Request $request)
    {
        // TTL 'search' (120s): yêu cầu kho là chứng từ đang lưu thông (draft →
        // pending → approved → converted). Mọi đường ghi trong chính controller này
        // bump `stock:version` sau commit nên danh sách đổi ngay khi có thao tác.
        $payload = $this->cachedReadPayload(
            'warehouse:stock-request:list',
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
        $query = StockRequest::query()->with($this->relations());

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('request_type')) {
            $query->where('request_type', $request->input('request_type'));
        }
        $this->applyKeyword($query, $request, ['request_no', 'note']);

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
        // Payload rỗng = không tìm thấy: một model bao giờ cũng có thuộc tính nên `[]`
        // không thể trùng với dữ liệu thật. Bản ghi mới chỉ sinh qua store() — đường
        // ghi đó bump `stock:version` nên entry "không tìm thấy" chết ngay sau khi tạo.
        $payload = $this->cachedReadPayload(
            'warehouse:stock-request:detail',
            ['id' => $id],
            function () use ($id): array {
                $stockRequest = StockRequest::with($this->relations())->find($id);

                return $stockRequest?->toArray() ?? [];
            },
            'search',
            'stock:version',
        );

        return $payload === [] ? $this->respondNotFound('Không tìm thấy yêu cầu.') : $this->respondSuccess($payload);
    }

    /**
     * Ghi là vô hiệu hoá NGAY (điều 4 của CachesReadPayload). Gọi sau khi service đã
     * chạy xong: trong transaction thì afterCommit() hoãn tới lúc commit thật, ngoài
     * transaction thì chạy luôn — không đường ghi nào nạp lại cache từ dữ liệu chưa commit.
     */
    private function invalidateReadCache(): void
    {
        DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));
    }

    public function store(StoreStockRequestRequest $request)
    {
        $data = $request->validated();
        $created = $this->service->create($data['request_type'], $data, $this->userId());
        $this->invalidateReadCache();

        return $this->respondCreated($created, 'Tạo yêu cầu thành công');
    }

    public function update(UpdateStockRequestRequest $request, string $id)
    {
        if (! StockRequest::find($id)) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }

        $updated = $this->service->update($id, $request->validated(), $this->userId());
        $this->invalidateReadCache();

        return $this->respondSuccess($updated, 'Cập nhật yêu cầu thành công');
    }

    public function destroy(string $id)
    {
        $stockRequest = StockRequest::find($id);
        if (! $stockRequest) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }
        if ($stockRequest->status !== StockRequest::STATUS_DRAFT) {
            return $this->respondError('Chỉ có thể xóa yêu cầu ở trạng thái nháp.', [], 409, 'STATE_CONFLICT');
        }

        $stockRequest->delete();
        $this->invalidateReadCache();

        return $this->respondSuccess(null, 'Xóa yêu cầu thành công');
    }

    public function submit(string $id)
    {
        if (! StockRequest::find($id)) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }

        $result = $this->service->submit($id, $this->userId());
        $this->invalidateReadCache();

        return $this->respondSuccess($result, 'Đã gửi duyệt yêu cầu');
    }

    public function approve(string $id)
    {
        if (! StockRequest::find($id)) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }

        $result = $this->service->approve($id, $this->userId());
        $this->invalidateReadCache();

        return $this->respondSuccess($result, 'Đã duyệt yêu cầu');
    }

    public function reject(Request $request, string $id)
    {
        if (! StockRequest::find($id)) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }
        $reason = (string) $request->input('reason');
        if ($reason === '') {
            return $this->respondError('Lý do từ chối không được để trống.', [], 422, 'VALIDATION_FAILED');
        }

        $result = $this->service->reject($id, $reason, $this->userId());
        $this->invalidateReadCache();

        return $this->respondSuccess($result, 'Đã từ chối duyệt yêu cầu');
    }

    public function convert(string $id)
    {
        if (! StockRequest::find($id)) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }

        $result = $this->service->convert($id, $this->userId());
        $this->invalidateReadCache();

        return $this->respondSuccess($result, 'Chuyển đổi sang phiếu nghiệp vụ thành công');
    }

    public function cancel(Request $request, string $id)
    {
        if (! StockRequest::find($id)) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }

        $result = $this->service->cancel($id, $request->input('reason'), $this->userId());
        $this->invalidateReadCache();

        return $this->respondSuccess($result, 'Đã hủy yêu cầu thành công');
    }
}
