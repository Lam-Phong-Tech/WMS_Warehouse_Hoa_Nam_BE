<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đại lý/cửa hàng con — chỉ dùng để tham chiếu/gán (vd bên nhận trên outbound_document sau
 * này), KHÔNG được phát sinh inventory_balance/stocktake/inventory_movement riêng
 * (BR-AGY-01/DEC-WM-001, docs/HoaNam_ERD_v1.0.md §agency).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->uuid('agency_id')->primary();
            $table->string('agency_code', 50)->unique();
            $table->string('agency_name', 255);
            $table->string('phone', 30)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | INACTIVE
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
