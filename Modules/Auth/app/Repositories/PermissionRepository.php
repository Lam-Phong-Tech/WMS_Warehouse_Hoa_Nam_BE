<?php

namespace Modules\Auth\Repositories;

use App\Repositories\BaseRepository;
use Modules\Auth\Models\Permission;
use Modules\Auth\Repositories\Contracts\PermissionRepositoryInterface;

class PermissionRepository extends BaseRepository implements PermissionRepositoryInterface
{
    protected array $searchable = ['permission_key', 'permission_name'];

    protected array $filterable = ['status', 'scope_type'];

    // Danh mục permission gần như tĩnh (chỉ đổi qua seeder/migration, không có API
    // create/update/delete — xem PermissionController), TTL dài hơn RBAC theo user.
    protected bool $cacheEnabled = true;

    protected string $cacheDomain = 'auth.permission';

    protected string $cacheTtlPolicy = 'reference';

    public function __construct(Permission $model)
    {
        parent::__construct($model);
    }
}
