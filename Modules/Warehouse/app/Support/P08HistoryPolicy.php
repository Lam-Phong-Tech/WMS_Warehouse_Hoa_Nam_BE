<?php

namespace Modules\Warehouse\Support;

use InvalidArgumentException;

/** Pure rules: business identities never fall back to a different ID namespace. */
final class P08HistoryPolicy
{
    public const SOURCES = ['DOCUMENT', 'WARRANTY', 'NFC', 'SCAN'];
    public const GROUPS = ['INBOUND', 'OUTBOUND', 'WARRANTY', 'NFC', 'SCAN', 'OTHER'];

    public static function identity(string $value): array
    {
        if (! preg_match('/^(DOCUMENT|WARRANTY|NFC|SCAN):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/iD', $value, $match)) {
            throw new InvalidArgumentException('INVALID_HISTORY_ID');
        }

        return [strtoupper($match[1]), strtolower($match[2])];
    }

    public static function fingerprint(array $scope, array $filters): string
    {
        ksort($filters);
        ksort($scope);

        return hash('sha256', json_encode([$scope, $filters], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function cursorMatches(array $cursor, string $fingerprint, int $now): bool
    {
        return ($cursor['v'] ?? null) === 1
            && is_int($cursor['expires'] ?? null) && $cursor['expires'] > $now
            && is_string($cursor['scope'] ?? null) && hash_equals($fingerprint, $cursor['scope'])
            && is_string($cursor['asOf'] ?? null) && is_string($cursor['time'] ?? null)
            && is_string($cursor['activityId'] ?? null);
    }

    public static function pattern(string $keyword): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($keyword)).'%';
    }

    public static function scanCounts(array $statuses): array
    {
        $statuses = array_map('intval', $statuses);
        $accepted = $statuses['ACCEPTED'] ?? 0;
        $duplicate = $statuses['DUPLICATE'] ?? 0;
        $total = array_sum($statuses);

        // RESOLVED is not evidence of acceptance/posting; unknown statuses remain visible.
        return ['total' => $total, 'accepted' => $accepted, 'duplicate' => $duplicate,
            'other' => $total - $accepted - $duplicate, 'statusTotals' => $statuses,
            'quantity' => null, 'quantityStatus' => 'NOT_RECORDED_IN_SCAN_EVIDENCE',
            'acceptedStatusValues' => ['ACCEPTED'], 'duplicateStatusValues' => ['DUPLICATE']];
    }
}
