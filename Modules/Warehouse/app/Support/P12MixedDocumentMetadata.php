<?php

namespace Modules\Warehouse\Support;

use App\Support\DocumentTimestamp;
use Carbon\CarbonImmutable;

/** Public response metadata excludes actor/session IDs and raw search text. */
final class P12MixedDocumentMetadata
{
    public static function make(array $scope, array $filters, array $state, array $facets, array $page, array $snapshot): array
    {
        $options = P12MixedDocumentQuery::statusOptions($scope['allowed']);

        return [
            'contract' => P12MixedDocumentReadModel::CONTRACT,
            'timestamp_contract' => DocumentTimestamp::CONTRACT,
            'timestamps' => DocumentTimestamp::metadata(),
            'cursor_version' => 3,
            'warehouse_id' => $scope['warehouse_id'],
            'warehouse_context' => ['status' => 'READY', 'source' => 'CONFIRMED_SERVER_SESSION'],
            'source' => $filters['source'], 'total' => $facets[$filters['source']]['total'],
            'count_unit' => 'DOCUMENT_LIST_RECORD', 'per_page' => $filters['per_page'], ...$page,
            'pagination_mode' => 'KEYSET', 'cursor_ttl_seconds' => 900,
            'cursor_expires_at' => CarbonImmutable::createFromTimestampUTC($state['expires'])->toISOString(),
            'upper_bound_created_at' => DocumentTimestamp::utc($state['upper_bound'], 'UTC'),
            'snapshot' => $snapshot, 'snapshot_consistent' => false, 'cross_request_snapshot' => false,
            'consistency' => 'SINGLE_RESPONSE_SNAPSHOT_LIVE_KEYSET_BETWEEN_REQUESTS',
            'source_facets' => $facets,
            'allowed_sources' => array_keys(array_filter($scope['allowed'])),
            'facet_scope' => 'AUTHORIZED_FILTERED_BEFORE_SOURCE_SELECTION_AND_CURSOR',
            'coverage' => in_array(false, $scope['allowed'], true) ? 'AUTHORIZED_SOURCES_ONLY' : 'ALL_SOURCES',
            'source_failure_policy' => 'FAIL_CLOSED_NO_PARTIAL_DATA_OR_TOTAL',
            'source_mapping_contract' => P12MixedDocumentStatus::CONTRACT,
            'deduplication' => ['key' => 'source:id', 'policy' => 'CANONICAL_OWNER_PRIMARY_KEY',
                'display_number_is_identity' => false, 'cross_source_uuid_collision' => 'KEEP_SEPARATE'],
            'date_field' => 'created_at', 'timezone' => $filters['timezone'],
            'date_window' => P12MixedDocumentQuery::dateWindow($state['issued_at']),
            'query' => ['source' => $filters['source'], 'status' => $filters['status'],
                'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'],
                'q_applied' => $filters['keyword'] !== ''],
            'sort' => $filters['sort'], 'tie_break' => ['source_rank' => 'asc', 'id' => 'asc'],
            'source_rank' => ['INBOUND' => 1, 'OUTBOUND' => 2, 'WARRANTY' => 3],
            'null_created_at' => 'LAST_UNLESS_EXCLUDED_BY_DATE_FILTER',
            'search_fields' => [
                'INBOUND' => ['doc_no', 'source_name', 'source_type'],
                'OUTBOUND' => ['doc_no', 'recipient_name', 'source_type'],
                'WARRANTY' => ['warranty_case_code', 'temporary_item_code', 'serial_number', 'source_type'],
            ],
            'source_type_terms' => P12MixedDocumentSearch::TYPE_TERMS,
            'search_semantics' => 'NFC_LITERAL_CONTAINS_CASE_INSENSITIVE_ACCENT_SENSITIVE',
            'warranty_customer_search' => 'NOT_SUPPORTED',
            'quantity_semantics' => 'NOT_PROJECTED_USE_OWNER_DETAIL_AND_P12_SCAN_SUMMARY',
            'status_filter_keys' => array_column($options, 'value'),
            'filter_options' => ['status' => $options, 'display_mapping_approval' => 'APPROVED'],
            'cache_policy' => 'NO_INTERNAL_RESULT_CACHE', 'write_operations' => [],
        ];
    }
}
