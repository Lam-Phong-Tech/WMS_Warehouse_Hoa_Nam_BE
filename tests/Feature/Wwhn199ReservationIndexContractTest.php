<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WWHN-199 · DEV199-P1-02R + DEV199-P2-01 — hợp đồng index reservation ở tầng DB.
 *
 * Rà soát gốc (ATT-11849) kết luận "backstop DB có tồn tại" bằng cách ĐỌC migration
 * chứ không chạy trên PostgreSQL. Test này biến khẳng định đó thành thứ chạy được:
 * nó hỏi thẳng `pg_indexes` xem index nào đang thực sự tồn tại.
 *
 * Hai bất biến:
 *   1. Mỗi bảng evidence (outbound_item_matches, inbound_scan_entries) phải có ĐÚNG
 *      MỘT unique index chặn item ACTIVE trùng — không được 0 (mất backstop) và
 *      không được 2 (bản sao dư thừa, chính là DEV199-P2-01).
 *   2. Index còn lại phải là bản neo theo ERD (`ux_erd_*`), để tên trong evidence
 *      trỏ đúng một nguồn.
 *
 * Trên sqlite các partial unique index này chưa bao giờ được tạo (mọi migration liên
 * quan đều gate `DB::getDriverName() === 'pgsql'`), nên test tự skip thay vì khẳng
 * định sai về một môi trường không có ràng buộc đó.
 */
class Wwhn199ReservationIndexContractTest extends TestCase
{
    /** @return list<array{indexname: string, indexdef: string}> */
    private function activeItemUniqueIndexes(string $table): array
    {
        $rows = DB::select(
            'SELECT indexname, indexdef FROM pg_indexes '.
            'WHERE schemaname = current_schema() AND tablename = ? ORDER BY indexname',
            [$table]
        );

        return array_values(array_filter(
            array_map(static fn (object $row): array => (array) $row, $rows),
            static fn (array $row): bool => str_starts_with($row['indexdef'], 'CREATE UNIQUE')
                && str_contains($row['indexdef'], '(item_id)')
                && str_contains($row['indexdef'], "'ACTIVE'")
        ));
    }

    private function requirePostgres(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index reservation chỉ được tạo trên PostgreSQL.');
        }
        if (! Schema::hasTable($table)) {
            $this->markTestSkipped("Bảng {$table} chưa tồn tại trên connection hiện tại.");
        }
    }

    public function test_outbound_active_item_has_exactly_one_canonical_unique_index(): void
    {
        $this->requirePostgres('outbound_item_matches');

        $indexes = $this->activeItemUniqueIndexes('outbound_item_matches');
        $names = array_column($indexes, 'indexname');

        $this->assertCount(
            1,
            $indexes,
            'outbound_item_matches phải có đúng 1 unique index chặn item ACTIVE trùng. Đang có: '.
            (implode(', ', $names) ?: '(không có)')
        );
        $this->assertSame(
            'ux_erd_outbound_active_item',
            $names[0],
            'Index còn lại phải là bản neo ERD; uk_outbound_match_active_item đã được hợp nhất '.
            'ở migration 2026_08_19_120000 (DEV199-P2-01).'
        );
    }

    public function test_inbound_active_item_has_exactly_one_canonical_unique_index(): void
    {
        $this->requirePostgres('inbound_scan_entries');

        $indexes = $this->activeItemUniqueIndexes('inbound_scan_entries');
        $names = array_column($indexes, 'indexname');

        $this->assertCount(
            1,
            $indexes,
            'inbound_scan_entries phải có đúng 1 unique index chặn item ACTIVE trùng. Đang có: '.
            (implode(', ', $names) ?: '(không có)')
        );
        $this->assertSame('ux_erd_inbound_active_item', $names[0]);
    }

    /**
     * Backstop phải chặn ở tầng DB kể cả khi bỏ qua toàn bộ guard ứng dụng — đó mới
     * là ý nghĩa của "lớp phòng thủ độc lập". Insert thẳng dòng thứ hai và đòi lỗi.
     */
    public function test_database_rejects_a_second_active_match_for_the_same_item(): void
    {
        $this->requirePostgres('outbound_item_matches');

        $existing = DB::table('outbound_item_matches')->where('status', 'ACTIVE')->whereNotNull('item_id')->first();
        if ($existing === null) {
            $this->markTestSkipped('Chưa có match ACTIVE nào trên connection này để làm mốc.');
        }

        $this->expectException(QueryException::class);

        DB::table('outbound_item_matches')->insert([
            'id' => (string) Str::uuid(),
            'outbound_line_id' => $existing->outbound_line_id,
            'item_id' => $existing->item_id,
            'quantity' => 1,
            'status' => 'ACTIVE',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
