<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thùng CARTON, hộp linh kiện BOX và khay TRAY. `physical_code_id` chỉ là cột
 * compatibility của schema cũ; canonical ERD map mã vật lý qua physical_codes.container_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('containers', function (Blueprint $table) {
            $table->uuid('container_id')->primary();
            $table->string('container_code', 100)->unique();
            $table->string('container_type', 20); // CARTON | BOX | TRAY
            $table->foreignUuid('physical_code_id')->nullable()->unique()->constrained('physical_codes', 'physical_code_id')->nullOnDelete();
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

        Schema::table('containers', function (Blueprint $table) {
            $table->foreign('parent_container_id')->references('container_id')->on('containers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('containers');
    }
};
