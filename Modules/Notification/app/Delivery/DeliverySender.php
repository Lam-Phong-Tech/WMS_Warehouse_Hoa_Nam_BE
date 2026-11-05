<?php
namespace Modules\Notification\Delivery;
interface DeliverySender
{
    /** Outcome is provider acceptance only; never delivery or read acknowledgement. */
    public function send(string $token,array $data): array;
}
