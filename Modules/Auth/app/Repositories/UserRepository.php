<?php

namespace Modules\Auth\Repositories;

use App\Repositories\BaseRepository;
use Modules\Auth\Models\User;
use Modules\Auth\Repositories\Contracts\UserRepositoryInterface;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    protected array $searchable = ['username', 'display_name', 'email'];

    protected array $filterable = ['status', 'department_id'];

    // GĐ1-REQ-011: FE cột "Phòng ban" cần tên phòng ban ngay trong user list —
    // eager-load để không trả UUID trần và không N+1.
    protected array $with = ['roles', 'department'];

    public function __construct(User $model)
    {
        parent::__construct($model);
    }
}
