<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Modules\Warehouse\Models\Agency;
use Modules\Warehouse\Repositories\Contracts\AgencyRepositoryInterface;

class AgencyRepository extends BaseRepository implements AgencyRepositoryInterface
{
    protected array $searchable = ['agency_code', 'agency_name'];

    protected array $filterable = ['status'];

    public function __construct(Agency $model)
    {
        parent::__construct($model);
    }
}
