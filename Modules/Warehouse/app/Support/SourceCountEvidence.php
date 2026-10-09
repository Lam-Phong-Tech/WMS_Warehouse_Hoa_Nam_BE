<?php

namespace Modules\Warehouse\Support;

use Illuminate\Http\Request;

/** Count evidence for one authorized source, never an all-documents aggregate. */
final class SourceCountEvidence
{
    public static function make(Request $request, int $total, array $transportKeys, array $neutralFilters = []): array
    {
        $unfiltered = $request->getContent() === '';
        foreach ($request->query() as $key => $value) {
            if (in_array($key, $transportKeys, true)
                || in_array($value, $neutralFilters[$key] ?? [], true)
                || $value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }
            // Unknown query fields also withhold unfiltered evidence. In particular,
            // false/0 readiness filters are filters, not an absent selection.
            $unfiltered = false;
            break;
        }

        return [
            'contract' => 'authorized-source-count-v1',
            'scope' => 'CURRENT_AUTHORIZED_ENDPOINT_QUERY',
            'filtered_total' => $total,
            'unfiltered_request' => $unfiltered,
            'unfiltered_authorized_total' => $unfiltered ? $total : null,
            'source_empty' => $unfiltered ? $total === 0 : null,
            'all_sources_aggregate' => false,
            'cross_request_snapshot' => false,
        ];
    }
}
