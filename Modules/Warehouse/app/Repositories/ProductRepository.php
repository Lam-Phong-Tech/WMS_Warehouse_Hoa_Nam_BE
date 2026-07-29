<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;

class ProductRepository extends BaseRepository implements ProductRepositoryInterface
{
    protected array $searchable = ['code', 'name'];

    protected array $filterable = ['status', 'sku_type'];

    public function __construct(Product $model)
    {
        parent::__construct($model);
    }
}
