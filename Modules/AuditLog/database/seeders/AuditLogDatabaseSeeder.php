<?php

namespace Modules\AuditLog\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\Warehouse;

/**
 * Audit log mẫu bám vào dữ liệu thật do WarehouseBusinessDataSeeder tạo ra.
 *
 * Trước đây seeder này tham chiếu doc_no 'PN-2026-0001'/'PX-2026-0001' vốn không
 * có seeder nào tạo, nên object_id luôn null. Nay dùng đúng doc_no của bộ dữ liệu
 * nghiệp vụ mẫu; nếu chứng từ chưa tồn tại (chạy AuditLog seeder độc lập) thì các
 * dòng phụ thuộc sẽ được bỏ qua thay vì ghi audit trỏ vào null.
 */
class AuditLogDatabaseSeeder extends Seeder
{
    private const RECEIPT_DOC_NO = 'PN-SEED-0002';

    private const ISSUE_DOC_NO = 'PX-SEED-0001';

    public function run(): void
    {
        $admin = User::query()->where('username', 'admin')->first();
        $staff = User::query()->where('username', 'staff01')->first() ?? $admin;
        $keeper = User::query()->where('username', 'keeper01')->first() ?? $admin;

        $warehouseId = Warehouse::query()->where('code', 'KHO-TONG-HN')->value('id');
        $skuId = Sku::query()->where('sku_code', 'HN-PV-BASE-001')->value('sku_id');
        $receiptId = StockDocument::query()->where('doc_no', self::RECEIPT_DOC_NO)->value('id');
        $issueId = StockDocument::query()->where('doc_no', self::ISSUE_DOC_NO)->value('id');

        $rows = [
            [
                'actor_id' => $admin?->user_id,
                'action' => 'CREATE',
                'object_type' => 'warehouse',
                'object_id' => $warehouseId,
                'reason' => 'Seed kho tong ban dau',
                'request_id' => 'seed-audit-001',
                'after_data' => ['code' => 'KHO-TONG-HN'],
            ],
            [
                'actor_id' => $admin?->user_id,
                'action' => 'CREATE',
                'object_type' => 'sku',
                'object_id' => $skuId,
                'reason' => 'Seed SKU baseline',
                'request_id' => 'seed-audit-002',
                'after_data' => ['sku_code' => 'HN-PV-BASE-001'],
            ],
            [
                'actor_id' => $staff?->user_id,
                'action' => 'SCAN_INBOUND',
                'object_type' => 'stock_document',
                'object_id' => $receiptId,
                'reason' => 'Quet nhan hang phieu nhap mau',
                'request_id' => 'seed-audit-003',
                'after_data' => ['doc_no' => self::RECEIPT_DOC_NO, 'status' => 'SCANNING'],
            ],
            [
                'actor_id' => $keeper?->user_id,
                'action' => 'POST_RECEIPT',
                'object_type' => 'stock_document',
                'object_id' => $receiptId,
                'reason' => 'Hach toan phieu nhap mau',
                'request_id' => 'seed-audit-004',
                'after_data' => ['doc_no' => self::RECEIPT_DOC_NO, 'status' => 'POSTED'],
            ],
            [
                'actor_id' => $keeper?->user_id,
                'action' => 'POST_ISSUE',
                'object_type' => 'stock_document',
                'object_id' => $issueId,
                'reason' => 'Hach toan phieu xuat mau',
                'request_id' => 'seed-audit-005',
                'after_data' => ['doc_no' => self::ISSUE_DOC_NO, 'status' => 'POSTED'],
            ],
        ];

        foreach ($rows as $i => $row) {
            // Không ghi audit "mồ côi" trỏ vào object không tồn tại.
            if ($row['object_id'] === null) {
                continue;
            }

            AuditLog::firstOrCreate(
                ['request_id' => $row['request_id']],
                $row + [
                    'before_data' => null,
                    'created_at' => Carbon::now()->subDay()->addMinutes($i * 10),
                ]
            );
        }
    }
}
