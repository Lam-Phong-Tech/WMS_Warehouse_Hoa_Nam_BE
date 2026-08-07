<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Repositories\Contracts\PhysicalCodeRepositoryInterface;

class PhysicalCodeRepository extends BaseRepository implements PhysicalCodeRepositoryInterface
{
    protected array $searchable = ['code_value', 'hardware_uid'];

    protected array $filterable = ['code_type', 'carrier_type', 'status'];

    public function __construct(PhysicalCode $model)
    {
        parent::__construct($model);
    }
}
