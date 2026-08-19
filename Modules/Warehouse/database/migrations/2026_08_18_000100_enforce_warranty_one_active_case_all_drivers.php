<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-244 / DEV244-P1-06.
 *
 * ERD §45 warranty_cases: "Một active case/item (not RETURNED/CANCELLED)" +
 * "partial UX(item_id) WHERE active". Trước migration này partial unique index chỉ
 * được tạo trong nhánh pgsql (2026_08_10_000620 / 000710), nên toàn bộ suite chạy
 * trên SQLite không hề có ràng buộc tầng DB: một request bypass API (job, seeder,
 * import, race hai request song song trước khi lockForUpdate kịp) vẫn tạo được case
 * active thứ hai cho cùng item. SQLite hỗ trợ partial index từ 3.8.0 nên invariant
 * này phải hiện diện trên mọi driver, không chỉ trên môi trường production.
 *
 * Sửa 19/08/2026 (vòng đóng P1-06 lần 2): `2026_08_10_000791_retire_remaining_
 * singular_legacy_tables` biến `warranty_case` (số ít) thành VIEW trên pgsql (bảng
 * vật lý là `warranty_cases` số nhiều — write-bridge trigger), trong khi trên sqlite
 * `warranty_case` số ít vẫn là bảng vật lý. `Schema::hasTable('warranty_case')` cố
 * định trước đây trả về false trên pgsql (đúng, vì đó là view) nên migration NO-OP
 * âm thầm trên pgsql: index `uk_warranty_case_one_active_item` không hề tồn tại ở đó.
 * Test `test_second_active_case_for_same_item_is_blocked_at_database_level` vẫn xanh
 * chỉ vì một index KHÁC không liên quan (`ux_erd_warranty_one_active_case_item`, sinh
 * từ `2026_08_10_000770_enforce_erd_1_0_keys_relationships_and_constraints`) tình cờ
 * chặn hộ trên bảng vật lý `warranty_cases`. Sửa bằng cách tự dò bảng vật lý đúng theo
 * driver thay vì cố định tên số ít, để migration này thật sự là nguồn enforce trên cả
 * hai driver như tài liệu DEC-WAR-STATE-001 §4 mô tả.
 */
return new class extends Migration
{
    private const INDEX = 'uk_warranty_case_one_active_item';

    public function up(): void
    {
        $table = $this->resolvePhysicalTable();
        if ($table === null) {
            return;
        }

        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        $this->assertNoDuplicateActiveWarranty($table);

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX." ON {$table}(item_id) "
            ."WHERE item_id IS NOT NULL AND status NOT IN ('RETURNED','CANCELLED')"
        );
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }

    /**
     * `warranty_case` (số ít) là bảng vật lý trên sqlite nhưng chỉ là view trên pgsql
     * (bảng vật lý ở đó là `warranty_cases` số nhiều) — xem ghi chú trên đầu file.
     * Dò cả hai tên và dùng `Schema::hasTable()` (loại trừ view) để luôn bám đúng
     * bảng vật lý, không phụ thuộc thứ tự migration nào chạy trước.
     */
    private function resolvePhysicalTable(): ?string
    {
        foreach (['warranty_case', 'warranty_cases'] as $candidate) {
            if (Schema::hasTable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Fail-fast trước khi tạo unique index: dữ liệu bẩn có sẵn sẽ làm migrate chết
     * giữa chừng với lỗi driver khó đọc thay vì thông điệp nghiệp vụ.
     */
    private function assertNoDuplicateActiveWarranty(string $table): void
    {
        $duplicate = DB::table($table)
            ->select('item_id')
            ->whereNotNull('item_id')
            ->whereNotIn('status', ['RETURNED', 'CANCELLED'])
            ->groupBy('item_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'ERD §45 one-active-case bị vi phạm sẵn trong dữ liệu (item_id='
                .(string) $duplicate->item_id.'); reconcile trước khi enforce.'
            );
        }
    }
};
