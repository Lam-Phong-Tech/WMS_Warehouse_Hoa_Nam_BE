<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yêu cầu xuất/nhập/điều chuyển kho — bước tiền duyệt trước khi tạo stock_documents.
 * request_type=transfer cần src ≠ dst; base hiện chỉ có 1 kho tổng nên nhánh này
 * chưa dùng được thực tế cho tới khi Hoà Nam thêm kho thứ 2 (xem BRD_ERD_Upgrade_Guide.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('request_no', 80)->unique();
            $table->string('request_type', 30)->comment('receipt | issue | transfer');
            $table->date('request_date');
            $table->string('status', 20)->default('draft')->comment('draft|pending|approved|rejected|converted|cancelled');
            $table->foreignUuid('src_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('dst_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->string('note', 1000)->nullable();
            $table->foreignUuid('created_by')->constrained('app_user', 'user_id')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->foreignUuid('submitted_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('reject_reason', 1000)->nullable();
            $table->foreignUuid('converted_doc_id')->nullable()->constrained('stock_documents')->nullOnDelete();
            $table->string('cancel_reason', 1000)->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['request_type', 'status'], 'idx_sr_type_status');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('stock_requests');
    }
};
