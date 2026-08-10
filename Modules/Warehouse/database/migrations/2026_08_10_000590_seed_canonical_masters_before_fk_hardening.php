<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_masters')) {
            return;
        }

        $maps = [
            'BRAND' => ['brands', 'brand_code', 'brand_name'],
            'CATEGORY' => ['product_categories', 'category_code', 'category_name'],
            'POWER' => ['power_sources', 'power_source_code', 'power_source_name'],
            'PACKAGE' => ['package_variants', 'package_code', 'package_name'],
        ];

        foreach ($maps as $type => [$table, $codeColumn, $nameColumn]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::table('product_masters')->where('master_type', $type)->orderBy('master_id')->each(
                function (object $legacy) use ($table, $codeColumn, $nameColumn): void {
                    if (DB::table($table)->where('id', $legacy->master_id)->exists()
                        || DB::table($table)->where($codeColumn, $legacy->code)->exists()) {
                        return;
                    }
                    $row = [
                        'id' => $legacy->master_id,
                        $codeColumn => $legacy->code,
                        $nameColumn => $legacy->name,
                        'status' => $legacy->status,
                        'sort_order' => 0,
                        'created_at' => $legacy->created_at ?? now(),
                        'updated_at' => $legacy->updated_at ?? now(),
                    ];
                    if (Schema::hasColumn($table, 'description')) {
                        $row['description'] = null;
                    }
                    if (Schema::hasColumn($table, 'created_by')) {
                        $row['created_by'] = null;
                    }
                    if (Schema::hasColumn($table, 'updated_by')) {
                        $row['updated_by'] = null;
                    }
                    if (Schema::hasColumn($table, 'version')) {
                        $row['version'] = 0;
                    }
                    if ($table === 'package_variants') {
                        if (Schema::hasColumn($table, 'variant_code')) {
                            $row['variant_code'] = $legacy->code;
                        }
                        if (Schema::hasColumn($table, 'variant_name')) {
                            $row['variant_name'] = $legacy->name;
                        }
                    }
                    DB::table($table)->insert($row);
                }
            );
        }

        if (Schema::hasTable('product_models')) {
            DB::table('product_masters')->where('master_type', 'MODEL')->orderBy('master_id')->each(
                function (object $legacy): void {
                    if (DB::table('product_models')->where('id', $legacy->master_id)->exists()
                        || DB::table('product_models')->where('model_code', $legacy->code)->exists()) {
                        return;
                    }
                    $row = [
                        'id' => $legacy->master_id,
                        'model_code' => $legacy->code,
                        'model_name' => $legacy->name,
                        'brand_id' => $legacy->parent_master_id,
                        'status' => $legacy->status,
                        'sort_order' => 0,
                        'created_at' => $legacy->created_at ?? now(),
                        'updated_at' => $legacy->updated_at ?? now(),
                    ];
                    foreach (['description', 'created_by', 'updated_by', 'version'] as $column) {
                        if (Schema::hasColumn('product_models', $column)) {
                            $row[$column] = $column === 'version' ? 0 : null;
                        }
                    }
                    DB::table('product_models')->insert($row);
                }
            );
        }
    }

    public function down(): void
    {
        // Data migration only; canonical rows are retained.
    }
};
