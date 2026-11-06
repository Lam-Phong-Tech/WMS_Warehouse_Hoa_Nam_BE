<?php

namespace App\Events;

/** Internal signal emitted after the committed namespace invalidation attempt. */
final class CacheNamespaceInvalidated
{
    public function __construct(public readonly string $namespace) {}
}
