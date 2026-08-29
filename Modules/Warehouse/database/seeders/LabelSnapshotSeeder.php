<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LabelSnapshotSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = DB::table('app_users')
            ->where('email', 'admin@gmail.com')
            ->orWhere('username', 'admin')
            ->value('id');

        if (! $adminId) {
            $adminId = DB::table('app_users')->value('id');
        }

        $outboundDocs = DB::table('outbound_documents')
            ->whereIn('outbound_no', ['ISS-2026-0001', 'ISS-2026-0002'])
            ->where('status', 'POSTED')
            ->pluck('outbound_no', 'id');

        if ($outboundDocs->isEmpty()) {
            $this->command->warn('Không tìm thấy outbound documents ISS-2026-0001/0002. Bỏ qua.');

            return;
        }

        $lines = DB::table('outbound_lines as ol')
            ->whereIn('ol.outbound_document_id', $outboundDocs->keys())
            ->join('sku as s', 's.sku_id', '=', 'ol.sku_id')
            ->select('ol.id as line_id', 'ol.outbound_document_id', 'ol.sku_id', 's.sku_code', 's.sku_name')
            ->orderBy('ol.created_at')
            ->get();

        if ($lines->isEmpty()) {
            $this->command->warn('Không tìm thấy outbound lines. Bỏ qua.');

            return;
        }

        $items = DB::table('item_instances')->get()->groupBy('sku_id');

        $now = now();
        $matches = [];
        $snapshots = [];
        $events = [];

        $statuses = ['PRINTED', 'PRINTED', 'PENDING_PRINT', 'PRINTED', 'PRINT_FAILED'];
        $reprints = [0, 1, 0, 0, 0];
        $daysAgo = [2, 5, 1, 8, 3];

        $pairings = [];
        foreach ($lines as $line) {
            $lineItems = $items->get($line->sku_id);
            if (! $lineItems || $lineItems->isEmpty()) {
                continue;
            }
            foreach ($lineItems as $item) {
                $pairings[] = [
                    'line' => $line,
                    'item' => $item,
                ];
                if (count($pairings) >= 5) {
                    break 2;
                }
            }
        }

        if (count($pairings) < 5) {
            $this->command->warn('Chỉ tìm được '.count($pairings).' cặp line-item.');
        }

        foreach ($pairings as $i => $pair) {
            $line = $pair['line'];
            $item = $pair['item'];
            $matchId = Str::uuid()->toString();
            $snapId = Str::uuid()->toString();
            $createdAt = $now->copy()->subDays($daysAgo[$i])->subHours(rand(1, 8));

            $matches[] = [
                'id' => $matchId,
                'outbound_line_id' => $line->line_id,
                'item_id' => $item->id,
                'container_id' => null,
                'quantity' => 1,
                'status' => 'ACTIVE',
                'matched_at' => $createdAt,
                'matched_by' => $adminId,
                'created_at' => $createdAt,
                'created_by' => $adminId,
                'updated_at' => $createdAt,
                'updated_by' => $adminId,
                'version' => 0,
            ];

            $outboundNo = $outboundDocs->get($line->outbound_document_id, 'ISS-UNKNOWN');

            $snapshots[] = [
                'id' => $snapId,
                'label_code' => 'LBL-2026-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'outbound_item_match_id' => $matchId,
                'outbound_document_id' => $line->outbound_document_id,
                'sku_id' => $line->sku_id,
                'sku_code' => $line->sku_code,
                'product_name' => $line->sku_name,
                'item_id' => $item->id,
                'item_code_value' => $item->item_code,
                'outbound_no' => $outboundNo,
                'customer_name' => 'Khach hang mau',
                'customer_address' => 'TP Ho Chi Minh',
                'template_version' => 'V1.0',
                'label_payload' => json_encode([
                    'template' => 'standard',
                    'version' => '1.0',
                    'sku' => $line->sku_code,
                    'item' => $item->item_code,
                ]),
                'payload' => json_encode([
                    'template' => 'standard',
                    'version' => '1.0',
                    'sku' => $line->sku_code,
                    'item' => $item->item_code,
                ]),
                'status' => $statuses[$i],
                'reprint_count' => $reprints[$i],
                'created_at' => $createdAt,
                'created_by' => $adminId,
                'updated_at' => $createdAt,
                'updated_by' => $adminId,
                'version' => 0,
            ];

            $attemptCount = match ($statuses[$i]) {
                'PRINTED' => $reprints[$i] + 1,
                'PRINT_FAILED' => 2,
                default => 0,
            };

            for ($a = 0; $a < $attemptCount; $a++) {
                $eventAt = $createdAt->copy()->addMinutes($a * 5 + 1);
                $isLast = ($a === $attemptCount - 1);
                $eventStatus = ($statuses[$i] === 'PRINT_FAILED' || (! $isLast && $reprints[$i] > 0))
                    ? 'FAILED'
                    : 'PRINTED';

                $events[] = [
                    'id' => Str::uuid()->toString(),
                    'label_snapshot_id' => $snapId,
                    'printer_id' => null,
                    'printer_config_id' => null,
                    'event_type' => $a === 0 ? 'PRINT' : 'REPRINT',
                    'status' => $eventStatus,
                    'attempt_no' => $a + 1,
                    'error_message' => $eventStatus === 'FAILED' ? 'Máy in hết thời gian chờ' : null,
                    'error_code' => $eventStatus === 'FAILED' ? 'TIMEOUT' : null,
                    'requested_at' => $eventAt,
                    'completed_at' => $eventAt->copy()->addSeconds(3),
                    'printed_at' => $eventStatus === 'PRINTED' ? $eventAt->copy()->addSeconds(3) : null,
                    'actor_id' => $adminId,
                    'created_at' => $eventAt,
                    'created_by' => $adminId,
                    'version' => 0,
                ];
            }
        }

        DB::table('outbound_item_matches')->insert($matches);
        $this->command->info('Da tao '.count($matches).' outbound_item_matches.');

        DB::table('label_snapshots')->insert($snapshots);
        $this->command->info('Da tao '.count($snapshots).' label_snapshots.');

        if (! empty($events)) {
            DB::table('label_print_events')->insert($events);
            $this->command->info('Da tao '.count($events).' label_print_events.');
        }
    }
}
