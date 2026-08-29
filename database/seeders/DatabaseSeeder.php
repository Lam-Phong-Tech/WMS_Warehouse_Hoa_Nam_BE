<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AuditLog\Database\Seeders\AuditLogDatabaseSeeder;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Notification\Database\Seeders\NotificationDatabaseSeeder;
use Modules\Warehouse\Database\Seeders\OperationalReportRangeSeeder;
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
    }
}
