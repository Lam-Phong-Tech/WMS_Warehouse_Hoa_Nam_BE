<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
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
                new OA\Property(property: 'request_type', type: 'string', enum: ['receipt', 'issue', 'transfer']),
                new OA\Property(property: 'request_no', type: 'string', nullable: true),
                new OA\Property(property: 'request_date', type: 'string', format: 'date'),
                new OA\Property(property: 'src_warehouse_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'dst_warehouse_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'note', type: 'string', nullable: true),
                new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'qty_planned', type: 'number', format: 'float'),
                        new OA\Property(property: 'unit_cost', type: 'number', format: 'float', nullable: true),
                        new OA\Property(property: 'note', type: 'string', nullable: true),
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
        new OA\Property(property: 'reason', type: 'string'),
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
        new OA\Property(property: 'reason', type: 'string', nullable: true),
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
        $query = StockRequest::query()->with($this->relations());

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('request_type')) {
            $query->where('request_type', $request->input('request_type'));
        }

        return $this->respondPaginated($query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 15)));
    }

    public function show(string $id)
    {
        $request = StockRequest::with($this->relations())->find($id);

        return $request ? $this->respondSuccess($request) : $this->respondNotFound('Không tìm thấy yêu cầu.');
    }

    public function store(StoreStockRequestRequest $request)
    {
        $data = $request->validated();
        $created = $this->service->create($data['request_type'], $data, $this->userId());

        return $this->respondCreated($created, 'Tạo yêu cầu thành công');
    }

    public function update(UpdateStockRequestRequest $request, string $id)
    {
        if (! StockRequest::find($id)) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }

        $updated = $this->service->update($id, $request->validated(), $this->userId());

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

        return $this->respondSuccess(null, 'Xóa yêu cầu thành công');
    }

    public function submit(string $id)
    {
        if (! StockRequest::find($id)) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }

        return $this->respondSuccess($this->service->submit($id, $this->userId()), 'Đã gửi duyệt yêu cầu');
    }

    public function approve(string $id)
    {
        if (! StockRequest::find($id)) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }

        return $this->respondSuccess($this->service->approve($id, $this->userId()), 'Đã duyệt yêu cầu');
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

        return $this->respondSuccess($this->service->reject($id, $reason, $this->userId()), 'Đã từ chối duyệt yêu cầu');
    }

    public function convert(string $id)
    {
        if (! StockRequest::find($id)) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }

        return $this->respondSuccess($this->service->convert($id, $this->userId()), 'Chuyển đổi sang phiếu nghiệp vụ thành công');
    }

    public function cancel(Request $request, string $id)
    {
        if (! StockRequest::find($id)) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }

        return $this->respondSuccess($this->service->cancel($id, $request->input('reason'), $this->userId()), 'Đã hủy yêu cầu thành công');
    }
}
