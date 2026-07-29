<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Http\Requests\StoreSafeStockConfigRequest;
use Modules\Warehouse\Repositories\Contracts\SafeStockConfigRepositoryInterface;

/**
 * Chỉ CRUD định mức — KHÔNG tự đánh giá cảnh báo tại đây. Đánh giá cảnh báo chỉ chạy khi tồn
 * kho thực sự đổi (StockLedger sau mỗi movement), để lịch sử cảnh báo phản ánh đúng sự kiện
 * đổi tồn, không phải sự kiện cấu hình định mức.
 */
class SafeStockConfigController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SafeStockConfigRepositoryInterface $configs) {}

    public function index(Request $request)
    {
        return $this->respondPaginated($this->configs->paginate($request->all(), (int) $request->input('per_page', 15)));
    }

    public function store(StoreSafeStockConfigRequest $request)
    {
        $config = $this->configs->create($request->validated());

        return $this->respondCreated($config, 'Tạo định mức tồn kho an toàn thành công');
    }

    public function show(string $id)
    {
        $config = $this->configs->find($id);

        return $config ? $this->respondSuccess($config) : $this->respondNotFound('Không tìm thấy định mức.');
    }
}
