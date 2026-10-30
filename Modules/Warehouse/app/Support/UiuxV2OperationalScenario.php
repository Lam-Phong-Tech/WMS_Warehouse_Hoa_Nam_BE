<?php

namespace Modules\Warehouse\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\CurrentBaselineReversalService;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use RuntimeException;

/** Date-owned, additive fixtures; stock effects use the production posting services. */
final class UiuxV2OperationalScenario
{
    private array $createdFiles = [];

    public function run(Carbon $date, string $actorId): array
    {
        $clock = Carbon::getTestNow();
        try {
            Carbon::setTestNow($date->copy()->setTime(9, 0));

            $result = DB::transaction(fn (): array => $this->build($date, $actorId));
            if (DB::transactionLevel() === 0) {
                $this->releaseCreatedFiles();
            }

            return $result;
        } catch (\Throwable $error) {
            $this->cleanupCreatedFiles();
            throw $error;
        } finally {
            Carbon::setTestNow($clock);
        }
    }

    private function build(Carbon $date, string $actor): array
    {
        $prefix = 'UIUXV2-'.$date->format('Ymd');
        $warehouses = DB::table('warehouses')->where('status', 'ACTIVE')->get();
        if ($warehouses->count() !== 1) {
            throw new RuntimeException('UIUX_V2_SEED_SINGLE_ACTIVE_WAREHOUSE_REQUIRED');
        }
        $warehouse = (string) $warehouses->first()->id;
        $existing = DB::table('sku')->where('sku_code', $prefix.'-PRODUCT')->first();
        if ($existing !== null) {
            $metadata = AuditLog::query()->where('action', 'UIUX_V2_SCENARIO_SEEDED')
                ->where('object_type', 'uiux_v2_scenario')->where('object_id', $existing->sku_id)
                ->first()?->after_data;
            if (! is_array($metadata) || ($metadata['actor_id'] ?? null) !== $actor ||
                ($metadata['manifest']['warehouse_id'] ?? null) !== $warehouse ||
                ($metadata['digest'] ?? null) !== $this->digest($metadata['manifest'])) {
                throw new RuntimeException('UIUX_V2_SEED_OPERATIONAL_COLLISION: '.$prefix);
            }
            $attachment = DB::table('document_attachments')->where('document_id', $metadata['manifest']['reversal_document_id'])->first();
            if (! $attachment || ! Storage::disk('local')->exists($attachment->storage_key)
                || hash('sha256', Storage::disk('local')->get($attachment->storage_key)) !== $attachment->checksum) {
                throw new RuntimeException('UIUX_V2_SEED_EVIDENCE_MISSING_OR_CHANGED');
            }

            return $this->canonical($metadata['manifest']);
        }

        $audit = ['version' => 1, 'created_by' => $actor, 'updated_by' => $actor,
            'created_at' => now(), 'updated_at' => now()];
        $brand = $this->fresh('brands', 'id', 'brand_code', $prefix.'-BRAND', ['brand_name' => 'Hãng mẫu UIUX', 'status' => 'ACTIVE'] + $audit);
        $category = $this->fresh('product_categories', 'id', 'category_code', $prefix.'-CATEGORY', ['category_name' => 'Nhóm mẫu UIUX', 'status' => 'ACTIVE'] + $audit);
        $model = $this->fresh('product_models', 'id', 'model_code', $prefix.'-MODEL', ['model_name' => 'Dòng mẫu UIUX', 'brand_id' => $brand, 'status' => 'ACTIVE'] + $audit);
        $power = $this->fresh('power_sources', 'id', 'power_source_code', $prefix.'-POWER', ['power_source_name' => 'Nguồn mẫu UIUX', 'status' => 'ACTIVE'] + $audit);
        $package = $this->fresh('package_variants', 'id', 'package_code', $prefix.'-PACKAGE', ['package_name' => 'Bộ mẫu UIUX', 'status' => 'ACTIVE'] + $audit);
        $skuValues = ['unit' => 'cái', 'brand_id' => $brand, 'category_id' => $category, 'model_id' => $model,
            'power_source_id' => $power, 'package_variant_id' => $package, 'status' => 'ACTIVE',
            'warranty_applicable' => false, 'published_to_app' => false] + $audit;
        $product = $this->fresh('sku', 'sku_id', 'sku_code', $prefix.'-PRODUCT',
            ['sku_name' => 'Máy mẫu giao nhận UIUX', 'sku_type' => 'PRODUCT', 'is_serial_tracked' => true] + $skuValues);
        $component = $this->fresh('sku', 'sku_id', 'sku_code', $prefix.'-COMPONENT',
            ['sku_name' => 'Linh kiện mẫu đóng hộp UIUX', 'sku_type' => 'COMPONENT', 'is_serial_tracked' => false] + $skuValues);
        $tray = $this->fresh('containers', 'container_id', 'container_code', $prefix.'-TRAY',
            ['container_type' => 'TRAY', 'status' => 'STORED', 'location_hint' => 'UIUX/Kệ mẫu'] + $audit);
        $box = $this->fresh('containers', 'container_id', 'container_code', $prefix.'-BOX',
            ['container_type' => 'BOX', 'status' => 'EMPTY', 'primary_sku_id' => $component,
                'parent_container_id' => $tray, 'location_hint' => 'UIUX/Kệ mẫu'] + $audit);
        $agency = $this->fresh('agencies', 'agency_id', 'agency_code', $prefix.'-RECIPIENT',
            ['agency_name' => 'Nơi nhận mẫu UIUX', 'address' => 'Địa chỉ dữ liệu mẫu UIUX', 'status' => 'ACTIVE', 'recipient_type' => 'AGENCY'] + $audit);
        $printer = $this->fresh('printer_configs', 'id', 'printer_code', $prefix.'-PRINTER',
            ['printer_name' => 'Máy in giả lập UIUX', 'agent_code' => $prefix.'-OFFLINE',
                'agent_endpoint' => 'http://127.0.0.1:9', 'label_size' => '100x150',
                'label_width_mm' => 100, 'label_height_mm' => 150, 'status' => 'ACTIVE'] + $audit);
        $stock = app(CurrentBaselineStockService::class);
        [$componentDoc, $componentLine] = $this->document($prefix.'-PN-COMPONENT', 'receipt', $component, 12, $warehouse, $actor);
        $stock->scanStockDocument($componentDoc, 'receipt', ['line_id' => $componentLine, 'quantity' => 12, 'container_id' => $box], $actor);
        $this->post($componentDoc, 'receipt', $actor);
        [$inbound, $inboundLine] = $this->document($prefix.'-PN-PRODUCT', 'receipt', $product, 1, $warehouse, $actor);
        $serial = $prefix.'-ITEM-001';
        $stock->scanStockDocument($inbound, 'receipt', ['line_id' => $inboundLine, 'quantity' => 1,
            'code_value' => 'HN1|SKU='.$prefix.'-PRODUCT|ITEM='.$serial], $actor);
        $this->post($inbound, 'receipt', $actor);
        $item = (string) DB::table('item_instances')->where('serial_number', $serial)->value('item_id');
        $import = $this->fresh('outbound_request_imports', 'id', 'import_no', $prefix.'-REQUEST-IMPORT',
            ['source_file_name' => $prefix.'-request.xlsx', 'source_key' => 'uiux-v2-fixtures/'.$prefix.'/request.xlsx',
                'source_checksum' => hash('sha256', $prefix.'-request'), 'status' => 'CONSUMED',
                'total_rows' => 1, 'valid_rows' => 1, 'error_rows' => 0, 'validated_at' => now(),
                'consumed_at' => now(), 'consumed_by' => $actor] + $audit);
        $request = $this->fresh('outbound_requests', 'id', 'request_no', $prefix.'-REQUEST',
            ['import_id' => $import, 'agency_id' => $agency, 'recipient_name' => 'Nơi nhận mẫu UIUX', 'recipient_address' => 'Địa chỉ dữ liệu mẫu UIUX',
                'request_date' => $date->toDateString(), 'status' => 'OPEN'] + $audit);
        DB::table('outbound_request_lines')->insert(['id' => (string) Str::uuid(), 'outbound_request_id' => $request,
            'line_no' => 1, 'sku_id' => $product, 'required_qty' => 1] + $audit);
        [$outbound, $outboundLine] = $this->document($prefix.'-PX-PRODUCT', 'issue', $product, 1, $warehouse, $actor,
            ['outbound_request_id' => $request, 'agency_id' => $agency,
                'recipient_name' => 'Nơi nhận mẫu UIUX', 'recipient_address' => 'Địa chỉ dữ liệu mẫu UIUX']);
        DB::table('outbound_requests')->where('id', $request)->update(['status' => 'CONSUMED', 'version' => 2]);
        $stock->scanStockDocument($outbound, 'issue', ['line_id' => $outboundLine, 'quantity' => 1, 'item_id' => $item], $actor);
        $label = (string) Str::uuid();
        $labelRow = ['id' => $label, 'outbound_item_match_id' => null,
            'outbound_document_id' => $outbound, 'sku_id' => $product, 'item_id' => $item,
            'label_payload' => json_encode(['source' => $prefix,
                'label_code' => 'PL-'.$prefix, 'outbound_document_id' => $outbound, 'outbound_no' => $prefix.'-PX-PRODUCT',
                'recipient_name' => 'Nơi nhận mẫu UIUX', 'recipient_address' => 'Địa chỉ dữ liệu mẫu UIUX',
                'sku_summary' => $prefix.'-PRODUCT x1', 'item_ids' => [$item],
                'items' => [['sku_id' => $product, 'sku_code' => $prefix.'-PRODUCT', 'sku_name' => 'Máy mẫu giao nhận UIUX', 'quantity' => 1]]], JSON_THROW_ON_ERROR),
            'status' => 'PRINTED', 'applied_at' => now(), 'applied_by' => $actor] + $audit;
        if (Schema::hasColumn('label_snapshots', 'label_code')) {
            $labelRow['label_code'] = 'PL-'.$prefix;
        }
        DB::table('label_snapshots')->insert($labelRow);
        $prints = [];
        foreach (['FAILED', 'PRINTED'] as $index => $status) {
            $prints[] = $id = (string) Str::uuid();
            DB::table('label_print_events')->insert(['id' => $id, 'label_snapshot_id' => $label,
                'printer_config_id' => $printer, 'printer_id' => $printer, 'event_type' => $index === 0 ? 'PRINT' : 'REPRINT',
                'attempt_no' => $index + 1, 'status' => $status, 'error_code' => $index === 0 ? 'PRINTER_UNAVAILABLE' : null,
                'error_message' => $index === 0 ? 'Lỗi in giả lập UIUX' : null, 'requested_at' => now(),
                'printed_at' => $index === 1 ? now() : null, 'completed_at' => now(),
                'job_id' => $prefix.'-JOB-'.($index + 1), 'request_id' => $prefix.'-PRINT-'.($index + 1),
                'actor_id' => $actor, 'metadata' => json_encode(['source' => $prefix, 'simulated' => true], JSON_THROW_ON_ERROR)] + $audit);
        }
        $issueResult = $this->post($outbound, 'issue', $actor);
        $reversalService = app(CurrentBaselineReversalService::class);
        $key = $prefix.'-REVERSAL';
        $reversalResult = $reversalService->execute($outbound, 'issue', ['reason' => 'Hoàn trả mẫu UIUX',
            'reason_code' => 'CORRECTION', 'condition_status' => 'SELLABLE', 'physical_return_confirmed' => true,
            'lines' => [['movement_id' => $issueResult['movement_ids'][0], 'quantity' => 1, 'item_id' => $item]]],
            $actor, $key, hash('sha256', $key), (int) DB::table('stock_documents')->where('id', $outbound)->value('version'));
        $reversal = (string) $reversalResult['reversal_document_id'];
        $storageKey = 'uiux-v2-fixtures/'.$prefix.'/return.pdf';
        $pdf = $this->evidencePdf($prefix);
        if (Storage::disk('local')->exists($storageKey)) {
            if (Storage::disk('local')->get($storageKey) !== $pdf) {
                throw new RuntimeException('UIUX_V2_SEED_EVIDENCE_COLLISION');
            }
        } else {
            if (! Storage::disk('local')->put($storageKey, $pdf)) {
                throw new RuntimeException('UIUX_V2_SEED_EVIDENCE_WRITE_FAILED');
            }
            $this->createdFiles[] = $storageKey;
        }
        DB::table('document_attachments')->insert(['id' => (string) Str::uuid(), 'document_type' => 'REVERSAL',
            'document_id' => $reversal, 'attachment_type' => 'SOURCE_DOCUMENT', 'file_name' => $prefix.'-return.pdf',
            'content_type' => 'application/pdf', 'file_size_bytes' => strlen($pdf), 'storage_disk' => 'local',
            'storage_key' => $storageKey, 'checksum' => hash('sha256', $pdf),
            'status' => 'ACTIVE', 'note' => 'Chứng từ mẫu UIUX, không phải giao dịch khách hàng.', 'uploaded_at' => now(), 'uploaded_by' => $actor] + $audit);
        $reversalService->postReversalDocument($reversal, $actor, $key.'-POST', hash('sha256', $key.'-POST'),
            (int) DB::table('reversal_document')->where('reversal_document_id', $reversal)->value('version'));
        $manifest = ['warehouse_id' => $warehouse, 'sku_id' => $product, 'component_sku_id' => $component,
            'inbound_document_id' => $inbound, 'component_inbound_document_id' => $componentDoc,
            'outbound_document_id' => $outbound, 'reversal_document_id' => $reversal,
            'box_container_id' => $box, 'tray_container_id' => $tray, 'label_snapshot_id' => $label,
            'print_event_ids' => $prints, 'outbound_request_id' => $request, 'item_id' => $item,
            'printer_id' => $printer, 'agency_id' => $agency, 'outbound_request_import_id' => $import,
            'master_ids' => ['brands' => $brand, 'product_categories' => $category, 'product_models' => $model,
                'power_sources' => $power, 'package_variants' => $package]];
        AuditLogger::record('UIUX_V2_SCENARIO_SEEDED', 'uiux_v2_scenario', $product, $actor,
            $prefix.'-SCENARIO', [], ['actor_id' => $actor, 'manifest' => $manifest, 'digest' => $this->digest($manifest)],
            'Explicit additive UIUX fixture scenario', 'SUCCESS');
        if (! AuditLog::query()->where('action', 'UIUX_V2_SCENARIO_SEEDED')->where('object_id', $product)->exists()) {
            throw new RuntimeException('UIUX_V2_SEED_MANIFEST_AUDIT_REQUIRED');
        }

        return $this->canonical($manifest);
    }

