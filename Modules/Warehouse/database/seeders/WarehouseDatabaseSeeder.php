<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Warehouse\Models\Agency;
use Modules\Warehouse\Models\DefectCatalog;
use Modules\Warehouse\Models\Warehouse;

class WarehouseDatabaseSeeder extends Seeder
{
    /**
     * Baseline chỉ có đúng 1 "kho tổng" (DEC-WM-001) — route Warehouse không có POST
     * nên đây là cách duy nhất để có dữ liệu warehouse trong môi trường mới.
     */
    private const WAREHOUSE = [
        'code' => 'WH-MAIN',
        'name' => 'Kho tổng Hòa Nam',
        'address' => 'Số 1, KCN Quang Minh, Mê Linh, Hà Nội',
        'contact_person' => 'Nguyễn Văn A',
        'phone' => '0900000000',
        'status' => 'ACTIVE',
    ];

    /**
     * Agency là master data chỉ để tham chiếu (điểm nhận hàng xuất kho) — không có
     * inventory_balance/stocktake riêng theo BR-AGY-01/DEC-WM-001.
     */
    private const AGENCIES = [
        ['agency_code' => 'AG-001', 'agency_name' => 'Đại lý Hòa Nam Hà Nội', 'phone' => '0911111111', 'address' => 'Hà Nội'],
        ['agency_code' => 'AG-002', 'agency_name' => 'Đại lý Hòa Nam Đà Nẵng', 'phone' => '0922222222', 'address' => 'Đà Nẵng'],
        ['agency_code' => 'AG-003', 'agency_name' => 'Đại lý Hòa Nam Hồ Chí Minh', 'phone' => '0933333333', 'address' => 'Hồ Chí Minh'],
    ];

    /**
     * Danh mục lỗi/bệnh máy dùng chung cho warranty_case — giữ INACTIVE thay vì xoá cứng
     * nếu về sau không dùng nữa (comment trong migration create_defect_catalogs_table).
     */
    private const DEFECT_CATALOGS = [
        ['defect_code' => 'DEF-001', 'defect_name' => 'Trầy xước bề mặt', 'description' => 'Trầy xước ngoại quan không ảnh hưởng chức năng'],
        ['defect_code' => 'DEF-002', 'defect_name' => 'Vỡ/nứt vỏ máy', 'description' => 'Vỡ hoặc nứt vỏ ngoài của thiết bị'],
        ['defect_code' => 'DEF-003', 'defect_name' => 'Thiếu linh kiện', 'description' => 'Thiếu phụ kiện/linh kiện đi kèm khi nhận hàng'],
        ['defect_code' => 'DEF-004', 'defect_name' => 'Không lên nguồn', 'description' => 'Thiết bị không khởi động được'],
        ['defect_code' => 'DEF-005', 'defect_name' => 'Lỗi phần mềm/firmware', 'description' => 'Lỗi phát sinh do phần mềm hoặc firmware'],
    ];

    public function run(): void
    {
        Warehouse::firstOrCreate(
            ['code' => self::WAREHOUSE['code']],
            [
                'name' => self::WAREHOUSE['name'],
                'address' => self::WAREHOUSE['address'],
                'contact_person' => self::WAREHOUSE['contact_person'],
                'phone' => self::WAREHOUSE['phone'],
                'status' => self::WAREHOUSE['status'],
            ]
        );

        foreach (self::AGENCIES as $agency) {
            Agency::firstOrCreate(
                ['agency_code' => $agency['agency_code']],
                [
                    'agency_name' => $agency['agency_name'],
                    'phone' => $agency['phone'],
                    'address' => $agency['address'],
                    'status' => 'ACTIVE',
                ]
            );
        }

        foreach (self::DEFECT_CATALOGS as $defect) {
            DefectCatalog::firstOrCreate(
                ['defect_code' => $defect['defect_code']],
                [
                    'defect_name' => $defect['defect_name'],
                    'description' => $defect['description'],
                    'status' => 'ACTIVE',
                ]
            );
        }

        // Seed dữ liệu demo (product_masters, SKU, import_batches, physical_codes,
        // containers, item_instances, inventory_serials, stock_documents,
        // inventory_balances, stock_movements, container_items).
        $this->call(DemoDataSeeder::class);
    }
}
