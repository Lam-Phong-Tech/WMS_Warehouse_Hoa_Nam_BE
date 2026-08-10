<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $schema = HoaNamErdSchema::parse();
        foreach ($schema as $table => $definition) {
            foreach ($definition['fields'] as $field => $spec) {
                $this->normalizeType($table, $field, $spec['type']);
                $this->applyDefault($table, $field, $spec['key']);
            }
        }

        $this->backfillSameTableAliases();
        $this->backfillMaterializedProjections();
        $this->backfillAuditLogs();
        $this->backfillCommonAuditFields($schema);
    }

    public function down(): void
    {
        // Fail-forward: documented types and deterministic canonical backfills are retained.
    }

    private function normalizeType(string $table, string $field, string $documentedType): void
    {
        $actual = DB::table('information_schema.columns')
            ->where('table_schema', DB::raw('current_schema()'))
            ->where('table_name', $table)
            ->where('column_name', $field)
            ->first(['data_type', 'character_maximum_length', 'numeric_precision', 'numeric_scale']);

        if ($actual === null) {
            throw new \RuntimeException("Missing ERD field {$table}.{$field} before type alignment.");
        }
        if (HoaNamErdSchema::typeMatches($actual, $documentedType)) {
            return;
        }

        $target = HoaNamErdSchema::postgresType($documentedType, true);
        $using = $this->typeCastExpression($table, $field, $target, (string) $actual->data_type);
        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN %s TYPE %s USING %s',
            HoaNamErdSchema::quote($table),
            HoaNamErdSchema::quote($field),
            $target,
            $using,
        ));
    }

    private function typeCastExpression(string $table, string $field, string $target, string $actualType): string
    {
        $quoted = HoaNamErdSchema::quote($field);

        if ($target === 'uuid') {
            if (in_array($actualType, ['character varying', 'character', 'text'], true)) {
                return sprintf(
                    "CASE WHEN %s ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN %s::uuid ELSE %s END",
                    $quoted,
                    $quoted,
                    $this->legacyUuidExpression($quoted),
                );
            }

            return $this->legacyUuidExpression($quoted);
        }

        if ($table === 'idempotency_records' && $field === 'request_hash' && $target === 'char(64)') {
            return sprintf(
                "CASE WHEN %s::text ~ '^v2:[a-f0-9]{64}' THEN substring(%s::text from 4 for 64) ELSE substring(%s::text from 1 for 64) END::char(64)",
                $quoted,
                $quoted,
                $quoted,
            );
        }

        return "{$quoted}::{$target}";
    }

    private function legacyUuidExpression(string $sqlExpression): string
    {
        $hash = "md5('hoa-nam-legacy-id:' || {$sqlExpression}::text)";

        return "((substr({$hash}, 1, 8) || '-' || substr({$hash}, 9, 4) || '-' || substr({$hash}, 13, 4) || '-' || substr({$hash}, 17, 4) || '-' || substr({$hash}, 21, 12))::uuid)";
    }

    private function applyDefault(string $table, string $field, string $key): void
    {
        if (! preg_match('/\bDEFAULT\s+([^\s,]+)/i', $key, $match)) {
            return;
        }

        $raw = trim($match[1]);
        $upper = strtoupper($raw);
        $default = match ($upper) {
            'NOW()', 'CURRENT_TIMESTAMP' => 'CURRENT_TIMESTAMP',
            'GEN_RANDOM_UUID()' => 'gen_random_uuid()',
            'FALSE', 'TRUE' => strtolower($raw),
            "'{}'::JSONB", '{}::JSONB' => "'{}'::jsonb",
            default => is_numeric($raw)
                ? $raw
                : "'".str_replace("'", "''", trim($raw, "'\""))."'",
        };

        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN %s SET DEFAULT %s',
            HoaNamErdSchema::quote($table),
            HoaNamErdSchema::quote($field),
            $default,
        ));
    }

    private function backfillSameTableAliases(): void
    {
        $copies = [
            ['warehouses', 'warehouse_code', 'code'],
            ['warehouses', 'warehouse_name', 'name'],
            ['agencies', 'id', 'agency_id'],
            ['package_variants', 'package_code', 'variant_code'],
            ['package_variants', 'package_name', 'variant_name'],
            ['defect_catalog', 'id', 'defect_id'],
            ['import_batches', 'id', 'import_batch_id'],
            ['import_batches', 'batch_code', 'import_batch_code'],
            ['import_batches', 'source_name', 'supplier_name'],
            ['import_batches', 'reference_no', 'supplier_batch_no'],
            ['item_instances', 'id', 'item_id'],
            ['item_instances', 'status', 'item_status'],
            ['item_instances', 'condition', 'item_condition'],
            ['item_instances', 'current_container_id', 'container_id'],
            ['physical_codes', 'id', 'physical_code_id'],
            ['containers', 'id', 'container_id'],
            ['container_items', 'id', 'container_item_id'],
            ['inventory_balances', 'sku_id', 'product_id'],
            ['inventory_balances', 'quantity', 'on_hand_qty'],
            ['outbound_request_import_rows', 'raw_data', 'raw_payload'],
            ['outbound_request_import_rows', 'errors', 'validation_errors'],
            ['outbound_requests', 'customer_name', 'recipient_name'],
            ['outbound_requests', 'customer_address', 'recipient_address'],
            ['label_snapshots', 'payload', 'label_payload'],
            ['label_print_events', 'printer_id', 'printer_config_id'],
            ['label_print_events', 'completed_at', 'printed_at'],
            ['warranty_attachments', 'content_type', 'mime_type'],
            ['warranty_attachments', 'file_size_bytes', 'file_size'],
            ['warranty_attachments', 'storage_key', 'storage_path'],
            ['pv_product_images', 'storage_url', 'image_url'],
        ];

        foreach ($copies as [$table, $target, $source]) {
            if (! Schema::hasColumn($table, $target) || ! Schema::hasColumn($table, $source)) {
                continue;
            }
            $sourceExpression = $this->coalesceSourceExpression($table, $target, $source);
            DB::statement(sprintf(
                'UPDATE %s SET %s = COALESCE(%s, %s) WHERE %s IS NULL',
                HoaNamErdSchema::quote($table),
                HoaNamErdSchema::quote($target),
                HoaNamErdSchema::quote($target),
                $sourceExpression,
                HoaNamErdSchema::quote($target),
            ));
        }

        if (Schema::hasTable('warehouses')) {
            DB::statement("UPDATE warehouses SET timezone = COALESCE(timezone, 'Asia/Ho_Chi_Minh') WHERE timezone IS NULL");
        }
        if (Schema::hasTable('item_instances')) {
            DB::statement("UPDATE item_instances SET item_code = COALESCE(item_code, serial_number, 'ITEM-' || id::text), received_at = COALESCE(received_at, created_at) WHERE item_code IS NULL OR received_at IS NULL");
        }
        if (Schema::hasTable('physical_codes')) {
            DB::statement('UPDATE physical_codes SET assigned_at = COALESCE(assigned_at, created_at) WHERE assigned_at IS NULL');
        }
        if (Schema::hasTable('container_items')) {
            DB::statement('UPDATE container_items SET placed_at = COALESCE(placed_at, created_at), is_active = COALESCE(is_active, status <> \'REMOVED\') WHERE placed_at IS NULL OR is_active IS NULL');
        }
        if (Schema::hasTable('outbound_request_imports')) {
            DB::statement("UPDATE outbound_request_imports SET source_key = COALESCE(source_key, 'CHECKSUM:' || source_checksum), uploaded_at = COALESCE(uploaded_at, created_at), uploaded_by = COALESCE(uploaded_by, created_by) WHERE source_key IS NULL OR uploaded_at IS NULL");
        }
        if (Schema::hasTable('outbound_request_import_rows')) {
            DB::statement("UPDATE outbound_request_import_rows SET validation_status = CASE WHEN validation_status IN ('VALID','ERROR','WARNING') THEN validation_status WHEN validation_status IN ('PENDING','INVALID') THEN 'WARNING' ELSE 'ERROR' END");
        }
        if (Schema::hasTable('outbound_requests')) {
            DB::statement("UPDATE outbound_requests SET status = CASE WHEN status IN ('OPEN','CONSUMED','CANCELLED') THEN status WHEN status IN ('DRAFT','VALIDATED') THEN 'OPEN' ELSE 'OPEN' END");
            if (Schema::hasColumn('outbound_requests', 'source_checksum')) {
                DB::statement('UPDATE outbound_requests r SET source_checksum = i.source_checksum FROM outbound_request_imports i WHERE r.import_id = i.id AND r.source_checksum IS NULL');
            }
        }
        if (Schema::hasTable('label_print_events')) {
            DB::statement("UPDATE label_print_events SET attempt_no = COALESCE(attempt_no, 1), event_type = COALESCE(event_type, 'PRINT'), requested_at = COALESCE(requested_at, created_at), status = CASE WHEN status IN ('PENDING','SENT','PRINTED','FAILED') THEN status WHEN status='PRINT_FAILED' THEN 'FAILED' WHEN status='PENDING_PRINT' THEN 'PENDING' ELSE 'PENDING' END");
        }
        if (Schema::hasTable('warranty_attachments')) {
            DB::statement("UPDATE warranty_attachments SET attachment_type = COALESCE(attachment_type, 'OTHER'), status = COALESCE(status, 'ACTIVE'), uploaded_at = COALESCE(uploaded_at, created_at), uploaded_by = COALESCE(uploaded_by, created_by) WHERE attachment_type IS NULL OR status IS NULL OR uploaded_at IS NULL");
        }
        if (Schema::hasTable('pv_product_images')) {
            DB::statement("UPDATE pv_product_images SET status = COALESCE(status, 'ACTIVE') WHERE status IS NULL");
        }
    }

    private function coalesceSourceExpression(string $table, string $target, string $source): string
    {
        $types = DB::table('information_schema.columns')
            ->where('table_schema', DB::raw('current_schema()'))
            ->where('table_name', $table)
            ->whereIn('column_name', [$target, $source])
            ->pluck('data_type', 'column_name');

        $targetType = $types[$target] ?? null;
        $sourceType = $types[$source] ?? null;
        if ($targetType === 'jsonb' && $sourceType === 'json') {
            return HoaNamErdSchema::quote($source).'::jsonb';
        }
        if ($targetType === 'json' && $sourceType === 'jsonb') {
            return HoaNamErdSchema::quote($source).'::json';
        }

        return HoaNamErdSchema::quote($source);
    }

    private function backfillMaterializedProjections(): void
    {
        if (Schema::hasTable('idempotency_records')) {
            DB::statement("UPDATE idempotency_records SET scope = COALESCE(scope, operation, 'LEGACY'), response_status = COALESCE(response_status, response_code), resource_type = COALESCE(resource_type, operation), created_by = COALESCE(created_by, CASE WHEN user_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN user_id::uuid ELSE NULL END) WHERE scope IS NULL OR resource_type IS NULL OR created_by IS NULL");
        }
        if (Schema::hasTable('scan_events')) {
            DB::statement("UPDATE scan_events SET context_type = COALESCE(context_type, resolved_object_type, 'LEGACY_SCAN'), scan_source = COALESCE(scan_source, CASE WHEN physical_code_id IS NULL THEN 'MANUAL' ELSE 'HARDWARE' END), code_value = COALESCE(code_value, raw_code), classification = COALESCE(classification, CASE WHEN resolved_object_type IN ('ITEM','CARTON','BOX','TRAY') THEN resolved_object_type ELSE 'UNKNOWN' END), result = COALESCE(result, CASE WHEN result_status IN ('SUCCESS','DUPLICATE','UNKNOWN','INVALID','WRONG_SKU','WRONG_STATE','ERROR') THEN result_status WHEN result_status IN ('FOUND','OK') THEN 'SUCCESS' ELSE 'ERROR' END), scanned_at = COALESCE(scanned_at, created_at) WHERE context_type IS NULL OR scan_source IS NULL OR code_value IS NULL OR result IS NULL OR scanned_at IS NULL");
        }
        if (Schema::hasTable('inventory_movements')) {
            DB::statement("UPDATE inventory_movements SET movement_key = COALESCE(movement_key, movement_no, 'MOVE-' || id::text), quantity_delta = COALESCE(quantity_delta, quantity), source_type = COALESCE(source_type, 'STOCK_DOCUMENT'), source_id = COALESCE(source_id, id), occurred_at = COALESCE(occurred_at, created_at), stock_status_from = COALESCE(stock_status_from, CASE WHEN COALESCE(quantity_delta, quantity) < 0 THEN stock_status END), stock_status_to = COALESCE(stock_status_to, CASE WHEN COALESCE(quantity_delta, quantity) > 0 THEN stock_status END) WHERE movement_key IS NULL OR quantity_delta IS NULL OR source_type IS NULL OR source_id IS NULL OR occurred_at IS NULL");
        }
        if (Schema::hasTable('posting_events')) {
            DB::statement("UPDATE posting_events SET resource_type = COALESCE(resource_type, CASE WHEN document_id IS NOT NULL THEN 'STOCK_DOCUMENT' WHEN movement_id IS NOT NULL THEN 'MOVEMENT' ELSE 'LEGACY' END), resource_id = COALESCE(resource_id, document_id, movement_id, id), posting_action = COALESCE(posting_action, operation, 'POST'), movement_count = COALESCE(movement_count, CASE WHEN movement_id IS NULL THEN 0 ELSE 1 END), started_at = COALESCE(started_at, created_at), committed_at = COALESCE(committed_at, CASE WHEN status='COMMITTED' THEN created_at END), actor_id = COALESCE(actor_id, created_by) WHERE resource_type IS NULL OR resource_id IS NULL OR posting_action IS NULL OR started_at IS NULL");
        }
        if (Schema::hasTable('reconciliation_incidents')) {
            DB::statement("UPDATE reconciliation_incidents SET detected_at = COALESCE(detected_at, created_at), severity = COALESCE(severity, 'ERROR'), resolution_type = COALESCE(resolution_type, resolution_ref), resolution_note = COALESCE(resolution_note, reason) WHERE detected_at IS NULL OR severity IS NULL");
        }
        if (Schema::hasTable('reversal_documents')) {
            DB::statement("UPDATE reversal_documents rd SET warehouse_id = COALESCE(rd.warehouse_id, sd.src_warehouse_id, sd.dst_warehouse_id), original_document_type = COALESCE(rd.original_document_type, CASE WHEN sd.doc_type='receipt' THEN 'INBOUND' WHEN sd.doc_type='issue' THEN 'OUTBOUND' ELSE 'STOCK_DOCUMENT' END), correction_chain_id = COALESCE(rd.correction_chain_id, rd.id), reason_code = COALESCE(rd.reason_code, 'MANUAL'), reason_text = COALESCE(rd.reason_text, rd.reason, 'Legacy reversal'), updated_by = COALESCE(rd.updated_by, rd.created_by) FROM stock_documents sd WHERE sd.id = rd.original_document_id");
            DB::statement("UPDATE reversal_documents SET original_document_type = COALESCE(original_document_type, 'STOCK_DOCUMENT'), correction_chain_id = COALESCE(correction_chain_id, id), reason_code = COALESCE(reason_code, 'MANUAL'), reason_text = COALESCE(reason_text, reason, 'Legacy reversal') WHERE original_document_type IS NULL OR correction_chain_id IS NULL OR reason_code IS NULL OR reason_text IS NULL");
        }
        if (Schema::hasTable('reversal_lines')) {
            DB::statement("UPDATE reversal_lines SET quantity_to_reverse = COALESCE(quantity_to_reverse, quantity), line_no = COALESCE(line_no, 1), return_condition = COALESCE(return_condition, 'NON_SELLABLE') WHERE quantity_to_reverse IS NULL OR line_no IS NULL OR return_condition IS NULL");
        }
        if (Schema::hasTable('warranty_cases')) {
            DB::statement("UPDATE warranty_cases SET case_no = COALESCE(case_no, warranty_case_code), reported_defect = COALESCE(reported_defect, description, 'Legacy warranty case'), received_at = COALESCE(received_at, created_at) WHERE case_no IS NULL OR reported_defect IS NULL OR received_at IS NULL");
        }
        if (Schema::hasTable('warranty_events')) {
            DB::statement("UPDATE warranty_events SET occurred_at = COALESCE(occurred_at, created_at), actor_id = COALESCE(actor_id, created_by), event_data = COALESCE(event_data, '{}'::jsonb) WHERE occurred_at IS NULL OR event_data IS NULL");
        }
        if (Schema::hasTable('pv_product_profiles')) {
            DB::statement("UPDATE pv_product_profiles SET published = COALESCE(published, is_published, false), featured = COALESCE(featured, false), public_specifications = COALESCE(public_specifications, '{}'::jsonb) WHERE published IS NULL OR featured IS NULL OR public_specifications IS NULL");
        }
    }

    private function backfillAuditLogs(): void
    {
        if (HoaNamErdSchema::relationKind('audit_logs__legacy_projection') !== 'v') {
            return;
        }

        DB::statement(<<<'SQL'
            INSERT INTO audit_logs (request_id, actor_id, action, entity_type, entity_id, result, before_data, after_data, metadata, created_at)
            SELECT p.request_id,
                   p.actor_id,
                   p.action,
                   COALESCE(p.object_type, 'LEGACY'),
                   CASE
                       WHEN p.object_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
                           THEN p.object_id::uuid
                       ELSE NULL
                   END,
                   'SUCCESS',
                   p.before_data::jsonb,
                   p.after_data::jsonb,
                   jsonb_strip_nulls(jsonb_build_object('legacy_id', p.id::text, 'legacy_object_id', p.object_id, 'legacy_reason', p.reason)),
                   p.created_at
            FROM audit_logs__legacy_projection p
            WHERE NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.metadata->>'legacy_id' = p.id::text)
        SQL);
    }

    /** @param array<string,array{section:string,fields:array<string,array{type:string,nullable:bool,key:string}>}> $schema */
    private function backfillCommonAuditFields(array $schema): void
    {
        foreach ($schema as $table => $definition) {
            $fields = $definition['fields'];
            if (isset($fields['version']) && isset($fields['created_at'])) {
                DB::statement('UPDATE '.HoaNamErdSchema::quote($table).' SET version = COALESCE(version, 0) WHERE version IS NULL');
            }
            if (isset($fields['updated_at']) && isset($fields['created_at'])) {
                DB::statement('UPDATE '.HoaNamErdSchema::quote($table).' SET updated_at = COALESCE(updated_at, created_at) WHERE updated_at IS NULL');
            }
        }
    }
};
