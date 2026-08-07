<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Modules\Warehouse\Models\ProductMaster;
use Modules\Warehouse\Repositories\Contracts\ProductMasterRepositoryInterface;

class ProductMasterRepository extends BaseRepository implements ProductMasterRepositoryInterface
{
    protected array $searchable = ['code', 'name'];

    protected array $filterable = ['master_type', 'status'];

    public function __construct(ProductMaster $model)
    {
        parent::__construct($model);
    }
}
