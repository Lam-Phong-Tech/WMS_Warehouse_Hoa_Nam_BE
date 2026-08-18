<?php

namespace Modules\Auth\Repositories;

use App\Repositories\BaseRepository;
use Modules\Auth\Models\Role;
use Modules\Auth\Repositories\Contracts\RoleRepositoryInterface;

class RoleRepository extends BaseRepository implements RoleRepositoryInterface
{
    protected array $searchable = ['role_code', 'role_name'];

    protected array $filterable = ['status', 'scope_type'];

    /**
     * Role detail/list is the authoritative RBAC read model for FE. Always return
     * permission membership so the UI never has to infer assignments from the
     * permission catalog or issue N+1 role-detail calls.
     */
    protected array $with = ['permissions'];

    protected bool $cacheEnabled = true;

    protected string $cacheDomain = 'auth.role';

    protected string $cacheTtlPolicy = 'rbac';

    public function __construct(Role $model)
    {
        parent::__construct($model);
    }
}
