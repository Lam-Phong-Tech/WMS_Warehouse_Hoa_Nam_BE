<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Database\Seeders\AuditLogDatabaseSeeder;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Notification\Database\Seeders\NotificationDatabaseSeeder;
use Modules\Warehouse\Database\Seeders\DisplayFieldCoverageSeeder;
use Modules\Warehouse\Database\Seeders\HoaNamBulkDemoSeeder;
use Modules\Warehouse\Database\Seeders\InventoryStockStatusMixSeeder;
use Modules\Warehouse\Database\Seeders\MasterCatalogCoverageSeeder;
use Modules\Warehouse\Database\Seeders\OperationalReportRangeSeeder;
use Modules\Warehouse\Database\Seeders\SkuAttributeBackfillSeeder;
use Modules\Warehouse\Database\Seeders\SkuCoverageSeeder;
use Modules\Warehouse\Database\Seeders\SkuStockThresholdSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseBusinessDataSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseDatabaseSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseOperationsCoverageSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseSupplementalDataSeeder;
use Modules\Warehouse\Database\Seeders\WarrantyCheckResultBackfillSeeder;
use Modules\Warehouse\Database\Seeders\WarrantyReportRangeSeeder;

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
     *
     * BỘ SEEDER "PHỦ KÍN" (yêu cầu người dùng 04/09/2026 — dữ liệu phải trải đều
     * 06→09/2026 và MỌI giá trị của MỌI bộ lọc đều phải ra dữ liệu):
     *  - MasterCatalogCoverageSeeder — 5 tab danh mục sản phẩm + bệnh/lỗi + nơi
     *    nhận đủ ACTIVE/INACTIVE và đủ 4 loại đơn nhận.
     *  - SkuCoverageSeeder — lưới SKU nhóm hàng × hãng × PRODUCT/COMPONENT ×
     *    SERIAL/QUANTITY × ACTIVE/INACTIVE.
     *  - WarehouseOperationsCoverageSeeder — chứng từ nhập/xuất/xuất linh kiện/
     *    điều chỉnh trải đều 4 tháng và đủ mọi trạng thái; kèm yêu cầu xuất,
     *    container, sự cố đối soát, batch import, nhật ký in nhãn, cất hàng,
     *    thông báo. Mọi hiệu ứng tồn kho đi qua service nghiệp vụ thật nên sổ
     *    cái = sổ tồn = sổ phụ (trừ 6 chênh lệch CỐ Ý, mỗi cái có một sự cố đối
     *    soát đang mở mô tả kèm, để màn đối soát có cả dòng "Lệch").
     *  - SkuAttributeBackfillSeeder — điền nốt thuộc tính SKU còn trống.
     * Tất cả đều idempotent theo khoá tự nhiên: chạy `db:seed` lần hai không
     * nhân bản và không vỡ ràng buộc.
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
                // 8a. Danh mục gốc phủ đủ trạng thái/loại (hãng, nhóm hàng, model,
                //     nguồn năng lượng, quy cách, bệnh/lỗi, nơi nhận) — phải chạy
                //     TRƯỚC SkuCoverageSeeder vì SKU tham chiếu các mã mới này.
                MasterCatalogCoverageSeeder::class,
                // 8b. Lưới SKU phủ nhóm hàng × hãng × loại × cách theo dõi × trạng
                //     thái; đồng thời điền các thuộc tính còn trống của SKU cũ.
                SkuCoverageSeeder::class,
                HoaNamBulkDemoSeeder::class,
                // 8c. Chứng từ vận hành trải ĐỀU 06→09/2026 và phủ đủ trạng thái
                //     phiếu nhập/xuất/xuất linh kiện/điều chỉnh, yêu cầu xuất,
                //     container, sự cố đối soát, batch import, nhật ký in nhãn.
                //     Chạy sau bulk (mượn case bảo hành đang xử lý làm đích cho
                //     phiếu xuất linh kiện) và trước WarrantyReportRangeSeeder
                //     (seeder đó mượn hiện vật ISSUED do bước này sinh thêm).
                WarehouseOperationsCoverageSeeder::class,
                // Bổ sung dữ liệu báo cáo: ≥50 hồ sơ bảo hành 2026-06-01..08-31 đủ 6
                // trạng thái (WarrantyReportRangeSeeder) + tồn kho trải đủ 4 trạng thái
                // matched cho báo cáo tồn & đối soát (InventoryStockStatusMixSeeder).
                // Chạy sau bulk để mượn item ISSUED + SKU/tồn sẵn có.
                WarrantyReportRangeSeeder::class,
                InventoryStockStatusMixSeeder::class,
                WarrantyCheckResultBackfillSeeder::class,
                // Điền nốt thuộc tính SKU còn trống (nguồn năng lượng / quy cách /
                // dòng máy). PHẢI đứng sau HoaNamBulkDemoSeeder vì seeder đó còn
                // sinh thêm ~500 SKU sau SkuCoverageSeeder.
                SkuAttributeBackfillSeeder::class,
                SkuStockThresholdSeeder::class,
                // 8d. Lấp các CỘT HIỂN THỊ còn trống 100% sau toàn bộ chuỗi trên:
                //     vị trí lưu kho (chỉ 5/132 SKU có tồn từng được gán), tệp lỗi
                //     của lô import, sku/item của nhãn in, lý do huỷ chứng từ, ghi
                //     chú dòng. PHẢI chạy CUỐI vì nó rà theo tồn/chứng từ đã có.
                DisplayFieldCoverageSeeder::class,
            ]);
        }
    }
}
