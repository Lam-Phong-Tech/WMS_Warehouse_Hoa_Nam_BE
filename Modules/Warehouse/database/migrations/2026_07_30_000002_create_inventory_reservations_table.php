<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đặt giữ tồn kho — chức năng non-MVP, bảng để sẵn, chưa wire vào luồng xuất kho
 * (giữ nguyên quyết định port ban đầu từ ERP-Backend, chỉ cung cấp endpoint read-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('sku', 'sku_id')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->string('source_type', 50)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('status', 20)->default('active')->comment('active | completed | cancelled | released');
            $table->timestamp('expires_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('app_user', 'user_id')->nullOnDelete();
            $table->timestamps();

            $table->index(['warehouse_id', 'product_id', 'status'], 'idx_ir_warehouse_product_status');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('inventory_reservations');
    }
};
