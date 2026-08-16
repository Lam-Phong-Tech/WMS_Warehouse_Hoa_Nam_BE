<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Database\Migrations\Concerns\DropsBaselineViewsAroundAlter;

return new class extends Migration
{
    use DropsBaselineViewsAroundAlter;

    public function up(): void
    {
        // After 2026_08_10_000791, PostgreSQL singular names such as
        // `pv_product_profile` and `warranty_case` are compatibility views.
        // Integrity constraints/indexes must be applied to the physical ERD
        // tables, never to those views. SQLite still uses the singular
        // physical baseline tables, so preserve the legacy path there.
        if (DB::getDriverName() === 'pgsql') {
            $duplicates = DB::table('pv_product_profiles')
                ->select('sku_id')
                ->groupBy('sku_id')
                ->havingRaw('count(*) > 1')
                ->count();

            if ($duplicates > 0) {
                throw new \RuntimeException("Cannot add PV profile uniqueness: {$duplicates} SKU(s) have multiple profiles.");
            }

            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uk_pv_product_profile_product ON pv_product_profiles (sku_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_warranty_case_product_status ON warranty_cases (sku_id, status)');

            // The canonical PostgreSQL warranty_cases.defect_id relationship is
            // already materialized against defect_catalogs by the ERD hardening
            // chain. The retired `defect_catalog` name is only a writable
            // compatibility view at this point, so no FK replacement is needed.
            return;
        }

        $this->copyLegacyDefectsToCatalog();
        $this->replaceWarrantyDefectForeignKey('defect_catalogs');

        $duplicates = DB::table('pv_product_profile')
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('count(*) > 1')
            ->count();

        if ($duplicates > 0) {
            throw new \RuntimeException("Cannot add PV profile uniqueness: {$duplicates} SKU(s) have multiple profiles.");
        }

        $this->alterTableAroundBaselineViews('pv_product_profile', function () {
            Schema::table('pv_product_profile', function (Blueprint $table) {
                $table->unique('product_id', 'uk_pv_product_profile_product');
            });
        });

        $this->alterTableAroundBaselineViews('warranty_case', function () {
            Schema::table('warranty_case', function (Blueprint $table) {
                $table->index(['product_id', 'status'], 'idx_warranty_case_product_status');
            });
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_warranty_case_product_status');
            DB::statement('DROP INDEX IF EXISTS uk_pv_product_profile_product');

            return;
        }

        $this->copyCatalogDefectsToLegacy();
        $this->replaceWarrantyDefectForeignKey('defect_catalog');

        $this->alterTableAroundBaselineViews('warranty_case', function () {
            Schema::table('warranty_case', function (Blueprint $table) {
                $table->dropIndex('idx_warranty_case_product_status');
            });
        });

        $this->alterTableAroundBaselineViews('pv_product_profile', function () {
            Schema::table('pv_product_profile', function (Blueprint $table) {
                $table->dropUnique('uk_pv_product_profile_product');
            });
        });
    }

    private function copyLegacyDefectsToCatalog(): void
    {
        if (! Schema::hasTable('defect_catalog') || ! Schema::hasTable('defect_catalogs')) {
            return;
        }

        DB::table('defect_catalog')->orderBy('defect_id')->each(function (object $defect): void {
            DB::table('defect_catalogs')->insertOrIgnore([
                'defect_id' => $defect->defect_id,
                'defect_code' => $defect->defect_code,
                'defect_name' => $defect->defect_name,
                'description' => null,
                'status' => $defect->status,
                'created_at' => $defect->created_at,
                'updated_at' => $defect->updated_at,
            ]);
        });
    }

    private function copyCatalogDefectsToLegacy(): void
    {
        if (! Schema::hasTable('defect_catalog') || ! Schema::hasTable('defect_catalogs')) {
            return;
        }

        DB::table('defect_catalogs')->orderBy('defect_id')->each(function (object $defect): void {
            DB::table('defect_catalog')->insertOrIgnore([
                'defect_id' => $defect->defect_id,
                'defect_code' => $defect->defect_code,
                'defect_name' => $defect->defect_name,
                'severity' => null,
                'status' => $defect->status,
                'created_at' => $defect->created_at,
                'updated_at' => $defect->updated_at,
            ]);
        });
    }

    private function replaceWarrantyDefectForeignKey(string $targetTable): void
    {
        if (! Schema::hasTable('warranty_case')) {
            return;
        }

        $this->alterTableAroundBaselineViews('warranty_case', function () use ($targetTable) {
            Schema::table('warranty_case', function (Blueprint $table) {
                $table->dropForeign(['defect_id']);
            });

            Schema::table('warranty_case', function (Blueprint $table) use ($targetTable) {
                $table->foreign('defect_id')
                    ->references('defect_id')
                    ->on($targetTable)
                    ->nullOnDelete();
            });
        });
    }
};
