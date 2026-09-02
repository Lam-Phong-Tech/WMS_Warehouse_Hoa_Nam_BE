<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Backfill "Kết quả kiểm tra" (warranty_case.confirmed_defect) cho MỌI hồ sơ bảo
 * hành đã qua bước mở máy (status CHECKING/REPAIRING/COMPLETED/RETURNED) mà còn để
 * trống — bất kể seeder nào tạo ra case (WC-BULK / WC-SEED / WC-RPT...).
 *
 * Vì sao là bước finalize riêng (giống SkuStockThresholdSeeder): các case bảo hành
 * do NHIỀU seeder tạo độc lập; nếu vá từng chỗ insert sẽ sót và seeder mới thêm sau
 * lại tái phạm. Gom về một điểm cuối chuỗi để cột "Kết quả kiểm tra" không bao giờ
 * "Chưa có" đồng loạt ở các trạng thái tiến xa. RECEIVED (chưa kiểm) / CANCELLED để
 * trống đúng nghiệp vụ. Idempotent: chỉ điền chỗ đang NULL.
 */
class WarrantyCheckResultBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $affected = DB::update(<<<'SQL'
            UPDATE warranty_case w
               SET confirmed_defect =
                     'KTV xác nhận lỗi ('
                     || COALESCE((SELECT d.defect_code FROM defect_catalogs d WHERE d.defect_id = w.defect_id), 'DEF')
                     || ') sau khi mở máy — hồ sơ ' || w.warranty_case_code || '.',
                   updated_at = now()
             WHERE w.status IN ('CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED')
               AND (w.confirmed_defect IS NULL OR btrim(w.confirmed_defect) = '')
        SQL);

        $this->command?->info("WarrantyCheckResultBackfillSeeder: điền kết quả kiểm tra cho {$affected} hồ sơ bảo hành.");
    }
}
