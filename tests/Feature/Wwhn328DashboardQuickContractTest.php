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
 * WWHN-328 — khoá contract của GET /api/v1/reports/dashboard-quick.
 *
 * Đóng đúng các mục trong P0/P1/P2 EXACT FIX ACTION REGISTER:
 *  - DEV328-P1-01: data-scope là GLOBAL hay WAREHOUSE-SCOPED, và expected totals.
 *  - DEV328-P1-04: field set, timezone/cutoff, error contract 401/403/422.
 *
 * Trọng tâm hồi quy: dashboard từng trả tổng toàn hệ thống cho cả người dùng bị
 * giới hạn kho, trong khi /inventory/balances và /stock-movements của chính người
 * đó đã bị EnforceInventoryWarehouseScope cắt theo scope_ref — hai màn hình mâu
 * thuẫn nhau và số liệu kho ngoài phạm vi bị lộ qua KPI + reconciliation_warnings.
 */
class Wwhn328DashboardQuickContractTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private Sku $product;

    private string $globalToken;

    private User $globalUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouseA = $this->ensureActiveWarehouse('KHO-328-A', 'Kho A');
        $this->warehouseB = Warehouse::create([
            'code' => 'KHO-328-B', 'name' => 'Kho B', 'status' => 'ACTIVE',
        ]);
        $this->product = Sku::create([
            'code' => 'SP-328', 'name' => 'SKU dashboard', 'sku_type' => 'PRODUCT',
            'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE',
        ]);

        $this->globalUser = $this->makeUser('dash-global', ['report.view'], null, 'SUPER_ADMIN');
        $this->globalToken = JWTAuth::fromUser($this->globalUser);
    }

    // ── data-scope ───────────────────────────────────────────

    public function test_unscoped_user_gets_global_aggregate(): void
    {
        $this->seedTwoWarehouses();

        $this->get('/api/v1/reports/dashboard-quick', $this->auth($this->globalToken))
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'GLOBAL')
            ->assertJsonPath('data.scope_warehouse_ids', null)
            // Kho A 10 + kho B 4 = 14.
            ->assertJsonPath('data.receipt_qty', 14)
            ->assertJsonPath('data.issue_qty', 3)
            ->assertJsonPath('data.available_qty', 21);
    }

    public function test_warehouse_scoped_user_only_sees_own_warehouse_totals(): void
    {
        $this->seedTwoWarehouses();

        $scoped = $this->makeUser('dash-scoped', ['report.view'], $this->warehouseA->id);

        $this->get('/api/v1/reports/dashboard-quick', $this->auth(JWTAuth::fromUser($scoped)))
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'WAREHOUSE_SCOPED')
            ->assertJsonPath('data.scope_warehouse_ids', [$this->warehouseA->id])
            // Chỉ kho A: nhập 10, xuất 3, tồn 12. Số của kho B không được lọt qua.
            ->assertJsonPath('data.receipt_qty', 10)
            ->assertJsonPath('data.issue_qty', 3)
            ->assertJsonPath('data.available_qty', 12);
    }

    public function test_reconciliation_warnings_are_cut_by_data_scope(): void
    {
        // Chỉ kho B lệch ledger/balance ⇒ người dùng scope kho A không được thấy dòng nào.
        $this->movement('RECEIPT', $this->warehouseB, 9);
        $this->balance($this->warehouseB, 5);
        $this->movement('RECEIPT', $this->warehouseA, 6);
        $this->balance($this->warehouseA, 6);

        $global = $this->get('/api/v1/reports/dashboard-quick', $this->auth($this->globalToken))->assertOk();
        $this->assertSame(1, $global->json('data.reconciliation_warning_count'));
        $this->assertCount(1, $global->json('data.reconciliation_warnings'));

        $scoped = $this->makeUser('dash-scoped-recon', ['report.view'], $this->warehouseA->id);
        $this->get('/api/v1/reports/dashboard-quick', $this->auth(JWTAuth::fromUser($scoped)))
            ->assertOk()
            ->assertJsonPath('data.reconciliation_warning_count', 0)
            ->assertJsonPath('data.reconciliation_warnings', []);
    }

    public function test_cache_is_isolated_between_data_scopes(): void
    {
        $this->seedTwoWarehouses();
        $scoped = $this->makeUser('dash-scope-cache', ['report.view'], $this->warehouseA->id);

        // Người dùng GLOBAL đi trước để nạp cache; nếu cache key không mang scope thì
        // lượt gọi thứ hai sẽ nhận lại đúng payload toàn hệ thống của lượt đầu.
        $this->get('/api/v1/reports/dashboard-quick', $this->auth($this->globalToken))
            ->assertOk()
            ->assertJsonPath('data.available_qty', 21);

        $this->get('/api/v1/reports/dashboard-quick', $this->auth(JWTAuth::fromUser($scoped)))
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'WAREHOUSE_SCOPED')
            ->assertJsonPath('data.available_qty', 12);

        // Và chiều ngược lại: scope không được "dính" vào lượt gọi GLOBAL kế tiếp.
        $this->get('/api/v1/reports/dashboard-quick', $this->auth($this->globalToken))
            ->assertOk()
            ->assertJsonPath('data.available_qty', 21);
    }

    public function test_warehouse_id_filter_narrows_global_user(): void
    {
        $this->seedTwoWarehouses();

        $this->get("/api/v1/reports/dashboard-quick?warehouse_id={$this->warehouseB->id}", $this->auth($this->globalToken))
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'WAREHOUSE_SCOPED')
            ->assertJsonPath('data.scope_warehouse_ids', [$this->warehouseB->id])
            ->assertJsonPath('data.receipt_qty', 4)
            ->assertJsonPath('data.available_qty', 9);
    }

    public function test_warehouse_id_outside_scope_is_denied(): void
    {
        $this->seedTwoWarehouses();
        $scoped = $this->makeUser('dash-scope-denied', ['report.view'], $this->warehouseA->id);

        $this->get("/api/v1/reports/dashboard-quick?warehouse_id={$this->warehouseB->id}", $this->auth(JWTAuth::fromUser($scoped)))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ACCESS_DENIED');
    }

    public function test_warehouse_id_denial_is_not_swallowed_by_a_warm_cache(): void
    {
        $this->seedTwoWarehouses();
        $scoped = $this->makeUser('dash-scope-cache-403', ['report.view'], $this->warehouseA->id);

        // Nạp cache cho đúng cặp (period, kho B) bằng người dùng GLOBAL trước.
        $this->get("/api/v1/reports/dashboard-quick?warehouse_id={$this->warehouseB->id}", $this->auth($this->globalToken))
            ->assertOk();

        $this->get("/api/v1/reports/dashboard-quick?warehouse_id={$this->warehouseB->id}", $this->auth(JWTAuth::fromUser($scoped)))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ACCESS_DENIED');
    }

    // ── field set / công thức ────────────────────────────────

    public function test_field_set_is_exactly_the_signed_off_contract(): void
    {
        $this->seedTwoWarehouses();

        $data = $this->get('/api/v1/reports/dashboard-quick', $this->auth($this->globalToken))
            ->assertOk()
            ->json('data');

        $this->assertSame([
            'period',
            'data_scope',
            'scope_warehouse_ids',
            'available_qty',
            'receipt_qty',
            'issue_qty',
            'component_issue_qty',
            'reversal_net',
            'warranty_cases_by_status',
            'reconciliation_warning_count',
            'reconciliation_warnings',
        ], array_keys($data), 'Field set của dashboard-quick đã được BA/Tech ký; thêm/bớt field phải qua sign-off lại.');
    }

    public function test_reversal_keeps_its_sign_and_opening_is_excluded(): void
    {
        $this->movement('REVERSAL', $this->warehouseA, -5);
        $this->movement('COMPONENT_ISSUE', $this->warehouseA, -2);
        // OPENING không nằm trong 4 KPI luồng và không được cộng vào bất kỳ ô nào.
        $this->movement('OPENING', $this->warehouseA, 999);

        $this->get('/api/v1/reports/dashboard-quick', $this->auth($this->globalToken))
            ->assertOk()
            ->assertJsonPath('data.reversal_net', -5)
            ->assertJsonPath('data.component_issue_qty', 2)
            ->assertJsonPath('data.receipt_qty', 0);
    }

    // ── timezone / cutoff ────────────────────────────────────

    public function test_period_boundaries_are_inclusive_on_both_ends(): void
    {
        $this->movement('RECEIPT', $this->warehouseA, 3, '2026-05-10 00:00:00');
        $this->movement('RECEIPT', $this->warehouseA, 4, '2026-05-12 23:59:59');
        $this->movement('RECEIPT', $this->warehouseA, 100, '2026-05-13 00:00:00');

        $this->get('/api/v1/reports/dashboard-quick?from_date=2026-05-10&to_date=2026-05-12', $this->auth($this->globalToken))
            ->assertOk()
            ->assertJsonPath('data.period.from_date', '2026-05-10')
            ->assertJsonPath('data.period.to_date', '2026-05-12')
            // 00:00:00 của from_date và 23:59:59 của to_date đều nằm trong kỳ; 100 thì không.
            ->assertJsonPath('data.receipt_qty', 7);
    }

    public function test_datetime_input_is_normalised_to_a_date_before_querying(): void
    {
        $this->movement('RECEIPT', $this->warehouseA, 8, '2026-05-11 12:00:00');

        // Rule 'date' cho qua chuỗi có giờ; nếu không chuẩn hoá thì chuỗi ghép ra
        // "2026-05-11T08:00:00 00:00:00" và KPI lặng lẽ về 0 thay vì báo lỗi.
        $this->get('/api/v1/reports/dashboard-quick?from_date=2026-05-11T08:00:00&to_date=2026-05-11T09:00:00', $this->auth($this->globalToken))
            ->assertOk()
            ->assertJsonPath('data.period.from_date', '2026-05-11')
            ->assertJsonPath('data.period.to_date', '2026-05-11')
            ->assertJsonPath('data.receipt_qty', 8);
    }

    public function test_default_period_is_the_last_thirty_days_inclusive(): void
    {
        $data = $this->get('/api/v1/reports/dashboard-quick', $this->auth($this->globalToken))
            ->assertOk()
            ->json('data.period');

        $this->assertSame(now()->subDays(30)->toDateString(), $data['from_date']);
        $this->assertSame(now()->toDateString(), $data['to_date']);
    }

    // ── error contract ───────────────────────────────────────

    public function test_reversed_period_via_alias_is_rejected(): void
    {
        // Alias from/to không đi qua rule 'after_or_equal' nên phải được chặn riêng.
        $this->get('/api/v1/reports/dashboard-quick?from=2026-05-20&to=2026-05-01', $this->auth($this->globalToken))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BUSINESS_RULE_ERROR');
    }

    public function test_from_date_alone_cannot_produce_a_reversed_period(): void
    {
        $future = now()->addYear()->toDateString();

        $this->get("/api/v1/reports/dashboard-quick?from_date={$future}", $this->auth($this->globalToken))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BUSINESS_RULE_ERROR');
    }

    public function test_invalid_warehouse_id_is_a_validation_error_not_a_silent_global_read(): void
    {
        $this->get('/api/v1/reports/dashboard-quick?warehouse_id=not-a-uuid', $this->auth($this->globalToken))
            ->assertStatus(422);
    }

    public function test_requires_authentication(): void
    {
        $this->get('/api/v1/reports/dashboard-quick', ['Accept' => 'application/json'])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_requires_report_view_permission(): void
    {
        $noPermission = $this->makeUser('dash-no-perm', [], null);

        $this->get('/api/v1/reports/dashboard-quick', $this->auth(JWTAuth::fromUser($noPermission)))
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ACCESS_DENIED');
    }

    // ── helpers ──────────────────────────────────────────────

    /** Kho A: nhập 10, xuất 3, tồn 12. Kho B: nhập 4, tồn 9. */
    private function seedTwoWarehouses(): void
    {
        $this->movement('RECEIPT', $this->warehouseA, 10);
        $this->movement('ISSUE', $this->warehouseA, -3);
        $this->balance($this->warehouseA, 12);

        $this->movement('RECEIPT', $this->warehouseB, 4);
        $this->balance($this->warehouseB, 9);
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
            'email' => "{$username}@wwhn328.test",
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
            'business_key' => 'test:328:'.Str::random(8),
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
