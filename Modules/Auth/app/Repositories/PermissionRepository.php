<?php

namespace Modules\Auth\Repositories;

use App\Repositories\BaseRepository;
use Modules\Auth\Models\Permission;
use Modules\Auth\Repositories\Contracts\PermissionRepositoryInterface;

class PermissionRepository extends BaseRepository implements PermissionRepositoryInterface
{
    protected array $searchable = ['permission_key', 'permission_name'];

    protected array $filterable = ['status', 'scope_type'];

    public function __construct(Permission $model)
    {
        parent::__construct($model);
    }
}
