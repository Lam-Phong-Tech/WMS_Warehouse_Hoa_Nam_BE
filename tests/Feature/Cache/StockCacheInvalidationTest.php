<?php

namespace Tests\Feature\Cache;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/**
 * Mục 8: tồn kho là correctness-sensitive. Test đi qua HTTP thật END-TO-END (không
 * mock CacheService) để chứng minh: post-receipt thật (qua
 * CurrentBaselineStockService::mutateBalance — điểm chốt duy nhất ghi
 * inventory_balances) bump `stock:version` và GET /api/v1/inventory,
 * /api/v1/reports/inventory-balance, /api/v1/reports/dashboard-quick phản ánh dữ
 * liệu mới NGAY LẬP TỨC, không chờ TTL ngắn (stock_short/report/dashboard).
 */
class StockCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function authHeaders(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'stock-cache-'.Str::lower(Str::random(6)),
            'display_name' => 'Stock Cache Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function canonicalWarehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE']
        );
    }

    /** Nhận kho thật qua HTTP: create -> box -> scan -> post-receipt. */
    private function postComponentReceipt(array $headers, string $skuCode, int $qty): array
    {
        $warehouse = $this->canonicalWarehouse();
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => "Component {$skuCode}",
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        // Hợp đồng GĐ1 scan-driven: header-only + materialize dòng COMPONENT qua
        // /component-lines (không còn tạo phiếu kèm lines/qty_planned).
        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'Stock cache test fixture',
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');

        $ensure = $this->postJson("/api/v1/inbound-documents/{$documentId}/component-lines", [
            'sku_id' => (string) $sku->sku_id,
        ], $headers)->assertOk();
        $lineId = (string) $ensure->json('data.line_id');

        $register = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-'.Str::upper(Str::random(8)),
        ], $headers);
        $boxId = (string) $register->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => $qty,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'receipt-'.Str::random(12),
        ])->assertOk();

        return ['warehouse_id' => (string) $warehouse->id, 'sku_id' => (string) $sku->sku_id];
    }

    public function test_inventory_balance_list_reflects_post_receipt_immediately(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->canonicalWarehouse();

        // Warm cache TRƯỚC khi có tồn kho — endpoint list toàn bộ (không filter theo
        // SKU cụ thể vì SKU chưa tồn tại lúc này).
        $before = $this->getJson('/api/v1/inventory?warehouse_id='.$warehouse->id, $headers)->assertOk();
        $beforeTotal = (int) $before->json('meta.total');

        $fixture = $this->postComponentReceipt($headers, 'STOCK-CACHE-INV-01', 7);

        $after = $this->getJson('/api/v1/inventory?warehouse_id='.$warehouse->id, $headers)->assertOk();
        $afterTotal = (int) $after->json('meta.total');
        $afterRows = collect($after->json('data'));
        $row = $afterRows->firstWhere('sku_id', $fixture['sku_id']);

        $this->assertGreaterThan($beforeTotal, $afterTotal, 'Danh sách tồn kho phải phản ánh dòng balance mới ngay sau post-receipt.');
        $this->assertNotNull($row, 'Balance của SKU vừa nhập phải xuất hiện ngay, không đợi TTL stock_short hết hạn.');
        $this->assertSame(7.0, (float) $row['on_hand_qty']);
    }

    public function test_dashboard_quick_reflects_post_receipt_immediately(): void
    {
        $headers = $this->authHeaders();

        $before = $this->getJson('/api/v1/reports/dashboard-quick', $headers)->assertOk();
        $beforeAvailable = (float) $before->json('data.available_qty');

        $this->postComponentReceipt($headers, 'STOCK-CACHE-DASH-01', 4);

        $after = $this->getJson('/api/v1/reports/dashboard-quick', $headers)->assertOk();
        $afterAvailable = (float) $after->json('data.available_qty');

        $this->assertGreaterThanOrEqual(
            $beforeAvailable + 4,
            $afterAvailable,
            'dashboard-quick available_qty phải cộng dồn ngay sau post-receipt, không được trả cache cũ.'
        );
    }

    public function test_report_inventory_balance_reflects_post_receipt_immediately(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->canonicalWarehouse();

        $this->getJson('/api/v1/reports/inventory-balance?warehouse_id='.$warehouse->id, $headers)->assertOk();

        $fixture = $this->postComponentReceipt($headers, 'STOCK-CACHE-RPT-01', 9);

        $after = $this->getJson('/api/v1/reports/inventory-balance?warehouse_id='.$warehouse->id, $headers)->assertOk();
        $row = collect($after->json('data.rows'))->firstWhere('product_id', $fixture['sku_id']);

        $this->assertNotNull($row, 'Report inventory-balance phải thấy SKU vừa post ngay lập tức.');
        $this->assertSame(9.0, (float) $row['on_hand_qty']);
    }

    public function test_inventory_balance_warehouse_filter_does_not_leak_across_filters(): void
    {
        $headers = $this->authHeaders();
        $warehouseA = $this->canonicalWarehouse();

        $fixture = $this->postComponentReceipt($headers, 'STOCK-CACHE-FILTER-01', 3);

        $matching = $this->getJson('/api/v1/inventory?warehouse_id='.$warehouseA->id.'&sku_id='.$fixture['sku_id'], $headers)->assertOk();
        $nonMatching = $this->getJson('/api/v1/inventory?warehouse_id='.$warehouseA->id.'&sku_id='.((string) Str::uuid()), $headers)->assertOk();

        $this->assertGreaterThan(0, (int) $matching->json('meta.total'));
        $this->assertSame(0, (int) $nonMatching->json('meta.total'), 'Filter theo sku_id khác nhau không được đụng cache key.');
    }
}
