<?php

namespace Modules\Auth\Repositories;

use App\Repositories\BaseRepository;
use Modules\Auth\Models\User;
use Modules\Auth\Repositories\Contracts\UserRepositoryInterface;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    protected array $searchable = ['username', 'display_name', 'email'];

    protected array $filterable = ['status'];

    /**
     * IAM-01 needs the persisted role assignment and pivot scope in list/detail
     * responses. Keep this at repository level so every user read has one
     * canonical response shape instead of UI-only role/scope reconstruction.
     */
    protected array $with = ['roles'];

    public function __construct(User $model)
    {
        parent::__construct($model);
    }
}
