<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-219 DEV219-P1-03R: `defect_catalogs` (bảng vật lý canonical trên PostgreSQL sau
 * 2026_08_10_000791 — `defect_catalog` số ít chỉ còn là compatibility view) thiếu một số
 * cột/index/constraint so với Data Dictionary bảng 15 (docs/HoaNam_ERD_Moi_Nhat.md):
 *   - created_by/updated_by (FK app_users.id) hoàn toàn chưa tồn tại.
 *   - created_at/updated_at đang nullable, Data Dictionary ghi NOT NULL DEFAULT now().
 *   - Thiếu CHECK status ACTIVE/INACTIVE, IDX(status), IDX(defect_name).
 *
 * Migration này CHỈ vá phần cột/index/constraint còn thiếu thật sự trên bảng vật lý —
 * KHÔNG đổi tên bảng/field theo ERD (defect_catalog/id). Đã thử đổi tên trong ERD doc
 * (bảng 15 defect_catalog → defect_catalogs, field id → defect_id) để test parity xanh
 * hẳn, nhưng phải revert: docs/HoaNam_ERD_Moi_Nhat.md không chỉ là tài liệu, nó được
 * `App\Support\HoaNamErdSchema::parse()` đọc TRỰC TIẾP LÚC CHẠY MIGRATION bởi 7 migration
 * khác (2026_08_10_000750/000760/000770/0007695/000772/000783,
 * 2026_08_17_000100_materialize_erd_columns_on_sqlite) để quyết định backfill/normalize
 * cột nào trên bảng nào. Đổi tên bảng/field ngay lúc đó làm migration
 * 2026_08_10_000760 ngừng tạo cột `id` trên `defect_catalog` (vì ERD không còn field đó),
 * khiến 2026_08_10_0007675_prepare_defect_catalog_canonical_key.php chạy sau đó FAIL
 * (`column "id" does not exist`) — tái hiện được 100% trên DB sạch. Đổi tên bảng/field
 * theo ERD cho defect_catalog vì vậy phải làm CÙNG LÚC với audit toàn bộ 7 migration đó
 * (đúng như SUITE-TRIAGE.md đã khuyến nghị: "C — ERD-versus-implementation reconciliation.
 * Scope as its own ticket, not WWHN-219"), không an toàn để làm rời trong đêm nay.
 *
 * Chỉ chạy trên PostgreSQL: sqlite giữ `defect_catalog` số ít làm bảng vật lý baseline
 * riêng (theo quyết định kiến trúc "chỉ PostgreSQL đảo chiều số ít/số nhiều" của
 * 2026_08_10_000791), không thuộc phạm vi migration này.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('defect_catalogs')) {
            return;
        }

        Schema::table('defect_catalogs', function ($table): void {
            if (! Schema::hasColumn('defect_catalogs', 'created_by')) {
                $table->foreignUuid('created_by')->nullable()->after('status')->constrained('app_users', 'id')->nullOnDelete();
            }
            if (! Schema::hasColumn('defect_catalogs', 'updated_by')) {
                $table->foreignUuid('updated_by')->nullable()->after('created_by')->constrained('app_users', 'id')->nullOnDelete();
            }
        });

        // 0 hàng NULL hiện tại (đã kiểm chứng trước khi viết migration này) nên siết
        // NOT NULL + DEFAULT now() an toàn, khớp Data Dictionary.
        DB::statement('ALTER TABLE defect_catalogs ALTER COLUMN created_at SET DEFAULT now()');
        DB::statement('UPDATE defect_catalogs SET created_at = now() WHERE created_at IS NULL');
        DB::statement('ALTER TABLE defect_catalogs ALTER COLUMN created_at SET NOT NULL');

        DB::statement('ALTER TABLE defect_catalogs ALTER COLUMN updated_at SET DEFAULT now()');
        DB::statement('UPDATE defect_catalogs SET updated_at = now() WHERE updated_at IS NULL');
        DB::statement('ALTER TABLE defect_catalogs ALTER COLUMN updated_at SET NOT NULL');

        if (! collect(DB::select("SELECT conname FROM pg_constraint WHERE conrelid='defect_catalogs'::regclass AND contype='c'"))
            ->contains(fn ($row) => $row->conname === 'chk_defect_catalogs_status')) {
            DB::statement("ALTER TABLE defect_catalogs ADD CONSTRAINT chk_defect_catalogs_status CHECK (status IN ('ACTIVE','INACTIVE'))");
        }

        $existingIndexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename='defect_catalogs'"))
            ->pluck('indexname');

        if (! $existingIndexes->contains('idx_defect_catalogs_status')) {
            DB::statement('CREATE INDEX idx_defect_catalogs_status ON defect_catalogs (status)');
        }
        if (! $existingIndexes->contains('idx_defect_catalogs_defect_name')) {
            DB::statement('CREATE INDEX idx_defect_catalogs_defect_name ON defect_catalogs (defect_name)');
        }
    }

    public function down(): void
    {
        // Fail-forward: mở rộng cột/constraint để khớp ERD, không đổi semantics đã ký.
    }
};
