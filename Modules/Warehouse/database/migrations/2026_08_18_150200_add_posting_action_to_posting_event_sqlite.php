<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-219 posting_action overflow — nhánh sqlite.
 *
 * `2026_08_17_000100_materialize_erd_columns_on_sqlite.php` đọc tên bảng canonical
 * từ docs/HoaNam_ERD_Moi_Nhat.md (`posting_events`, số nhiều) rồi chỉ ALTER TABLE nếu
 * tên đó khớp bảng vật lý sqlite thật — nhưng trên sqlite bảng vật lý là `posting_event`
 * (số ít; 2026_08_10_000791 chỉ đảo tên số ít/số nhiều trên PostgreSQL, xem
 * reference_be_test_drivers), nên vòng lặp đó bỏ qua `posting_events` hoàn toàn và
 * `posting_event` sqlite chưa từng có cột `posting_action`.
 *
 * Fix song song ở tầng application (đang WIP trong working tree — xem
 * 2026_08_18_150100) ghi thẳng `posting_action` vào `DB::table('posting_event')`
 * trên MỌI driver, không riêng pgsql. Thiếu cột này trên sqlite thì test suite sqlite
 * (nhánh CI chính, baseline 463/482 pass) sẽ vỡ ngay ở "table posting_event has no
 * column named posting_action" cho mọi post/reverse — nghiêm trọng hơn cả bug gốc.
 */
return new class extends Migration
{
    public function up(): void
    {
        // DEV195-P2-02R: nhánh này CHỈ dành cho SQLite — workaround test/dev only (PostgreSQL là
        // canonical/production DB, xem docs/HN-BE-001_BACKEND_STANDARD.md §3 và migration
        // 2026_08_18_150100 cho nhánh pgsql tương ứng qua compatibility view).
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        if (! Schema::hasTable('posting_event') || Schema::hasColumn('posting_event', 'posting_action')) {
            return;
        }

        Schema::table('posting_event', function ($table) {
            $table->string('posting_action', 50)->nullable();
        });
    }

    public function down(): void
    {
        // Fail-forward: xem docblock 2026_08_18_150000 — không có bản "cũ đúng hơn".
    }
};
