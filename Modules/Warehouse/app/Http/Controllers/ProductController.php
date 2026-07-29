<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
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
