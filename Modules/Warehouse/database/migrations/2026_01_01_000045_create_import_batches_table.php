<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lô/đợt nhập khẩu nghiệp vụ (ERD §5.3 import_batch) — dùng cho traceability của item_instance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('import_batch_id')->primary();
            $table->string('import_batch_code', 80)->unique();
            $table->string('shipment_container_code', 100)->nullable();
            $table->string('supplier_batch_no', 100)->nullable();
            $table->string('import_period', 30)->nullable();
            $table->string('supplier_name', 200)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->index('shipment_container_code', 'idx_import_batches_shipment_code');
            $table->index('supplier_batch_no', 'idx_import_batches_supplier_batch_no');
            $table->index('import_period', 'idx_import_batches_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
