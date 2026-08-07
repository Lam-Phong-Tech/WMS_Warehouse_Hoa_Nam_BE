<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Repositories\Contracts\ContainerRepositoryInterface;

class ContainerRepository extends BaseRepository implements ContainerRepositoryInterface
{
    protected array $searchable = ['container_code', 'zone_code'];

    protected array $filterable = ['container_type', 'status', 'zone_code'];

    public function __construct(Container $model)
    {
        parent::__construct($model);
    }
}
