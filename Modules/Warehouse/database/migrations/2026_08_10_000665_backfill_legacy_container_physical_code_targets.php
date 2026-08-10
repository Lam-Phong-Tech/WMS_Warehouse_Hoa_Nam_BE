<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('physical_codes')
            || ! Schema::hasTable('containers')
            || ! Schema::hasColumn('physical_codes', 'item_id')
            || ! Schema::hasColumn('physical_codes', 'container_id')
            || ! Schema::hasColumn('containers', 'physical_code_id')) {
            return;
        }

        DB::table('containers')
            ->whereNotNull('physical_code_id')
            ->orderBy('container_id')
            ->each(function (object $container): void {
                $updates = [
                    'container_id' => $container->container_id,
                ];

                if (Schema::hasColumn('physical_codes', 'assigned_object_type')) {
                    $updates['assigned_object_type'] = 'CONTAINER';
                }
                if (Schema::hasColumn('physical_codes', 'assigned_object_id')) {
                    $updates['assigned_object_id'] = $container->container_id;
                }
                if (Schema::hasColumn('physical_codes', 'assigned_at')) {
                    $updates['assigned_at'] = DB::raw('COALESCE(assigned_at, created_at)');
                }

                DB::table('physical_codes')
                    ->where('physical_code_id', $container->physical_code_id)
                    ->whereNull('item_id')
                    ->whereNull('container_id')
                    ->update($updates);
            });

        $unresolved = DB::table('physical_codes')
            ->whereNull('item_id')
            ->whereNull('container_id')
            ->count();

        if ($unresolved > 0) {
            throw new RuntimeException("Cannot enforce physical_codes target XOR: {$unresolved} legacy physical code row(s) still have no item/container target.");
        }
    }

    public function down(): void
    {
        // Fail-forward data repair. Canonical target ownership must not be removed on rollback.
    }
};
