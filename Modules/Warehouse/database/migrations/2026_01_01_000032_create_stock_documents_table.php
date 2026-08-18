<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stock_documents = chứng từ nhập (receipt) / xuất (issue). Kho Hoà Nam chỉ vận hành 1 kho
 * tổng nên bỏ hẳn doc_type điều chuyển (transfer) và toàn bộ in_transit_ledger đi kèm.
 *
 * State machine: draft → pending → approved → (post-receipt|post-issue: received|issued)
 *                                            → (scan nhiều lần: partially_posted → received|issued khi đủ)
 *                                            → (close-remainder: closed, phần còn thiếu = shortage)
 *                → (reverse từ received|issued|closed: reversed)
 * cancelled chỉ từ draft/pending/approved; rejected chỉ từ pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('doc_type', 20); // receipt | issue
            $table->string('doc_no', 80)->unique();
            $table->date('doc_date');
            $table->string('status', 30)->default('draft');
            // receipt: chỉ dst_warehouse_id; issue: chỉ src_warehouse_id.
            $table->foreignUuid('src_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('dst_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->string('note', 1000)->nullable();

            $table->string('create_idempotency_key', 100)->nullable();

            $table->foreignUuid('submitted_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('posted_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('reversed_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reverse_reason', 1000)->nullable();
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
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('stock_documents');
    }
};
