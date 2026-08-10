<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'warehouses',
            'agency',
            'defect_catalog',
            'product_master',
            'import_batches',
            'item_instances',
            'app_user',
            'role',
        ] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'version')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedInteger('version')->default(1);
            });
        }
    }

    public function down(): void
    {
        // Optimistic-lock version is a current-baseline invariant and is retained.
    }
};
