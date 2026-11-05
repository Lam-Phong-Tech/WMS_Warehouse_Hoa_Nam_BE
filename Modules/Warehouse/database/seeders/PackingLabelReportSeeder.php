<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use RuntimeException;

/** Explicit additive fixtures; owned source creation requires a separate opt-in. */
final class PackingLabelReportSeeder extends Seeder
{
    public array $manifest = [];

    public function run(): void
    {
        $this->manifest = [];
        if (! config('packing_label_report_seed.enabled', false)) {
            throw new RuntimeException('PACKING_LABEL_SEED_EXPLICIT_OPT_IN_REQUIRED');
        }
        if (! in_array(app()->environment(), ['local', 'testing', 'dev', 'development', 'uat', 'demo'], true)) {
            throw new RuntimeException('PACKING_LABEL_SEED_ENVIRONMENT_NOT_ALLOWED');
        }
        if (config('deployment_safety.protect_database', true) && ! config('deployment_safety.allow_protected_database_seed', false)) {
            throw new RuntimeException('PACKING_LABEL_SEED_PROTECTED_DATABASE');
        }
        $value = (string) (config('packing_label_report_seed.date') ?: Carbon::now('Asia/Ho_Chi_Minh')->toDateString());
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, 'Asia/Ho_Chi_Minh');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || ! $date || $date->toDateString() !== $value) {
                throw new RuntimeException('Invalid date');
            }
        } catch (\Throwable $error) {
            throw new RuntimeException('PACKING_LABEL_SEED_INVALID_DATE', 0, $error);
        }
        $actor = (string) config('packing_label_report_seed.actor_id');
        if (! Str::isUuid($actor) || ! User::whereKey($actor)->where('status', 'ACTIVE')->exists()) {
            throw new RuntimeException('PACKING_LABEL_SEED_ACTIVE_ACTOR_REQUIRED');
        }
        $clock = Carbon::getTestNow();
        try {
            Carbon::setTestNow($date->copy()->setTime(8, 0));
            $this->manifest = DB::transaction(function () use ($date, $actor): array {
                if (DB::getDriverName() === 'pgsql') {
                    $lock = DB::selectOne("SELECT pg_try_advisory_xact_lock(hashtext('hoa-nam:packing-label-report:v1')) AS acquired");
                    if (! in_array($lock->acquired, [true, 1, '1', 't'], true)) {
                        throw new RuntimeException('PACKING_LABEL_SEED_BUSY');
                    }
                }

                return $this->build($date, $actor);
            });
        } finally {
            Carbon::setTestNow($clock);
        }
        $this->command?->info(json_encode($this->manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function build(Carbon $date, string $actor): array
    {
        $prefix = 'PLRPT-'.$date->format('Ymd');
        $record = AuditLog::query()->where('action', 'PACKING_LABEL_REPORT_SEEDED')->where('request_id', $prefix)->first();
        if ($record) {
            $data = $record->after_data;
            $manifest = is_array($data) ? ($data['manifest'] ?? null) : null;
            if (is_array($manifest) && ! isset($manifest['source_graph']) &&
                (DB::table('stock_documents')->where('doc_no', 'like', $prefix.'-%')->exists() ||
                 DB::table('sku')->where('sku_code', 'like', $prefix.'-%')->exists())) {
                throw new RuntimeException('PACKING_LABEL_SEED_COLLISION: '.$prefix);
            }
            if (! is_array($manifest) || ($data['actor_id'] ?? null) !== $actor ||
                ($data['digest'] ?? null) !== $this->digest($manifest)) {
                throw new RuntimeException('PACKING_LABEL_SEED_COLLISION: '.$prefix);
            }

            return $manifest;
        }
        if (DB::table('printer_configs')->where('printer_code', 'like', $prefix.'-%')->exists() ||
            DB::table('label_print_events')->where('request_id', 'like', $prefix.'-%')->exists() ||
            DB::table('label_snapshots')->whereRaw('CAST(label_payload AS TEXT) LIKE ?', ['%'.$prefix.'%'])->exists() ||
            DB::table('sku')->where('sku_code', $prefix.'-PRODUCT')->exists() ||
            DB::table('stock_documents')->where('doc_no', 'like', $prefix.'-%')->exists()) {
            throw new RuntimeException('PACKING_LABEL_SEED_COLLISION: '.$prefix);
        }
        // Existing posted source documents only. Never fabricate a posted transaction,
        // update a source row, dispatch a printer job, or write inventory projections.
        $documents = DB::table('stock_documents as sd')->where('sd.doc_type', 'issue')->where('sd.status', 'POSTED')
            ->where('sd.doc_date', '<=', $date->toDateString())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('label_snapshots as ls')->whereColumn('ls.outbound_document_id', 'sd.id'))
            ->orderByDesc('sd.doc_date')->orderBy('sd.id')->limit(10)->get('sd.*');
        $sourceGraph = null;
        if ($documents->count() !== 10) {
            if (! config('packing_label_report_seed.create_sources', false)) {
                throw new RuntimeException('PACKING_LABEL_SEED_SOURCE_DOCUMENTS_REQUIRED: 10 posted outbound documents without labels');
            }
            $sourceGraph = $this->createSources($prefix, $date, $actor);
            $documents = DB::table('stock_documents')->whereIn('id', $sourceGraph['outbound_document_ids'])->orderBy('doc_no')->get();
        }
        $at = $date->copy()->setTime(8, 0);
        $audit = ['version' => 1, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => $at, 'updated_at' => $at];
        $printers = [];
        foreach (['A' => 'Máy in đóng gói mẫu A', 'B' => 'Máy in đóng gói mẫu B'] as $code => $name) {
            $printers[] = $id = (string) Str::uuid();
            DB::table('printer_configs')->insert(['id' => $id, 'printer_code' => $prefix.'-'.$code,
                'printer_name' => $name, 'agent_code' => $prefix.'-OFFLINE-'.$code, 'agent_endpoint' => 'http://127.0.0.1:9',
                'label_size' => '100x150', 'label_width_mm' => 100, 'label_height_mm' => 150, 'status' => 'ACTIVE'] + $audit);
        }
        $plans = [
            [['PRINTED', null]],
            [['PRINTED', null], ['PRINTED', null]],
            [['FAILED', 'PAPER_OUT'], ['PRINTED', null]],
            [['FAILED', 'PAPER_OUT']],
            [['FAILED', 'PRINTER_UNAVAILABLE']],
            [['PENDING', null]],
            [['SENT', null]],
            [['PRINTED', null]],
            [],
            [['PRINTED', null], ['FAILED', 'PRINTER_UNAVAILABLE'], ['PRINTED', null]],
        ];
        $labels = $events = [];
        foreach ($documents as $i => $document) {
            $time = $at->copy()->addMinutes($i * 5);
            $labelCode = $prefix.'-'.sprintf('%02d', $i + 1);
            $items = DB::table('stock_document_lines as l')->join('sku as s', 's.sku_id', '=', 'l.product_id')
                ->where('l.stock_document_id', $document->id)->orderBy('l.line_no')
                ->get(['s.sku_id', 's.sku_code', 's.sku_name', 'l.qty_actual', 'l.qty_planned'])
                ->map(fn ($line) => ['sku_id' => $line->sku_id, 'sku_code' => $line->sku_code, 'sku_name' => $line->sku_name,
                    'quantity' => (int) ($line->qty_actual ?: $line->qty_planned)])->all();
            $payload = ['source' => $prefix, 'simulated' => true, 'label_code' => $labelCode,
                'outbound_document_id' => $document->id, 'outbound_no' => $document->doc_no];
            if ($i !== 7) {
                $payload += ['recipient_name' => $document->recipient_name, 'recipient_address' => $document->recipient_address,
                    'items' => $items, 'sku_summary' => $items, 'sku_count' => count($items),
                    'item_count' => array_sum(array_column($items, 'quantity'))];
            }
            $last = $plans[$i] === [] ? null : $plans[$i][array_key_last($plans[$i])][0];
            $status = match ($last) {
                'PRINTED' => 'PRINTED', 'FAILED' => 'PRINT_FAILED', default => 'READY_TO_PRINT'
            };
            $labels[] = $label = (string) Str::uuid();
            $row = ['id' => $label, 'outbound_document_id' => $document->id, 'outbound_item_match_id' => null,
                'sku_id' => null, 'item_id' => null, 'label_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => $status, 'applied_at' => $last === 'PRINTED' ? $time->copy()->addMinutes(2) : null,
                'applied_by' => $last === 'PRINTED' ? $actor : null,
                'created_at' => $time, 'updated_at' => $time] + $audit;
            if (Schema::hasColumn('label_snapshots', 'label_code')) {
                $row['label_code'] = $labelCode;
            }
            DB::table('label_snapshots')->insert($row);
            $successes = 0;
            foreach ($plans[$i] as $index => [$result, $error]) {
                $events[] = $id = (string) Str::uuid();
                $requested = $time->copy()->addSeconds($index * 30 + 5);
                $completed = in_array($result, ['PRINTED', 'FAILED'], true) ? $requested->copy()->addSeconds(2) : null;
                DB::table('label_print_events')->insert(['id' => $id, 'label_snapshot_id' => $label,
                    'printer_config_id' => $printers[$i % 2], 'printer_id' => $printers[$i % 2],
                    'event_type' => $successes > 0 ? 'REPRINT' : 'PRINT', 'attempt_no' => $index + 1, 'status' => $result,
                    'error_code' => $error, 'error_message' => match ($error) {
                        'PAPER_OUT' => 'Máy in hết giấy. Thay cuộn giấy rồi thử in lại.',
                        'PRINTER_UNAVAILABLE' => 'Máy in ngoại tuyến. Kiểm tra kết nối máy in.', default => null,
                    },
                    'requested_at' => $requested, 'printed_at' => $result === 'PRINTED' ? $completed : null,
                    'completed_at' => $completed, 'job_id' => $labelCode.'-JOB-'.($index + 1),
                    'request_id' => $labelCode.'-PRINT-'.($index + 1), 'actor_id' => $actor,
                    'metadata' => json_encode(['source' => $prefix, 'simulated' => true, 'reason' => $successes > 0 ? 'Nhãn mẫu bị mờ' : null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => $requested, 'updated_at' => $completed ?? $requested] + $audit);
                if ($result === 'PRINTED') {
                    $successes++;
                }
            }
            if ($sourceGraph !== null && $last === 'PRINTED') {
                $key = $prefix.'-POST-ISSUE-'.($i + 1);
                app(CurrentBaselineStockService::class)->postStockDocument($document->id, 'issue', $actor, $key, hash('sha256', $key));
            }
        }
        $manifest = ['schema' => 'packing-label-report-v1', 'date' => $date->toDateString(), 'prefix' => $prefix,
            'source_document_ids' => $documents->pluck('id')->all(), 'label_ids' => $labels,
            'print_event_ids' => $events, 'printer_ids' => $printers, 'label_count' => 10, 'attempt_count' => 13,
            'source_graph' => $sourceGraph];
        AuditLogger::record('PACKING_LABEL_REPORT_SEEDED', 'packing_label_report_fixture', $labels[0], $actor, $prefix, [],
            ['actor_id' => $actor, 'manifest' => $manifest, 'digest' => $this->digest($manifest)],
            'Explicit additive, simulated packing label report fixtures; owned stock only; no printer dispatch', 'SUCCESS');
        if (! AuditLog::query()->where('action', 'PACKING_LABEL_REPORT_SEEDED')->where('request_id', $prefix)->exists()) {
            throw new RuntimeException('PACKING_LABEL_SEED_MANIFEST_AUDIT_REQUIRED');
        }

        return $manifest;
    }

    private function digest(array $manifest): string
    {
        $graph = [];
        foreach ([['label_snapshots', 'label_ids'], ['label_print_events', 'print_event_ids'], ['printer_configs', 'printer_ids']] as [$table, $key]) {
            $rows = DB::table($table)->where(function ($query) use ($table, $key, $manifest): void {
                $query->whereIn('id', $manifest[$key] ?? []);
                if ($table === 'label_print_events') {
                    $query->orWhereIn('label_snapshot_id', $manifest['label_ids'])
                        ->orWhere('request_id', 'like', $manifest['prefix'].'-%');
                } elseif ($table === 'printer_configs') {
                    $query->orWhere('printer_code', 'like', $manifest['prefix'].'-%');
                } else {
                    $query->orWhereRaw('CAST(label_payload AS TEXT) LIKE ?', ['%'.$manifest['prefix'].'%']);
                }
            });
            $graph[$table] = $rows->orderBy('id')->get()->map(function ($row): array {
                $row = (array) $row;
                foreach (['label_payload', 'metadata'] as $json) {
                    if (isset($row[$json])) {
                        $row[$json] = json_decode($row[$json], true, 512, JSON_THROW_ON_ERROR);
                    }
                }

                return $this->canonical($row);
            })->all();
        }
        if (isset($manifest['source_graph'])) {
            $source = $manifest['source_graph'];
            $documents = DB::table('stock_documents')->whereIn('id', array_merge([$source['inbound_document_id']], $source['outbound_document_ids']))
                ->orWhere('doc_no', 'like', $manifest['prefix'].'-%')->pluck('id')->all();
            $skus = DB::table('sku')->where('sku_id', $source['sku_id'])->orWhere('sku_code', 'like', $manifest['prefix'].'-%')->pluck('sku_id')->all();
            $items = DB::table('item_instances')->whereIn('item_id', $source['item_ids'])->orWhereIn('sku_id', $skus)->pluck('item_id')->all();
            $lines = DB::table('stock_document_lines')->whereIn('stock_document_id', $documents)->pluck('id')->all();
            foreach ([['sku', 'sku_id', $skus], ['stock_documents', 'id', $documents],
                ['stock_document_lines', 'stock_document_id', $documents], ['stock_movements', 'sku_id', $skus],
                ['inventory_balances', 'product_id', $skus], ['item_instances', 'item_id', $items],
                ['physical_codes', 'item_id', $items], ['inbound_scan_entries', 'inbound_line_id', $lines],
                ['outbound_item_matches', 'outbound_line_id', $lines]] as [$table, $column, $ids]) {
                $rows = DB::table($table)->whereIn($column, $ids)->get()->map(fn ($row) => $this->canonical((array) $row))->all();
                usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
                $graph[$table] = $rows;
            }
        }

        return hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR));
    }

    private function createSources(string $prefix, Carbon $date, string $actor): array
    {
        $warehouse = DB::table('warehouses')->where('status', 'ACTIVE')->sole();
        $template = DB::table('sku')->where('sku_type', 'PRODUCT')->where('status', 'ACTIVE')->orderBy('sku_id')->first();
        if (! $template) {
            throw new RuntimeException('PACKING_LABEL_SEED_ACTIVE_PRODUCT_TEMPLATE_REQUIRED');
        }
        $sku = (string) Str::uuid();
        $audit = ['version' => 1, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()];
        $values = ['sku_id' => $sku, 'sku_code' => $prefix.'-PRODUCT', 'sku_name' => 'Sản phẩm mẫu báo cáo nhãn đóng gói',
            'sku_type' => 'PRODUCT', 'unit' => $template->unit, 'is_serial_tracked' => true, 'status' => 'ACTIVE',
            'warranty_applicable' => false, 'published_to_app' => false] + $audit;
        foreach (['brand_id', 'category_id', 'model_id', 'power_source_id', 'package_variant_id'] as $column) {
            $values[$column] = $template->$column ?? null;
        }
        foreach (['created_by', 'updated_by'] as $column) {
            if (! Schema::hasColumn('sku', $column)) {
                unset($values[$column]);
            }
        }
        DB::table('sku')->insert($values);
        $stock = app(CurrentBaselineStockService::class);
        [$inbound, $line] = $this->sourceDocument($prefix.'-PN', 'receipt', $sku, 10, $warehouse->id, $date, $actor);
        for ($i = 1; $i <= 10; $i++) {
            $stock->scanStockDocument($inbound, 'receipt', ['line_id' => $line, 'quantity' => 1,
                'code_value' => 'HN1|SKU='.$prefix.'-PRODUCT|ITEM='.$prefix.'-ITEM-'.sprintf('%02d', $i)], $actor);
        }
        $key = $prefix.'-POST-RECEIPT';
        $stock->postStockDocument($inbound, 'receipt', $actor, $key, hash('sha256', $key));
        $items = DB::table('item_instances')->where('sku_id', $sku)->orderBy('serial_number')->pluck('item_id')->all();
        $outbounds = [];
        foreach ($items as $i => $item) {
            [$outbound, $outboundLine] = $this->sourceDocument($prefix.'-PX-'.sprintf('%02d', $i + 1), 'issue', $sku, 1, $warehouse->id, $date, $actor);
            $stock->scanStockDocument($outbound, 'issue', ['line_id' => $outboundLine, 'quantity' => 1, 'item_id' => $item], $actor);
            $outbounds[] = $outbound;
        }

        return ['sku_id' => $sku, 'inbound_document_id' => $inbound, 'outbound_document_ids' => $outbounds, 'item_ids' => $items];
    }

    private function sourceDocument(string $code, string $type, string $sku, int $quantity, string $warehouse, Carbon $date, string $actor): array
    {
        $id = (string) Str::uuid();
        $audit = ['version' => 1, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()];
        DB::table('stock_documents')->insert(['id' => $id, 'doc_no' => $code, 'doc_type' => $type,
            'doc_date' => $date->toDateString(), 'status' => 'DRAFT', 'src_warehouse_id' => $type === 'issue' ? $warehouse : null,
            'dst_warehouse_id' => $type === 'receipt' ? $warehouse : null, 'source_name' => 'Nguồn hàng mẫu báo cáo đóng gói',
            'recipient_name' => $type === 'issue' ? 'Nơi nhận mẫu báo cáo đóng gói' : null,
            'recipient_address' => $type === 'issue' ? 'Địa chỉ mẫu, không phải khách hàng thật' : null,
            'note' => 'Dữ liệu mẫu PLRPT; chỉ dùng kiểm tra báo cáo đóng gói.'] + $audit);
        $line = (string) Str::uuid();
        DB::table('stock_document_lines')->insert(['id' => $line, 'stock_document_id' => $id, 'line_no' => 1,
            'product_id' => $sku, 'qty_planned' => $quantity, 'qty_actual' => $quantity, 'scanned_qty' => 0,
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$id, $line];
    }

    private function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
