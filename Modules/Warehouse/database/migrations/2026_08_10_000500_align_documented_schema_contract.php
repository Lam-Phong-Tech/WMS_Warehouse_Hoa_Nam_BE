<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->alignPackageVariants();
        $this->alignCompatibleAccessories();
        $this->alignIntegerQuantitiesOnPostgres();
    }

    public function down(): void
    {
        // Additive canonical compatibility columns are intentionally retained on rollback.
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
    }
};
