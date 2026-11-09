<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('warranty_daily_numbers')) {
            Schema::create('warranty_daily_numbers', function (Blueprint $table): void {
                $table->date('number_date')->primary();
                $table->unsignedBigInteger('last_value')->default(0);
            });
        }
        if (! Schema::hasTable('component_issue_daily_numbers')) {
            Schema::create('component_issue_daily_numbers', function (Blueprint $table): void {
                $table->date('number_date')->primary();
                $table->unsignedBigInteger('last_value')->default(0);
            });
        }
    }

    public function down(): void
    {
        // These counters produce externally visible document numbers. Dropping
        // them could reissue an already-used number; retain them on rollback.
        throw new RuntimeException('Warranty/component numbering must be preserved; use a reviewed, data-preserving administrative rollback.');
    }
};
