<?php

namespace Modules\Auth\Repositories;

use App\Repositories\BaseRepository;
use Modules\Auth\Models\Role;
use Modules\Auth\Repositories\Contracts\RoleRepositoryInterface;

class RoleRepository extends BaseRepository implements RoleRepositoryInterface
{
    protected array $searchable = ['role_code', 'role_name'];

    protected array $filterable = ['status', 'scope_type'];

    public function __construct(Role $model)
    {
        parent::__construct($model);
    }
}
