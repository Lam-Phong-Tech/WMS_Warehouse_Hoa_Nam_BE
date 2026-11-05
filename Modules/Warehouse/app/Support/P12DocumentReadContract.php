<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            $query->where('created_at', '>=', $start->setTimezone($storageTimezone));
        }
        if ($request->filled('date_to')) {
            // Calendar-day exclusive upper bound, not 23:59:59 (fractional timestamps).
            $end = CarbonImmutable::parse($request->query('date_to'), $timezone)->startOfDay()->addDay();
            $query->where('created_at', '<', $end->setTimezone($storageTimezone));
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
        self::requireEvidenceParent($request);
    }

    /** Existing scan GETs must recheck the typed, live parent before any cache hit. */
    private static function requireEvidenceParent(Request $request): void
    {
        $parents = [
            'api/v1/mini-app/inbound-documents/{id}/scan-entries' => ['receipt', 'dst_warehouse_id'],
            'api/v1/mini-app/outbound-documents/{id}/scan-entries' => ['issue', 'src_warehouse_id'],
        ];
        $parent = $parents[$request->route()?->uri() ?? ''] ?? null;
        if ($parent === null || ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return;
        }
        [$type, $warehouseField] = $parent;
        $id = (string) $request->route('id');
        if (! Str::isUuid($id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nguồn của bằng chứng quét.');
        }
        $document = DB::table('stock_documents')->where('id', $id)
            ->where('doc_type', $type)->whereNull('deleted_at')->first(['id', $warehouseField]);
        if ($document === null || ! Str::isUuid((string) $document->{$warehouseField})) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nguồn của bằng chứng quét.');
        }
        $actor = $request->user();
        if ($actor === null || ! method_exists($actor, 'canAccessWarehouse')
            || ! $actor->canAccessWarehouse((string) $document->{$warehouseField})) {
            ApiError::abort(403, 'DATA_SCOPE_DENIED', 'Phiếu nguồn nằm ngoài phạm vi kho.');
        }
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
        // A rolling deploy can briefly read old or incompletely upgraded cache entries.
        // One boolean alone is not evidence that all v2 quantity fields are present.
        if (! self::hasV2Summary($summary)) {
            $units = is_array($summary['quantity_by_unit'] ?? null) ? $summary['quantity_by_unit'] : [];
            $summary = array_replace($summary, [
                'quantity_total' => null,
                'quantity_by_unit' => array_values(array_map(static fn (array $unit): array => [
                    'unit' => $unit['unit'] ?? null, 'quantity' => null, 'known_quantity' => null,
                    'entry_count' => null, 'missing_quantity_count' => null,
                ], array_filter($units, 'is_array'))),
                'quantity_complete' => null, 'missing_quantity_count' => null,
                'unit_complete' => null, 'unknown_unit_entry_count' => null,
                'mixed_units' => null, 'coverage' => 'LEGACY_CACHE_PENDING_REFRESH',
            ]);
        }
        $body = $response->getData(true);
        // Reserved evidence fields belong to THIS route/summary, never old response meta.
        // Preserve owner pagination/request metadata, but do not use array union here.
        $body['meta'] = array_replace(is_array($body['meta'] ?? null) ? $body['meta'] : [], [
            'contract' => 'p12-scan-evidence-v2',
            'document_id' => $id,
            'scan_status' => $status,
            'summary' => $summary,
            'sort' => ['created_at' => 'desc', 'id' => 'desc'],
            'pagination_mode' => 'offset',
            'snapshot_consistent' => false,
        ]);

        return $response->setData($body)->header('Cache-Control', 'private, no-store');
    }

    private static function hasV2Summary(array $summary): bool
    {
        foreach (['quantity_total', 'quantity_by_unit', 'quantity_complete', 'missing_quantity_count',
            'unit_complete', 'unknown_unit_entry_count', 'mixed_units', 'coverage'] as $field) {
            if (! array_key_exists($field, $summary)) {
                return false;
            }
        }
        if (! is_bool($summary['quantity_complete']) || ! is_bool($summary['unit_complete'])
            || ! is_bool($summary['mixed_units']) || $summary['coverage'] !== 'CURRENT_QUERY'
            || ! is_array($summary['quantity_by_unit'])
            || ! is_int($summary['missing_quantity_count']) || $summary['missing_quantity_count'] < 0
            || ! is_int($summary['unknown_unit_entry_count']) || $summary['unknown_unit_entry_count'] < 0) {
            return false;
        }
        foreach ($summary['quantity_by_unit'] as $unit) {
            if (! is_array($unit)) {
                return false;
            }
            foreach (['unit', 'quantity', 'known_quantity', 'entry_count', 'missing_quantity_count'] as $field) {
                if (! array_key_exists($field, $unit)) {
                    return false;
                }
            }
            if (! is_int($unit['entry_count']) || $unit['entry_count'] < 1
                || ! is_int($unit['missing_quantity_count']) || $unit['missing_quantity_count'] < 0
                || $unit['missing_quantity_count'] > $unit['entry_count']) {
                return false;
            }
        }

        return true;
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
