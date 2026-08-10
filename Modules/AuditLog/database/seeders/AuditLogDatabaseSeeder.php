<?php

namespace Modules\AuditLog\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\Warehouse;

class AuditLogDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('username', 'admin')->first();
        $staff = User::query()->where('username', 'staff01')->first() ?? $admin;
        $keeper = User::query()->where('username', 'keeper01')->first() ?? $admin;

        $objects = [
            ['actor_id' => $admin?->user_id, 'action' => 'CREATE', 'object_type' => 'warehouse', 'object_id' => Warehouse::query()->where('code', 'KHO-TONG-HN')->value('id'), 'reason' => 'Seed kho tong ban dau', 'request_id' => 'seed-audit-001', 'after_data' => ['code' => 'KHO-TONG-HN']],
            ['actor_id' => $admin?->user_id, 'action' => 'CREATE', 'object_type' => 'product', 'object_id' => Product::query()->where('code', 'HN-PV-BASE-001')->value('id'), 'reason' => 'Seed SKU san pham PV', 'request_id' => 'seed-audit-002', 'after_data' => ['code' => 'HN-PV-BASE-001']],
            ['actor_id' => $staff?->user_id, 'action' => 'SUBMIT', 'object_type' => 'stock_document', 'object_id' => StockDocument::query()->where('doc_no', 'PN-2026-0001')->value('id'), 'reason' => 'Gui duyet phieu nhap mau', 'request_id' => 'seed-audit-003', 'after_data' => ['doc_no' => 'PN-2026-0001', 'status' => 'pending']],
            ['actor_id' => $keeper?->user_id, 'action' => 'POST', 'object_type' => 'stock_document', 'object_id' => StockDocument::query()->where('doc_no', 'PN-2026-0001')->value('id'), 'reason' => 'Hach toan phieu nhap mau', 'request_id' => 'seed-audit-004', 'after_data' => ['doc_no' => 'PN-2026-0001', 'status' => 'received']],
            ['actor_id' => $keeper?->user_id, 'action' => 'POST', 'object_type' => 'stock_document', 'object_id' => StockDocument::query()->where('doc_no', 'PX-2026-0001')->value('id'), 'reason' => 'Hach toan phieu xuat mau', 'request_id' => 'seed-audit-005', 'after_data' => ['doc_no' => 'PX-2026-0001', 'status' => 'issued']],
        ];

        foreach ($objects as $i => $row) {
            AuditLog::firstOrCreate(
                ['request_id' => $row['request_id']],
                $row + [
                    'before_data' => null,
                    'created_at' => Carbon::parse('2026-01-20 08:00:00')->addMinutes($i * 10),
                ]
            );
        }
    }
}
