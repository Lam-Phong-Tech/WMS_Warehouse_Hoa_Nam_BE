<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreWarehouseRequest;
use Modules\Warehouse\Repositories\Contracts\WarehouseRepositoryInterface;

class WarehouseController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WarehouseRepositoryInterface $warehouses) {}

    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->warehouses->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    public function store(StoreWarehouseRequest $request)
    {
        $warehouse = $this->warehouses->create($request->validated() + ['status' => 'ACTIVE']);

        AuditLogger::record(
            'CREATE',
            'warehouse',
            $warehouse->getKey(),
            auth('api')->id(),
            $request->attributes->get('request_id'),
            afterData: $warehouse->only(['id', 'code', 'name', 'address', 'status'])
        );

        return $this->respondCreated($warehouse, 'Tạo kho thành công');
    }

    public function show(string $id)
    {
        $warehouse = $this->warehouses->find($id);

        if (! $warehouse) {
            return $this->respondNotFound('Không tìm thấy kho.');
        }

        return $this->respondSuccess($warehouse);
    }
}
