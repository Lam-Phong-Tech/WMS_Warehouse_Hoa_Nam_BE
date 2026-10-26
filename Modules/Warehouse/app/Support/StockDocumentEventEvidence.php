<?php

namespace Modules\Warehouse\Support;

use App\Support\DocumentTimestamp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Events\StockDocumentCreated;

final class StockDocumentEventEvidence
{
    public const TABLE = 'stock_document_event_evidence';

    public const CONTRACT = 'p12-event-evidence-v2';

    public function handle(StockDocumentCreated $event): void
    {
        if ($event->sourceSnapshot === [] || $event->warehouseId === null || ! in_array($event->type, ['receipt', 'issue'], true)) {
            return;
        }
        $parent = DB::table('stock_documents')->where('id', $event->documentId)->first();
        if ($parent !== null) {
            self::append($parent->id, $event->type === 'receipt' ? 'INBOUND' : 'OUTBOUND',
                $event->warehouseId, (int) $parent->version, 'CREATED', $event->sourceSnapshot,
                'Document created by canonical owner');
        }
    }

    public static function append(string $id, string $source, string $warehouse, int $version, string $key,
        array $snapshot, string $description, ?string $requestKey = null, ?string $hash = null, ?string $session = null): array
    {
        $row = ['id' => (string) Str::uuid(), 'request_key' => $requestKey, 'request_hash' => $hash,
            'document_id' => $id, 'warehouse_id' => $warehouse, 'source' => $source,
            'event_key' => $key, 'document_version' => $version, 'actor_id' => $snapshot['actor_id'] ?? null,
            'actor_name' => $snapshot['actor_name'] ?? null, 'job_title' => $snapshot['job_title'] ?? null,
            'auth_session_id' => $session, 'description' => $description, 'occurred_at' => $snapshot['captured_at']];
        DB::table(self::TABLE)->insert($row);

        return $row;
    }

    public static function milestones(string $id, string $warehouse, string $source): array
    {
        $rows = DB::table(self::TABLE)->where('document_id', $id)->where('warehouse_id', $warehouse)
            ->where('source', $source)->orderBy('occurred_at')->orderBy('id')->get();
        $result = [];
        foreach (['CREATED', 'CHECKED', 'SENT'] as $key) {
            $row = $rows->firstWhere('event_key', $key);
            $result[strtolower($key)] = ['availability' => $row === null ? 'NOT_RECORDED' : 'RECORDED',
                'occurred_at' => DocumentTimestamp::utc($row?->occurred_at, 'UTC'),
                'event_id' => $row?->id, 'event_key' => $key, 'subject_type' => 'stock_document',
                'subject_id' => $id, 'warehouse_id' => $warehouse, 'document_version' => $row?->document_version,
                'actor' => $row === null ? null : ['id' => $row->actor_id, 'name_snapshot' => $row->actor_name,
                    'job_title_snapshot' => $row->job_title], 'description' => $row?->description,
                'timestamp_source' => $row === null ? null : self::TABLE.'.occurred_at',
                'snapshot_policy' => 'FUTURE_EVENT_CAPTURE_NO_HISTORICAL_BACKFILL'];
        }

        return $result;
    }
}
