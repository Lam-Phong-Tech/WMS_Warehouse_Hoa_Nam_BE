<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\Warehouse;

class WarehouseDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Warehouse::updateOrCreate(
            ['code' => 'KHO-TONG-HN'],
            [
                'name' => 'Kho tong Hoa Nam',
                'address' => 'Hoa Nam WMS baseline warehouse',
                'status' => 'ACTIVE',
            ]
        );

        Product::updateOrCreate(
            ['code' => 'HN-PV-BASE-001'],
            [
                'sku_code' => 'HN-PV-BASE-001',
                'name' => 'San pham PV baseline',
                'unit' => 'cai',
                'sku_type' => 'PRODUCT',
                'brand_code' => 'HOA-NAM',
                'category_code' => 'PV',
                'model_code' => 'BASE',
                'power_or_voltage' => '220V',
                'package_variant' => 'STANDARD',
                'version' => 1,
                'published_to_app' => true,
                'is_serial_tracked' => true,
                'status' => 'ACTIVE',
            ]
        );

        Product::updateOrCreate(
            ['code' => 'HN-COMP-BASE-001'],
            [
                'sku_code' => 'HN-COMP-BASE-001',
                'name' => 'Linh kien baseline',
                'unit' => 'cai',
                'sku_type' => 'COMPONENT',
                'brand_code' => 'HOA-NAM',
                'category_code' => 'COMPONENT',
                'model_code' => 'BASE',
                'version' => 1,
                'published_to_app' => false,
                'is_serial_tracked' => false,
                'status' => 'ACTIVE',
            ]
        );
    }
}
