<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\ContainerItem;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * RPT-INV-01 · SRS §7.5 — badge "Trạng thái đối soát" tổng hợp 4 check, còn cột
 * "Chênh lệch" chỉ đo Ledger↔Balance. Test khoá hai điều:
 *
 * 1. Check sổ phụ serial CHỈ áp cho SKU quản lý theo serial. Trước đây điều kiện
 *    là `serial_qty > 0` nên một SKU quản lý theo SỐ LƯỢNG lỡ có 1 item_instance
 *    là bị báo lệch (available 25 vs serial 1) dù sổ cái khớp tồn kho — người
 *    dùng thấy "Chênh lệch 0 nhưng Có chênh lệch" và không giải thích được.
 * 2. Khi lệch THẬT ở sổ phụ, response phải nói lệch ở check nào (mismatch_checks)
 *    để FE hiển thị, thay vì chỉ trả một badge đỏ trống nghĩa.
 */
class InventoryReconciliationSubledgerScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_quantity_tracked_sku_is_not_judged_by_serial_subledger(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        $warehouse = $this->ensureActiveWarehouse('KHO-SUBLEDGER');
        $sku = $this->createSku('SP-QTY-01', serialTracked: false);

        // Sổ cái khớp tồn kho tuyệt đối: 25 = 25.
        $this->seedBalanceAndLedger($warehouse, $sku, 25, $user);
        // Một item_instance lẻ (dữ liệu rơi vãi) KHÔNG được biến SKU quản lý theo
        // số lượng thành SKU có sổ phụ serial.
        $this->seedItemInstances($sku, 1);

        $response = $this->getJson(
            "/api/v1/reports/inventory-reconciliation?warehouse_id={$warehouse->id}&product_id={$sku->sku_id}&stock_status=AVAILABLE",
            ['Authorization' => "Bearer {$token}"]
        )->assertOk();

        $response
            ->assertJsonPath('data.rows.0.variance', 0)
            ->assertJsonPath('data.rows.0.reconciliation_status', 'MATCH')
            ->assertJsonPath('data.rows.0.serial_subledger_status', 'NOT_APPLICABLE')
            ->assertJsonPath('data.rows.0.reconciliation_overall', 'MATCH')
            ->assertJsonPath('data.rows.0.mismatch_checks', [])
            ->assertJsonPath('data.summary.serial_subledger_mismatch_count', 0)
            ->assertJsonPath('data.summary.overall_status', 'PASS');
    }

    public function test_serial_tracked_sku_reports_which_check_diverged(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        $warehouse = $this->ensureActiveWarehouse('KHO-SUBLEDGER');
        $sku = $this->createSku('SP-SERIAL-01', serialTracked: true);

        // Sổ cái vẫn khớp tồn kho (variance = 0)…
        $this->seedBalanceAndLedger($warehouse, $sku, 1, $user);
        // …nhưng sổ phụ serial có 3 cái IN_STOCK: lệch THẬT, phải cảnh báo.
        $this->seedItemInstances($sku, 3);

        $response = $this->getJson(
            "/api/v1/reports/inventory-reconciliation?warehouse_id={$warehouse->id}&product_id={$sku->sku_id}&stock_status=AVAILABLE",
            ['Authorization' => "Bearer {$token}"]
        )->assertOk();

        $response
            ->assertJsonPath('data.rows.0.variance', 0)
            // Chênh lệch bằng 0 mà vẫn đỏ — và response nói rõ đỏ vì đâu.
            ->assertJsonPath('data.rows.0.reconciliation_status', 'MATCH')
            ->assertJsonPath('data.rows.0.serial_subledger_status', 'MISMATCH')
            ->assertJsonPath('data.rows.0.reconciliation_overall', 'MISMATCH')
            ->assertJsonPath('data.rows.0.mismatch_checks', ['serial_subledger'])
            ->assertJsonPath('data.summary.mismatch_count', 0)
            ->assertJsonPath('data.summary.serial_subledger_mismatch_count', 1)
            ->assertJsonPath('data.summary.overall_status', 'FAIL');
    }

    /**
     * Sổ phụ container đếm theo SKU trên toàn hệ thống (container_items không có
     * warehouse_id lẫn stock_status), nên vế đối chiếu phải là TỔNG on_hand của SKU
     * đọc thẳng từ inventory_balances — KHÔNG phải tổng của các dòng đang hiển thị.
     *
     * Nếu cộng từ dòng đã lọc: xem `stock_status=AVAILABLE` sẽ bỏ mất bucket
     * DEFECTIVE, tổng on_hand tụt từ 5 xuống 2, và 4 cái trong thùng bỗng "vượt tồn"
     * → cùng một SKU đổi kết luận đối soát chỉ vì người dùng bấm khác bộ lọc.
     */
    public function test_subledger_verdict_does_not_change_with_the_active_filter(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        $warehouse = $this->ensureActiveWarehouse('KHO-SUBLEDGER');
        $sku = $this->createSku('SP-BOX-01', serialTracked: false);

        $this->seedBalanceAndLedger($warehouse, $sku, 2, $user, 'AVAILABLE');
        $this->seedBalanceAndLedger($warehouse, $sku, 3, $user, 'DEFECTIVE');
        // 4 <= tổng on_hand 5 → thùng không tạo thêm bucket tồn, phải là MATCH.
        $this->seedContainerItems($sku, 4);

        $headers = ['Authorization' => "Bearer {$token}"];
        $base = "/api/v1/reports/inventory-reconciliation?warehouse_id={$warehouse->id}&product_id={$sku->sku_id}";

        foreach ([$base, "{$base}&stock_status=AVAILABLE"] as $url) {
            $this->getJson($url, $headers)
                ->assertOk()
                ->assertJsonPath('data.rows.0.quantity_subledger_status', 'MATCH')
                ->assertJsonPath('data.rows.0.container_integrity_status', 'MATCH')
                ->assertJsonPath('data.rows.0.reconciliation_overall', 'MATCH')
                ->assertJsonPath('data.summary.overall_status', 'PASS');
        }
    }

    /** @return array{0: User, 1: string} */
    private function createSuperAdmin(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Quản trị hệ thống',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'subledger-admin-'.Str::random(6),
            'display_name' => 'Subledger Admin',
            'email' => 'subledger-'.Str::random(6).'@wms.test',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return [$user, JWTAuth::fromUser($user)];
    }

    private function createSku(string $code, bool $serialTracked): Sku
    {
        return Sku::create([
            'code' => $code,
            'name' => $code,
            'sku_type' => $serialTracked ? 'PRODUCT' : 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => $serialTracked,
            'status' => 'ACTIVE',
        ]);
    }

    private function seedBalanceAndLedger(
        Warehouse $warehouse,
        Sku $sku,
        float $qty,
        User $actor,
        string $stockStatus = 'AVAILABLE',
    ): void {
        InventoryBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $sku->sku_id,
            'stock_status' => $stockStatus,
            'on_hand_qty' => $qty,
            'available_qty' => $stockStatus === 'AVAILABLE' ? $qty : 0,
            'reserved_qty' => 0,
            'version' => 1,
        ]);

        StockMovement::create([
            'movement_no' => 'MV-'.Str::random(12),
            'business_key' => 'test:subledger:'.Str::random(12),
            'movement_type' => StockMovement::RECEIPT_POSTED,
            'warehouse_id' => $warehouse->id,
            'product_id' => $sku->sku_id,
            'stock_status' => $stockStatus,
            'qty_before' => 0,
            'qty_delta' => $qty,
            'qty_after' => $qty,
            'quantity' => $qty,
            'created_by' => $actor->user_id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function seedContainerItems(Sku $sku, float $quantity): void
    {
        $container = Container::create([
            'container_code' => 'CT-'.Str::random(8),
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        ContainerItem::create([
            'container_id' => $container->container_id,
            'sku_id' => $sku->sku_id,
            'quantity' => $quantity,
            'status' => 'ACTIVE',
        ]);
    }

    private function seedItemInstances(Sku $sku, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ItemInstance::create([
                'sku_id' => $sku->sku_id,
                'serial_number' => "{$sku->sku_code}-SN-{$i}",
                'item_status' => 'IN_STOCK',
                'item_condition' => 'GOOD',
                'version' => 1,
            ]);
        }
    }
}
