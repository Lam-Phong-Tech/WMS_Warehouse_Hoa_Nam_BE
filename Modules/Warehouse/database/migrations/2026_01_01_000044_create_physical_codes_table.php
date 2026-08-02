<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nguồn định danh vật lý duy nhất cho item/container/mã tạm (ERD §5.2 physical_code).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_codes', function (Blueprint $table) {
            $table->uuid('physical_code_id')->primary();
            $table->string('code_value', 100)->unique();
            $table->string('code_type', 20); // ITEM|CARTON|COMPONENT_SERIAL|BOX|TRAY|TEMP_WARRANTY|UNKNOWN
            $table->string('carrier_type', 20); // QR|BARCODE|RFID|NFC|PLAIN_LABEL|MANUAL_CODE|TEMP_CODE
            $table->string('hardware_uid', 150)->nullable()->unique();
            $table->string('encoded_payload_version', 30)->nullable();
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE|LOST|REPLACED|INACTIVE
            $table->uuid('replaced_by_code_id')->nullable();
            $table->timestamps();

            $table->index('code_type', 'idx_physical_codes_code_type');
            $table->index('status', 'idx_physical_codes_status');
        });

        // FK tự tham chiếu thêm sau khi bảng đã tồn tại (Postgres cần PK đã commit trước).
        Schema::table('physical_codes', function (Blueprint $table) {
            $table->foreign('replaced_by_code_id')->references('physical_code_id')->on('physical_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_codes');
    }
};
