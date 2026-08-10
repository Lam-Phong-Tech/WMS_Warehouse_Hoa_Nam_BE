<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->alignMasterDescriptions();
        $this->alignPackageVariants();
        $this->alignCompatibleAccessories();
        $this->alignIntegerQuantitiesOnPostgres();
        $this->refreshInventoryMovementView();
    }

    public function down(): void
    {
        // Additive canonical compatibility columns are intentionally retained on rollback.
    }

    private function alignMasterDescriptions(): void
    {
        foreach (['product_categories', 'brands', 'power_sources', 'product_models', 'package_variants'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'description')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table): void {
                $table->text('description')->nullable();
            });
        }
    }

    private function alignPackageVariants(): void
    {
        if (! Schema::hasTable('package_variants')) {
            return;
        }

        $addCode = ! Schema::hasColumn('package_variants', 'package_code');
        $addName = ! Schema::hasColumn('package_variants', 'package_name');
        if ($addCode || $addName) {
            Schema::table('package_variants', function (Blueprint $table) use ($addCode, $addName): void {
                if ($addCode) {
                    $table->string('package_code', 80)->nullable();
                }
                if ($addName) {
                    $table->string('package_name', 200)->nullable();
                }
            });
        }

        if (Schema::hasColumn('package_variants', 'variant_code')) {
            DB::table('package_variants')->whereNull('package_code')->update(['package_code' => DB::raw('variant_code')]);
        }
        if (Schema::hasColumn('package_variants', 'variant_name')) {
            DB::table('package_variants')->whereNull('package_name')->update(['package_name' => DB::raw('variant_name')]);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION sync_package_variant_canonical_columns()
                RETURNS trigger AS $$
                BEGIN
                    NEW.package_code := COALESCE(NEW.package_code, NEW.variant_code);
                    NEW.package_name := COALESCE(NEW.package_name, NEW.variant_name);
                    NEW.variant_code := COALESCE(NEW.variant_code, NEW.package_code);
                    NEW.variant_name := COALESCE(NEW.variant_name, NEW.package_name);
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS trg_package_variant_canonical_columns ON package_variants;
                CREATE TRIGGER trg_package_variant_canonical_columns
                BEFORE INSERT OR UPDATE ON package_variants
                FOR EACH ROW EXECUTE FUNCTION sync_package_variant_canonical_columns();
            SQL);
            DB::statement('ALTER TABLE package_variants ALTER COLUMN package_code SET NOT NULL');
            DB::statement('ALTER TABLE package_variants ALTER COLUMN package_name SET NOT NULL');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uk_package_variants_package_code ON package_variants(package_code)');
        }
    }

    private function alignCompatibleAccessories(): void
    {
        if (! Schema::hasTable('sku_compatible_accessories')) {
            return;
        }

        $addNote = ! Schema::hasColumn('sku_compatible_accessories', 'note');
        $addStatus = ! Schema::hasColumn('sku_compatible_accessories', 'status');
        if ($addNote || $addStatus) {
            Schema::table('sku_compatible_accessories', function (Blueprint $table) use ($addNote, $addStatus): void {
                if ($addNote) {
                    $table->text('note')->nullable();
                }
                if ($addStatus) {
                    $table->string('status', 20)->default('ACTIVE');
                }
            });
        }
    }

    private function alignIntegerQuantitiesOnPostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([['inventory_balances', 'quantity'], ['stock_movements', 'quantity']] as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            if (DB::table($table)->whereRaw("{$column} <> trunc({$column})")->exists()) {
                throw new RuntimeException("Cannot align {$table}.{$column} to BIGINT: fractional values exist.");
            }
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE BIGINT USING {$column}::bigint");
        }

        if (Schema::hasTable('stock_movements') && Schema::hasColumn('stock_movements', 'qty_delta')) {
            if (DB::table('stock_movements')->whereRaw('qty_delta <> trunc(qty_delta)')->exists()) {
                throw new RuntimeException('Cannot expose inventory_movements.quantity as BIGINT: fractional qty_delta values exist.');
            }
        }
    }

    private function refreshInventoryMovementView(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP VIEW IF EXISTS inventory_movements');
            DB::statement(<<<'SQL'
                CREATE VIEW inventory_movements AS
                SELECT id,
                       movement_no,
                       business_key,
                       CASE movement_type
                           WHEN 'RECEIPT_IN' THEN 'RECEIPT'
                           WHEN 'ISSUE_OUT' THEN 'ISSUE'
                           WHEN 'RECEIPT_REVERSED' THEN 'REVERSAL'
                           WHEN 'ISSUE_REVERSED' THEN 'REVERSAL'
                           ELSE movement_type
                       END AS movement_type,
                       warehouse_id,
                       product_id AS sku_id,
                       stock_status,
                       CAST(qty_delta AS BIGINT) AS quantity,
                       stock_document_id AS source_document_id,
                       stock_document_line_id AS source_line_id,
                       original_movement_id,
                       created_by,
                       occurred_at,
                       created_at
                FROM stock_movements
            SQL);
        }
    }
};
