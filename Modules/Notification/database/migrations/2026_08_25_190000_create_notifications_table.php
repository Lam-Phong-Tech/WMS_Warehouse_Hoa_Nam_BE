<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // vd 'inbound.completed', 'outbound.posted', 'warranty.status_changed'.
            $table->string('event_key', 100)->index();
            $table->string('title', 255);
            $table->text('body');
            $table->string('severity', 20)->default('info'); // info|warning|critical
            $table->string('source_module', 60);
            // Đối tượng nghiệp vụ liên quan (StockDocument, WarrantyCase, User, ...).
            $table->string('subject_type', 100)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestampTz('created_at');

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('notifications');
    }
};
