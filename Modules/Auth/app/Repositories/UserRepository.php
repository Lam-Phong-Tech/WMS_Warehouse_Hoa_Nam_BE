<?php

namespace Modules\Auth\Repositories;

use App\Repositories\BaseRepository;
use Modules\Auth\Models\User;
use Modules\Auth\Repositories\Contracts\UserRepositoryInterface;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    protected array $searchable = ['username', 'display_name', 'email'];

    protected array $filterable = ['status', 'department_id'];

    protected array $with = ['roles'];

    public function __construct(User $model)
    {
        parent::__construct($model);
    }
}
