<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Http\Requests\StoreStockRequestRequest;
use Modules\Warehouse\Models\StockRequest;
use Modules\Warehouse\Services\StockRequestService;

class StockRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockRequestService $service) {}

    public function index(Request $request)
    {
        $query = StockRequest::query()->with('lines');

        if ($request->filled('request_type')) {
            $query->where('request_type', $request->input('request_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->respondPaginated($query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 15)));
    }

    public function show(string $id)
    {
        $stockRequest = StockRequest::with(['lines', 'convertedDocument'])->find($id);
        if (! $stockRequest) {
            return $this->respondNotFound('Không tìm thấy yêu cầu.');
        }

        return $this->respondSuccess($stockRequest);
    }

    public function store(StoreStockRequestRequest $request)
    {
        $stockRequest = $this->service->create($request->input('request_type'), $request->validated(), $this->userId());

        return $this->respondCreated($stockRequest, 'Tạo yêu cầu thành công');
    }

    public function submit(string $id)
    {
        return $this->respondSuccess($this->service->submit($this->find($id), $this->userId()), 'Gửi duyệt thành công');
    }

    public function approve(string $id)
    {
        return $this->respondSuccess($this->service->approve($this->find($id), $this->userId()), 'Duyệt yêu cầu thành công');
    }

    public function reject(string $id, Request $request)
    {
        return $this->respondSuccess($this->service->reject($this->find($id), $request->input('reason'), $this->userId()), 'Từ chối yêu cầu thành công');
    }

    public function cancel(string $id, Request $request)
    {
        return $this->respondSuccess($this->service->cancel($this->find($id), $request->input('reason'), $this->userId()), 'Huỷ yêu cầu thành công');
    }

    public function convert(string $id)
    {
        return $this->respondSuccess($this->service->convert($this->find($id), $this->userId()), 'Chuyển đổi thành phiếu thành công');
    }

    private function find(string $id): StockRequest
    {
        $stockRequest = StockRequest::with('lines')->find($id);
        if (! $stockRequest) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy yêu cầu.');
        }

        return $stockRequest;
    }

    private function userId(): string
    {
        return (string) auth('api')->id();
    }
}
