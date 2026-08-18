<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-254 — bằng chứng độc lập cho báo cáo tích hợp (DEV254-P1-03).
 *
 * Bổ sung đúng các checkpoint QA1 mà bộ test Flow 06 hiện hành CHƯA phủ:
 *
 *  1. 200/403 theo data-scope kho — không chỉ theo permission. WWHN-328 mới áp scope
 *     cho `/reports/dashboard-quick`; toàn bộ `/reports/*` còn lại đọc và export dữ
 *     liệu của mọi kho, vi phạm AC-SRS-032 và BR-EXP-01..05.
 *  2. Export dùng ĐÚNG scope của màn hình (BR-EXP-01).
 *  3. Biên timezone Asia/Ho_Chi_Minh của period (§13.10, cột "Timezone").
 *  4. Cache không được dùng chung giữa hai người dùng khác data-scope.
 *  5. Đại lý không phải bucket tồn kho — RPT-RECIPIENT-01 "Agency is receiver
 *     dimension only; never warehouse/balance owner".
 *
 * Test này KHÔNG lặp lại phần đã có ở ReportFlow06IntegrationTest (công thức XNT,
 * filter, phân trang, export XLSX) và ReportFlow06DocsAlignmentTest (as_of, PII,
 * tên file export, import-match tầng dòng).
 */
