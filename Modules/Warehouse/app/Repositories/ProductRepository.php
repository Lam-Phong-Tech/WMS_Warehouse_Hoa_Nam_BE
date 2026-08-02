<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;

class ProductRepository extends BaseRepository implements ProductRepositoryInterface
{
    protected array $searchable = ['sku_code', 'sku_name'];

    protected array $filterable = ['status', 'sku_type'];

    public function __construct(Sku $model)
    {
        parent::__construct($model);
    }
}
