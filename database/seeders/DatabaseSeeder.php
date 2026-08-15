<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AuditLog\Database\Seeders\AuditLogDatabaseSeeder;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseBusinessDataSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Thứ tự phụ thuộc:
     *  1. Auth      — users/roles/permissions (actor_id của mọi bản ghi phía sau).
     *  2. Warehouse — master data (kho, SKU baseline, agency, defect, power source).
     *  3. Business  — chứng từ nhập/xuất, tồn kho, container, warranty (cần 1 + 2).
     *  4. AuditLog  — tham chiếu doc_no của bộ dữ liệu nghiệp vụ ở bước 3.
     */
    public function run(): void
    {
        $this->call([
            AuthDatabaseSeeder::class,
            WarehouseDatabaseSeeder::class,
            WarehouseBusinessDataSeeder::class,
            AuditLogDatabaseSeeder::class,
        ]);
    }
}