    private function fresh(string $table, string $pk, string $key, string $code, array $values): string
    {
        if (DB::table($table)->where($key, $code)->exists()) {
            throw new RuntimeException('UIUX_V2_SEED_OPERATIONAL_COLLISION: '.$table.'/'.$code);
        }
        $id = (string) Str::uuid();
        $row = [$pk => $id, $key => $code] + $values;
        // Older compatibility projections omit actor audit columns on some masters.
        foreach (['created_by', 'updated_by'] as $optional) {
            if (! Schema::hasColumn($table, $optional)) {
                unset($row[$optional]);
            }
        }
        if ($table === 'containers' && Schema::hasColumn('containers', 'id')) {
            $row['id'] = $id;
        }
        if ($table === 'package_variants') {
            foreach (['variant_code' => $code, 'variant_name' => $values['package_name']] as $column => $value) {
                if (Schema::hasColumn($table, $column)) {
                    $row[$column] = $value;
                }
            }
        }
        DB::table($table)->insert($row);
        $masterTypes = ['brands' => ['BRAND', 'brand_name'], 'product_categories' => ['CATEGORY', 'category_name'],
            'product_models' => ['MODEL', 'model_name'], 'power_sources' => ['POWER', 'power_source_name'],
            'package_variants' => ['PACKAGE', 'package_name']];
        if (DB::getDriverName() === 'sqlite' && isset($masterTypes[$table])) {
            [$type, $name] = $masterTypes[$table];
            DB::table('product_masters')->insert(['master_id' => $id, 'master_type' => $type, 'code' => $code,
                'name' => $values[$name], 'parent_master_id' => $table === 'product_models' ? $values['brand_id'] : null,
                'status' => 'ACTIVE', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    private function document(string $code, string $type, string $sku, int $qty, string $warehouse, string $actor, array $extra = []): array
    {
        $audit = ['version' => 1, 'created_by' => $actor, 'updated_by' => $actor, 'created_at' => now(), 'updated_at' => now()];
        $id = $this->fresh('stock_documents', 'id', 'doc_no', $code, $extra + ['doc_type' => $type,
            'doc_date' => now()->toDateString(), 'status' => 'DRAFT', 'source_name' => 'Nguồn hàng mẫu UIUX',
            'src_warehouse_id' => $type === 'issue' ? $warehouse : null,
            'dst_warehouse_id' => $type === 'receipt' ? $warehouse : null, 'note' => $code] + $audit);
        $line = (string) Str::uuid();
        DB::table('stock_document_lines')->insert(['id' => $line, 'stock_document_id' => $id, 'line_no' => 1,
            'product_id' => $sku, 'qty_planned' => $qty, 'qty_actual' => $qty, 'scanned_qty' => 0,
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$id, $line];
    }

    private function post(string $document, string $type, string $actor): array
    {
        $key = 'uiux-v2-post-'.$document;

        return app(CurrentBaselineStockService::class)->postStockDocument($document, $type, $actor, $key, hash('sha256', $key));
    }

    /** Hash complete linked rows, preserving a strict no-write replay contract. */
    private function digest(array $m): string
    {
        $documents = [$m['inbound_document_id'], $m['component_inbound_document_id'], $m['outbound_document_id']];
        $lines = DB::table('stock_document_lines')->whereIn('stock_document_id', $documents)->pluck('id')->all();
        $scope = [
            ['sku', 'sku_id', [$m['sku_id'], $m['component_sku_id']]],
            ['stock_documents', 'id', $documents], ['stock_document_lines', 'stock_document_id', $documents],
            ['stock_movements', 'sku_id', [$m['sku_id'], $m['component_sku_id']]],
            ['inventory_balances', 'product_id', [$m['sku_id'], $m['component_sku_id']]],
            ['inbound_scan_entries', 'inbound_line_id', $lines], ['outbound_item_matches', 'outbound_line_id', $lines],
            ['item_instances', 'item_id', [$m['item_id']]], ['physical_codes', 'item_id', [$m['item_id']]],
            ['containers', 'container_id', [$m['box_container_id'], $m['tray_container_id']]],
            ['container_items', 'container_id', [$m['box_container_id']]],
            ['outbound_requests', 'id', [$m['outbound_request_id']]], ['outbound_request_lines', 'outbound_request_id', [$m['outbound_request_id']]],
            ['outbound_request_imports', 'id', [$m['outbound_request_import_id']]],
            ['reversal_document', 'reversal_document_id', [$m['reversal_document_id']]], ['reversal_line', 'reversal_document_id', [$m['reversal_document_id']]],
            ['document_attachments', 'document_id', [$m['reversal_document_id']]], ['label_snapshots', 'id', [$m['label_snapshot_id']]],
            ['label_print_events', 'label_snapshot_id', [$m['label_snapshot_id']]], ['printer_configs', 'id', [$m['printer_id']]],
            ['agencies', 'agency_id', [$m['agency_id']]],
        ];
        foreach ($m['master_ids'] as $table => $id) {
            $scope[] = [$table, 'id', [$id]];
        }
        $graph = [];
        foreach ($scope as [$table, $column, $ids]) {
            $rows = DB::table($table)->whereIn($column, $ids)->get()->map(function ($row): array {
                $row = (array) $row;
                ksort($row);

                return $row;
            })->all();
            usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            $graph[$table] = $rows;
        }

        ksort($graph);

        return hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR));
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

    public function cleanupCreatedFiles(): void
    {
        foreach ($this->createdFiles as $key) {
            Storage::disk('local')->delete($key);
        }
        $this->createdFiles = [];
    }

    public function releaseCreatedFiles(): void
    {
        $this->createdFiles = [];
    }

    private function evidencePdf(string $prefix): string
    {
        // Fixed ASCII fixture text; valid single-page PDF, no remote renderer or user input.
        $text = 'BT /F1 12 Tf 50 780 Td (UIUX V2 TEST FIXTURE - '.$prefix.' - RETURN EVIDENCE) Tj ET';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($text).' >>'."\nstream\n".$text."\nendstream"];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
