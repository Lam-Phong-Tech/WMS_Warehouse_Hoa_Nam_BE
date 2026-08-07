<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Modules\Warehouse\Models\DefectCatalog;
use Modules\Warehouse\Repositories\Contracts\DefectCatalogRepositoryInterface;

class DefectCatalogRepository extends BaseRepository implements DefectCatalogRepositoryInterface
{
    protected array $searchable = ['defect_code', 'defect_name'];

    protected array $filterable = ['status'];

    public function __construct(DefectCatalog $model)
    {
        parent::__construct($model);
    }
}
