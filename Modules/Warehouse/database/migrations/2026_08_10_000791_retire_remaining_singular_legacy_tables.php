<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->copyRemainingLegacyRows();
        $this->dropRemainingLegacyTables();
        $this->createRemainingCompatibilityViews();
    }

    public function down(): void
    {
        // Fail-forward: singular legacy names remain as compatibility views only.
    }

    private function copyRemainingLegacyRows(): void
    {
        $statements = [
            <<<'SQL'
                INSERT INTO containers (
                    id, container_id, container_code, container_type, physical_code_id,
                    primary_sku_id, parent_container_id, zone_code, location_hint,
                    status, version, created_at, updated_at
                )
                SELECT container_id, container_id, container_code, container_type, physical_code_id,
                       primary_product_id, parent_container_id, zone_code, location_hint,
                       status, COALESCE(version, 1), created_at, COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)
                  FROM container
                ON CONFLICT (id) DO UPDATE SET
                    container_code=EXCLUDED.container_code,
                    container_type=EXCLUDED.container_type,
                    physical_code_id=EXCLUDED.physical_code_id,
                    primary_sku_id=EXCLUDED.primary_sku_id,
                    parent_container_id=EXCLUDED.parent_container_id,
                    zone_code=EXCLUDED.zone_code,
                    location_hint=EXCLUDED.location_hint,
                    status=EXCLUDED.status,
                    version=EXCLUDED.version,
                    updated_at=EXCLUDED.updated_at
            SQL,
            <<<'SQL'
                INSERT INTO container_items (
                    id, container_item_id, container_id, item_id, sku_id, quantity, status,
                    created_at, updated_at, is_active, placed_at, version
                )
                SELECT container_item_id, container_item_id, container_id, inventory_serial_id,
                       product_id, quantity, status, created_at,
                       COALESCE(updated_at, created_at, CURRENT_TIMESTAMP),
                       status <> 'REMOVED', created_at, COALESCE(version, 1)
                  FROM container_item
                ON CONFLICT (id) DO UPDATE SET
                    container_id=EXCLUDED.container_id,
                    item_id=EXCLUDED.item_id,
                    sku_id=EXCLUDED.sku_id,
                    quantity=EXCLUDED.quantity,
                    status=EXCLUDED.status,
                    updated_at=EXCLUDED.updated_at,
                    is_active=EXCLUDED.is_active,
                    version=EXCLUDED.version
            SQL,
            <<<'SQL'
                INSERT INTO defect_catalogs (defect_id, defect_code, defect_name, description, status, created_at, updated_at, version)
                SELECT defect_id, defect_code, defect_name, description, status, created_at,
                       COALESCE(updated_at, created_at, CURRENT_TIMESTAMP), COALESCE(version, 1)
                  FROM defect_catalog
                ON CONFLICT (defect_id) DO UPDATE SET
                    defect_code=EXCLUDED.defect_code,
                    defect_name=EXCLUDED.defect_name,
                    description=EXCLUDED.description,
                    status=EXCLUDED.status,
                    updated_at=EXCLUDED.updated_at,
                    version=EXCLUDED.version
            SQL,
        ];

        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }

    private function dropRemainingLegacyTables(): void
    {
        foreach ([
            'container',
            'container_item',
            'physical_code',
            'posting_event',
            'scan_event',
            'defect_catalog',
            'warranty_case',
            'warranty_event',
            'pv_product_profile',
            'pv_product_tag',
            'pv_recommendation_rule',
            'reconciliation_incident',
            'reversal_line',
            'reversal_document',
        ] as $table) {
            $kind = HoaNamErdSchema::relationKind($table);
            if ($kind === 'v') {
                DB::statement('DROP VIEW '.HoaNamErdSchema::quote($table).' CASCADE');
            } elseif ($kind === 'r' || $kind === 'p') {
                DB::statement('DROP TABLE '.HoaNamErdSchema::quote($table).' CASCADE');
            }
        }
    }

    private function createRemainingCompatibilityViews(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW container AS
            SELECT container_id, physical_code_id, container_code, container_type, parent_container_id,
                   NULL::uuid AS warehouse_id, primary_sku_id AS primary_product_id, zone_code, location_hint,
                   status, version, created_at, updated_at, NULL::timestamptz AS deleted_at, NULL::timestamptz AS sealed_at
              FROM containers
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW container_item AS
            SELECT container_item_id, container_id, item_id AS inventory_serial_id, sku_id AS product_id,
                   quantity, status, created_at, updated_at,
                   COALESCE(item_id::text, sku_id::text, id::text) AS item_key, version
              FROM container_items
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW physical_code AS
            SELECT physical_code_id, code_value, code_type, carrier_type, assigned_object_type,
                   assigned_object_id, status, created_at, updated_at,
                   CASE WHEN assigned_object_type = 'SKU' THEN assigned_object_id END AS product_id,
                   version, hardware_uid
              FROM physical_codes
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW defect_catalog AS
            SELECT defect_id, defect_code, defect_name, NULL::varchar(30) AS severity, status,
                   created_at, updated_at, version, defect_id AS id, description,
                   NULL::uuid AS created_by, NULL::uuid AS updated_by
              FROM defect_catalogs
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW posting_event AS
            SELECT id AS posting_event_id, operation, idempotency_key, request_hash, status,
                   document_id, movement_id, response_snapshot, created_by, created_at
              FROM posting_events
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW scan_event AS
            SELECT id AS scan_event_id, scan_batch_id, raw_code, physical_code_id,
                   resolved_object_type, resolved_object_id, result_status, message, created_at
              FROM scan_events
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW reconciliation_incident AS
            SELECT id AS incident_id, incident_no, divergence_type, warehouse_id, sku_id AS product_id,
                   detected_value, status, resolution_ref, reason, created_at, resolved_at, resolved_by
              FROM reconciliation_incidents
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW reversal_document AS
            SELECT id AS reversal_document_id, reversal_no AS document_no, original_document_id AS stock_document_id,
                   status, reason, created_by, posted_at, posted_by, version, created_at, updated_at,
                   correction_chain_id, NULL::varchar(30) AS condition_status, NULL::varchar(200) AS idempotency_key,
                   warehouse_id, original_document_type, original_document_id, reason_code, reason_text,
                   NULL::varchar(30) AS physical_return_status, cancelled_at, cancel_reason, updated_by
              FROM reversal_documents
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW reversal_line AS
            SELECT id AS reversal_line_id, reversal_document_id, original_movement_id,
                   quantity_to_reverse AS reversal_qty, reason, created_at, updated_at, sku_id, item_id,
                   stock_status, line_no, quantity_to_reverse, return_condition, downstream_check_result,
                   posted_movement_id, created_by, updated_by, version
              FROM reversal_lines
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW warranty_case AS
            SELECT id AS warranty_case_id, warranty_case_code, item_id AS inventory_serial_id, sku_id AS product_id,
                   temporary_item_code, identification_status, missing_code_reason, manual_product_description,
                   defect_id, customer_name, customer_phone, status, condition_status, description, created_by,
                   version, closed_at, retention_until, legal_hold_flag, created_at, updated_at,
                   NULL::timestamptz AS deleted_at, item_id, customer_address, purchase_date, sales_trace_status
              FROM warranty_cases
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW warranty_event AS
            SELECT id AS warranty_event_id, warranty_case_id, event_type, from_status, to_status,
                   note, created_by, occurred_at AS event_at
              FROM warranty_events
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW pv_product_profile AS
            SELECT id AS profile_id, sku_id AS product_id, display_name, description,
                   NULL::json AS image_urls, is_published AS published, published_at,
                   last_inventory_sync_at, sort_order, version, created_at, updated_at
              FROM pv_product_profiles
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW pv_product_tag AS
            SELECT profile_id, tag_id, 0 AS priority, created_at
              FROM pv_product_tags
        SQL);
        DB::statement(<<<'SQL'
            CREATE VIEW pv_recommendation_rule AS
            SELECT id AS rule_id, industry_id, category_id, tag_id, sku_id AS product_id,
                   priority, status, created_at, updated_at
              FROM pv_recommendation_rules
        SQL);

        $this->createWriteTriggers();
    }

    private function createWriteTriggers(): void
    {
        $definitions = [
            'container' => [
                'target' => 'containers',
                'keys' => ['id' => 'COALESCE(NEW.container_id, gen_random_uuid())'],
                'columns' => [
                    'container_id' => 'COALESCE(NEW.container_id, gen_random_uuid())',
                    'container_code' => 'NEW.container_code',
                    'container_type' => 'NEW.container_type',
                    'physical_code_id' => 'NEW.physical_code_id',
                    'primary_sku_id' => 'NEW.primary_product_id',
                    'parent_container_id' => 'NEW.parent_container_id',
                    'zone_code' => 'NEW.zone_code',
                    'location_hint' => 'NEW.location_hint',
                    'status' => "COALESCE(NEW.status, 'EMPTY')",
                    'version' => 'COALESCE(NEW.version, 1)',
                    'created_at' => 'COALESCE(NEW.created_at, CURRENT_TIMESTAMP)',
                    'updated_at' => 'COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)',
                ],
                'delete' => 'id=OLD.container_id',
            ],
            'container_item' => [
                'target' => 'container_items',
                'keys' => ['id' => 'COALESCE(NEW.container_item_id, gen_random_uuid())'],
                'columns' => [
                    'container_item_id' => 'COALESCE(NEW.container_item_id, gen_random_uuid())',
                    'container_id' => 'NEW.container_id',
                    'item_id' => 'NEW.inventory_serial_id',
                    'assigned_object_type' => "COALESCE(NEW.assigned_object_type, CASE WHEN NEW.product_id IS NOT NULL THEN 'SKU' END)",
                    'assigned_object_id' => 'COALESCE(NEW.assigned_object_id, NEW.product_id)',
                    'quantity' => 'NEW.quantity',
                    'status' => "COALESCE(NEW.status, 'ACTIVE')",
                    'is_active' => "COALESCE(NEW.status, 'ACTIVE') <> 'REMOVED'",
                    'placed_at' => 'COALESCE(NEW.created_at, CURRENT_TIMESTAMP)',
                    'version' => 'COALESCE(NEW.version, 1)',
                    'created_at' => 'COALESCE(NEW.created_at, CURRENT_TIMESTAMP)',
                    'updated_at' => 'COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)',
                ],
                'delete' => 'id=OLD.container_item_id',
            ],
            'physical_code' => [
                'target' => 'physical_codes',
                'keys' => ['id' => 'COALESCE(NEW.physical_code_id, gen_random_uuid())'],
                'columns' => [
                    'physical_code_id' => 'COALESCE(NEW.physical_code_id, gen_random_uuid())',
                    'code_value' => 'NEW.code_value',
                    'code_type' => "COALESCE(NEW.code_type, 'CANONICAL')",
                    'carrier_type' => "COALESCE(NEW.carrier_type, 'QR')",
                    'hardware_uid' => 'NEW.hardware_uid',
                    'assigned_object_type' => "COALESCE(NEW.assigned_object_type, CASE WHEN NEW.product_id IS NOT NULL THEN 'SKU' END)",
                    'assigned_object_id' => 'COALESCE(NEW.assigned_object_id, NEW.product_id)',
                    'status' => "COALESCE(NEW.status, 'ACTIVE')",
                    'version' => 'COALESCE(NEW.version, 1)',
                    'created_at' => 'COALESCE(NEW.created_at, CURRENT_TIMESTAMP)',
                    'updated_at' => 'COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)',
                ],
                'delete' => 'id=OLD.physical_code_id',
            ],
            'defect_catalog' => [
                'target' => 'defect_catalogs',
                'keys' => ['defect_id' => 'COALESCE(NEW.defect_id, NEW.id, gen_random_uuid())'],
                'columns' => [
                    'defect_code' => 'NEW.defect_code',
                    'defect_name' => 'NEW.defect_name',
                    'description' => 'NEW.description',
                    'status' => "COALESCE(NEW.status, 'ACTIVE')",
                    'version' => 'COALESCE(NEW.version, 1)',
                    'created_at' => 'COALESCE(NEW.created_at, CURRENT_TIMESTAMP)',
                    'updated_at' => 'COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)',
                ],
                'delete' => 'defect_id=OLD.defect_id',
            ],
            'posting_event' => [
                'target' => 'posting_events',
                'keys' => ['id' => 'COALESCE(NEW.posting_event_id, gen_random_uuid())'],
                'columns' => [
                    'operation' => 'NEW.operation',
                    'idempotency_key' => 'NEW.idempotency_key',
                    'request_hash' => 'NEW.request_hash',
                    'status' => "COALESCE(NEW.status, 'STARTED')",
                    'document_id' => 'NEW.document_id',
                    'movement_id' => 'NEW.movement_id',
                    'response_snapshot' => 'NEW.response_snapshot',
                    'created_by' => 'NEW.created_by',
                    'created_at' => 'COALESCE(NEW.created_at, CURRENT_TIMESTAMP)',
                ],
                'delete' => 'id=OLD.posting_event_id',
            ],
            'scan_event' => [
                'target' => 'scan_events',
                'keys' => ['id' => 'COALESCE(NEW.scan_event_id, gen_random_uuid())'],
                'columns' => [
                    'scan_batch_id' => 'NEW.scan_batch_id',
                    'raw_code' => 'NEW.raw_code',
                    'physical_code_id' => 'NEW.physical_code_id',
                    'resolved_object_type' => 'NEW.resolved_object_type',
                    'resolved_object_id' => 'NEW.resolved_object_id',
                    'result_status' => 'NEW.result_status',
                    'message' => 'NEW.message',
                    'created_at' => 'COALESCE(NEW.created_at, CURRENT_TIMESTAMP)',
                ],
                'delete' => 'id=OLD.scan_event_id',
            ],
            'warranty_case' => [
                'target' => 'warranty_cases',
                'keys' => ['id' => 'COALESCE(NEW.warranty_case_id, gen_random_uuid())'],
                'columns' => [
                    'warranty_case_code' => 'NEW.warranty_case_code',
                    'item_id' => 'COALESCE(NEW.item_id, NEW.inventory_serial_id)',
                    'sku_id' => 'NEW.product_id',
                    'temporary_item_code' => 'NEW.temporary_item_code',
                    'identification_status' => "COALESCE(NEW.identification_status, 'IDENTIFIED')",
                    'missing_code_reason' => 'NEW.missing_code_reason',
                    'manual_product_description' => 'NEW.manual_product_description',
                    'defect_id' => 'NEW.defect_id',
                    'customer_name' => 'NEW.customer_name',
                    'customer_phone' => 'NEW.customer_phone',
                    'customer_address' => 'NEW.customer_address',
                    'purchase_date' => 'NEW.purchase_date',
                    'sales_trace_status' => "COALESCE(NEW.sales_trace_status, 'NOT_TRACEABLE')",
                    'status' => "COALESCE(NEW.status, 'RECEIVED')",
                    'condition_status' => 'NEW.condition_status',
                    'description' => 'NEW.description',
                    'created_by' => 'NEW.created_by',
                    'version' => 'COALESCE(NEW.version, 1)',
                    'closed_at' => 'NEW.closed_at',
                    'retention_until' => 'NEW.retention_until',
                    'legal_hold_flag' => 'COALESCE(NEW.legal_hold_flag, false)',
                    'created_at' => 'COALESCE(NEW.created_at, CURRENT_TIMESTAMP)',
                    'updated_at' => 'COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)',
                ],
                'delete' => 'id=OLD.warranty_case_id',
            ],
            'warranty_event' => [
                'target' => 'warranty_events',
                'keys' => ['id' => 'COALESCE(NEW.warranty_event_id, gen_random_uuid())'],
                'columns' => [
                    'warranty_case_id' => 'NEW.warranty_case_id',
                    'event_type' => 'NEW.event_type',
                    'from_status' => 'NEW.from_status',
                    'to_status' => 'NEW.to_status',
                    'note' => 'NEW.note',
                    'created_by' => 'NEW.created_by',
                    'created_at' => 'COALESCE(NEW.event_at, CURRENT_TIMESTAMP)',
                    'occurred_at' => 'COALESCE(NEW.event_at, CURRENT_TIMESTAMP)',
                    'actor_id' => 'NEW.created_by',
                    'event_data' => "'{}'::jsonb",
                ],
                'delete' => 'id=OLD.warranty_event_id',
            ],
            'reversal_document' => [
                'target' => 'reversal_documents',
                'keys' => ['id' => 'COALESCE(NEW.reversal_document_id, gen_random_uuid())'],
                'columns' => [
                    'reversal_no' => 'NEW.document_no',
                    'original_document_id' => 'COALESCE(NEW.original_document_id, NEW.stock_document_id)',
                    'status' => "COALESCE(NEW.status, 'DRAFT')",
                    'reason' => 'NEW.reason',
                    'posted_at' => 'NEW.posted_at',
                    'posted_by' => 'NEW.posted_by',
                    'created_at' => 'COALESCE(NEW.created_at, CURRENT_TIMESTAMP)',
                    'created_by' => 'NEW.created_by',
                    'updated_at' => 'COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)',
                    'updated_by' => 'NEW.updated_by',
                    'version' => 'COALESCE(NEW.version, 1)',
                    'warehouse_id' => 'NEW.warehouse_id',
                    'original_document_type' => 'NEW.original_document_type',
                    'correction_chain_id' => 'NEW.correction_chain_id',
                    'reason_code' => 'NEW.reason_code',
                    'reason_text' => 'COALESCE(NEW.reason_text, NEW.reason)',
                    'cancelled_at' => 'NEW.cancelled_at',
                    'cancel_reason' => 'NEW.cancel_reason',
                ],
                'delete' => 'id=OLD.reversal_document_id',
            ],
            'reversal_line' => [
                'target' => 'reversal_lines',
                'keys' => ['id' => 'COALESCE(NEW.reversal_line_id, gen_random_uuid())'],
                'columns' => [
                    'reversal_document_id' => 'NEW.reversal_document_id',
                    'line_no' => 'COALESCE(NEW.line_no, 1)',
                    'original_movement_id' => 'NEW.original_movement_id',
                    'quantity' => 'COALESCE(NEW.quantity_to_reverse, NEW.reversal_qty)',
                    'quantity_to_reverse' => 'COALESCE(NEW.quantity_to_reverse, NEW.reversal_qty)',
                    'sku_id' => 'NEW.sku_id',
                    'item_id' => 'NEW.item_id',
                    'stock_status' => 'NEW.stock_status',
                    'return_condition' => "COALESCE(NEW.return_condition, 'NON_SELLABLE')",
                    'downstream_check_result' => 'NEW.downstream_check_result',
                    'posted_movement_id' => 'NEW.posted_movement_id',
                    'reason' => 'NEW.reason',
                    'created_at' => 'COALESCE(NEW.created_at, CURRENT_TIMESTAMP)',
                    'created_by' => 'NEW.created_by',
                    'updated_at' => 'COALESCE(NEW.updated_at, CURRENT_TIMESTAMP)',
                    'updated_by' => 'NEW.updated_by',
                    'version' => 'COALESCE(NEW.version, 1)',
                ],
                'delete' => 'id=OLD.reversal_line_id',
            ],
        ];

        foreach ($definitions as $view => $definition) {
            $this->createGenericWriteTrigger($view, $definition['target'], $definition['keys'], $definition['columns'], $definition['delete']);
        }

        $this->createSimpleInsertUpdateTrigger('reconciliation_incident', 'reconciliation_incidents', 'id', 'incident_id');
        $this->createSimpleInsertUpdateTrigger('pv_product_profile', 'pv_product_profiles', 'id', 'profile_id');
        $this->createSimpleInsertUpdateTrigger('pv_recommendation_rule', 'pv_recommendation_rules', 'id', 'rule_id');
    }

    /** @param array<string,string> $keys @param array<string,string> $columns */
    private function createGenericWriteTrigger(string $view, string $target, array $keys, array $columns, string $deleteWhere): void
    {
        $allColumns = array_merge($keys, $columns);
        $quotedColumns = implode(', ', array_map(HoaNamErdSchema::quote(...), array_keys($allColumns)));
        $values = implode(', ', array_values($allColumns));
        $conflict = implode(', ', array_map(HoaNamErdSchema::quote(...), array_keys($keys)));
        $updates = collect(array_keys($columns))
            ->map(fn (string $column): string => HoaNamErdSchema::quote($column).'=EXCLUDED.'.HoaNamErdSchema::quote($column))
            ->implode(', ');
        $function = substr('hn_legacy_'.$view.'_write', 0, 63);

        DB::statement('CREATE OR REPLACE FUNCTION '.HoaNamErdSchema::quote($function).'() RETURNS trigger AS $$ BEGIN '.
            'IF TG_OP = \'DELETE\' THEN DELETE FROM '.HoaNamErdSchema::quote($target).' WHERE '.$deleteWhere.'; RETURN OLD; END IF; '.
            'INSERT INTO '.HoaNamErdSchema::quote($target).' ('.$quotedColumns.') VALUES ('.$values.') '.
            'ON CONFLICT ('.$conflict.') DO UPDATE SET '.$updates.'; RETURN NEW; END; $$ LANGUAGE plpgsql');
        $this->insteadOf($view, $function);
    }

    private function createSimpleInsertUpdateTrigger(string $view, string $target, string $targetKey, string $viewKey): void
    {
        DB::statement('CREATE OR REPLACE FUNCTION '.HoaNamErdSchema::quote('hn_legacy_'.$view.'_reject_write').'() RETURNS trigger AS $$ BEGIN '.
            'RAISE EXCEPTION \''.str_replace("'", "''", $view).' compatibility view is read-only; write canonical table '.str_replace("'", "''", $target).' directly\'; '.
            'END; $$ LANGUAGE plpgsql');
        $this->insteadOf($view, 'hn_legacy_'.$view.'_reject_write', 'INSERT OR UPDATE OR DELETE');
    }

    private function insteadOf(string $view, string $function, string $events = 'INSERT OR UPDATE OR DELETE'): void
    {
        $trigger = substr('trg_'.$function, 0, 63);
        DB::statement('CREATE TRIGGER '.HoaNamErdSchema::quote($trigger).' INSTEAD OF '.$events.' ON '.HoaNamErdSchema::quote($view).' FOR EACH ROW EXECUTE FUNCTION '.HoaNamErdSchema::quote($function).'()');
    }

};