class Wwhn254ReportEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private Sku $product;

    private User $globalUser;

    private string $globalToken;

    private string $scopedToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouseA = $this->ensureActiveWarehouse('KHO-254-A', 'Kho A');
        // Chỉ 01 kho được ACTIVE (uk_warehouses_single_active trên PostgreSQL). Kho B
        // ở đây chỉ đóng vai "kho ngoài data-scope" nên INACTIVE là đủ và không vỡ
        // unique guard — cùng lý do đã ghi ở Wwhn328DashboardQuickContractTest.
        $this->warehouseB = Warehouse::create([
            'code' => 'KHO-254-B', 'name' => 'Kho B', 'status' => 'INACTIVE',
        ]);
        $this->product = Sku::create([
            'code' => 'SP-254', 'name' => 'SKU báo cáo tích hợp', 'sku_type' => 'PRODUCT',
            'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE',
        ]);

        $this->globalUser = $this->makeUser('rpt254-global', ['report.view', 'report.export'], null, 'SUPER_ADMIN');
        $this->globalToken = JWTAuth::fromUser($this->globalUser);

        $scoped = $this->makeUser('rpt254-scoped', ['report.view', 'report.export'], (string) $this->warehouseA->id);
        $this->scopedToken = JWTAuth::fromUser($scoped);
    }

    // ── 1. Data-scope: 200 nhưng chỉ thấy kho trong scope ─────────────────────

    public function test_inventory_balance_is_cut_to_caller_warehouse_scope(): void
    {
        $this->seedBothWarehouses();

        $this->get('/api/v1/reports/inventory-balance', $this->auth($this->globalToken))
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'GLOBAL')
            ->assertJsonPath('data.summary.total_rows', 2);

        $scoped = $this->get('/api/v1/reports/inventory-balance', $this->auth($this->scopedToken))
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'WAREHOUSE_SCOPED')
            ->assertJsonPath('data.summary.total_rows', 1);

        $this->assertSame(
            [(string) $this->warehouseA->id],
            collect($scoped->json('data.rows'))->pluck('warehouse_id')->unique()->values()->all(),
            'RPT-INV-01 không được trả dòng của kho ngoài data-scope.'
        );
    }

    public function test_xnt_and_movement_trace_are_cut_to_caller_warehouse_scope(): void
    {
        $this->seedBothWarehouses();
        $today = now()->toDateString();

        // XNT: kho A nhận 10, kho B nhận 4. Người bị giới hạn chỉ được thấy 10.
        $global = $this->get("/api/v1/reports/inventory-xnt?from_date={$today}&to_date={$today}", $this->auth($this->globalToken))
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'GLOBAL');
        $this->assertEqualsWithDelta(14.0, collect($global->json('data.lines'))->sum('receipt'), 0.001);

        $scoped = $this->get("/api/v1/reports/inventory-xnt?from_date={$today}&to_date={$today}", $this->auth($this->scopedToken))
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'WAREHOUSE_SCOPED');
        $this->assertEqualsWithDelta(10.0, collect($scoped->json('data.lines'))->sum('receipt'), 0.001);

        $trace = $this->get("/api/v1/reports/movement-trace?from_date={$today}&to_date={$today}", $this->auth($this->scopedToken))
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'WAREHOUSE_SCOPED');
        $this->assertSame(
            [(string) $this->warehouseA->id],
            collect($trace->json('data.rows'))->pluck('warehouse_id')->unique()->values()->all(),
            'RPT-MOV-01 không được lộ movement của kho ngoài data-scope.'
        );
    }

    public function test_document_and_recipient_reports_declare_effective_scope(): void
    {
        $today = now()->toDateString();

        foreach (['document-history', 'outbound-by-agency'] as $report) {
            $this->get("/api/v1/reports/{$report}?from_date={$today}&to_date={$today}", $this->auth($this->scopedToken))
                ->assertOk()
                ->assertJsonPath('data.data_scope', 'WAREHOUSE_SCOPED')
                ->assertJsonPath('data.scope_warehouse_ids', [(string) $this->warehouseA->id]);
        }
    }

    // ── 2. 403 khi xin kho ngoài scope ────────────────────────────────────────

    public function test_requesting_a_warehouse_outside_scope_is_denied_on_every_report(): void
    {
        $outside = (string) $this->warehouseB->id;
        $today = now()->toDateString();

        $cases = [
            "/api/v1/reports/inventory-balance?warehouse_id={$outside}",
            "/api/v1/reports/inventory-xnt?from_date={$today}&to_date={$today}&warehouse_id={$outside}",
            "/api/v1/reports/movement-trace?from_date={$today}&to_date={$today}&warehouse_id={$outside}",
            "/api/v1/reports/dashboard-quick?warehouse_id={$outside}",
        ];

        foreach ($cases as $url) {
            $this->get($url, $this->auth($this->scopedToken))
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'ACCESS_DENIED');
        }

        // Người dùng GLOBAL vẫn xem được đúng kho đó — 403 ở trên là data-scope,
        // không phải kho hỏng.
        $this->get("/api/v1/reports/inventory-balance?warehouse_id={$outside}", $this->auth($this->globalToken))
            ->assertOk();
    }

    // ── 3. Export dùng cùng scope với màn hình (BR-EXP-01) ────────────────────

    public function test_export_applies_the_same_warehouse_scope_as_the_screen(): void
    {
        $this->seedBothWarehouses();
        $outside = (string) $this->warehouseB->id;

        $this->get("/api/v1/reports/inventory-balance/export-excel?warehouse_id={$outside}", $this->auth($this->scopedToken))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ACCESS_DENIED');

        $this->get('/api/v1/reports/inventory-balance/export-excel', $this->auth($this->scopedToken))
            ->assertOk();
    }

    // ── 4. Cache không được dùng chung giữa hai data-scope ────────────────────

    public function test_cache_entry_is_not_shared_between_two_data_scopes(): void
    {
        $this->seedBothWarehouses();

        // GLOBAL gọi trước để nạp cache, rồi SCOPED gọi ngay sau với cùng filter.
        $this->get('/api/v1/reports/inventory-balance', $this->auth($this->globalToken))
            ->assertOk()
            ->assertJsonPath('data.summary.total_rows', 2);

        $this->get('/api/v1/reports/inventory-balance', $this->auth($this->scopedToken))
            ->assertOk()
            ->assertJsonPath('data.summary.total_rows', 1);
    }

    // ── 5. Biên timezone Asia/Ho_Chi_Minh của period ──────────────────────────

    public function test_period_boundary_is_inclusive_in_asia_ho_chi_minh(): void
    {
        $day = now()->toDateString();
        $nextDay = now()->addDay()->toDateString();

        // 23:59:59 của to_date phải nằm TRONG kỳ; 00:00:00 hôm sau phải nằm NGOÀI.
        $this->movement('RECEIPT', $this->warehouseA, 7, "{$day} 23:59:59");
        $this->movement('RECEIPT', $this->warehouseA, 99, "{$nextDay} 00:00:00");

        $response = $this->get("/api/v1/reports/inventory-xnt?from_date={$day}&to_date={$day}", $this->auth($this->globalToken))
            ->assertOk();

        $this->assertEqualsWithDelta(
            7.0,
            collect($response->json('data.lines'))->sum('receipt'),
            0.001,
            'Biên kỳ phải inclusive tới 23:59:59 và không được nuốt ngày kế tiếp.'
        );
    }

    // ── 6. Đại lý không phải bucket tồn kho (RPT-RECIPIENT-01) ────────────────

    public function test_agency_is_never_an_inventory_bucket(): void
    {
        $this->seedBothWarehouses();

        $balance = $this->get('/api/v1/reports/inventory-balance', $this->auth($this->globalToken))
            ->assertOk();

        foreach ((array) $balance->json('data.rows') as $row) {
            $this->assertArrayNotHasKey('agency_id', $row, 'RPT-INV-01 không được có chiều đại lý.');
            $this->assertNotSame('', (string) ($row['warehouse_id'] ?? ''), 'Mọi dòng tồn phải thuộc về một kho.');
        }

        $today = now()->toDateString();
        $recipient = $this->get("/api/v1/reports/outbound-by-agency?from_date={$today}&to_date={$today}", $this->auth($this->globalToken))
            ->assertOk();

        foreach ((array) $recipient->json('data.by_agency') as $row) {
            foreach (['on_hand_qty', 'available_qty', 'closing', 'balance'] as $stockField) {
                $this->assertArrayNotHasKey(
                    $stockField,
                    $row,
                    "RPT-RECIPIENT-01: đại lý là chiều người nhận, không được mang số tồn [{$stockField}]."
                );
            }
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function seedBothWarehouses(): void
    {
        $this->movement('RECEIPT', $this->warehouseA, 10);
        $this->balance($this->warehouseA, 10);

        $this->movement('RECEIPT', $this->warehouseB, 4);
        $this->balance($this->warehouseB, 4);
    }

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    /** @param list<string> $permissions */
    private function makeUser(string $username, array $permissions, ?string $warehouseScope, ?string $roleCode = null): User
    {
        $role = Role::create([
            'role_code' => $roleCode ?? strtoupper(str_replace('-', '_', $username)),
            'role_name' => $username,
            'scope_type' => $warehouseScope === null ? 'SYSTEM' : 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
            );
            $role->permissions()->attach($permission->permission_id, [
                'role_permission_id' => (string) Str::uuid(),
            ]);
        }

        $user = User::create([
            'username' => $username,
            'display_name' => $username,
            'email' => "{$username}@wwhn254.test",
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(),
            'scope_ref' => $warehouseScope,
        ]);

        return $user;
    }

    private function movement(string $type, Warehouse $warehouse, float $qty, ?string $occurredAt = null): void
    {
        $occurredAt ??= now()->toDateString().' 12:00:00';
        StockMovement::create([
            'movement_no' => 'MV-'.Str::random(8),
            'business_key' => 'test:254:'.Str::random(8),
            'movement_type' => $type,
            'warehouse_id' => $warehouse->id,
            'product_id' => $this->product->id,
            'stock_status' => 'AVAILABLE',
            'qty_before' => 0,
            'qty_delta' => $qty,
            'qty_after' => abs($qty),
            'quantity' => abs($qty),
            'created_by' => $this->globalUser->user_id,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }

    private function balance(Warehouse $warehouse, float $availableQty): void
    {
        InventoryBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $this->product->id,
            'stock_status' => 'AVAILABLE',
            'on_hand_qty' => $availableQty,
            'available_qty' => $availableQty,
            'reserved_qty' => 0,
            'version' => 1,
        ]);
    }
}
