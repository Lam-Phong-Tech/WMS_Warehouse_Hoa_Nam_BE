<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->string('suspension_reason', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropColumn('suspension_reason');
        });
    }
};
