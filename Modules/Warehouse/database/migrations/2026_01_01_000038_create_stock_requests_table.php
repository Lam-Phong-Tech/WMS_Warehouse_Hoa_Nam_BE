<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stock_requests = yêu cầu xin duyệt TRƯỚC KHI tạo chứng từ nghiệp vụ thật (stock_documents).
 * draft → pending → approved → converted (đã sinh stock_document) | rejected | cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('request_type', 20); // receipt | issue | transfer
            $table->string('request_no', 80)->unique();
            $table->date('request_date');
            $table->string('status', 20)->default('draft'); // draft|pending|approved|rejected|converted|cancelled
            $table->foreignUuid('src_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('dst_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->string('note', 1000)->nullable();

            $table->foreignUuid('submitted_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('reject_reason', 1000)->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('cancel_reason', 1000)->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('converted_doc_id')->nullable()->constrained('stock_documents')->nullOnDelete();

            $table->foreignUuid('created_by')->constrained('app_user', 'user_id')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['request_type', 'status'], 'idx_sr_type_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_requests');
    }
};
