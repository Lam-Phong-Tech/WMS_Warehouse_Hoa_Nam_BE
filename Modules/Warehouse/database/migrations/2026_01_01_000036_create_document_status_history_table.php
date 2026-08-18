<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only — mọi chuyển trạng thái chứng từ đều ghi 1 dòng, không update/xoá. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_document_id')->constrained('stock_documents')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('action', 40); // create | post | cancel
            $table->foreignUuid('changed_by')->constrained('app_user', 'user_id')->restrictOnDelete();
            $table->string('note', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['stock_document_id'], 'idx_dsh_document');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('document_status_history');
    }
};
