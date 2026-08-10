<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->copyLegacyMastersWithStableIds();
        $this->addSkuCanonicalMasterColumns();
        $this->backfillSkuCanonicalMasterColumns();
        $this->repointSkuForeignKeys();
        $this->refreshSkuView();
    }

    public function down(): void
    {
        // Current ERD canonical master relationships are intentionally retained.
    }

    private function copyLegacyMastersWithStableIds(): void
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
            DB::table('product_masters')
                ->where('master_type', $type)
                ->orderBy('master_id')
                ->each(function (object $legacy) use ($table, $codeColumn, $nameColumn): void {
                    if (DB::table($table)->where('id', $legacy->master_id)->exists()) {
                        return;
                    }
                    if (DB::table($table)->where($codeColumn, $legacy->code)->exists()) {
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
                    foreach (['created_by', 'updated_by', 'version', 'description'] as $column) {
                        if (Schema::hasColumn($table, $column)) {
                            $row[$column] = $column === 'version' ? 0 : null;
                        }
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
                });
        }

        if (Schema::hasTable('product_models')) {
            DB::table('product_masters')
                ->where('master_type', 'MODEL')
                ->orderBy('master_id')
                ->each(function (object $legacy): void {
                    if (DB::table('product_models')->where('id', $legacy->master_id)->exists()
                        || DB::table('product_models')->where('model_code', $legacy->code)->exists()) {
                        return;
                    }
                    DB::table('product_models')->insert([
                        'id' => $legacy->master_id,
                        'model_code' => $legacy->code,
                        'model_name' => $legacy->name,
                        'brand_id' => $legacy->parent_master_id,
                        'status' => $legacy->status,
                        'sort_order' => 0,
                        'description' => null,
                        'created_at' => $legacy->created_at ?? now(),
                        'created_by' => null,
                        'updated_at' => $legacy->updated_at ?? now(),
                        'updated_by' => null,
                        'version' => 0,
                    ]);
                });
        }
    }

    private function addSkuCanonicalMasterColumns(): void
    {
        if (! Schema::hasTable('sku')) {
            return;
        }

        Schema::table('sku', function (Blueprint $table): void {
            if (! Schema::hasColumn('sku', 'power_source_id')) {
                $table->uuid('power_source_id')->nullable();
            }
            if (! Schema::hasColumn('sku', 'package_variant_id')) {
                $table->uuid('package_variant_id')->nullable();
            }
            if (! Schema::hasColumn('sku', 'version')) {
                $table->unsignedInteger('version')->default(1);
            }
        });
    }

    private function backfillSkuCanonicalMasterColumns(): void
    {
        if (! Schema::hasTable('sku')) {
            return;
        }

        DB::table('sku')->orderBy('sku_id')->each(function (object $sku): void {
            $updates = [];
            if ($sku->power_source_id === null && ! empty($sku->power_or_voltage) && Schema::hasTable('power_sources')) {
                $powerId = DB::table('power_sources')
                    ->where('power_source_code', $sku->power_or_voltage)
                    ->orWhere('power_source_name', $sku->power_or_voltage)
                    ->value('id');
                if ($powerId !== null) {
                    $updates['power_source_id'] = $powerId;
                }
            }
            if ($sku->package_variant_id === null && ! empty($sku->package_variant) && Schema::hasTable('package_variants')) {
                $query = DB::table('package_variants');
                if (Schema::hasColumn('package_variants', 'package_code')) {
                    $query->where('package_code', $sku->package_variant);
                } else {
                    $query->where('variant_code', $sku->package_variant);
                }
                $packageId = $query->value('id');
                if ($packageId !== null) {
                    $updates['package_variant_id'] = $packageId;
                }
            }
            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('sku')->where('sku_id', $sku->sku_id)->update($updates);
            }
        });
    }

    private function repointSkuForeignKeys(): void
    {
        if (! Schema::hasTable('sku') || DB::getDriverName() === 'sqlite') {
            return;
        }

        $targets = [
            'brand_id' => ['brands', 'id', 'fk_sku_brand_canonical'],
            'category_id' => ['product_categories', 'id', 'fk_sku_category_canonical'],
            'model_id' => ['product_models', 'id', 'fk_sku_model_canonical'],
            'power_source_id' => ['power_sources', 'id', 'fk_sku_power_source_canonical'],
            'package_variant_id' => ['package_variants', 'id', 'fk_sku_package_variant_canonical'],
        ];

        foreach ($targets as $column => [$parent, $parentColumn, $name]) {
            if (! Schema::hasTable($parent) || ! Schema::hasColumn('sku', $column)) {
                continue;
            }
            $this->assertNoOrphans('sku', $column, $parent, $parentColumn);
            $this->dropForeignKeysForColumn('sku', $column);
            if (! $this->foreignKeyExists('sku', $name)) {
                Schema::table('sku', function (Blueprint $table) use ($column, $parent, $parentColumn, $name): void {
                    $table->foreign($column, $name)->references($parentColumn)->on($parent)->nullOnDelete();
                });
            }
        }
    }

    private function refreshSkuView(): void
    {
        if (! Schema::hasTable('sku')) {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS skus');
        DB::statement(<<<'SQL'
            CREATE VIEW skus AS
            SELECT sku_id AS id,
                   sku_code,
                   sku_name,
                   sku_type,
                   CASE WHEN is_serial_tracked THEN 'SERIAL' ELSE 'QUANTITY' END AS tracking_mode,
                   unit AS unit_code,
                   brand_id,
                   category_id,
                   model_id,
                   power_source_id,
                   package_variant_id,
                   specification AS specifications,
                   status,
                   created_at,
                   NULL AS created_by,
                   updated_at,
                   NULL AS updated_by,
                   version
            FROM sku
        SQL);
    }

    private function assertNoOrphans(string $table, string $column, string $parent, string $parentColumn): void
    {
        $exists = DB::table("{$table} as c")
            ->leftJoin("{$parent} as p", "p.{$parentColumn}", '=', "c.{$column}")
            ->whereNotNull("c.{$column}")
            ->whereNull("p.{$parentColumn}")
            ->exists();
        if ($exists) {
            throw new \RuntimeException("Cannot repoint {$table}.{$column}; legacy value has no canonical master row.");
        }
    }

    private function dropForeignKeysForColumn(string $table, string $column): void
    {
        try {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                if (($foreign['columns'] ?? []) === [$column] && isset($foreign['name'])) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreign['name']));
                }
            }
        } catch (\Throwable) {
            // No introspectable FK on this driver/version.
        }
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        try {
            return collect(Schema::getForeignKeys($table))->contains(fn (array $foreign): bool => ($foreign['name'] ?? null) === $name);
        } catch (\Throwable) {
            return false;
        }
    }
};
