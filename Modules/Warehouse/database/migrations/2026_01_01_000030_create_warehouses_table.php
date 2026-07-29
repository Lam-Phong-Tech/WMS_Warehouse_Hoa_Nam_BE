<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base chỉ vận hành 01 kho tổng (đúng BASE-01/WM-SCOPE-001 của BRD Hoa Nam) — không có
 * company_id/branch_id/cây parent_id/warehouse_type như bản multi-warehouse gốc của
 * ERP-Backend. Khi nâng cấp theo ERD, đổi tên bảng thành `warehouse` (số ít).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->string('address', 500)->nullable();
            $table->string('contact_person', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | INACTIVE
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
