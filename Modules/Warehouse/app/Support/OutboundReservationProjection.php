<?php

namespace Modules\Warehouse\Support;

/** Presentation only: a hidden reservation still blocks outbound eligibility. */
final class OutboundReservationProjection
{
    public static function make(array $reservation, bool $canReadDocument): array
    {
        if (! $canReadDocument || empty($reservation['document_id'])) {
            // Keep a non-null reservation. Null would incorrectly imply no hold.
            return ['redacted' => true, 'can_open' => false];
        }

        // Do not accidentally expose future private fields added to the query.
        return array_intersect_key($reservation, array_flip([
            'match_id', 'document_id', 'doc_no', 'status', 'outbound_request_id',
        ])) + ['redacted' => false, 'can_open' => true];
    }
}
