<?php

namespace Modules\Warehouse\Support;

/** Pure projection of an already-authorized owner response. No lookups or writes. */
final class P12DocumentPresentation
{
    public const CONTRACT = 'p12-documents-read-v2';

    private const OWNERS = [
        'INBOUND' => ['owner' => 'P04', 'resource_type' => 'STOCK_DOCUMENT', 'path' => 'inbound-documents'],
        'OUTBOUND' => ['owner' => 'P05', 'resource_type' => 'STOCK_DOCUMENT', 'path' => 'outbound-documents'],
        'WARRANTY' => ['owner' => 'P09', 'resource_type' => 'WARRANTY_CASE', 'path' => 'warranty-cases'],
    ];

    public static function metadata(string $source): array
    {
        $owner = self::OWNERS[$source];

        return [
            'contract' => self::CONTRACT,
            'source' => $source,
            'owner' => $owner['owner'],
            'count_unit' => $owner['resource_type'],
            'all_sources_aggregate' => false,
            'cross_source_mapping' => 'PENDING_BA',
            'query_contract' => $source === 'WARRANTY' ? 'P09_WITH_P23_DATE_EXTENSION' : 'P12_STOCK_CREATED_DATE_OFFSET',
            'write_operations' => [],
        ];
    }

    public static function record(array $row, string $source, bool $detail): array
    {
        $owner = self::OWNERS[$source];
        $id = self::uuid($row[$source === 'WARRANTY' ? 'warranty_case_id' : 'id'] ?? null);
        $warehouseField = match ($source) {
            'INBOUND' => 'dst_warehouse_id',
            'OUTBOUND' => 'src_warehouse_id',
            default => 'warehouse_id',
        };
        // A session's selected warehouse is NOT proof of persisted record ownership.
        $warehouse = self::uuid($row[$warehouseField] ?? null);
        $readiness = [];
        foreach (['ready_for_post', 'ready_for_issue', 'can_post', 'product_info_complete'] as $field) {
            if (array_key_exists($field, $row)) {
                $readiness[$field] = is_bool($row[$field]) ? $row[$field] : null;
            }
        }
        $result = [
            'identity' => ['source' => $source, 'resource_type' => $owner['resource_type'],
                'id' => $id, 'warehouse_id' => $warehouse],
            'read_target' => $id === null ? null : self::target($owner['path'], $id, $warehouse),
            'status' => [
                'canonical' => self::text($row['status'] ?? null),
                'mini_app' => self::text($row['mini_app_status'] ?? null),
                'readiness' => (object) $readiness,
                'mini_app_semantics' => $source === 'INBOUND' ? 'LEGACY_SCAN_READINESS_NOT_HANDOFF_PROOF' : 'OWNER_DEFINED',
                'waiting_for_web' => null,
                'waiting_for_web_evidence' => 'NOT_ESTABLISHED_BY_STATUS_OR_READINESS',
            ],
        ];
        if ($detail) {
            $table = $source === 'WARRANTY' ? 'warranty_case' : 'stock_documents';
            $result['timeline'] = [
                'coverage' => 'PARTIAL',
                'is_workflow_state' => false,
                'milestones' => [
                    'created' => self::milestone($row['created_at'] ?? null, $table.'.created_at'),
                    // Neither full scan nor SCANNING proves that review/submission occurred.
                    // Do not repurpose generic submitted_at/approved_at without an owner mapping.
                    'checked' => self::unavailable('SOURCE_MAPPING_PENDING'),
                    'sent_to_web' => self::unavailable('SOURCE_MAPPING_PENDING'),
                    'posted' => $source === 'WARRANTY'
                        ? self::unavailable('NOT_APPLICABLE')
                        : self::milestone($row['posted_at'] ?? null, 'stock_documents.posted_at'),
                ],
            ];
        }

        return $result;
    }

    /** GET receipt only. The P09 owner has already rechecked current case access. */
    public static function receipt(array $data, array $meta): array
    {
        $receipt = is_array($data['receipt'] ?? null) ? $data['receipt'] : [];
        $id = self::uuid($receipt['warranty_case_id'] ?? null);
        $confirmed = ($receipt['state'] ?? null) === 'SUCCEEDED'
            && $id !== null && $id === self::uuid($data['warranty_case_id'] ?? null);

        return self::metadata('WARRANTY') + [
            'receipt_state' => self::text($receipt['state'] ?? null),
            'read_target' => $confirmed ? self::target('warranty-cases', $id, self::uuid($meta['warehouse_id'] ?? null)) : null,
            'detail_required' => $confirmed,
            'recovery_owner' => 'P09',
            'receipt_is_detail' => false,
        ];
    }

    private static function target(string $path, string $id, ?string $warehouse): array
    {
        return ['method' => 'GET', 'path' => '/api/v1/mini-app/'.$path.'/'.$id,
            'query' => (object) ($warehouse === null ? [] : ['warehouse_id' => $warehouse])];
    }

    private static function milestone(mixed $at, string $source): array
    {
        $time = self::text($at);

        return ['availability' => $time === null ? 'NOT_RECORDED' : 'RECORDED',
            'occurred_at' => $time, 'timestamp_source' => $source, 'event_id' => null];
    }

    private static function unavailable(string $reason): array
    {
        return ['availability' => $reason, 'occurred_at' => null, 'timestamp_source' => null, 'event_id' => null];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $value)
            ? strtolower($value) : null;
    }
}
