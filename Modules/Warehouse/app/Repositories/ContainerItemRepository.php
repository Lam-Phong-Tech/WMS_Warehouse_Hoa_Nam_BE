<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Modules\Warehouse\Models\ContainerItem;
use Modules\Warehouse\Repositories\Contracts\ContainerItemRepositoryInterface;

class ContainerItemRepository extends BaseRepository implements ContainerItemRepositoryInterface
{
    protected array $filterable = ['container_id', 'status'];

    public function __construct(ContainerItem $model)
    {
        parent::__construct($model);
    }
}
