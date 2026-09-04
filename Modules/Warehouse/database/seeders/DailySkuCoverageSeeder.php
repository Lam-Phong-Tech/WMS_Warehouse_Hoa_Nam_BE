<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phủ ĐỀU mọi SKU đang có trong sổ cái — mỗi ngày, đủ cả bốn cột của phương
 * trình Nhập–Xuất–Tồn (yêu cầu người dùng 04/09/2026, lần hai).
 *
 * Vì sao là một seeder RIÊNG thay vì một phase trong WarehouseOperations-
 * CoverageSeeder: phần thân nằm ở đó (dùng chung helper tạo/ghi sổ chứng từ)
 * nhưng THỜI ĐIỂM chạy phải muộn hơn. `InventoryStockStatusMixSeeder` cấp tồn
 * OPENING cho vài SKU chưa từng có tồn, tức đưa thêm SKU vào sổ cái SAU khi
 * seeder kia đã xong; chạy chung thì đúng những SKU mới đó không được phủ và
 * lại hiện 0 ở mọi cột của RPT-XNT-01 (đo được 7/136 SKU trên wms_seedcheck).
 *
 * Chi phí: ~130 SKU × 14 ngày ≈ 3.000 lượt scan qua service nghiệp vụ thật,
 * khoảng 18 phút — phần lâu nhất của cả chuỗi seed. Tắt/chỉnh bằng env
 * HN_DAILY_COVERAGE_DAYS (mặc định 14 ở máy dev, 0 khi CI=true).
 *
 * Idempotent theo khoá tự nhiên nhúng ngày; chạy lại trong cùng ngày không
 * nhân bản. Chỉ pgsql (guard nằm trong bootstrap của seeder gốc).
 */
class DailySkuCoverageSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $seeder = new WarehouseOperationsCoverageSeeder;
        if ($this->command !== null) {
            $seeder->setCommand($this->command);
        }
        $seeder->runDailyCoverageOnly();
    }
}
