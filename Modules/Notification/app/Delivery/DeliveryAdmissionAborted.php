<?php

namespace Modules\Notification\Delivery;

use RuntimeException;

/** Roll back a reserved slot if its delivery is no longer safe to send. */
final class DeliveryAdmissionAborted extends RuntimeException
{
    public function __construct(public readonly string $state, public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
