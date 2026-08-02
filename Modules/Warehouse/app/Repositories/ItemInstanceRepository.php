<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Repositories\Contracts\ItemInstanceRepositoryInterface;

class ItemInstanceRepository extends BaseRepository implements ItemInstanceRepositoryInterface
{
    protected array $searchable = ['serial_number'];

    protected array $filterable = ['sku_id', 'item_status', 'item_condition', 'container_id'];

    public function __construct(ItemInstance $model)
    {
        parent::__construct($model);
    }
}
