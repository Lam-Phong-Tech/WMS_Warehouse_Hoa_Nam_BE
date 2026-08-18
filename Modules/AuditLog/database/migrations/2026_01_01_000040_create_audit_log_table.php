<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only theo docs/HoaNam_ERD_v1.0.md §5.7 — "Audit append-only cho action nhạy cảm
 * và thay đổi dữ liệu. Không ghi raw PII không cần thiết vào application log."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->string('action', 100); // CREATE | UPDATE | DELETE | POST | CANCEL | LOGIN...
            $table->string('object_type', 100); // vd stock_document, warehouse, product — không FK cứng (polymorphic)
            $table->string('object_id', 40)->nullable();
            $table->text('reason')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['object_type', 'object_id'], 'idx_audit_log_object');
            $table->index('actor_id', 'idx_audit_log_actor');
            $table->index('created_at', 'idx_audit_log_created_at');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('audit_log');
    }
};
