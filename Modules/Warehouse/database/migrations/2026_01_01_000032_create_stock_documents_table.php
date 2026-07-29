<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stock_documents = chứng từ nhập (receipt) / xuất (issue) / điều chuyển (transfer).
 *
 * Port đầy đủ state machine từ Modules/Warehouse của ERP-Backend (M2):
 * draft → pending → approved → received|issued (receipt/issue)
 *                             → in_transit → completed|completed_with_loss (transfer)
 *                                          → return_in_transit → returned
 * cancelled chỉ từ draft/pending/approved; rejected chỉ từ pending.
 * Khác ERP gốc: dùng UUID, bỏ multi-branch/company trên warehouses, bỏ supplier_id (chưa có
 * master data nhà cung cấp trong base), bỏ lot_no/expiry_date (chưa cần ở base này).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('doc_type', 20); // receipt | issue | transfer
            $table->string('doc_no', 80)->unique();
            $table->date('doc_date');
            $table->string('status', 30)->default('draft');
            // receipt: chỉ dst_warehouse_id; issue: chỉ src_warehouse_id; transfer: cả hai.
            $table->foreignUuid('src_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('dst_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->string('ref_doc_type', 50)->nullable(); // vd stock_request, khi được convert từ yêu cầu
            $table->uuid('ref_doc_id')->nullable();
            $table->string('note', 1000)->nullable();

            $table->string('create_idempotency_key', 100)->nullable();

            $table->foreignUuid('submitted_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('posted_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignUuid('dispatched_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignUuid('returned_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->string('return_reason', 1000)->nullable();
            $table->string('reject_reason', 1000)->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('cancel_reason', 1000)->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignUuid('created_by')->constrained('app_user', 'user_id')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['doc_type', 'create_idempotency_key'], 'uk_stock_documents_doc_type_create_idem');
            $table->index(['doc_type', 'status'], 'idx_sd_doc_type_status');
            $table->index('doc_date', 'idx_sd_doc_date');
            $table->index(['ref_doc_type', 'ref_doc_id'], 'idx_sd_ref_doc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_documents');
    }
};
