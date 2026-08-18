<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERD bảng 19 (import_batches) và 20 (item_instances) khai báo `migration_run_id`
 * (FK migration_runs.id, "Nguồn migration") nhưng schema mới chỉ có cột này ở
 * bảng 22 (containers). Thiếu cột làm REQ-SRS-034 ("trace được nguồn/migration_run")
 * không thực hiện được và làm rollback của lần nạp dữ liệu cuối không xác định
 * chính xác được tập bản ghi do run tạo ra.
 */
return new class extends Migration
{
    private const TARGETS = [
        'import_batches' => 'fk_import_batches_migration_run',
        'item_instances' => 'fk_item_instances_migration_run',
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $foreignKey) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'migration_run_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->uuid('migration_run_id')->nullable();
                });
            }

            $this->addIndex($table, ['migration_run_id'], "idx_{$table}_migration_run");
            $this->addForeignKey($table, $foreignKey);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TARGETS) as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'migration_run_id')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('migration_run_id'));
            }
        }
    }

    private function addForeignKey(string $table, string $name): void
    {
        if (DB::getDriverName() === 'sqlite' || ! Schema::hasTable('migration_runs') || $this->foreignKeyExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->foreign('migration_run_id', $name)->references('id')->on('migration_runs')->nullOnDelete();
        });
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->exists();
    }

    private function indexExists(string $table, string $name): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'index')->where('name', $name)->exists();
        }

        return DB::table('pg_indexes')->where('tablename', $table)->where('indexname', $name)->exists();
    }
};
