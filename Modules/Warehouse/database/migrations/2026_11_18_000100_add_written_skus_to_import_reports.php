<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_reports', function (Blueprint $table): void {
            // NULL means legacy linkage was never recorded, not an empty import.
            $table->json('written_skus')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_reports', fn (Blueprint $table) => $table->dropColumn('written_skus'));
    }
};
