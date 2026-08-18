<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-219 DEV219-P1-03R / P1-07R: `migrate:fresh` trên PostgreSQL sạch FAIL tại
 * 2026_08_10_0007675_prepare_defect_catalog_canonical_key.php
 * ("column \"id\" does not exist") — reproducible 100% trên DB hoàn toàn mới, không liên
 * quan tới bất kỳ thay đổi nào của WWHN-219.
 *
 * Nguyên nhân: `2026_01_01_000044_create_hoanam_erd_extension_tables.php` tạo bảng
 * `defect_catalog` chỉ với (defect_id, defect_code, defect_name, severity, status,
 * timestamps). Nhưng `0007675` cần cột `id` để tạo UNIQUE INDEX, và
 * `2026_08_10_000768_sync_defect_catalog_runtime_to_erd.php` cần thêm
 * (description, created_by, updated_by, version) cho câu `INSERT ... ON CONFLICT (id)`.
 * Trên DB dev đang chạy sẵn các cột này đã tồn tại (rất có thể một migration đã thêm
 * chúng rồi bị xoá khỏi thư mục migrations mà không ai để ý, vì DB dev không chạy lại
 * migrate:fresh thường xuyên) — nên bug chỉ lộ ra khi cài đặt hoàn toàn mới (đúng tình
 * huống CI/WWHN-219 Final Gate gặp phải).
 *
 * Migration này bù đúng phần thiếu, đặt trước 0007675 theo thứ tự file.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('defect_catalog')) {
            return;
        }

        Schema::table('defect_catalog', function ($table): void {
            if (! Schema::hasColumn('defect_catalog', 'id')) {
                $table->uuid('id')->nullable()->after('defect_id');
            }
            if (! Schema::hasColumn('defect_catalog', 'description')) {
                $table->text('description')->nullable()->after('defect_name');
            }
            if (! Schema::hasColumn('defect_catalog', 'created_by')) {
                $table->uuid('created_by')->nullable();
            }
            if (! Schema::hasColumn('defect_catalog', 'updated_by')) {
                $table->uuid('updated_by')->nullable();
            }
            if (! Schema::hasColumn('defect_catalog', 'version')) {
                $table->integer('version')->default(0);
            }
        });

        DB::statement('UPDATE defect_catalog SET id = defect_id WHERE id IS NULL');
        DB::statement('ALTER TABLE defect_catalog ALTER COLUMN id SET NOT NULL');
    }

    public function down(): void
    {
        // Fail-forward: bù cột còn thiếu để migration sau chạy được, không đổi semantics.
    }
};
