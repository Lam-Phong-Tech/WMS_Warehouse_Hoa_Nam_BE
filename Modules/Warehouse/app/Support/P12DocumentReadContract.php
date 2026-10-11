<?php

namespace Modules\Warehouse\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only additions to existing document routes; not an All-list aggregate API. */
final class P12DocumentReadContract
{
    public static function validateList(Request $request): void
    {
        $endRules = ['nullable', 'date_format:Y-m-d'];
        if ($request->filled('date_from')) {
            $endRules[] = 'after_or_equal:date_from';
        }
        $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
            'keyword' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
            'page_size' => ['sometimes', 'integer', 'between:1,200'],
            'sort_order' => ['sometimes', 'string', 'in:asc,desc'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => $endRules,
            'timezone' => ['sometimes', 'string', 'timezone'],
            'cursor' => ['prohibited'],
        ]);
    }

    public static function applyCreatedRange(EloquentBuilder|Builder $query, Request $request): void
    {
        $timezone = $request->query('timezone', 'Asia/Ho_Chi_Minh');
        $storageTimezone = config('app.timezone', 'UTC');
        if ($request->filled('date_from')) {
            $start = CarbonImmutable::parse($request->query('date_from'), $timezone)->startOfDay();
            $query->where('created_at', '>=', $start->setTimezone($storageTimezone)->format('Y-m-d H:i:s'));
        }
        if ($request->filled('date_to')) {
            // Calendar-day exclusive upper bound, not 23:59:59 (fractional timestamps).
            $end = CarbonImmutable::parse($request->query('date_to'), $timezone)->startOfDay()->addDay();
            $query->where('created_at', '<', $end->setTimezone($storageTimezone)->format('Y-m-d H:i:s'));
        }
    }

    public static function validateEvidence(Request $request): void
    {
        $request->validate([
            'status' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
            'page_size' => ['sometimes', 'integer', 'between:1,200'],
            'cursor' => ['prohibited'],
        ]);
    }

    /** Aggregate the entire parent/status query BEFORE pagination, never the page items. */
    public static function evidenceSummary(Builder $query): array
    {
        $base = DB::query()->fromSub((clone $query)->reorder(), 'p12_evidence');
        $counts = (clone $base)->selectRaw(
            'COUNT(*) AS entry_count, COUNT(DISTINCT sku_id) AS sku_count, '
            .'COUNT(DISTINCT item_id) AS item_count, '
            ."COUNT(DISTINCT NULLIF(TRIM(serial_number), '')) AS resolved_serial_count"
        )->first();
        $byUnit = (clone $base)->select('unit')
            ->selectRaw('COUNT(*) AS entry_count, COUNT(quantity) AS known_quantity_count, SUM(quantity) AS known_quantity')
            ->groupBy('unit')->orderBy('unit')->get()->map(function (object $row): array {
                $missing = (int) $row->entry_count - (int) $row->known_quantity_count;

                return [
                    'unit' => $row->unit,
                    'quantity' => $missing === 0 ? self::number($row->known_quantity) : null,
                    'known_quantity' => self::number($row->known_quantity),
                    'entry_count' => (int) $row->entry_count,
                    'missing_quantity_count' => $missing,
                ];
            })->all();
        $entryCount = (int) $counts->entry_count;
        $missingQuantity = array_sum(array_column($byUnit, 'missing_quantity_count'));
        $unknownUnit = 0;
        $knownUnits = 0;
        foreach ($byUnit as $unit) {
            if (trim((string) $unit['unit']) === '') {
                $unknownUnit += $unit['entry_count'];
            } else {
                $knownUnits++;
            }
        }
        $total = $entryCount === 0 ? 0 : null;
        if (count($byUnit) === 1 && $unknownUnit === 0 && $missingQuantity === 0) {
            $total = $byUnit[0]['quantity'];
        }

        return [
            'scope' => 'FILTERED_DOCUMENT_SCAN_ENTRIES',
            'entry_count' => $entryCount,
            'sku_count' => (int) $counts->sku_count,
            'item_count' => (int) $counts->item_count,
            // Candidate serials are not silently counted as resolved physical serials.
            'resolved_serial_count' => (int) $counts->resolved_serial_count,
            'quantity_total' => $total,
            'quantity_by_unit' => $byUnit,
            'quantity_complete' => $missingQuantity === 0,
            'missing_quantity_count' => $missingQuantity,
            'unit_complete' => $unknownUnit === 0,
            'unknown_unit_entry_count' => $unknownUnit,
            'mixed_units' => $knownUnits > 1,
            'coverage' => 'CURRENT_QUERY',
        ];
    }

    public static function evidenceResponse(JsonResponse $response, array $summary, string $id, string $status): JsonResponse
    {
        // A rolling deploy can briefly read the previous owner's cached summary.
        // Do not publish its SQL SUM as a complete quantity until that cache refreshes.
        if (! array_key_exists('quantity_complete', $summary)) {
            $summary['quantity_total'] = null;
            $summary['quantity_by_unit'] = array_map(static fn (array $unit): array => [
                'unit' => $unit['unit'], 'quantity' => null, 'known_quantity' => null,
                'entry_count' => null, 'missing_quantity_count' => null,
            ], $summary['quantity_by_unit'] ?? []);
            $summary += ['quantity_complete' => null, 'missing_quantity_count' => null,
                'unit_complete' => null, 'unknown_unit_entry_count' => null,
                'mixed_units' => null, 'coverage' => 'LEGACY_CACHE_PENDING_REFRESH'];
        }
        $body = $response->getData(true);
        $body['meta'] += [
            'contract' => 'p12-scan-evidence-v2',
            'document_id' => $id,
            'scan_status' => $status,
            'summary' => $summary,
            'sort' => ['created_at' => 'desc', 'id' => 'desc'],
            'pagination_mode' => 'offset',
            'snapshot_consistent' => false,
        ];

        return $response->setData($body)->header('Cache-Control', 'private, no-store');
    }

    public static function listResponse(JsonResponse $response, Request $request, string $source): JsonResponse
    {
        $body = $response->getData(true);
        $order = $request->query('sort_order', 'desc');
        $body['meta']['count_evidence'] = SourceCountEvidence::make($request, (int) $body['meta']['total'],
            ['warehouse_id', 'page', 'per_page', 'page_size', 'sort_order', 'timezone']);
        $body['meta']['read_contract'] = [
            'source' => $source,
            'date_field' => 'created_at',
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'timezone' => $request->query('timezone', 'Asia/Ho_Chi_Minh'),
            'sort' => ['created_at' => $order, 'id' => $order],
            'pagination_mode' => 'offset',
            'snapshot_consistent' => false,
            'all_sources_aggregate' => false,
        ];

        return $response->setData($body)->header('Cache-Control', 'private, no-store');
    }

    private static function number(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }
        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }
}
