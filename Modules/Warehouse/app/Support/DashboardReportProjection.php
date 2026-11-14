<?php

namespace Modules\Warehouse\Support;

/** Lossless field selection from canonical reports; never filters or aggregates rows. */
final class DashboardReportProjection
{
    public static function xnt(array $report): array
    {
        return self::rows($report, 'lines', [
            'product_code', 'product_name', 'opening', 'receipt_net', 'issue_net',
            'component_issue_net', 'reversal_unlinked_net', 'closing',
        ]);
    }

    public static function thresholds(array $report): array
    {
        return self::rows($report, 'rows', [
            'product_code', 'product_name', 'sku_type', 'available_qty',
        ]);
    }

    private static function rows(array $report, string $key, array $fields): array
    {
        $keep = array_flip($fields);

        return [$key => array_map(
            static fn (array $row): array => array_intersect_key($row, $keep),
            $report[$key]
        )];
    }
}
