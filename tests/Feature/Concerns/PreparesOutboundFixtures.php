<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;

/**
 * Fixture helpers dùng chung cho các test HTTP end-to-end của luồng outbound
 * (WWHN-195). Tách ra trait riêng để OutboundDocumentFlowTest, PostIssue*Test và
 * OutboundPermissionTest không lặp lại cùng một block seed DB.
 */
trait PreparesOutboundFixtures
{
    /**
     * Dùng AuthDatabaseSeeder thật (cùng pattern AuthorizationTest/SkuAuthorizationTest)
     * thay vì tự gán permission thủ công, để test bám đúng role/permission matrix thật
     * của hệ thống thay vì một tập hợp tự chế có thể lệch dần theo thời gian.
     */
    private function authHeadersWithRole(string $roleCode): array
    {
        if (DB::table('permission')->count() === 0) {
            $this->seed(AuthDatabaseSeeder::class);
            Cache::flush();
        }

        $role = Role::where('role_code', $roleCode)->firstOrFail();
        $user = User::create([
            'username' => Str::lower(Str::random(4)).'-'.Str::lower(Str::random(6)),
            'display_name' => 'Outbound Fixture User',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}", '__user_id' => $user->user_id];
    }

    private function superAdminHeaders(): array
    {
        return $this->authHeadersWithRole('SUPER_ADMIN');
    }

    private function warehouseFixture(string $code = 'KHO-TONG-HN'): Warehouse
    {
        // REQ-SRS-001 + uk_warehouses_single_active: chỉ một warehouse ACTIVE.
        // Tái sử dụng warehouse ACTIVE hiện hữu (vd. từ seed) thay vì tạo bản thứ hai.
        $existing = Warehouse::query()->where('status', 'ACTIVE')->first();
        if ($existing !== null) {
            return $existing;
        }

        return Warehouse::create([
            'code' => $code,
            'name' => 'Kho tổng Hoa Nam',
            'status' => 'ACTIVE',
        ]);
    }

    private function productSkuFixture(string $code): Sku
    {
        return Sku::create([
            'sku_code' => $code,
            'sku_name' => 'Product outbound test',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
    }

    private function seedAvailableBalance(string $warehouseId, string $skuId, int $qty): void
    {
        DB::table('inventory_balances')->insert([
            'id' => (string) Str::uuid(),
            'warehouse_id' => $warehouseId,
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'stock_status' => 'AVAILABLE',
            'quantity' => $qty,
            'on_hand_qty' => $qty,
            'available_qty' => $qty,
            'reserved_qty' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedItemInStock(string $skuId): string
    {
        $itemId = (string) Str::uuid();
        DB::table('item_instances')->insert([
            'item_id' => $itemId,
            'sku_id' => $skuId,
            'serial_number' => 'SN-'.Str::upper(Str::random(8)),
            'item_status' => 'IN_STOCK',
            'item_condition' => 'NORMAL',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $itemId;
    }

    /**
     * RFID gắn item — bắt buộc trước Post Issue theo assertMatchedProductRfids().
     * Ghi cả `physical_codes` (canonical) lẫn `physical_code` (legacy) vì FK của
     * scan_event.physical_code_id không được repoint trên sqlite test driver
     * (xem migration 2026_08_10_000630, dòng 18-20 chủ động bỏ qua sqlite).
     */
    private function seedRfidCode(string $itemId): void
    {
        $physicalCodeId = (string) Str::uuid();
        $codeValue = 'RFID-'.Str::upper(Str::random(10));
        DB::table('physical_codes')->insert([
            'physical_code_id' => $physicalCodeId,
            'code_value' => $codeValue,
            'code_type' => 'ITEM',
            'carrier_type' => 'RFID',
            'hardware_uid' => 'HW-'.Str::upper(Str::random(10)),
            'status' => 'ACTIVE',
            'assigned_object_type' => 'ITEM',
            'assigned_object_id' => $itemId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mirrorLegacyPhysicalCode($physicalCodeId, $codeValue, 'RFID', $itemId);
    }

    /** Barcode/QR dùng cho chế độ "quét mã" trong scan payload (`code_value`). */
    private function seedScanCode(string $itemId, string $codeValue): void
    {
        $physicalCodeId = (string) Str::uuid();
        DB::table('physical_codes')->insert([
            'physical_code_id' => $physicalCodeId,
            'code_value' => $codeValue,
            'code_type' => 'ITEM',
            'carrier_type' => 'QR',
            'status' => 'ACTIVE',
            'assigned_object_type' => 'ITEM',
            'assigned_object_id' => $itemId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mirrorLegacyPhysicalCode($physicalCodeId, $codeValue, 'QR', $itemId);
    }

    private function mirrorLegacyPhysicalCode(string $physicalCodeId, string $codeValue, string $carrierType, string $itemId): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! Schema::hasTable('physical_code')) {
            return;
        }

        DB::table('physical_code')->insert([
            'physical_code_id' => $physicalCodeId,
            'code_value' => $codeValue,
            'code_type' => 'ITEM',
            'carrier_type' => $carrierType,
            'assigned_object_type' => 'ITEM',
            'assigned_object_id' => $itemId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Đánh dấu PRINTED cho Packing Label của document. Từ migration
     * 2026_08_15_140000 (flow06), label là document-level: đúng một
     * label_snapshots cho mỗi outbound_document (uk_label_snapshots_outbound_document),
     * còn outbound_item_match_id/sku_id/item_id chỉ giữ lại cho snapshot lịch sử.
     */
    private function markLabelsPrinted(string $documentId): void
    {
        $existing = DB::table('label_snapshots')->where('outbound_document_id', $documentId)->first();
        if ($existing !== null) {
            DB::table('label_snapshots')->where('id', $existing->id)->update([
                'status' => 'PRINTED',
                'applied_at' => now(),
                'version' => ((int) $existing->version) + 1,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('label_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'outbound_document_id' => $documentId,
            'outbound_item_match_id' => null,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode(['test' => true]),
            'status' => 'PRINTED',
            'applied_at' => now(),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function outboundRequestFixture(string $skuId, int $qty, string $requestNo): string
    {
        // ERD bảng 37: outbound_requests.import_id NOT NULL — mọi toa (Excel hoặc
        // nhập tay) đều phải trace về một outbound_request_imports record, khớp
        // REQ-SRS-014 (cả hai kênh lưu source_type/source_ref/checksum).
        $importId = (string) Str::uuid();
        $checksum = hash('sha256', 'test-fixture:'.$requestNo.':'.$importId);
        DB::table('outbound_request_imports')->insert([
            'id' => $importId,
            'import_no' => 'OBI-TEST-'.Str::upper(Str::random(10)),
            'source_file_name' => 'test-fixture.xlsx',
            'source_checksum' => $checksum,
            'source_key' => 'CHECKSUM:'.$checksum,
            'status' => 'CONSUMED',
            'total_rows' => 1,
            'valid_rows' => 1,
            'error_rows' => 0,
            'consumed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'version' => 0,
        ]);

        $requestId = (string) Str::uuid();
        DB::table('outbound_requests')->insert([
            'id' => $requestId,
            'request_no' => $requestNo,
            'import_id' => $importId,
            'source_checksum' => $checksum,
            'customer_name' => 'Đại lý test',
            'customer_address' => '123 Test Street',
            'recipient_name' => 'Đại lý test',
            'recipient_address' => '123 Test Street',
            'request_date' => now()->toDateString(),
            'status' => 'OPEN',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('outbound_request_lines')->insert([
            'id' => (string) Str::uuid(),
            'outbound_request_id' => $requestId,
            'line_no' => 1,
            'sku_id' => $skuId,
            'required_qty' => $qty,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $requestId;
    }

    /**
     * SRS §8.1: "no direct POST /outbound-documents create operation" — phiếu xuất chỉ
     * sinh từ Confirm toa. Test dựng thẳng phiếu DRAFT ở tầng DB đúng như
     * consumeOutboundRequestImport tạo ra, thay vì gọi endpoint đã bị gỡ.
     *
     * @return array{document_id: string, line_ids: array<int,string>}
     */
    private function outboundDocumentFixture(string $warehouseId, string $requestId): array
    {
        $request = DB::table('outbound_requests')->where('id', $requestId)->first();
        $actorId = (string) (auth('api')->id() ?? DB::table('app_user')->value('user_id'));
        $documentId = (string) Str::uuid();
        DB::table('stock_documents')->insert([
            'id' => $documentId,
            'doc_type' => 'issue',
            'doc_no' => 'PX-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
            'doc_date' => now()->toDateString(),
            'status' => 'DRAFT',
            'src_warehouse_id' => $warehouseId,
            'dst_warehouse_id' => null,
            'outbound_request_id' => $requestId,
            'agency_id' => $request->agency_id ?? null,
            'recipient_name' => (string) ($request->customer_name ?? $request->recipient_name ?? 'Đại lý test'),
            'recipient_address' => $request->customer_address ?? $request->recipient_address ?? null,
            'version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineIds = [];
        $requestLines = DB::table('outbound_request_lines')
            ->where('outbound_request_id', $requestId)
            ->orderBy('line_no')
            ->get();
        foreach ($requestLines as $index => $requestLine) {
            $lineId = (string) Str::uuid();
            DB::table('stock_document_lines')->insert([
                'id' => $lineId,
                'stock_document_id' => $documentId,
                'outbound_request_line_id' => $requestLine->id,
                'line_no' => $index + 1,
                'product_id' => $requestLine->sku_id,
                'qty_planned' => (int) $requestLine->required_qty,
                'qty_actual' => (int) $requestLine->required_qty,
                'scanned_qty' => 0,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $lineIds[] = $lineId;
        }

        DB::table('outbound_requests')->where('id', $requestId)->update([
            'status' => 'CONSUMED',
            'updated_at' => now(),
        ]);

        return ['document_id' => $documentId, 'line_ids' => $lineIds];
    }

    /**
     * Dựng 1 outbound document đã SCANNING đầy đủ (đã match item, RFID, label PRINTED),
     * sẵn sàng Post Issue — dùng cho test concurrency/audit không cần lặp lại toàn bộ setup.
     */
    private function readyToPostIssueDocument(array $headers, string $skuCode): array
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-'.Str::upper(Str::random(6)));

        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $documentId = $created['document_id'];
        $lineId = $created['line_ids'][0];

        $itemId = $this->seedItemInStock($sku->sku_id);
        $this->seedRfidCode($itemId);

        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->markLabelsPrinted($documentId);

        return ['document_id' => $documentId, 'sku_id' => $sku->sku_id, 'item_id' => $itemId, 'warehouse_id' => $warehouse->id];
    }

    /**
     * WWHN-202: `seedAvailableBalance()` chèn thẳng một dòng `inventory_balances` mà không
     * kèm `stock_movements` tương ứng, nên dòng đó vĩnh viễn không có gốc ledger và
     * `reconciliation_status` luôn đọc MISMATCH một cách chính đáng (ledger thiếu đúng phần
     * opening balance). Helper này chèn kèm 1 movement RECEIPT cho phần seed, khớp cách
     * balance thật luôn được tạo trong production (qua Post Receipt, không bao giờ insert
     * thẳng) — dùng cho mọi test cần khẳng định MATCH.
     *
     * Trước đây hai helper này nằm private trong `Wwhn207OutboundBalanceReconciliationTest`;
     * đưa lên trait để bộ evidence WWHN-202 dùng đúng một fixture, không nhân bản logic.
     */
    private function seedLedgerBackedBalance(string $warehouseId, string $skuId, int $qty, string $actorId): void
    {
        DB::table('inventory_balances')->insert([
            'id' => (string) Str::uuid(),
            'warehouse_id' => $warehouseId,
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'stock_status' => 'AVAILABLE',
            'quantity' => $qty,
            'on_hand_qty' => $qty,
            'available_qty' => $qty,
            'reserved_qty' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(),
            'movement_no' => 'MV-SEED-'.Str::upper(Str::random(8)),
            'business_key' => 'seed-opening-'.Str::uuid(),
            'movement_type' => 'RECEIPT',
            'warehouse_id' => $warehouseId,
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'stock_status' => 'AVAILABLE',
            'quantity' => $qty,
            'qty_before' => 0,
            'qty_delta' => $qty,
            'qty_after' => $qty,
            'source_type' => 'OPENING_BALANCE_SEED',
            'source_id' => (string) Str::uuid(),
            'created_by' => $actorId,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** Giống readyToPostIssueDocument() nhưng opening balance có gốc ledger (khẳng định MATCH được). */
    private function readyToPostIssueDocumentLedgerBacked(array $headers, string $skuCode): array
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);
        $this->seedLedgerBackedBalance($warehouse->id, $sku->sku_id, 1, $headers['__user_id']);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-'.Str::upper(Str::random(6)));

        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $documentId = $created['document_id'];
        $lineId = $created['line_ids'][0];
        $itemId = $this->seedItemInStock($sku->sku_id);
        $this->seedRfidCode($itemId);

        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->markLabelsPrinted($documentId);

        return [
            'document_id' => $documentId,
            'line_id' => $lineId,
            'sku_id' => $sku->sku_id,
            'item_id' => $itemId,
            'warehouse_id' => $warehouse->id,
        ];
    }
}
