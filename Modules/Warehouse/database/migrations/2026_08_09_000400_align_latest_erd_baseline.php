<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VIEWS = [
        'app_users',
        'roles',
        'permissions',
        'user_roles',
        'role_permissions',
        'idempotency_records',
        'audit_logs',
        'skus',
        'sku_aliases',
        'putaway_events',
        'scan_events',
        'inventory_movements',
        'posting_events',
        'reconciliation_incidents',
        'reversal_documents',
        'reversal_lines',
        'inbound_documents',
        'inbound_lines',
        'outbound_documents',
        'outbound_lines',
        'warranty_cases',
        'warranty_events',
        'pv_product_profiles',
        'pv_industries',
        'pv_tags',
        'pv_product_industries',
        'pv_product_tags',
        'pv_recommendation_rules',
    ];

    public function up(): void
    {
        $this->dropExcludedMvpTables();
        $this->createCanonicalMasterTables();
        $this->createMissingOperationalTables();
        $this->createCanonicalViews();
    }

    public function down(): void
    {
        foreach (array_reverse(self::VIEWS) as $view) {
            $this->dropView($view);
        }

        foreach ([
            'migration_errors',
            'migration_runs',
            'component_issue_items',
            'component_issue_lines',
            'component_issue_documents',
            'warranty_attachments',
            'label_print_events',
            'printer_configs',
            'label_snapshots',
            'outbound_item_matches',
            'outbound_request_lines',
            'outbound_requests',
            'outbound_request_import_rows',
            'outbound_request_imports',
            'inbound_scan_entries',
            'sku_compatible_accessories',
            'package_variants',
            'product_models',
            'power_sources',
            'brands',
            'product_categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function dropExcludedMvpTables(): void
    {
        if (Schema::hasColumn('stock_movements', 'stocktake_variance_id')) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_stocktake_variance_id_foreign');
            }

            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->dropColumn('stocktake_variance_id');
            });
        }

        foreach ([
            'stocktake_variance',
            'stocktake_count',
            'stocktake_snapshot',
            'stocktake_session',
            'inventory_reservations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createCanonicalMasterTables(): void
    {
        if (! Schema::hasTable('product_categories')) {
            Schema::create('product_categories', function (Blueprint $table): void {
                $this->masterColumns($table, 'category_code', 'category_name');
            });
        }

        if (! Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table): void {
                $this->masterColumns($table, 'brand_code', 'brand_name');
            });
        }

        if (! Schema::hasTable('power_sources')) {
            Schema::create('power_sources', function (Blueprint $table): void {
                $this->masterColumns($table, 'power_source_code', 'power_source_name');
            });
        }

        if (! Schema::hasTable('product_models')) {
            Schema::create('product_models', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->string('model_code', 80)->unique();
                $table->string('model_name', 200);
                $table->foreignUuid('brand_id')->nullable();
                $table->string('status', 20)->default('ACTIVE');
                $table->integer('sort_order')->default(0);
                $this->standardAudit($table);
                $table->index(['brand_id', 'status']);
            });
        }

        if (! Schema::hasTable('package_variants')) {
            Schema::create('package_variants', function (Blueprint $table): void {
                $this->masterColumns($table, 'variant_code', 'variant_name');
            });
        }
    }

    private function createMissingOperationalTables(): void
    {
        if (! Schema::hasTable('sku_compatible_accessories')) {
            Schema::create('sku_compatible_accessories', function (Blueprint $table): void {
                $table->foreignUuid('sku_id');
                $table->foreignUuid('accessory_sku_id');
                $table->integer('priority')->default(0);
                $table->timestamp('created_at')->useCurrent();
                $table->uuid('created_by')->nullable();
                $table->primary(['sku_id', 'accessory_sku_id'], 'pk_sku_compatible_accessories');
                $table->index(['accessory_sku_id', 'priority'], 'idx_sku_compatible_accessory');
            });
        }

        if (! Schema::hasTable('inbound_scan_entries')) {
            Schema::create('inbound_scan_entries', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->uuid('inbound_line_id');
                $table->uuid('item_id')->nullable();
                $table->uuid('container_id')->nullable();
                $table->bigInteger('quantity')->default(1);
                $table->string('scan_source', 30)->nullable();
                $this->standardAudit($table);
                $table->index(['inbound_line_id', 'item_id'], 'idx_inbound_scan_line_item');
                $table->index(['inbound_line_id', 'container_id'], 'idx_inbound_scan_line_container');
            });
        }

        if (! Schema::hasTable('outbound_request_imports')) {
            Schema::create('outbound_request_imports', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->string('import_no', 80)->unique();
                $table->string('source_file_name', 255);
                $table->string('source_checksum', 128);
                $table->string('status', 20)->default('UPLOADED');
                $table->integer('total_rows')->default(0);
                $table->integer('valid_rows')->default(0);
                $table->integer('error_rows')->default(0);
                $this->standardAudit($table);
                $table->index(['status', 'created_at'], 'idx_outbound_import_status_created');
            });
        }

        if (! Schema::hasTable('outbound_request_import_rows')) {
            Schema::create('outbound_request_import_rows', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->uuid('import_id');
                $table->integer('row_no');
                $table->json('raw_payload')->nullable();
                $table->json('normalized_payload')->nullable();
                $table->string('validation_status', 20)->default('PENDING');
                $table->json('validation_errors')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['import_id', 'row_no'], 'idx_outbound_import_rows_import_row');
            });
        }

        if (! Schema::hasTable('outbound_requests')) {
            Schema::create('outbound_requests', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->string('request_no', 80)->unique();
                $table->uuid('import_id')->nullable();
                $table->uuid('agency_id')->nullable();
                $table->string('recipient_name', 200);
                $table->text('recipient_address');
                $table->date('request_date');
                $table->string('status', 20)->default('DRAFT');
                $this->standardAudit($table);
                $table->index(['status', 'request_date'], 'idx_outbound_requests_status_date');
            });
        }

        if (! Schema::hasTable('outbound_request_lines')) {
            Schema::create('outbound_request_lines', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->uuid('outbound_request_id');
                $table->integer('line_no');
                $table->uuid('sku_id');
                $table->bigInteger('required_qty');
                $this->standardAudit($table);
                $table->unique(['outbound_request_id', 'line_no'], 'uk_outbound_request_line_no');
            });
        }

        if (! Schema::hasTable('outbound_item_matches')) {
            Schema::create('outbound_item_matches', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->uuid('outbound_line_id');
                $table->uuid('item_id')->nullable();
                $table->uuid('container_id')->nullable();
                $table->bigInteger('quantity')->default(1);
                $this->standardAudit($table);
                $table->index(['outbound_line_id', 'item_id'], 'idx_outbound_match_line_item');
                $table->index(['outbound_line_id', 'container_id'], 'idx_outbound_match_line_container');
            });
        }

        if (! Schema::hasTable('label_snapshots')) {
            Schema::create('label_snapshots', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->uuid('outbound_item_match_id');
                $table->uuid('sku_id');
                $table->uuid('item_id')->nullable();
                $table->json('label_payload');
                $table->string('status', 20)->default('PENDING_PRINT');
                $this->standardAudit($table);
                $table->index(['status', 'created_at'], 'idx_label_snapshots_status_created');
            });
        }

        if (! Schema::hasTable('printer_configs')) {
            Schema::create('printer_configs', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->string('printer_code', 80)->unique();
                $table->string('printer_name', 200);
                $table->string('agent_code', 120)->nullable();
                $table->string('label_size', 80)->nullable();
                $this->standardAudit($table);
            });
        }

        if (! Schema::hasTable('label_print_events')) {
            Schema::create('label_print_events', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->uuid('label_snapshot_id');
                $table->uuid('printer_config_id')->nullable();
                $table->string('status', 20)->default('PENDING_PRINT');
                $table->text('error_message')->nullable();
                $table->timestamp('printed_at')->nullable();
                $this->standardAudit($table);
                $table->index(['label_snapshot_id', 'created_at'], 'idx_label_print_snapshot_created');
            });
        }

        if (! Schema::hasTable('warranty_attachments')) {
            Schema::create('warranty_attachments', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->uuid('warranty_case_id');
                $table->string('file_name', 255);
                $table->string('storage_path', 500);
                $table->string('mime_type', 120)->nullable();
                $table->bigInteger('file_size')->nullable();
                $this->standardAudit($table);
                $table->index(['warranty_case_id', 'created_at'], 'idx_warranty_attachments_case_created');
            });
        }

        if (! Schema::hasTable('component_issue_documents')) {
            Schema::create('component_issue_documents', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->string('component_issue_no', 80)->unique();
                $table->uuid('warehouse_id');
                $table->uuid('warranty_case_id');
                $table->date('document_date');
                $table->string('status', 20)->default('DRAFT');
                $table->timestamp('posted_at')->nullable();
                $table->uuid('posted_by')->nullable();
                $this->standardAudit($table);
                $table->index(['warehouse_id', 'status', 'document_date'], 'idx_component_issue_wh_status_date');
            });
        }

        if (! Schema::hasTable('component_issue_lines')) {
            Schema::create('component_issue_lines', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->uuid('component_issue_document_id');
                $table->integer('line_no');
                $table->uuid('sku_id');
                $table->bigInteger('required_qty');
                $table->bigInteger('scanned_qty')->default(0);
                $this->standardAudit($table);
                $table->unique(['component_issue_document_id', 'line_no'], 'uk_component_issue_line_no');
            });
        }

        if (! Schema::hasTable('component_issue_items')) {
            Schema::create('component_issue_items', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->uuid('component_issue_line_id');
                $table->uuid('item_id')->nullable();
                $table->uuid('container_id')->nullable();
                $table->bigInteger('quantity')->default(1);
                $this->standardAudit($table);
                $table->index(['component_issue_line_id', 'item_id'], 'idx_component_issue_item_line_item');
            });
        }

        if (! Schema::hasTable('pv_product_images')) {
            Schema::create('pv_product_images', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->uuid('profile_id');
                $table->string('image_url', 500);
                $table->boolean('is_primary')->default(false);
                $table->integer('sort_order')->default(0);
                $this->standardAudit($table);
                $table->index(['profile_id', 'sort_order'], 'idx_pv_product_images_profile_sort');
            });
        }

        if (! Schema::hasTable('migration_runs')) {
            Schema::create('migration_runs', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->string('migration_run_code', 80)->unique();
                $table->string('package_version', 50);
                $table->string('source_file_name', 255);
                $table->char('source_checksum', 64);
                $table->string('mode', 20);
                $table->string('status', 30);
                $table->integer('total_rows')->default(0);
                $table->integer('valid_rows')->default(0);
                $table->integer('error_rows')->default(0);
                $table->string('reconciliation_status', 20)->nullable();
                $table->json('reconciliation_result')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->uuid('executed_by')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->uuid('rollback_of_run_id')->nullable();
                $table->text('note')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['status', 'mode'], 'idx_migration_runs_status_mode');
            });
        }

        if (! Schema::hasTable('migration_errors')) {
            Schema::create('migration_errors', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->uuid('migration_run_id');
                $table->string('dataset', 80);
                $table->integer('row_no')->nullable();
                $table->string('field_name', 120)->nullable();
                $table->string('error_code', 80);
                $table->string('severity', 20);
                $table->text('message');
                $table->text('raw_value_masked')->nullable();
                $table->text('suggested_action')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['migration_run_id', 'dataset', 'row_no'], 'idx_migration_errors_run_dataset_row');
                $table->index(['severity', 'error_code'], 'idx_migration_errors_severity_code');
            });
        }
    }

    private function createCanonicalViews(): void
    {
        $this->createView('app_users', 'SELECT user_id AS id, username, display_name, email, phone, status, created_at, NULL AS created_by, updated_at, NULL AS updated_by, version FROM app_user');
        $this->createView('roles', 'SELECT role_id AS id, role_code, role_name, status, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM role');
        $this->createView('permissions', 'SELECT permission_id AS id, permission_key, permission_name AS description, status, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM permission');
        $this->createView('user_roles', 'SELECT user_id, role_id, created_at AS assigned_at, NULL AS assigned_by, scope_ref, effective_from, effective_to FROM user_role');
        $this->createView('role_permissions', 'SELECT role_id, permission_id, created_at AS assigned_at, NULL AS assigned_by FROM role_permission');
        $this->createView('idempotency_records', 'SELECT id, idem_key AS idempotency_key, user_id, operation, business_key, request_hash, status, response_code, response_body, locked_until, completed_at, created_at, updated_at FROM idempotency_keys');
        $this->createView('audit_logs', 'SELECT id, actor_id, action, object_type, object_id, reason, request_id, before_data, after_data, created_at FROM audit_log');
        $this->createView('skus', "SELECT sku_id AS id, sku_code, sku_name, sku_type, CASE WHEN is_serial_tracked THEN 'SERIAL' ELSE 'QUANTITY' END AS tracking_mode, unit AS unit_code, brand_id, category_id, model_id, NULL AS power_source_id, NULL AS package_variant_id, specification AS specifications, status, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM sku");
        $this->createView('sku_aliases', "SELECT alias_id AS id, product_id AS sku_id, COALESCE(alias_type, 'LEGACY') AS source_system, alias_code, 'ACTIVE' AS status, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM sku_alias");
        $this->createView('putaway_events', 'SELECT putaway_task_id AS id, stock_document_id, warehouse_id, NULL AS container_id, NULL AS from_location_hint, NULL AS to_location_hint, status, created_at, created_by, updated_at, NULL AS updated_by, version FROM putaway_task');
        $this->createView('scan_events', 'SELECT scan_event_id AS id, scan_batch_id, raw_code, physical_code_id, resolved_object_type, resolved_object_id, result_status, message, created_at FROM scan_event');
        $this->createView('inventory_movements', "SELECT id, movement_no, business_key, CASE movement_type WHEN 'RECEIPT_IN' THEN 'RECEIPT' WHEN 'ISSUE_OUT' THEN 'ISSUE' WHEN 'RECEIPT_REVERSED' THEN 'REVERSAL' WHEN 'ISSUE_REVERSED' THEN 'REVERSAL' ELSE movement_type END AS movement_type, warehouse_id, product_id AS sku_id, stock_status, qty_delta AS quantity, stock_document_id AS source_document_id, stock_document_line_id AS source_line_id, original_movement_id, created_by, occurred_at, created_at FROM stock_movements");
        $this->createView('posting_events', 'SELECT posting_event_id AS id, operation, idempotency_key, request_hash, status, document_id, movement_id, response_snapshot, created_by, created_at FROM posting_event');
        $this->createView('reconciliation_incidents', 'SELECT incident_id AS id, incident_no, divergence_type, warehouse_id, product_id AS sku_id, detected_value, status, resolution_ref, reason, created_at, resolved_at, resolved_by FROM reconciliation_incident');
        $this->createView('reversal_documents', 'SELECT reversal_document_id AS id, document_no AS reversal_no, stock_document_id AS original_document_id, status, reason, posted_at, posted_by, created_at, created_by, updated_at, NULL AS updated_by, version FROM reversal_document');
        $this->createView('reversal_lines', 'SELECT reversal_line_id AS id, reversal_document_id, original_movement_id, reversal_qty AS quantity, reason, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM reversal_line');
        $this->createView('inbound_documents', "SELECT id, doc_no AS inbound_no, dst_warehouse_id AS warehouse_id, NULL AS source_name, doc_date AS document_date, UPPER(status) AS status, note, posted_at, posted_by, cancelled_at, cancelled_by, cancel_reason, created_at, created_by, updated_at, updated_by, 0 AS version FROM stock_documents WHERE doc_type = 'receipt'");
        $this->createView('inbound_lines', 'SELECT id, stock_document_id AS inbound_document_id, line_no, product_id AS sku_id, NULL AS import_batch_id, CAST(qty_planned AS INTEGER) AS expected_qty, CAST(scanned_qty AS INTEGER) AS scanned_qty, NULL AS note, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM stock_document_lines');
        $this->createView('outbound_documents', "SELECT id, doc_no AS outbound_no, src_warehouse_id AS warehouse_id, NULL AS outbound_request_id, NULL AS agency_id, '' AS recipient_name, '' AS recipient_address, doc_date AS document_date, UPPER(status) AS status, posted_at, posted_by, cancelled_at, cancel_reason, note, created_at, created_by, updated_at, updated_by, 0 AS version FROM stock_documents WHERE doc_type = 'issue'");
        $this->createView('outbound_lines', 'SELECT id, stock_document_id AS outbound_document_id, line_no, NULL AS outbound_request_line_id, product_id AS sku_id, CAST(qty_planned AS INTEGER) AS required_qty, CAST(scanned_qty AS INTEGER) AS scanned_qty, NULL AS note, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM stock_document_lines');
        $this->createView('warranty_cases', 'SELECT warranty_case_id AS id, warranty_case_code, inventory_serial_id AS item_id, product_id AS sku_id, temporary_item_code, identification_status, missing_code_reason, manual_product_description, defect_id, customer_name, customer_phone, status, condition_status, description, created_at, created_by, updated_at, NULL AS updated_by, version, closed_at, retention_until, legal_hold_flag FROM warranty_case');
        $this->createView('warranty_events', 'SELECT warranty_event_id AS id, warranty_case_id, event_type, from_status, to_status, note, created_by, event_at AS created_at FROM warranty_event');
        $this->createView('pv_product_profiles', 'SELECT profile_id AS id, product_id AS sku_id, display_name, description, published AS is_published, published_at, last_inventory_sync_at, sort_order, created_at, NULL AS created_by, updated_at, NULL AS updated_by, version FROM pv_product_profile');
        $this->createView('pv_industries', 'SELECT industry_id AS id, industry_code, industry_name, status, sort_order, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM pv_industry');
        $this->createView('pv_tags', 'SELECT tag_id AS id, tag_code, tag_name, status, sort_order, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM pv_filter_tag');
        $this->createView('pv_product_industries', 'SELECT profile_id, industry_id, priority, created_at, NULL AS created_by FROM pv_product_industry');
        $this->createView('pv_product_tags', 'SELECT profile_id, tag_id, created_at, NULL AS created_by FROM pv_product_tag');
        $this->createView('pv_recommendation_rules', 'SELECT rule_id AS id, industry_id, product_id AS sku_id, category_id, tag_id, priority, status, NULL AS effective_from, NULL AS effective_to, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM pv_recommendation_rule');
    }

    private function createView(string $name, string $selectSql): void
    {
        if ($this->tableExists($name)) {
            return;
        }

        $this->dropView($name);
        DB::statement("CREATE VIEW {$name} AS {$selectSql}");
    }

    private function dropView(string $name): void
    {
        DB::statement("DROP VIEW IF EXISTS {$name}");
    }

    private function tableExists(string $name): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            $relation = DB::selectOne(<<<'SQL'
                SELECT c.relkind
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema()
                  AND c.relname = ?
                LIMIT 1
            SQL, [$name]);

            return $relation !== null && in_array($relation->relkind, ['r', 'p'], true);
        }

        if (DB::getDriverName() === 'sqlite') {
            $relation = DB::selectOne(
                "SELECT type FROM sqlite_master WHERE name = ? AND type IN ('table', 'view') LIMIT 1",
                [$name]
            );

            return $relation !== null && $relation->type === 'table';
        }

        return Schema::hasTable($name);
    }

    private function masterColumns(Blueprint $table, string $codeColumn, string $nameColumn): void
    {
        $this->uuidPrimary($table);
        $table->string($codeColumn, 80)->unique();
        $table->string($nameColumn, 200);
        $table->string('status', 20)->default('ACTIVE');
        $table->integer('sort_order')->default(0);
        $this->standardAudit($table);
        $table->index(['status', 'sort_order']);
    }

    private function uuidPrimary(Blueprint $table): void
    {
        $table->uuid('id')->primary();
    }

    private function standardAudit(Blueprint $table): void
    {
        $table->timestamp('created_at')->useCurrent();
        $table->uuid('created_by')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->integer('version')->default(0);
    }
};
