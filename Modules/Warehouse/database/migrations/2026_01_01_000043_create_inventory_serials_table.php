<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Theo dõi từng serial vật lý cho sản phẩm có is_serial_tracked=true. Base chỉ có schema +
 * CRUD tối thiểu — chưa wire vào StockLedger (nhập/xuất theo serial cụ thể); dev sau nâng
 * cấp theo docs/HoaNam_ERD_v1.0.md (item_instance/physical_code) khi cần.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_serials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('sku', 'sku_id')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('serial_no', 150)->unique();
            $table->string('physical_code_id', 36)->nullable()->index();
            $table->string('condition', 30)->default('GOOD');
            $table->string('stock_condition', 30)->default('AVAILABLE');
            $table->string('item_condition', 30)->default('NEW');
            $table->string('status', 20)->default('IN_STOCK'); // IN_STOCK | ISSUED | IN_TRANSIT
            $table->timestamps();
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('inventory_serials');
    }
};
