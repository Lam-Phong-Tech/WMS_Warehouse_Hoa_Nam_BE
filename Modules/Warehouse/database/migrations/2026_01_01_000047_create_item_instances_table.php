<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quản lý từng máy/từng linh kiện có serial (ERD §5.2 item_instance). Chỉ item_status=IN_STOCK
 * và condition đủ điều kiện mới tính available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_instances', function (Blueprint $table) {
            $table->uuid('item_id')->primary();
            $table->foreignUuid('sku_id')->constrained('sku', 'sku_id')->restrictOnDelete();
            $table->foreignUuid('physical_code_id')->nullable()->unique()->constrained('physical_codes', 'physical_code_id')->nullOnDelete();
            $table->string('serial_number', 100)->nullable()->unique();
            $table->foreignUuid('container_id')->nullable()->constrained('containers', 'container_id')->nullOnDelete();
            $table->string('item_status', 20)->default('NEW'); // NEW|IN_STOCK|ISSUED|IN_WARRANTY|RETURNED_TO_CUSTOMER|LOST|ADJUSTED_OUT
            $table->string('item_condition', 20)->default('NORMAL'); // NORMAL|DEMO|DEFECTIVE|WARRANTY_RETURN|NON_SELLABLE
            $table->foreignUuid('import_batch_id')->nullable()->constrained('import_batches', 'import_batch_id')->nullOnDelete();
            $table->integer('version')->default(1);
            $table->timestamps();

            $table->index('item_status', 'idx_item_instances_status');
            $table->index('item_condition', 'idx_item_instances_condition');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_instances');
    }
};
