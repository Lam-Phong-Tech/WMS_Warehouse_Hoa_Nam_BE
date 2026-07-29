<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Modules\Warehouse\Models\SafeStockConfig;
use Modules\Warehouse\Repositories\Contracts\SafeStockConfigRepositoryInterface;

class SafeStockConfigRepository extends BaseRepository implements SafeStockConfigRepositoryInterface
{
    protected array $filterable = ['product_id', 'warehouse_id'];

    public function __construct(SafeStockConfig $model)
    {
        parent::__construct($model);
    }
}
