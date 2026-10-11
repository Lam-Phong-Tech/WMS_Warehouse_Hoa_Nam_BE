<?php

namespace Modules\Warehouse\Support;

/** Read-model semantics only; never a write receipt or a posting decision. */
final class P23ScanSessionPolicy
{
    public const CONTRACT = 'mini-app-p23-read-v2';

    public static function counts(?array $statuses): array
    {
        if ($statuses === null) {
            return ['total' => null, 'accepted' => null, 'duplicate' => null, 'other' => null,
                'explicitRejected' => null, 'rejected' => null, 'statusTotals' => null,
                'countStatus' => 'SOURCE_UNAVAILABLE', ...self::unknownQuantity()];
        }
        $base = P08HistoryPolicy::scanCounts($statuses);

        // Preserve P08 OTHER (including explicit REJECTED). A complete business
        // rejection mapping has NOT been approved. Do not equate OTHER to it.
        return $base + ['explicitRejected' => (int) ($statuses['REJECTED'] ?? 0),
            'rejected' => null, 'countStatus' => 'AVAILABLE_RETAINED_EVENTS',
            ...self::unknownQuantity()];
    }

    private static function unknownQuantity(): array
    {
        return ['quantity' => null, 'quantityStatus' => 'NOT_RECORDED_IN_SCAN_EVIDENCE',
            'issuedQuantity' => null, 'issuedQuantityStatus' => 'NO_AUTHORITATIVE_BATCH_POSTING_QUANTITY',
            'rejectionMappingStatus' => 'PENDING_APPROVED_STATUS_MAP',
            'isConfirmationReceipt' => false];
    }
}
