<?php

namespace Tests\Feature\Concerns;

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
            \Illuminate\Support\Facades\Cache::flush();
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
        return Warehouse::create([
            'code' => $code,
            'name' => 'Kho tổng Hòa Nam',
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
        $requestId = (string) Str::uuid();
        DB::table('outbound_requests')->insert([
            'id' => $requestId,
            'request_no' => $requestNo,
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
     * Dựng 1 outbound document đã SCANNING đầy đủ (đã match item, RFID, label PRINTED),
     * sẵn sàng Post Issue — dùng cho test concurrency/audit không cần lặp lại toàn bộ setup.
     */
    private function readyToPostIssueDocument(array $headers, string $skuCode): array
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-'.Str::upper(Str::random(6)));

        $create = $this->postJson('/api/v1/outbound-documents', [
            'src_warehouse_id' => $warehouse->id,
            'outbound_request_id' => $requestId,
        ], $headers);
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

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
}
