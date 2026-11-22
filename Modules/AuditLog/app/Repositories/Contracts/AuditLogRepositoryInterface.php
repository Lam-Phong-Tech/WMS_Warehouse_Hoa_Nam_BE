<?php

namespace Modules\AuditLog\Repositories\Contracts;

use App\Repositories\Contracts\RepositoryInterface;

interface AuditLogRepositoryInterface extends RepositoryInterface
{
    /** @param list<string>|null $allowedModules */
    public function actorIdForEntry(string $logId, ?array $allowedModules): ?string;
}
