<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_outbox', function (Blueprint $table) {
            $table->uuid('audit_outbox_id')->primary();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('available_at')->index();
            $table->timestampTz('processed_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('audit_outbox');
    }
};
