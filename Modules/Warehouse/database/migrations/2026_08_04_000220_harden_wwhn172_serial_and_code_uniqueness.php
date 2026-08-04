<?php

use App\Support\Wwhn172MigrationInspector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aggregate_mutexes')) {
            Schema::create('aggregate_mutexes', function (Blueprint $table): void {
                $table->string('mutex_key', 191)->primary();
                $table->timestamps();
            });
        }

        $report = app(Wwhn172MigrationInspector::class)->inspect();
        if (($report['summary']['status'] ?? null) !== 'PASS') {
            throw new RuntimeException(
                'WWHN-172 migration preflight BLOCKED: '.json_encode(
                    $report['checks'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
        }

        if (Schema::hasTable('container_item')
            && Schema::hasColumn('container_item', 'inventory_serial_id')
            && ! $this->indexExists('container_item', 'uk_wwhn172_container_item_serial')) {
            Schema::table('container_item', function (Blueprint $table): void {
                $table->unique('inventory_serial_id', 'uk_wwhn172_container_item_serial');
            });
        }

        if (Schema::hasTable('container')
            && Schema::hasColumn('container', 'physical_code_id')
            && ! $this->indexExists('container', 'uk_wwhn172_container_physical_code')) {
            Schema::table('container', function (Blueprint $table): void {
                $table->unique('physical_code_id', 'uk_wwhn172_container_physical_code');
            });
        }

        $this->addPortableChecks();
    }

    public function down(): void
    {
        if (Schema::hasTable('container_item') && $this->indexExists('container_item', 'uk_wwhn172_container_item_serial')) {
            Schema::table('container_item', fn (Blueprint $table) => $table->dropUnique('uk_wwhn172_container_item_serial'));
        }

        if (Schema::hasTable('container') && $this->indexExists('container', 'uk_wwhn172_container_physical_code')) {
            Schema::table('container', fn (Blueprint $table) => $table->dropUnique('uk_wwhn172_container_physical_code'));
        }

        $this->dropPortableChecks();
        Schema::dropIfExists('aggregate_mutexes');
    }

    private function addPortableChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }

        $this->addConstraint(
            'sku_component',
            'chk_wwhn172_component_not_self_v2',
            'CHECK (parent_product_id <> component_product_id)'
        );
        $this->addConstraint(
            'sku_component',
            'chk_wwhn172_component_quantity_v2',
            'CHECK (quantity > 0)'
        );
        $this->addConstraint(
            'container_item',
            'chk_wwhn172_container_item_identity_v2',
            'CHECK (inventory_serial_id IS NOT NULL OR product_id IS NOT NULL)'
        );
        $this->addConstraint(
            'container_item',
            'chk_wwhn172_container_item_quantity_v2',
            'CHECK (quantity > 0)'
        );
    }

    private function dropPortableChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'mysql'], true)) {
            return;
        }

        foreach ([
            ['sku_component', 'chk_wwhn172_component_not_self_v2'],
            ['sku_component', 'chk_wwhn172_component_quantity_v2'],
            ['container_item', 'chk_wwhn172_container_item_identity_v2'],
            ['container_item', 'chk_wwhn172_container_item_quantity_v2'],
        ] as [$table, $constraint]) {
            if ($this->constraintExists($table, $constraint)) {
                $operation = DB::getDriverName() === 'mysql' ? 'DROP CHECK' : 'DROP CONSTRAINT';
                DB::statement(sprintf('ALTER TABLE %s %s %s', $table, $operation, $constraint));
            }
        }
    }

    private function addConstraint(string $table, string $name, string $definition): void
    {
        if (! Schema::hasTable($table) || $this->constraintExists($table, $name)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s %s', $table, $name, $definition));
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return match (DB::getDriverName()) {
            'pgsql' => DB::table('pg_constraint as pc')
                ->join('pg_class as c', 'c.oid', '=', 'pc.conrelid')
                ->where('c.relname', $table)
                ->where('pc.conname', $constraint)
                ->exists(),
            'mysql' => DB::table('information_schema.table_constraints')
                ->where('constraint_schema', DB::connection()->getDatabaseName())
                ->where('table_name', $table)
                ->where('constraint_name', $constraint)
                ->exists(),
            default => false,
        };
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $candidate): bool => ($candidate['name'] ?? null) === $index);
    }
};
