<?php

namespace Modules\AuditLog\Repositories;

use App\Repositories\BaseRepository;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Repositories\Contracts\AuditLogRepositoryInterface;

class AuditLogRepository extends BaseRepository implements AuditLogRepositoryInterface
{
    protected array $filterable = ['action', 'object_type', 'object_id', 'actor_id'];

    public function __construct(AuditLog $model)
    {
        parent::__construct($model);
    }
}
