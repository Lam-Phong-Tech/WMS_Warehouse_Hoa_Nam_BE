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
 */
return new class extends Migration
{
    private const INDEX = 'uk_warranty_case_one_active_item';

    public function up(): void
    {
        if (! Schema::hasTable('warranty_case')) {
            return;
        }

        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        $this->assertNoDuplicateActiveWarranty();

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX.' ON warranty_case(item_id) '
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
     * Fail-fast trước khi tạo unique index: dữ liệu bẩn có sẵn sẽ làm migrate chết
     * giữa chừng với lỗi driver khó đọc thay vì thông điệp nghiệp vụ.
     */
    private function assertNoDuplicateActiveWarranty(): void
    {
        $duplicate = DB::table('warranty_case')
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
