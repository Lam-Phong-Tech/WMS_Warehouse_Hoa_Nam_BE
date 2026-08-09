<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createMasterExtensions();
        $this->createCodeAndContainerExtensions();
        $this->createStocktakeAndReversalExtensions();
        $this->createWarrantyExtensions();
        $this->createPvExtensions();
        $this->createGovernanceExtensions();
    }

    public function down(): void
    {
        foreach ([
            'reconciliation_incident',
            'migration_batch',
            'pv_view_event',
            'pv_recommendation_rule',
            'pv_product_tag',
            'pv_product_industry',
            'pv_filter_tag',
            'pv_industry',
            'pv_product_profile',
            'warranty_event',
            'warranty_case',
            'reversal_line',
            'reversal_document',
            'stocktake_variance',
            'stocktake_count',
            'stocktake_snapshot',
            'stocktake_session',
            'putaway_task_line',
            'putaway_task',
            'posting_event',
            'scan_event',
            'scan_batch',
            'container_item',
            'container',
            'physical_code',
            'defect_catalog',
            'sku_alias',
            'product_master',
            'agency',
            'import_batch',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createMasterExtensions(): void
    {
        $skuTable = $this->skuTable();
        $skuKey = $this->skuKey();

        if (! Schema::hasTable('agency')) {
            Schema::create('agency', function (Blueprint $table) {
                $table->uuid('agency_id')->primary();
                $table->string('agency_code', 50)->unique();
                $table->string('agency_name', 200);
                $table->string('contact_name', 200)->nullable();
                $table->string('contact_phone', 50)->nullable();
                $table->string('address', 500)->nullable();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('product_master')) {
            Schema::create('product_master', function (Blueprint $table) {
                $table->uuid('master_id')->primary();
                $table->string('master_type', 30)->index();
                $table->string('master_code', 80);
                $table->string('master_name', 200);
                $table->uuid('parent_id')->nullable()->index();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->timestamps();
                $table->unique(['master_type', 'master_code'], 'uk_product_master_type_code');
            });
        }

        // Keep product_id column name for API compatibility while targeting the active SKU table.
        if (! Schema::hasTable('sku_alias')) {
            Schema::create('sku_alias', function (Blueprint $table) use ($skuTable, $skuKey) {
                $table->uuid('alias_id')->primary();
                $table->foreignUuid('product_id')->constrained($skuTable, $skuKey)->cascadeOnDelete();
                $table->string('alias_code', 100)->unique();
                $table->string('alias_type', 30)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('defect_catalog')) {
            Schema::create('defect_catalog', function (Blueprint $table) {
                $table->uuid('defect_id')->primary();
                $table->string('defect_code', 80)->unique();
                $table->string('defect_name', 200);
                $table->string('severity', 30)->nullable();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('import_batch')) {
            Schema::create('import_batch', function (Blueprint $table) {
                $table->uuid('import_batch_id')->primary();
                $table->string('batch_no', 80)->unique();
                $table->string('import_period', 80)->nullable();
                $table->string('status', 30)->default('DRAFT')->index();
                $table->foreignUuid('created_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    private function createCodeAndContainerExtensions(): void
    {
        $skuTable = $this->skuTable();
        $skuKey = $this->skuKey();

        if (! Schema::hasTable('physical_code')) {
            Schema::create('physical_code', function (Blueprint $table) {
                $table->uuid('physical_code_id')->primary();
                $table->string('code_value', 150)->unique();
                $table->string('code_type', 30)->index();
                $table->string('carrier_type', 30)->nullable();
                $table->string('assigned_object_type', 50)->nullable()->index();
                $table->uuid('assigned_object_id')->nullable()->index();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('container')) {
            Schema::create('container', function (Blueprint $table) use ($skuTable, $skuKey) {
                $table->uuid('container_id')->primary();
                $table->foreignUuid('physical_code_id')->nullable()->constrained('physical_code', 'physical_code_id')->nullOnDelete();
                $table->string('container_code', 150)->unique();
                $table->string('container_type', 20)->index();
                $table->uuid('parent_container_id')->nullable()->index();
                $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->foreignUuid('primary_product_id')->nullable()->constrained($skuTable, $skuKey)->nullOnDelete();
                $table->string('zone_code', 80)->nullable()->index();
                $table->string('location_hint', 200)->nullable();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('container_item')) {
            Schema::create('container_item', function (Blueprint $table) use ($skuTable, $skuKey) {
                $table->uuid('container_item_id')->primary();
                $table->foreignUuid('container_id')->constrained('container', 'container_id')->cascadeOnDelete();
                $table->foreignUuid('inventory_serial_id')->nullable()->constrained('inventory_serials')->cascadeOnDelete();
                $table->foreignUuid('product_id')->nullable()->constrained($skuTable, $skuKey)->cascadeOnDelete();
                $table->decimal('quantity', 18, 3)->nullable();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->timestamps();
                $table->index(['container_id', 'product_id'], 'idx_container_item_product');
                $table->index(['inventory_serial_id', 'status'], 'idx_container_item_serial_status');
            });
        }

        if (! Schema::hasTable('scan_batch')) {
            Schema::create('scan_batch', function (Blueprint $table) {
                $table->uuid('scan_batch_id')->primary();
                $table->string('source_type', 50)->nullable();
                $table->uuid('source_id')->nullable();
                $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->foreignUuid('created_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['source_type', 'source_id'], 'idx_scan_batch_source');
            });
        }

        if (! Schema::hasTable('scan_event')) {
            Schema::create('scan_event', function (Blueprint $table) {
                $table->uuid('scan_event_id')->primary();
                $table->foreignUuid('scan_batch_id')->constrained('scan_batch', 'scan_batch_id')->cascadeOnDelete();
                $table->string('raw_code', 255);
                $table->foreignUuid('physical_code_id')->nullable()->constrained('physical_code', 'physical_code_id')->nullOnDelete();
                $table->string('resolved_object_type', 50)->nullable();
                $table->uuid('resolved_object_id')->nullable();
                $table->string('result_status', 30)->default('UNKNOWN')->index();
                $table->string('message', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['resolved_object_type', 'resolved_object_id'], 'idx_scan_event_resolved');
            });
        }

        if (! Schema::hasTable('posting_event')) {
            Schema::create('posting_event', function (Blueprint $table) {
                $table->uuid('posting_event_id')->primary();
                $table->string('operation', 160);
                $table->string('idempotency_key', 100);
                $table->string('request_hash', 128);
                $table->string('status', 30)->default('COMMITTED')->index();
                $table->uuid('document_id')->nullable()->index();
                $table->uuid('movement_id')->nullable()->index();
                $table->json('response_snapshot')->nullable();
                $table->foreignUuid('created_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['operation', 'idempotency_key'], 'uk_posting_event_operation_idem');
            });
        }

        if (! Schema::hasTable('putaway_task')) {
            Schema::create('putaway_task', function (Blueprint $table) {
                $table->uuid('putaway_task_id')->primary();
                $table->string('task_no', 80)->unique();
                $table->foreignUuid('stock_document_id')->nullable()->constrained('stock_documents')->nullOnDelete();
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->string('status', 30)->default('OPEN')->index();
                $table->foreignUuid('assigned_to')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
                $table->foreignUuid('created_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('putaway_task_line')) {
            Schema::create('putaway_task_line', function (Blueprint $table) use ($skuTable, $skuKey) {
                $table->uuid('putaway_task_line_id')->primary();
                $table->foreignUuid('putaway_task_id')->constrained('putaway_task', 'putaway_task_id')->cascadeOnDelete();
                $table->foreignUuid('container_id')->nullable()->constrained('container', 'container_id')->nullOnDelete();
                $table->foreignUuid('product_id')->nullable()->constrained($skuTable, $skuKey)->nullOnDelete();
                $table->decimal('quantity', 18, 3)->nullable();
                $table->foreignUuid('target_container_id')->nullable()->constrained('container', 'container_id')->nullOnDelete();
                $table->string('target_location_hint', 200)->nullable();
                $table->string('status', 30)->default('OPEN')->index();
                $table->timestamps();
            });
        }
    }

    private function createStocktakeAndReversalExtensions(): void
    {
        $skuTable = $this->skuTable();
        $skuKey = $this->skuKey();

        if (! Schema::hasTable('stocktake_session')) {
            Schema::create('stocktake_session', function (Blueprint $table) {
                $table->uuid('session_id')->primary();
                $table->string('session_no', 80)->unique();
                $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->string('status', 30)->default('DRAFT')->index();
                $table->timestamp('stocktake_started_at');
                $table->timestamp('count_cutoff_at')->nullable();
                $table->boolean('movement_warning_flag')->default(false);
                $table->timestamp('applied_at')->nullable();
                $table->foreignUuid('applied_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
                $table->foreignUuid('created_by')->constrained('app_user', 'user_id')->restrictOnDelete();
                $table->unsignedInteger('session_version')->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stocktake_snapshot')) {
            Schema::create('stocktake_snapshot', function (Blueprint $table) use ($skuTable, $skuKey) {
                $table->uuid('snapshot_id')->primary();
                $table->foreignUuid('session_id')->constrained('stocktake_session', 'session_id')->cascadeOnDelete();
                $table->foreignUuid('product_id')->constrained($skuTable, $skuKey)->restrictOnDelete();
                $table->string('stock_status', 30)->default('AVAILABLE');
                $table->decimal('snapshot_qty', 18, 3)->default(0);
                $table->timestamp('stocktake_started_at');
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['session_id', 'product_id', 'stock_status'], 'uk_stocktake_snapshot_session_product_status');
            });
        }

        if (! Schema::hasTable('stocktake_count')) {
            Schema::create('stocktake_count', function (Blueprint $table) use ($skuTable, $skuKey) {
                $table->uuid('count_id')->primary();
                $table->foreignUuid('session_id')->constrained('stocktake_session', 'session_id')->cascadeOnDelete();
                $table->foreignUuid('product_id')->nullable()->constrained($skuTable, $skuKey)->nullOnDelete();
                $table->foreignUuid('inventory_serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
                $table->foreignUuid('container_id')->nullable()->constrained('container', 'container_id')->nullOnDelete();
                $table->decimal('actual_qty', 18, 3)->default(0);
                $table->timestamp('count_cutoff_at')->nullable();
                $table->foreignUuid('scan_batch_id')->nullable()->constrained('scan_batch', 'scan_batch_id')->nullOnDelete();
                $table->foreignUuid('counted_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
                $table->timestamp('counted_at')->useCurrent();
                $table->index(['session_id', 'product_id'], 'idx_stocktake_count_session_product');
            });
        }

        if (! Schema::hasTable('stocktake_variance')) {
            Schema::create('stocktake_variance', function (Blueprint $table) use ($skuTable, $skuKey) {
                $table->uuid('variance_id')->primary();
                $table->foreignUuid('session_id')->constrained('stocktake_session', 'session_id')->cascadeOnDelete();
                $table->foreignUuid('product_id')->constrained($skuTable, $skuKey)->restrictOnDelete();
                $table->decimal('expected_qty', 18, 3)->default(0);
                $table->decimal('expected_at_count', 18, 3)->default(0);
                $table->decimal('actual_qty', 18, 3)->default(0);
                $table->decimal('variance_qty', 18, 3)->default(0);
                $table->json('acknowledged_movement_ids')->nullable();
                $table->string('decision', 30)->nullable()->index();
                $table->string('status', 30)->default('OPEN')->index();
                $table->foreignUuid('approved_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
                $table->foreignUuid('applied_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();
                $table->index(['session_id', 'product_id'], 'idx_stocktake_variance_session_product');
            });
        }

        if (! Schema::hasTable('reversal_document')) {
            Schema::create('reversal_document', function (Blueprint $table) {
                $table->uuid('reversal_document_id')->primary();
                $table->string('document_no', 80)->unique();
                $table->foreignUuid('stock_document_id')->nullable()->constrained('stock_documents')->nullOnDelete();
                $table->string('status', 30)->default('DRAFT')->index();
                $table->string('reason', 1000);
                $table->foreignUuid('created_by')->constrained('app_user', 'user_id')->restrictOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->foreignUuid('posted_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reversal_line')) {
            Schema::create('reversal_line', function (Blueprint $table) {
                $table->uuid('reversal_line_id')->primary();
                $table->foreignUuid('reversal_document_id')->constrained('reversal_document', 'reversal_document_id')->cascadeOnDelete();
                $table->foreignUuid('original_movement_id')->constrained('stock_movements')->restrictOnDelete();
                $table->decimal('reversal_qty', 18, 3)->default(0);
                $table->string('reason', 1000)->nullable();
                $table->timestamps();
            });
        }
    }

    private function createWarrantyExtensions(): void
    {
        $skuTable = $this->skuTable();
        $skuKey = $this->skuKey();

        if (! Schema::hasTable('warranty_case')) {
            Schema::create('warranty_case', function (Blueprint $table) use ($skuTable, $skuKey) {
                $table->uuid('warranty_case_id')->primary();
                $table->string('warranty_case_code', 80)->unique();
                $table->foreignUuid('inventory_serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
                $table->foreignUuid('product_id')->nullable()->constrained($skuTable, $skuKey)->nullOnDelete();
                $table->string('temporary_item_code', 150)->nullable();
                $table->string('identification_status', 30)->default('IDENTIFIED')->index();
                $table->string('missing_code_reason', 500)->nullable();
                $table->text('manual_product_description')->nullable();
                $table->foreignUuid('defect_id')->nullable()->constrained('defect_catalog', 'defect_id')->nullOnDelete();
                $table->string('customer_name', 200)->nullable();
                $table->string('customer_phone', 50)->nullable()->index();
                $table->string('status', 30)->default('RECEIVED')->index();
                $table->string('condition_status', 30)->nullable();
                $table->text('description')->nullable();
                $table->foreignUuid('created_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('retention_until')->nullable();
                $table->boolean('legal_hold_flag')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['inventory_serial_id', 'status'], 'idx_warranty_case_serial_status');
            });
        }

        if (! Schema::hasTable('warranty_event')) {
            Schema::create('warranty_event', function (Blueprint $table) {
                $table->uuid('warranty_event_id')->primary();
                $table->foreignUuid('warranty_case_id')->constrained('warranty_case', 'warranty_case_id')->cascadeOnDelete();
                $table->string('event_type', 50)->index();
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();
                $table->text('note')->nullable();
                $table->foreignUuid('created_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
                $table->timestamp('event_at')->useCurrent()->index();
            });
        }
    }

    private function createPvExtensions(): void
    {
        $skuTable = $this->skuTable();
        $skuKey = $this->skuKey();

        if (! Schema::hasTable('pv_product_profile')) {
            Schema::create('pv_product_profile', function (Blueprint $table) use ($skuTable, $skuKey) {
                $table->uuid('profile_id')->primary();
                $table->foreignUuid('product_id')->constrained($skuTable, $skuKey)->cascadeOnDelete();
                $table->string('display_name', 255);
                $table->text('description')->nullable();
                $table->json('image_urls')->nullable();
                $table->boolean('published')->default(false)->index();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('last_inventory_sync_at')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pv_industry')) {
            Schema::create('pv_industry', function (Blueprint $table) {
                $table->uuid('industry_id')->primary();
                $table->string('industry_code', 80)->unique();
                $table->string('industry_name', 200);
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pv_filter_tag')) {
            Schema::create('pv_filter_tag', function (Blueprint $table) {
                $table->uuid('tag_id')->primary();
                $table->string('tag_code', 80)->unique();
                $table->string('tag_name', 200);
                $table->string('tag_type', 50)->nullable()->index();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pv_product_industry')) {
            Schema::create('pv_product_industry', function (Blueprint $table) {
                $table->foreignUuid('profile_id')->constrained('pv_product_profile', 'profile_id')->cascadeOnDelete();
                $table->foreignUuid('industry_id')->constrained('pv_industry', 'industry_id')->cascadeOnDelete();
                $table->unsignedInteger('priority')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->primary(['profile_id', 'industry_id'], 'pk_pv_product_industry');
            });
        }

        if (! Schema::hasTable('pv_product_tag')) {
            Schema::create('pv_product_tag', function (Blueprint $table) {
                $table->foreignUuid('profile_id')->constrained('pv_product_profile', 'profile_id')->cascadeOnDelete();
                $table->foreignUuid('tag_id')->constrained('pv_filter_tag', 'tag_id')->cascadeOnDelete();
                $table->unsignedInteger('priority')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->primary(['profile_id', 'tag_id'], 'pk_pv_product_tag');
            });
        }

        if (! Schema::hasTable('pv_recommendation_rule')) {
            Schema::create('pv_recommendation_rule', function (Blueprint $table) use ($skuTable, $skuKey) {
                $table->uuid('rule_id')->primary();
                $table->foreignUuid('industry_id')->constrained('pv_industry', 'industry_id')->cascadeOnDelete();
                $table->uuid('category_id')->nullable()->index();
                $table->foreignUuid('tag_id')->nullable()->constrained('pv_filter_tag', 'tag_id')->nullOnDelete();
                $table->foreignUuid('product_id')->nullable()->constrained($skuTable, $skuKey)->nullOnDelete();
                $table->unsignedInteger('priority');
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->timestamps();
                $table->index(['industry_id', 'status', 'priority'], 'idx_pv_rule_industry_status_priority');
            });
        }

        if (! Schema::hasTable('pv_view_event')) {
            Schema::create('pv_view_event', function (Blueprint $table) use ($skuTable, $skuKey) {
                $table->uuid('view_event_id')->primary();
                $table->foreignUuid('product_id')->nullable()->constrained($skuTable, $skuKey)->nullOnDelete();
                $table->string('keyword', 255)->nullable()->index();
                $table->foreignUuid('industry_id')->nullable()->constrained('pv_industry', 'industry_id')->nullOnDelete();
                $table->string('event_type', 40)->index();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    private function createGovernanceExtensions(): void
    {
        $skuTable = $this->skuTable();
        $skuKey = $this->skuKey();

        if (! Schema::hasTable('migration_batch')) {
            Schema::create('migration_batch', function (Blueprint $table) {
                $table->uuid('migration_batch_id')->primary();
                $table->string('batch_code', 80)->unique();
                $table->string('source_file', 500);
                $table->string('checksum', 128)->index();
                $table->string('batch_status', 40)->default('DRAFT')->index();
                $table->unsignedInteger('valid_count')->default(0);
                $table->unsignedInteger('invalid_count')->default(0);
                $table->unsignedInteger('duplicate_count')->default(0);
                $table->string('reconciliation_status', 20)->default('PENDING')->index();
                $table->string('error_report_ref', 500)->nullable();
                $table->timestamp('committed_at')->nullable();
                $table->string('rollback_reference', 500)->nullable();
                $table->foreignUuid('created_by')->constrained('app_user', 'user_id')->restrictOnDelete();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('reconciliation_incident')) {
            Schema::create('reconciliation_incident', function (Blueprint $table) use ($skuTable, $skuKey) {
                $table->uuid('incident_id')->primary();
                $table->string('incident_no', 80)->unique();
                $table->string('divergence_type', 40)->index();
                $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->foreignUuid('product_id')->nullable()->constrained($skuTable, $skuKey)->nullOnDelete();
                $table->json('detected_value')->nullable();
                $table->string('status', 30)->default('OPEN')->index();
                $table->string('resolution_ref', 500)->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->timestamp('resolved_at')->nullable()->index();
                $table->foreignUuid('resolved_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            });
        }
    }

    private function skuTable(): string
    {
        return Schema::hasTable('sku') ? 'sku' : 'products';
    }

    private function skuKey(): string
    {
        return $this->skuTable() === 'sku' ? 'sku_id' : 'id';
    }
};
