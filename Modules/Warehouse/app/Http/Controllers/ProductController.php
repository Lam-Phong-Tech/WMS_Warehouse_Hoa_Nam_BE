<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreProductRequest;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductRepositoryInterface $products) {}

    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->products->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    public function store(StoreProductRequest $request)
    {
        $product = $this->products->create($request->validated() + ['status' => 'ACTIVE']);

        AuditLogger::record(
            'CREATE',
            'product',
            $product->getKey(),
            auth('api')->id(),
            $request->attributes->get('request_id'),
            afterData: $product->only([
                'id', 'code', 'sku_code', 'name', 'unit', 'sku_type',
                'brand_code', 'category_code', 'model_code', 'published_to_app',
                'is_serial_tracked', 'status',
            ])
        );

        return $this->respondCreated($product, 'Tạo sản phẩm thành công');
    }

    public function show(string $id)
    {
        $product = $this->products->find($id);

        if (! $product) {
            return $this->respondNotFound('Không tìm thấy sản phẩm.');
        }

        return $this->respondSuccess($product);
    }
}
