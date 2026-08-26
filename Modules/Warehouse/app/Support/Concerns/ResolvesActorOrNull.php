<?php

namespace Modules\Warehouse\Support\Concerns;

/**
 * Single source of truth for resolving the authenticated actor id (`auth('api')->id()`)
 * used to stamp `*_by` columns and `AuditLogger::record()` calls across Warehouse
 * controllers/services. Returns null when unauthenticated instead of aborting —
 * used by call sites that legitimately tolerate an anonymous actor (e.g. deny-audit,
 * read-only reports). Previously duplicated ~28 times with inconsistent 401 guarding.
 */
trait ResolvesActorOrNull
{
    private function actorIdOrNull(): ?string
    {
        $id = auth('api')->id();

        return $id === null ? null : (string) $id;
    }
}
