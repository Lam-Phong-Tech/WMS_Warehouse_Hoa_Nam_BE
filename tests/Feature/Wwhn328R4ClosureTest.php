<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\WarrantyCasePii;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-328 — vòng R4 của register dashboard (`GET /api/v1/reports/dashboard-quick`).
 *
 * Bổ sung đúng những khẳng định mà register R4 đòi QA2 phải chạy lại nhưng
 * `Wwhn328DashboardQuickContractTest` chưa hề khoá bằng test:
 *
 *  - DEV328-P1-01R4 "explicit WAREHOUSE_SCOPED vs warranty GLOBAL": ngoại lệ bảo hành
 *    mới chỉ được viết trong OpenAPI/matrix. Không có test nào chứng minh nó GLOBAL,
 *    nên một lần "sửa cho nhất quán" (thêm scope vào warranty) sẽ đi lọt.
 *  - DEV328-P1-06R4 "reconciliation": không có test nào đối chiếu
 *    `reconciliation_warning_count` với `/reports/inventory-balance` — hai màn hình có
 *    thể lệch định nghĩa MISMATCH mà cả hai vẫn xanh.
 *  - PAY-01 (đã sửa ở R3, chưa khoá): trần 50 dòng mẫu. Không có test vượt trần thì
 *    trần bị gỡ hoặc `count` bị nối lại vào `length` mà không ai biết.
 *  - DEV328-P1-04R4 "same contract": OpenAPI có thể mô tả một field set khác với field
 *    set runtime thực sự phát hành. Test buộc hai bên bằng nhau đúng từng khoá.
 *  - AC WWHN-328 "tái sử dụng, không tạo route mới": khoá số lượng route aggregate.
 *
 * File tách riêng khỏi Wwhn328DashboardQuickContractTest để không tranh chấp với các
 * phiên đang sửa song song trên cùng nhánh `m1`, và để fingerprint contract của
 * WWHN-328 (contract_test_case_count) giữ nguyên giá trị đã pin.
 */
