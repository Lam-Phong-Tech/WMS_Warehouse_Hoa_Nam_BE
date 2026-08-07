<?php

namespace Modules\Warehouse\Repositories;

use App\Repositories\BaseRepository;
use Modules\Warehouse\Models\ImportBatch;
use Modules\Warehouse\Repositories\Contracts\ImportBatchRepositoryInterface;

class ImportBatchRepository extends BaseRepository implements ImportBatchRepositoryInterface
{
    protected array $searchable = ['import_batch_code', 'supplier_name', 'supplier_batch_no'];

    protected array $filterable = ['status'];

    public function __construct(ImportBatch $model)
    {
        parent::__construct($model);
    }
}
