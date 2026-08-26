<?php

namespace Modules\Warehouse\Support\Concerns;

use App\Support\ApiError;

/**
 * Guarded variant of {@see ResolvesActorOrNull}: aborts 401 UNAUTHENTICATED
 * instead of returning null. Use for mutating actions that must always be
 * able to stamp an actor onto `*_by` columns / audit_log.
 */
trait ResolvesActor
{
    use ResolvesActorOrNull;

    private function actorId(): string
    {
        $id = $this->actorIdOrNull();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.');
        }

        return $id;
    }
}
