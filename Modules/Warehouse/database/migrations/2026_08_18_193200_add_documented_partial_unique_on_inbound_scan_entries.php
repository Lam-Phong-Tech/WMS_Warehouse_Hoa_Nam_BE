<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-310 (bàn giao dữ liệu) - vật chất hoá index đã ghi trong ERD bảng 28:
 * `Partial UX(item_id) WHERE status=ACTIVE AND item_id IS NOT NULL`.
 *
 * Đây là ràng buộc "Item active chỉ thuộc một inbound chưa Post". Phía outbound
 * (ERD bảng 41) có đúng index này trên `outbound_item_matches`
 * (`ux_erd_outbound_active_item`), phía inbound thì chưa bao giờ được tạo - lệch
 * này đi lọt vì cổng parity chỉ kiểm mệnh đề `UNIQUE(...)`/`IDX(...)` mà bỏ qua
 * `UX(...)` ở cột "Index khuyến nghị" (đã sửa cùng ticket).
 *
 * Tầng ứng dụng đang chặn bằng `assertNoDuplicateItemInDocument` (409
 * DUPLICATE_ITEM_IN_DOCUMENT), `assertItemAvailableForReceipt` (409
 * ITEM_ALREADY_RECEIVING) và row-lock trên `item_instances`, nên index này là lớp
 * chốt cuối cho tranh chấp đồng thời chứ không thay thế guard nghiệp vụ - đúng
 * NFR-SRS "20 người ghi tồn đồng thời: không trùng movement".
 */
return new class extends Migration
{
    private const INDEX = 'ux_erd_inbound_active_item';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('inbound_scan_entries')) {
            return;
        }

        $duplicates = (int) DB::scalar(<<<'SQL'
            SELECT COUNT(*) FROM (
                SELECT item_id
                FROM inbound_scan_entries
                WHERE item_id IS NOT NULL AND status = 'ACTIVE'
                GROUP BY item_id
                HAVING COUNT(*) > 1
            ) AS conflicting
        SQL);

        // Fail-closed có thông điệp hành động được: im lặng bỏ qua index sẽ khiến
        // gói bàn giao báo PASS trong khi bất biến vẫn hở.
        if ($duplicates > 0) {
            throw new RuntimeException(
                "WWHN-310: {$duplicates} item đang có nhiều hơn một inbound_scan_entries ACTIVE. ".
                'Bất biến ERD bảng 28 (item chỉ thuộc một inbound chưa Post) đang bị vi phạm; '.
                'xử lý các entry thừa (đặt status=REMOVED) trước khi chạy lại migration.'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX.' ON inbound_scan_entries (item_id) '.
            "WHERE item_id IS NOT NULL AND status = 'ACTIVE'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        HoaNamErdSchema::dropIndexIfExists('inbound_scan_entries', self::INDEX);
    }
};
