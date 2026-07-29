<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Repositories\Contracts\WarehouseRepositoryInterface;

class WarehouseRepository extends BaseRepository implements WarehouseRepositoryInterface
{
    protected array $searchable = ['code', 'name'];

    protected array $filterable = ['status'];

    public function __construct(Warehouse $model)
    {
        parent::__construct($model);
    }
}
