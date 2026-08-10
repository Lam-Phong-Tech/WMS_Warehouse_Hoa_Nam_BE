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

        $this->installSameTableCompatibilityTriggers();
        $this->installCanonicalNormalizerTriggers();
        $this->installIdempotencyRecordMirror();
        $this->installProjectionMirrors();
        $this->installAuditMirror();
    }

    public function down(): void
    {
        // Fail-forward compatibility bridge. Removing it would let legacy runtime writes
        // drift away from the signed physical ERD tables.
    }

    private function installSameTableCompatibilityTriggers(): void
    {
        $bodies = [
            'warehouses' => <<<'SQL'
                NEW.warehouse_code := COALESCE(NEW.warehouse_code, NEW.code);
                NEW.code := COALESCE(NEW.code, NEW.warehouse_code);
                NEW.warehouse_name := COALESCE(NEW.warehouse_name, NEW.name);
                NEW.name := COALESCE(NEW.name, NEW.warehouse_name);
                NEW.timezone := COALESCE(NEW.timezone, 'Asia/Ho_Chi_Minh');
            SQL,
            'agencies' => <<<'SQL'
                NEW.id := COALESCE(NEW.id, NEW.agency_id);
                NEW.agency_id := COALESCE(NEW.agency_id, NEW.id);
            SQL,
            'package_variants' => <<<'SQL'
                NEW.package_code := COALESCE(NEW.package_code, NEW.variant_code);
                NEW.variant_code := COALESCE(NEW.variant_code, NEW.package_code);
                NEW.package_name := COALESCE(NEW.package_name, NEW.variant_name);
                NEW.variant_name := COALESCE(NEW.variant_name, NEW.package_name);
            SQL,
            'defect_catalog' => <<<'SQL'
                NEW.id := COALESCE(NEW.id, NEW.defect_id);
                NEW.defect_id := COALESCE(NEW.defect_id, NEW.id);
            SQL,
            'import_batches' => <<<'SQL'
                NEW.id := COALESCE(NEW.id, NEW.import_batch_id);
                NEW.import_batch_id := COALESCE(NEW.import_batch_id, NEW.id);
                NEW.batch_code := COALESCE(NEW.batch_code, NEW.import_batch_code);
                NEW.import_batch_code := COALESCE(NEW.import_batch_code, NEW.batch_code);
                NEW.source_name := COALESCE(NEW.source_name, NEW.supplier_name);
                NEW.reference_no := COALESCE(NEW.reference_no, NEW.supplier_batch_no);
            SQL,
            'item_instances' => <<<'SQL'
                NEW.id := COALESCE(NEW.id, NEW.item_id);
                NEW.item_id := COALESCE(NEW.item_id, NEW.id);
                NEW.item_code := COALESCE(NEW.item_code, NEW.serial_number, 'ITEM-' || NEW.id::text);
                NEW.status := COALESCE(NEW.status, NEW.item_status);
                NEW.item_status := COALESCE(NEW.item_status, NEW.status);
                NEW.condition := COALESCE(NEW.condition, NEW.item_condition, 'UNKNOWN');
                NEW.item_condition := COALESCE(NEW.item_condition, NEW.condition);
                NEW.current_container_id := COALESCE(NEW.current_container_id, NEW.container_id);
                NEW.container_id := COALESCE(NEW.container_id, NEW.current_container_id);
                NEW.received_at := COALESCE(NEW.received_at, NEW.created_at, CURRENT_TIMESTAMP);
            SQL,
            'physical_codes' => <<<'SQL'
                NEW.id := COALESCE(NEW.id, NEW.physical_code_id);
                NEW.physical_code_id := COALESCE(NEW.physical_code_id, NEW.id);
                NEW.code_type := COALESCE(NEW.code_type, CASE WHEN NEW.carrier_type='RFID' THEN 'RFID' ELSE 'CANONICAL' END);
                NEW.assigned_at := COALESCE(NEW.assigned_at, NEW.created_at, CURRENT_TIMESTAMP);
            SQL,
            'containers' => <<<'SQL'
                NEW.id := COALESCE(NEW.id, NEW.container_id);
                NEW.container_id := COALESCE(NEW.container_id, NEW.id);
                NEW.container_type := COALESCE(NEW.container_type, NEW.type);
                NEW.type := COALESCE(NEW.type, NEW.container_type);
            SQL,
            'container_items' => <<<'SQL'
                NEW.id := COALESCE(NEW.id, NEW.container_item_id);
                NEW.container_item_id := COALESCE(NEW.container_item_id, NEW.id);
                NEW.is_active := COALESCE(NEW.is_active, NEW.status <> 'REMOVED', true);
                NEW.status := COALESCE(NEW.status, CASE WHEN NEW.is_active THEN 'ACTIVE' ELSE 'REMOVED' END);
                NEW.placed_at := COALESCE(NEW.placed_at, NEW.created_at, CURRENT_TIMESTAMP);
            SQL,
            'inventory_balances' => <<<'SQL'
                NEW.sku_id := COALESCE(NEW.sku_id, NEW.product_id);
                NEW.product_id := COALESCE(NEW.product_id, NEW.sku_id);
                NEW.quantity := COALESCE(NEW.quantity, NEW.on_hand_qty, 0);
                NEW.on_hand_qty := COALESCE(NEW.on_hand_qty, NEW.quantity, 0);
                NEW.updated_at := COALESCE(NEW.updated_at, CURRENT_TIMESTAMP);
            SQL,
            'outbound_request_imports' => <<<'SQL'
                NEW.source_key := COALESCE(NEW.source_key, 'CHECKSUM:' || NEW.source_checksum);
                NEW.uploaded_at := COALESCE(NEW.uploaded_at, NEW.created_at, CURRENT_TIMESTAMP);
                NEW.uploaded_by := COALESCE(NEW.uploaded_by, NEW.created_by);
            SQL,
            'outbound_request_import_rows' => <<<'SQL'
                NEW.raw_data := COALESCE(NEW.raw_data, NEW.raw_payload::jsonb);
                NEW.raw_payload := COALESCE(NEW.raw_payload, NEW.raw_data::json);
                NEW.errors := COALESCE(NEW.errors, NEW.validation_errors::jsonb);
                NEW.validation_errors := COALESCE(NEW.validation_errors, NEW.errors::json);
                NEW.validation_status := CASE
                    WHEN NEW.validation_status IN ('VALID','ERROR','WARNING') THEN NEW.validation_status
                    WHEN NEW.validation_status IN ('PENDING','INVALID') THEN 'WARNING'
                    ELSE 'ERROR'
                END;
            SQL,
            'outbound_requests' => <<<'SQL'
                NEW.customer_name := COALESCE(NEW.customer_name, NEW.recipient_name);
                NEW.recipient_name := COALESCE(NEW.recipient_name, NEW.customer_name);
                NEW.customer_address := COALESCE(NEW.customer_address, NEW.recipient_address);
                NEW.recipient_address := COALESCE(NEW.recipient_address, NEW.customer_address);
                NEW.status := CASE
                    WHEN NEW.status IN ('OPEN','CONSUMED','CANCELLED') THEN NEW.status
                    WHEN NEW.status IN ('DRAFT','VALIDATED') THEN 'OPEN'
                    ELSE COALESCE(NEW.status, 'OPEN')
                END;
                IF NEW.source_checksum IS NULL AND NEW.import_id IS NOT NULL THEN
                    SELECT source_checksum INTO NEW.source_checksum FROM outbound_request_imports WHERE id = NEW.import_id;
                END IF;
            SQL,
            'label_snapshots' => <<<'SQL'
                NEW.payload := COALESCE(NEW.payload, NEW.label_payload::jsonb, '{}'::jsonb);
                NEW.label_payload := COALESCE(NEW.label_payload, NEW.payload::json);
                NEW.label_code := COALESCE(NEW.label_code, NEW.payload->>'label_code', 'LBL-' || NEW.id::text);
                NEW.template_version := COALESCE(NEW.template_version, NEW.payload->>'template_version', 'v1');
                SELECT COALESCE(NEW.outbound_document_id, l.stock_document_id),
                       COALESCE(NEW.customer_name, d.recipient_name, NEW.payload->>'customer_name'),
                       COALESCE(NEW.customer_address, d.recipient_address, NEW.payload->>'customer_address'),
                       COALESCE(NEW.product_name, s.sku_name, NEW.payload->>'product_name'),
                       COALESCE(NEW.sku_code, s.sku_code, NEW.payload->>'sku_code'),
                       COALESCE(NEW.item_code_value, pc.code_value, ii.item_code, NEW.payload->>'item_code_value'),
                       COALESCE(NEW.outbound_no, d.doc_no, NEW.payload->>'outbound_no')
                  INTO NEW.outbound_document_id, NEW.customer_name, NEW.customer_address,
                       NEW.product_name, NEW.sku_code, NEW.item_code_value, NEW.outbound_no
                  FROM outbound_item_matches m
                  JOIN stock_document_lines l ON l.id = m.outbound_line_id
                  JOIN stock_documents d ON d.id = l.stock_document_id
                  JOIN sku s ON s.sku_id = NEW.sku_id
                  LEFT JOIN item_instances ii ON ii.item_id = NEW.item_id
                  LEFT JOIN physical_codes pc ON pc.item_id = NEW.item_id AND pc.status = 'ACTIVE'
                 WHERE m.id = NEW.outbound_item_match_id
                 ORDER BY pc.is_primary DESC NULLS LAST, pc.assigned_at DESC NULLS LAST
                 LIMIT 1;
            SQL,
            'printer_configs' => <<<'SQL'
                NEW.printer_name := COALESCE(NEW.printer_name, NEW.printer_code);
                NEW.agent_endpoint := COALESCE(NEW.agent_endpoint, NEW.agent_code);
                NEW.agent_code := COALESCE(NEW.agent_code, NEW.agent_endpoint);
                NEW.dpi := COALESCE(NEW.dpi, 203);
                IF NEW.label_width_mm IS NULL OR NEW.label_height_mm IS NULL THEN
                    IF NEW.label_size ~ '^[0-9]+(\.[0-9]+)?[xX][0-9]+(\.[0-9]+)?$' THEN
                        NEW.label_width_mm := COALESCE(NEW.label_width_mm, split_part(lower(NEW.label_size), 'x', 1)::numeric);
                        NEW.label_height_mm := COALESCE(NEW.label_height_mm, split_part(lower(NEW.label_size), 'x', 2)::numeric);
                    END IF;
                END IF;
            SQL,
            'label_print_events' => <<<'SQL'
                NEW.printer_id := COALESCE(NEW.printer_id, NEW.printer_config_id);
                NEW.printer_config_id := COALESCE(NEW.printer_config_id, NEW.printer_id);
                NEW.attempt_no := COALESCE(NEW.attempt_no, 1);
                NEW.event_type := COALESCE(NEW.event_type, 'PRINT');
                NEW.requested_at := COALESCE(NEW.requested_at, NEW.created_at, CURRENT_TIMESTAMP);
                NEW.completed_at := COALESCE(NEW.completed_at, NEW.printed_at);
                NEW.status := CASE
                    WHEN NEW.status IN ('PENDING','SENT','PRINTED','FAILED') THEN NEW.status
                    WHEN NEW.status='PRINT_FAILED' THEN 'FAILED'
                    WHEN NEW.status='PENDING_PRINT' THEN 'PENDING'
                    ELSE COALESCE(NEW.status, 'PENDING')
                END;
            SQL,
            'warranty_attachments' => <<<'SQL'
                NEW.content_type := COALESCE(NEW.content_type, NEW.mime_type);
                NEW.mime_type := COALESCE(NEW.mime_type, NEW.content_type);
                NEW.file_size_bytes := COALESCE(NEW.file_size_bytes, NEW.file_size);
                NEW.file_size := COALESCE(NEW.file_size, NEW.file_size_bytes);
                NEW.storage_key := COALESCE(NEW.storage_key, NEW.storage_path);
                NEW.storage_path := COALESCE(NEW.storage_path, NEW.storage_key);
                NEW.attachment_type := COALESCE(NEW.attachment_type, 'OTHER');
                NEW.status := COALESCE(NEW.status, 'ACTIVE');
                NEW.uploaded_at := COALESCE(NEW.uploaded_at, NEW.created_at, CURRENT_TIMESTAMP);
                NEW.uploaded_by := COALESCE(NEW.uploaded_by, NEW.created_by);
            SQL,
            'pv_product_images' => <<<'SQL'
                NEW.storage_url := COALESCE(NEW.storage_url, NEW.image_url);
                NEW.image_url := COALESCE(NEW.image_url, NEW.storage_url);
                NEW.status := COALESCE(NEW.status, 'ACTIVE');
            SQL,
        ];

        foreach ($bodies as $table => $body) {
            if (HoaNamErdSchema::relationKind($table) === 'r') {
                $this->createBeforeTrigger($table, $body, 'runtime');
            }
        }
    }

    private function installCanonicalNormalizerTriggers(): void
    {
        $bodies = [
            'idempotency_records' => <<<'SQL'
                NEW.scope := COALESCE(NEW.scope, NEW.operation, 'LEGACY');
                NEW.response_status := COALESCE(NEW.response_status, NEW.response_code);
                NEW.resource_type := COALESCE(NEW.resource_type, NEW.operation);
                NEW.created_by := COALESCE(NEW.created_by, CASE WHEN NEW.user_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN NEW.user_id::uuid ELSE NULL END);
            SQL,
            'scan_events' => <<<'SQL'
                NEW.context_type := COALESCE(NEW.context_type, NEW.resolved_object_type, 'LEGACY_SCAN');
                NEW.scan_source := COALESCE(NEW.scan_source, CASE WHEN NEW.physical_code_id IS NULL THEN 'MANUAL' ELSE 'HARDWARE' END);
                NEW.code_value := COALESCE(NEW.code_value, NEW.raw_code);
                NEW.classification := COALESCE(NEW.classification, CASE WHEN NEW.resolved_object_type IN ('ITEM','CARTON','BOX','TRAY') THEN NEW.resolved_object_type ELSE 'UNKNOWN' END);
                NEW.result := COALESCE(NEW.result, CASE WHEN NEW.result_status IN ('SUCCESS','DUPLICATE','UNKNOWN','INVALID','WRONG_SKU','WRONG_STATE','ERROR') THEN NEW.result_status WHEN NEW.result_status IN ('FOUND','OK') THEN 'SUCCESS' ELSE 'ERROR' END);
                NEW.scanned_at := COALESCE(NEW.scanned_at, NEW.created_at, CURRENT_TIMESTAMP);
            SQL,
            'inventory_movements' => <<<'SQL'
                NEW.movement_key := COALESCE(NEW.movement_key, NEW.movement_no, 'MOVE-' || NEW.id::text);
                NEW.quantity_delta := COALESCE(NEW.quantity_delta, NEW.quantity);
                NEW.source_type := COALESCE(NEW.source_type, 'STOCK_DOCUMENT');
                NEW.source_id := COALESCE(NEW.source_id, NEW.id);
                NEW.occurred_at := COALESCE(NEW.occurred_at, NEW.created_at, CURRENT_TIMESTAMP);
                NEW.stock_status_from := COALESCE(NEW.stock_status_from, CASE WHEN NEW.quantity_delta < 0 THEN NEW.stock_status END);
                NEW.stock_status_to := COALESCE(NEW.stock_status_to, CASE WHEN NEW.quantity_delta > 0 THEN NEW.stock_status END);
            SQL,
            'posting_events' => <<<'SQL'
                NEW.resource_type := COALESCE(NEW.resource_type, CASE WHEN NEW.document_id IS NOT NULL THEN 'STOCK_DOCUMENT' WHEN NEW.movement_id IS NOT NULL THEN 'MOVEMENT' ELSE 'LEGACY' END);
                NEW.resource_id := COALESCE(NEW.resource_id, NEW.document_id, NEW.movement_id, NEW.id);
                NEW.posting_action := COALESCE(NEW.posting_action, NEW.operation, 'POST');
                NEW.status := CASE WHEN NEW.status IN ('STARTED','COMMITTED','FAILED') THEN NEW.status WHEN NEW.status IN ('SUCCESS','SUCCEEDED','DONE') THEN 'COMMITTED' ELSE COALESCE(NEW.status, 'STARTED') END;
                NEW.movement_count := COALESCE(NEW.movement_count, CASE WHEN NEW.movement_id IS NULL THEN 0 ELSE 1 END);
                NEW.started_at := COALESCE(NEW.started_at, NEW.created_at, CURRENT_TIMESTAMP);
                NEW.committed_at := COALESCE(NEW.committed_at, CASE WHEN NEW.status='COMMITTED' THEN NEW.created_at END);
                NEW.actor_id := COALESCE(NEW.actor_id, NEW.created_by);
            SQL,
            'reconciliation_incidents' => <<<'SQL'
                NEW.detected_at := COALESCE(NEW.detected_at, NEW.created_at, CURRENT_TIMESTAMP);
                NEW.severity := COALESCE(NEW.severity, 'ERROR');
                NEW.resolution_type := COALESCE(NEW.resolution_type, NEW.resolution_ref);
                NEW.resolution_note := COALESCE(NEW.resolution_note, NEW.reason);
            SQL,
            'reversal_documents' => <<<'SQL'
                IF NEW.warehouse_id IS NULL OR NEW.original_document_type IS NULL THEN
                    SELECT COALESCE(NEW.warehouse_id, src_warehouse_id, dst_warehouse_id),
                           COALESCE(NEW.original_document_type, CASE WHEN doc_type='receipt' THEN 'INBOUND' WHEN doc_type='issue' THEN 'OUTBOUND' ELSE 'STOCK_DOCUMENT' END)
                      INTO NEW.warehouse_id, NEW.original_document_type
                      FROM stock_documents WHERE id = NEW.original_document_id;
                END IF;
                NEW.original_document_type := COALESCE(NEW.original_document_type, 'STOCK_DOCUMENT');
                NEW.correction_chain_id := COALESCE(NEW.correction_chain_id, NEW.id);
                NEW.reason_code := COALESCE(NEW.reason_code, 'MANUAL');
                NEW.reason_text := COALESCE(NEW.reason_text, NEW.reason, 'Legacy reversal');
            SQL,
            'reversal_lines' => <<<'SQL'
                NEW.quantity_to_reverse := COALESCE(NEW.quantity_to_reverse, NEW.quantity);
                NEW.line_no := COALESCE(NEW.line_no, 1);
                NEW.return_condition := COALESCE(NEW.return_condition, 'NON_SELLABLE');
                IF NEW.sku_id IS NULL THEN SELECT sku_id INTO NEW.sku_id FROM inventory_movements WHERE id = NEW.original_movement_id; END IF;
            SQL,
            'warranty_cases' => <<<'SQL'
                NEW.case_no := COALESCE(NEW.case_no, NEW.warranty_case_code);
                NEW.reported_defect := COALESCE(NEW.reported_defect, NEW.description, 'Legacy warranty case');
                NEW.received_at := COALESCE(NEW.received_at, NEW.created_at, CURRENT_TIMESTAMP);
            SQL,
            'warranty_events' => <<<'SQL'
                NEW.occurred_at := COALESCE(NEW.occurred_at, NEW.created_at, CURRENT_TIMESTAMP);
                NEW.actor_id := COALESCE(NEW.actor_id, NEW.created_by);
                NEW.event_data := COALESCE(NEW.event_data, '{}'::jsonb);
            SQL,
            'pv_product_profiles' => <<<'SQL'
                NEW.published := COALESCE(NEW.published, NEW.is_published, false);
                NEW.featured := COALESCE(NEW.featured, false);
                NEW.public_specifications := COALESCE(NEW.public_specifications, '{}'::jsonb);
            SQL,
        ];

        foreach ($bodies as $table => $body) {
            if (HoaNamErdSchema::relationKind($table) === 'r') {
                $this->createBeforeTrigger($table, $body, 'canonical');
            }
        }
    }

    private function createBeforeTrigger(string $table, string $body, string $suffix): void
    {
        $function = substr('hn_erd_normalize_'.$suffix.'_'.$table, 0, 63);
        $trigger = substr('trg_erd_normalize_'.$suffix.'_'.$table, 0, 63);
        DB::statement('CREATE OR REPLACE FUNCTION '.HoaNamErdSchema::quote($function).'() RETURNS trigger AS $$ BEGIN '.$body.' RETURN NEW; END; $$ LANGUAGE plpgsql');
        DB::statement('DROP TRIGGER IF EXISTS '.HoaNamErdSchema::quote($trigger).' ON '.HoaNamErdSchema::quote($table));
        DB::statement('CREATE TRIGGER '.HoaNamErdSchema::quote($trigger).' BEFORE INSERT OR UPDATE ON '.HoaNamErdSchema::quote($table).' FOR EACH ROW EXECUTE FUNCTION '.HoaNamErdSchema::quote($function).'()');
    }

    private function installIdempotencyRecordMirror(): void
    {
        if (HoaNamErdSchema::relationKind('idempotency_records') !== 'r'
            || HoaNamErdSchema::relationKind('idempotency_records__legacy_projection') !== 'v'
            || ! Schema::hasTable('idempotency_keys')) {
            return;
        }

        $this->ensureUniqueKey('idempotency_records', ['id']);

        DB::statement('CREATE OR REPLACE FUNCTION hn_erd_mirror_idempotency_records() RETURNS trigger AS $$ BEGIN
            INSERT INTO idempotency_records (
                id, scope, idempotency_key, request_hash, response_status, response_body,
                resource_type, resource_id, expires_at, created_at, created_by,
                user_id, operation, business_key, status, response_code, locked_until, completed_at, updated_at
            )
            SELECT '.
                $this->legacyUuidExpression('p.id').' AS id,
                COALESCE(p.operation, \'LEGACY\') AS scope,
                p.idempotency_key::varchar(200),
                CASE WHEN p.request_hash::text ~ \'^v2:[a-f0-9]{64}\' THEN substring(p.request_hash::text from 4 for 64) ELSE substring(p.request_hash::text from 1 for 64) END::char(64),
                p.response_code::integer,
                p.response_body::jsonb,
                p.operation::varchar(80),
                NULL::uuid,
                p.locked_until,
                p.created_at,
                CASE WHEN p.user_id ~* \'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$\' THEN p.user_id::uuid ELSE NULL END,
                p.user_id, p.operation, p.business_key, p.status, p.response_code, p.locked_until, p.completed_at, p.updated_at
            FROM idempotency_records__legacy_projection p
            ON CONFLICT (id) DO UPDATE SET
                scope = EXCLUDED.scope,
                idempotency_key = EXCLUDED.idempotency_key,
                request_hash = EXCLUDED.request_hash,
                response_status = EXCLUDED.response_status,
                response_body = EXCLUDED.response_body,
                resource_type = EXCLUDED.resource_type,
                expires_at = EXCLUDED.expires_at,
                created_by = EXCLUDED.created_by,
                user_id = EXCLUDED.user_id,
                operation = EXCLUDED.operation,
                business_key = EXCLUDED.business_key,
                status = EXCLUDED.status,
                response_code = EXCLUDED.response_code,
                locked_until = EXCLUDED.locked_until,
                completed_at = EXCLUDED.completed_at,
                updated_at = EXCLUDED.updated_at;
            RETURN NULL;
        END; $$ LANGUAGE plpgsql');

        DB::statement('DROP TRIGGER IF EXISTS trg_erd_mirror_idempotency_records_from_idempotency_keys ON idempotency_keys');
        DB::statement('CREATE TRIGGER trg_erd_mirror_idempotency_records_from_idempotency_keys AFTER INSERT OR UPDATE OR DELETE ON idempotency_keys FOR EACH STATEMENT EXECUTE FUNCTION hn_erd_mirror_idempotency_records()');
    }

    private function installProjectionMirrors(): void
    {
        $mappings = [
            'app_users' => [['app_user'], ['id']],
            'roles' => [['role'], ['id']],
            'permissions' => [['permission'], ['id']],
            'user_roles' => [['user_role'], ['user_id', 'role_id']],
            'role_permissions' => [['role_permission'], ['role_id', 'permission_id']],
            'skus' => [['sku'], ['id']],
            'sku_aliases' => [['sku_alias'], ['id']],
            'scan_events' => [['scan_event'], ['id']],
            'inventory_movements' => [['stock_movements'], ['id']],
            'posting_events' => [['posting_event'], ['id']],
            'reconciliation_incidents' => [['reconciliation_incident'], ['id']],
            'reversal_documents' => [['reversal_document'], ['id']],
            'reversal_lines' => [['reversal_line'], ['id']],
            'inbound_documents' => [['stock_documents'], ['id']],
            'inbound_lines' => [['stock_document_lines', 'stock_documents'], ['id']],
            'outbound_documents' => [['stock_documents'], ['id']],
            'outbound_lines' => [['stock_document_lines', 'stock_documents'], ['id']],
            'warranty_cases' => [['warranty_case'], ['id']],
            'warranty_events' => [['warranty_event'], ['id']],
            'pv_product_profiles' => [['pv_product_profile'], ['id']],
            'pv_industries' => [['pv_industry'], ['id']],
            'pv_tags' => [['pv_filter_tag'], ['id']],
            'pv_product_industries' => [['pv_product_industry'], ['profile_id', 'industry_id']],
            'pv_product_tags' => [['pv_product_tag'], ['profile_id', 'tag_id']],
            'pv_recommendation_rules' => [['pv_recommendation_rule'], ['id']],
        ];

        foreach ($mappings as $canonical => [$sources, $keys]) {
            $projection = substr($canonical.'__legacy_projection', 0, 63);
            if (HoaNamErdSchema::relationKind($canonical) !== 'r' || HoaNamErdSchema::relationKind($projection) !== 'v') {
                continue;
            }

            $this->ensureUniqueKey($canonical, $keys);
            $columns = DB::table('information_schema.columns')
                ->where('table_schema', DB::raw('current_schema()'))
                ->where('table_name', $projection)
                ->orderBy('ordinal_position')
                ->pluck('column_name')
                ->filter(fn (string $column): bool => Schema::hasColumn($canonical, $column))
                ->values()->all();

            if ($columns === [] || collect($keys)->contains(fn (string $key): bool => ! in_array($key, $columns, true))) {
                continue;
            }

            $quotedColumns = implode(', ', array_map(HoaNamErdSchema::quote(...), $columns));
            $conflict = implode(', ', array_map(HoaNamErdSchema::quote(...), $keys));
            $appendOnly = in_array($canonical, ['inventory_movements', 'warranty_events'], true);
            $updates = collect($columns)
                ->reject(fn (string $column): bool => in_array($column, $keys, true))
                ->map(fn (string $column): string => HoaNamErdSchema::quote($column).' = EXCLUDED.'.HoaNamErdSchema::quote($column))
                ->implode(', ');
            $upsert = $appendOnly || $updates === ''
                ? 'ON CONFLICT ('.$conflict.') DO NOTHING'
                : 'ON CONFLICT ('.$conflict.') DO UPDATE SET '.$updates;

            $cleanup = in_array($canonical, ['user_roles', 'role_permissions', 'pv_product_industries', 'pv_product_tags'], true)
                ? ' DELETE FROM '.HoaNamErdSchema::quote($canonical).' c WHERE NOT EXISTS (SELECT 1 FROM '.HoaNamErdSchema::quote($projection).' p WHERE '.$this->keyEquality('p', 'c', $keys).');'
                : '';

            $function = substr('hn_erd_mirror_'.$canonical, 0, 63);
            DB::statement('CREATE OR REPLACE FUNCTION '.HoaNamErdSchema::quote($function).'() RETURNS trigger AS $$ BEGIN INSERT INTO '.HoaNamErdSchema::quote($canonical).' ('.$quotedColumns.') SELECT '.$quotedColumns.' FROM '.HoaNamErdSchema::quote($projection).' '.$upsert.';'.$cleanup.' RETURN NULL; END; $$ LANGUAGE plpgsql');

            foreach ($sources as $source) {
                if (! Schema::hasTable($source)) {
                    continue;
                }
                $trigger = substr('trg_erd_mirror_'.$canonical.'_from_'.$source, 0, 63);
                DB::statement('DROP TRIGGER IF EXISTS '.HoaNamErdSchema::quote($trigger).' ON '.HoaNamErdSchema::quote($source));
                DB::statement('CREATE TRIGGER '.HoaNamErdSchema::quote($trigger).' AFTER INSERT OR UPDATE OR DELETE ON '.HoaNamErdSchema::quote($source).' FOR EACH STATEMENT EXECUTE FUNCTION '.HoaNamErdSchema::quote($function).'()');
            }
        }
    }

    private function installAuditMirror(): void
    {
        if (HoaNamErdSchema::relationKind('audit_logs') !== 'r'
            || HoaNamErdSchema::relationKind('audit_logs__legacy_projection') !== 'v'
            || ! Schema::hasTable('audit_log')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_mirror_audit_logs()
            RETURNS trigger AS $$
            BEGIN
                INSERT INTO audit_logs (request_id, actor_id, action, entity_type, entity_id, result, before_data, after_data, metadata, created_at)
                SELECT p.request_id, p.actor_id, p.action, COALESCE(p.object_type, 'LEGACY'),
                       CASE WHEN p.object_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' THEN p.object_id::uuid ELSE NULL END,
                       'SUCCESS', p.before_data::jsonb, p.after_data::jsonb,
                       jsonb_strip_nulls(jsonb_build_object('legacy_id', p.id::text, 'legacy_object_id', p.object_id, 'legacy_reason', p.reason)),
                       p.created_at
                FROM audit_logs__legacy_projection p
                WHERE NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.metadata->>'legacy_id' = p.id::text);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_mirror_audit_logs_from_audit_log ON audit_log');
        DB::statement('CREATE TRIGGER trg_erd_mirror_audit_logs_from_audit_log AFTER INSERT ON audit_log FOR EACH STATEMENT EXECUTE FUNCTION hn_erd_mirror_audit_logs()');
    }

    /** @param array<int,string> $keys */
    private function ensureUniqueKey(string $table, array $keys): void
    {
        $name = substr('ux_erd_mirror_'.$table.'_'.implode('_', $keys), 0, 63);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.HoaNamErdSchema::quote($name).' ON '.HoaNamErdSchema::quote($table).' ('.implode(', ', array_map(HoaNamErdSchema::quote(...), $keys)).')');
    }

    /** @param array<int,string> $keys */
    private function keyEquality(string $left, string $right, array $keys): string
    {
        return collect($keys)
            ->map(fn (string $key): string => $left.'.'.HoaNamErdSchema::quote($key).' = '.$right.'.'.HoaNamErdSchema::quote($key))
            ->implode(' AND ');
    }

    private function legacyUuidExpression(string $sqlExpression): string
    {
        $hash = "md5('hoa-nam-legacy-id:' || {$sqlExpression}::text)";

        return "((substr({$hash}, 1, 8) || '-' || substr({$hash}, 9, 4) || '-' || substr({$hash}, 13, 4) || '-' || substr({$hash}, 17, 4) || '-' || substr({$hash}, 21, 12))::uuid)";
    }
};
