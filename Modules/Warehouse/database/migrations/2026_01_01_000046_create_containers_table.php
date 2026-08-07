<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thùng CARTON, hộp linh kiện BOX và khay TRAY (ERD §5.2 container). Container/locator không
 * phải bucket tồn độc lập.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('containers', function (Blueprint $table) {
            $table->uuid('container_id')->primary();
            $table->string('container_code', 100)->unique();
            $table->string('container_type', 20); // CARTON | BOX | TRAY
            $table->foreignUuid('physical_code_id')->unique()->constrained('physical_codes', 'physical_code_id')->restrictOnDelete();
            $table->foreignUuid('primary_sku_id')->nullable()->constrained('sku', 'sku_id')->nullOnDelete();
            $table->uuid('parent_container_id')->nullable();
            $table->string('zone_code', 50)->nullable();
            $table->string('location_hint', 255)->nullable();
            $table->string('status', 20);
            $table->integer('version')->default(1);
            $table->timestamps();

            $table->index('container_type', 'idx_containers_type');
            $table->index('status', 'idx_containers_status');
        });

        // FK tự tham chiếu thêm sau khi bảng đã tồn tại (Postgres cần PK đã commit trước).
        Schema::table('containers', function (Blueprint $table) {
            $table->foreign('parent_container_id')->references('container_id')->on('containers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('containers');
    }
};
