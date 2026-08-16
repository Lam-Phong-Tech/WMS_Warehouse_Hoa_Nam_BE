<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Warehouse;

class ScanRecordingService
{
    public function __construct(private readonly ScanResolver $scanResolver) {}

    public function classifyAndRecord(array $data, Warehouse $warehouse, ?string $userId, ?string $requestId): array
    {
        return DB::transaction(function () use ($data, $warehouse, $userId, $requestId) {
            $resolution = $this->scanResolver->resolveActive($data['raw_code']);
            $batchId = (string) Str::uuid();
            $eventId = (string) Str::uuid();

            DB::table('scan_batches')->insert([
                'scan_batch_id' => $batchId,
                'source_type' => $data['context'],
                'warehouse_id' => $warehouse->id,
                'created_by' => $userId,
                'created_at' => now(),
            ]);
            DB::table('scan_record_events')->insert([
                'scan_event_id' => $eventId,
                'scan_batch_id' => $batchId,
                'raw_code' => trim($data['raw_code']),
                'physical_code_id' => $resolution['physical_code']->physical_code_id,
                'resolved_object_type' => $resolution['item'] ? 'ITEM_INSTANCE' : 'CONTAINER',
                'resolved_object_id' => $resolution['item']?->item_id ?? $resolution['container']?->container_id,
                'result_status' => 'RESOLVED',
                'created_at' => now(),
            ]);

            AuditLogger::record('SCAN', 'scan_event', $eventId, $userId, $requestId, afterData: [
                'scan_batch_id' => $batchId,
                'context' => $data['context'],
                'physical_code_id' => $resolution['physical_code']->physical_code_id,
            ]);

            return [
                'raw_code' => trim($data['raw_code']),
                'classification' => $resolution['type'],
                'scan_batch_id' => $batchId,
                'scan_event_id' => $eventId,
                'physical_code_id' => $resolution['physical_code']->physical_code_id,
                'item_id' => $resolution['item']?->item_id,
                'container_id' => $resolution['container']?->container_id,
                'sku_id' => $resolution['sku_id'],
                'item_status' => $resolution['item']?->item_status,
                'current_location' => [
                    'warehouse_id' => $warehouse->id,
                    'warehouse_code' => $warehouse->code,
                    'warehouse_name' => $warehouse->name,
                ],
            ];
        });
    }
}
