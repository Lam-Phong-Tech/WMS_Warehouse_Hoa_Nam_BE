<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Database\Seeders\AuditLogDatabaseSeeder;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Notification\Database\Seeders\NotificationDatabaseSeeder;
use Modules\Warehouse\Database\Seeders\HoaNamBulkDemoSeeder;
use Modules\Warehouse\Database\Seeders\OperationalReportRangeSeeder;
use Modules\Warehouse\Database\Seeders\SkuStockThresholdSeeder;
use Modules\Warehouse\Database\Seeders\WarrantyCheckResultBackfillSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseBusinessDataSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseDatabaseSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseSupplementalDataSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Thứ tự phụ thuộc:
     *  1. Auth        — users/roles/permissions (actor_id của mọi bản ghi phía sau).
     *  2. Warehouse   — master data (kho, SKU baseline, agency, defect, power source).
     *  3. Business    — chứng từ nhập/xuất, tồn kho, container, warranty (cần 1 + 2).
     *  4. Supplemental — bảng vệ tinh/hệ thống (import batch, scan, PV catalog,
     *     idempotency, audit outbox...); cần dữ liệu của bước 2 + 3 làm tham chiếu.
     *  5. AuditLog    — tham chiếu doc_no của bộ dữ liệu nghiệp vụ ở bước 3.
     *  6. Notification — thông báo mẫu tham chiếu chứng từ/case ở bước 3 (trước đây
     *     là seeder rỗng, chưa từng được gọi ở đây nên chuông thông báo luôn trống).
     *  7. OperationalReportRangeSeeder — dữ liệu bổ sung trải 2026-08-16..29 cho
     *     8 tab "Báo cáo vận hành" (yêu cầu người dùng 2026-08-29); độc lập với
     *     bước 3, guard idempotency riêng theo doc_no 'PN-RPT-01'.
     *  8. HoaNamBulkDemoSeeder — dữ liệu DEMO khối lượng lớn (mặc định ~200 hiện vật,
     *     ~500 SKU) trải tháng 7 & 8/2026, đủ mọi trạng thái state-machine cho báo cáo
     *     truy vết/vận hành, bảo hành, tồn kho. Idempotent theo khoá tự nhiên; chạy sau
     *     bước 3-7 (cần master data + service tồn). Chỉnh khối lượng qua env HN_BULK_ITEMS.
     *     Không wire trước đây nên clone + seed thiếu dữ liệu ở nhiều màn.
     *  9. SkuStockThresholdSeeder — đặt min_stock/max_stock cho MỌI SKU ACTIVE để 2
     *     cột "Định mức tối thiểu/tối đa" (màn Danh sách SKU) + card "Định mức tồn
     *     kho" có dữ liệu. PHẢI chạy CUỐI CÙNG (sau cả bulk ở bước 8) để phủ định mức
     *     cho toàn bộ SKU và tính theo available_qty tổng hợp từ inventory_balances.
     *     Không wire trước đây nên clone + seed để 2 cột này "Chưa có".
     */
    public function run(): void
    {
        $this->call([
            AuthDatabaseSeeder::class,
            WarehouseDatabaseSeeder::class,
            WarehouseBusinessDataSeeder::class,
            WarehouseSupplementalDataSeeder::class,
            AuditLogDatabaseSeeder::class,
            NotificationDatabaseSeeder::class,
            OperationalReportRangeSeeder::class,
        ]);

        // Bước 8-10 (demo khối lượng lớn + hoàn thiện cột hiển thị) dùng SQL
        // Postgres-only (regexp_replace, row_number() OVER, ::int, btrim) → CHỈ chạy
        // trên pgsql (CSDL thật của app/demo). SQLite của test suite bỏ qua để không
        // vỡ — cùng quy ước guard driver đã dùng ở WarehouseSupplementalDataSeeder /
        // WarehouseDatabaseSeeder. Test tự dựng dữ liệu riêng khi cần.
        if (DB::getDriverName() === 'pgsql') {
            $this->call([
                HoaNamBulkDemoSeeder::class,
                WarrantyCheckResultBackfillSeeder::class,
                SkuStockThresholdSeeder::class,
            ]);
        }
    }
}