class Wwhn328R4ClosureTest extends TestCase
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

        $this->warehouseA = $this->ensureActiveWarehouse('KHO-328R4-A', 'Kho A');
        // Cùng lý do với WWHN-328: partial unique uk_warehouses_single_active chỉ tồn
        // tại trên PostgreSQL, nên kho thứ hai phải INACTIVE để SQLite và PG cùng xanh.
        // Dashboard aggregate theo warehouse_id và không đọc warehouses.status.
        $this->warehouseB = Warehouse::create([
            'code' => 'KHO-328R4-B', 'name' => 'Kho B', 'status' => 'INACTIVE',
        ]);

        $this->product = Sku::create([
            'code' => 'SP-328R4', 'name' => 'SKU dashboard R4', 'sku_type' => 'PRODUCT',
            'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE',
        ]);

        // Lớp này đo DATA SCOPE và số liệu dashboard, không đo phân quyền theo màn.
        // Từ 07/09/2026 `report.view` không còn tự mở màn nào (BA tách theo từng màn),
        // nên phải cấp kèm mã của màn RPT-02 — reports/inventory-balance và
        // reports/inventory-reconciliation nay gác bằng nó.
        $reportPermissions = ['report.view', 'report.stock_reconcile.view'];

        $this->globalUser = $this->makeUser('r4-global', $reportPermissions, null, 'SUPER_ADMIN');
        $this->globalToken = JWTAuth::fromUser($this->globalUser);
        $this->scopedToken = JWTAuth::fromUser(
            $this->makeUser('r4-scoped', $reportPermissions, (string) $this->warehouseA->id, 'R4_SCOPED_A')
        );
    }

    // ── DEV328-P1-01R4: ngoại lệ warranty GLOBAL ─────────────

    public function test_warranty_kpi_stays_global_for_a_warehouse_scoped_user(): void
    {
        $this->seedTwoWarehouses();
        $this->warrantyCase('RECEIVED');
        $this->warrantyCase('RECEIVED');
        $this->warrantyCase('COMPLETED');

        $global = $this->get('/api/v1/reports/dashboard-quick', $this->auth($this->globalToken))
            ->assertOk()->json('data');
        $scoped = $this->get('/api/v1/reports/dashboard-quick', $this->auth($this->scopedToken))
            ->assertOk()->json('data');

        // Số liệu tồn/nhập/xuất PHẢI khác nhau — nếu bằng nhau thì data-scope đã hỏng
        // và phép so sánh warranty bên dưới trở nên vô nghĩa.
        $this->assertSame('GLOBAL', $global['data_scope']);
        $this->assertSame('WAREHOUSE_SCOPED', $scoped['data_scope']);
        $this->assertNotEquals($global['receipt_qty'], $scoped['receipt_qty']);

        // …còn warranty thì phải bằng nhau: ERD entity `warranty_case` không có
        // warehouse_id nên không có đường suy ra kho cho một ca bảo hành.
        $this->assertSame(
            $global['warranty_cases_by_status'],
            $scoped['warranty_cases_by_status'],
            'warranty_cases_by_status là ngoại lệ GLOBAL đã ký ở KPI matrix; cắt theo kho là sai contract.'
        );
        $this->assertSame(2, $global['warranty_cases_by_status']['RECEIVED']);
        $this->assertSame(1, $global['warranty_cases_by_status']['COMPLETED']);
    }

    // ── DEV328-P1-06R4: đối soát ─────────────────────────────

    public function test_reconciliation_warning_count_matches_the_inventory_balance_report(): void
    {
        $this->seedTwoWarehouses();
        // Balance không có movement đối ứng ⇒ variance ≠ 0 ⇒ MISMATCH.
        $this->balance($this->warehouseA, $this->product, 7);

        foreach ([$this->globalToken, $this->scopedToken] as $token) {
            $dashboard = $this->get('/api/v1/reports/dashboard-quick', $this->auth($token))
                ->assertOk()->json('data');
            $report = $this->get('/api/v1/reports/inventory-balance', $this->auth($token))
                ->assertOk()->json('data');

            $this->assertSame(
                $report['summary']['mismatch_count'],
                $dashboard['reconciliation_warning_count'],
                'Dashboard và RPT-INV-01 phải dùng chung một định nghĩa MISMATCH trong cùng data-scope.'
            );
            // Nhãn phạm vi của hai màn hình cũng phải trùng — chính sự lệch nhau giữa
            // dashboard và màn Tra cứu tồn là khiếm khuyết P0 mà WWHN-328 sinh ra để sửa.
            $this->assertSame($report['data_scope'], $dashboard['data_scope']);
            $this->assertSame($report['scope_warehouse_ids'], $dashboard['scope_warehouse_ids']);
        }
    }

    // ── PAY-01: trần mẫu vs tổng thật ────────────────────────

    public function test_warning_count_is_the_total_while_the_embedded_sample_is_capped_at_fifty(): void
    {
        // Cố ý KHÔNG gọi seedTwoWarehouses(): movement không có balance đối ứng cũng là
        // MISMATCH, nên trộn vào sẽ làm con số kỳ vọng phụ thuộc fixture nền thay vì
        // phụ thuộc đúng thứ đang đo (trần mẫu 50).
        for ($i = 0; $i < 55; $i++) {
            $sku = Sku::create([
                'code' => sprintf('SP-328R4-%02d', $i), 'name' => "SKU lệch {$i}",
                'sku_type' => 'PRODUCT', 'unit' => 'cai',
                'is_serial_tracked' => false, 'status' => 'ACTIVE',
            ]);
            $this->balance($this->warehouseA, $sku, 1 + $i);
        }

        $data = $this->get('/api/v1/reports/dashboard-quick', $this->auth($this->globalToken))
            ->assertOk()->json('data');

        $this->assertSame(55, $data['reconciliation_warning_count']);
        $this->assertCount(
            50,
            $data['reconciliation_warnings'],
            'Payload dashboard phải giữ trần 50 dòng mẫu; bỏ trần là để một lần lệch diện rộng thổi payload to bằng cả báo cáo tồn kho.'
        );
        $this->assertNotSame(
            count($data['reconciliation_warnings']),
            $data['reconciliation_warning_count'],
            'Khi vượt trần, length của mảng KHÔNG được bằng tổng — đây chính là cái bẫy FE từng đếm nhầm.'
        );
    }

    // ── DEV328-P1-04R4: OpenAPI == runtime ───────────────────

    public function test_openapi_operation_declares_exactly_the_runtime_field_set(): void
    {
        $this->seedTwoWarehouses();

        $runtime = array_keys(
            $this->get('/api/v1/reports/dashboard-quick', $this->auth($this->globalToken))
                ->assertOk()->json('data')
        );

        $spec = json_decode((string) file_get_contents(base_path('storage/api-docs/api-docs.json')), true);
        $declared = array_keys(
            $spec['paths']['/api/v1/reports/dashboard-quick']['get']['responses'][200]['content']['application/json']['schema']['properties']['data']['properties'] ?? []
        );

        sort($runtime);
        sort($declared);

        $this->assertSame(
            $declared,
            $runtime,
            'OpenAPI đã công bố cho FE/QA phải là đúng field set runtime phát hành, không phải một bản mô tả song song.'
        );
    }

    // ── AC WWHN-328: tái sử dụng, không tạo route mới ────────

    public function test_dashboard_aggregate_is_served_by_exactly_one_reused_route(): void
    {
        $aggregate = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'reports/dashboard'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame(
            ['GET|HEAD api/v1/reports/dashboard-quick'],
            $aggregate,
            'WWHN-328 chốt tái sử dụng endpoint sẵn có; thêm route dashboard mới là đổi contract chứ không phải bổ sung.'
        );
    }

    // ── helpers ──────────────────────────────────────────────

    /** Kho A: nhập 10, xuất 3. Kho B: nhập 4. */
    private function seedTwoWarehouses(): void
    {
        $this->movement('RECEIPT', $this->warehouseA, 10);
        $this->movement('ISSUE', $this->warehouseA, -3);
        $this->movement('RECEIPT', $this->warehouseB, 4);
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
            'email' => "{$username}@wwhn328r4.test",
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

    private function movement(string $type, Warehouse $warehouse, float $qty): void
    {
        $occurredAt = now()->toDateString().' 12:00:00';
        StockMovement::create([
            'movement_no' => 'MV-'.Str::random(8),
            'business_key' => 'test:328r4:'.Str::random(8),
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

    private function balance(Warehouse $warehouse, Sku $sku, float $availableQty): void
    {
        InventoryBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $sku->id,
            'stock_status' => 'AVAILABLE',
            'on_hand_qty' => $availableQty,
            'available_qty' => $availableQty,
            'reserved_qty' => 0,
            'version' => 1,
        ]);
    }

    /**
     * `warranty_case` là view tương thích trên PostgreSQL (ghi qua trigger
     * `hn_legacy_warranty_case_write` xuống bảng vật lý `warranty_cases`) và là bảng
     * vật lý trên SQLite — migration `2026_08_10_000791` đảo chiều số ít/số nhiều theo
     * driver. Ghi qua đúng tên mà dashboard đọc để đo cùng nguồn với runtime.
     *
     * Hình dạng bản ghi phải hợp lệ với CHECK `chk_erd_warranty_identification_shape`:
     * `IDENTIFIED` bắt buộc kèm `item_id`, nên fixture đi nhánh `TEMP_ONLY` (khỏi dựng
     * `item_instances`) với đủ `temporary_item_code` + `missing_code_reason` +
     * `manual_product_description`. `status` phải nằm trong vocabulary của
     * `chk_erd_warranty_cases_2` (RECEIVED/CHECKING/REPAIRING/COMPLETED/RETURNED/CANCELLED).
     * Cả hai CHECK chỉ tồn tại trên PostgreSQL, nên fixture sai hình dạng vẫn xanh trên
     * SQLite — đúng loại đỏ mà CI chạy SQLite không bắt được.
     */
    private function warrantyCase(string $status): void
    {
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => (string) Str::uuid(),
            'warranty_case_code' => 'WC-328R4-'.Str::random(6),
            'product_id' => $this->product->id,
            'item_id' => null,
            'identification_status' => 'TEMP_ONLY',
            'temporary_item_code' => 'TMP-328R4-'.Str::random(4),
            'missing_code_reason' => 'Fixture WWHN-328 R4: KPI chỉ đếm theo status nên không dựng item_instance.',
            'manual_product_description' => 'SKU dashboard R4',
            'customer_name' => 'KH 328R4',
            'customer_phone' => '0900000000',
            'sales_trace_status' => 'NOT_TRACEABLE',
            'status' => $status,
            'created_by' => $this->globalUser->user_id,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
