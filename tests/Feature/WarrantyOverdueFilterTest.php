<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\WarrantyCasePii;
use Modules\Warehouse\Support\WarrantyOverdue;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class WarrantyOverdueFilterTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 01:30 on 6 November in Vietnam, while UTC is still 5 November.
        Carbon::setTestNow(Carbon::parse('2026-11-05 18:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_unfinished_cases_before_today_are_overdue(): void
    {
        $headers = $this->viewerHeaders();
        foreach (['RECEIVED', 'CHECKING', 'REPAIRING'] as $status) {
            $this->makeCase('OVERDUE-'.$status, '2026-11-05', $status);
        }
        $this->makeCase('DUE-TODAY', '2026-11-06');
        $this->makeCase('DUE-TOMORROW', '2026-11-07');
        $this->makeCase('NO-DEADLINE', null);
        foreach (['COMPLETED', 'RETURNED', 'CANCELLED'] as $status) {
            $this->makeCase('TERMINAL-'.$status, '2026-11-05', $status);
        }

        $page = $this->getJson('/api/v1/warranty-cases?overdue=1&per_page=50', $headers)
            ->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertEqualsCanonicalizing(
            ['OVERDUE-RECEIVED', 'OVERDUE-CHECKING', 'OVERDUE-REPAIRING'],
            array_column($page->json('data'), 'warranty_case_code'),
        );
        $page->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_omitted_zero_and_empty_overdue_keep_the_full_list(): void
    {
        $headers = $this->viewerHeaders();
        $this->makeCase('OVERDUE', '2026-11-05');
        $this->makeCase('FUTURE', '2026-11-07');
        $this->makeCase('COMPLETED', '2026-11-05', 'COMPLETED');

        foreach (['', '?overdue=0', '?overdue='] as $query) {
            $this->getJson('/api/v1/warranty-cases'.$query, $headers)
                ->assertOk()->assertJsonPath('meta.total', 3);
        }
    }

    public function test_malformed_overdue_values_return_validation_errors(): void
    {
        $headers = $this->viewerHeaders();
        foreach (['true', 'false', 'yes', '2', '-1', '1,0', '1.0', '0.0'] as $value) {
            $this->getJson('/api/v1/warranty-cases?overdue='.urlencode($value), $headers)
                ->assertStatus(422)->assertJsonValidationErrors('overdue');
        }
        foreach (['overdue[]=1', 'overdue[enabled]=1', 'overdue[]='] as $query) {
            $this->getJson('/api/v1/warranty-cases?'.$query, $headers)
                ->assertStatus(422)->assertJsonValidationErrors('overdue');
        }
    }

    public function test_overdue_combines_with_filters_and_counts_before_pagination(): void
    {
        $headers = $this->viewerHeaders();
        $defectId = (string) Str::uuid();
        DB::table('defect_catalogs')->insert([
            'defect_id' => $defectId, 'defect_code' => 'OVERDUE-DEFECT', 'defect_name' => 'Test defect',
            'status' => 'ACTIVE', 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['MATCH-A', 'MATCH-B'] as $code) {
            $id = $this->makeCase($code, '2026-11-05', 'REPAIRING');
            // Secondary defects must continue to match the existing OR subquery.
            DB::table('warranty_case_defects')->insert([
                'warranty_case_defect_id' => (string) Str::uuid(), 'warranty_case_id' => $id,
                'defect_id' => $defectId, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->makeCase('MATCH-FUTURE', '2026-11-07', 'REPAIRING', $defectId);
        $this->makeCase('MATCH-STATUS', '2026-11-05', 'CHECKING', $defectId);
        $this->makeCase('MATCH-IDENTITY', '2026-11-05', 'REPAIRING', $defectId, 'IDENTIFIED');
        $this->makeCase('MATCH-NO-DEFECT', '2026-11-05', 'REPAIRING');
        $this->makeCase('OTHER-KEYWORD', '2026-11-05', 'REPAIRING', $defectId);

        $query = http_build_query([
            'overdue' => 1, 'status' => 'REPAIRING,RECEIVED', 'identification_status' => 'TEMP_ONLY',
            'defect_id' => $defectId, 'keyword' => 'MATCH', 'per_page' => 1,
        ]);
        $codes = [];
        foreach ([1, 2] as $page) {
            $response = $this->getJson('/api/v1/warranty-cases?'.$query.'&page='.$page, $headers)
                ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
            $codes[] = $response->json('data.0.warranty_case_code');
        }
        $this->assertEqualsCanonicalizing(['MATCH-A', 'MATCH-B'], $codes);
    }

    public function test_overdue_preserves_warehouse_scope_pii_and_permission_checks(): void
    {
        $a = $this->ensureActiveWarehouse('OVERDUE-A');
        $b = Warehouse::create(['code' => 'OVERDUE-B', 'name' => 'Other warehouse', 'status' => 'INACTIVE']);
        $idA = $this->makeCase('OWNED-A', '2026-11-05');
        $idB = $this->makeCase('OWNED-B', '2026-11-05');
        $this->makeCase('UNASSIGNED', '2026-11-05');
        $headers = $this->viewerHeaders((string) $a->id, ['warranty.view', 'report.view']);
        $actorId = User::where('display_name', 'Overdue viewer')->sole()->user_id;
        foreach ([[$idA, $a], [$idB, $b]] as [$id, $warehouse]) {
            DB::table(WarrantyWarehouseScope::TABLE)->insert([
                'warranty_case_id' => $id, 'warehouse_id' => $warehouse->id, 'source' => 'EXPLICIT_REQUEST',
                'actor_id' => $actorId, 'auth_session_id' => null, 'assigned_at' => now(), 'evidence' => null,
            ]);
        }
        $this->getJson('/api/v1/warranty-cases?overdue=1', $headers)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.warranty_case_code', 'OWNED-A')
            ->assertJsonPath('data.0.pii_masked', true)->assertJsonPath('data.0.customer_name', 'O*** C***');
        $this->getJson('/api/v1/reports/dashboard-overview', $headers)->assertOk()
            ->assertJsonPath('data.warranty.overdue_count', 1)->assertJsonPath('data.issues.warranty_overdue', 1);
        $this->getJson('/api/v1/warranty-cases?overdue=1&warehouse_id='.$b->id, $headers)->assertForbidden();
        $this->getJson('/api/v1/warranty-cases?overdue=1', $this->viewerHeaders(null, []))->assertForbidden();
    }

    public function test_dashboard_and_list_agree_across_vietnam_midnight_with_a_fixed_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 16:59:59', 'UTC'));
        $headers = $this->viewerHeaders(null, ['warranty.view', 'report.view']);
        $this->makeCase('MIDNIGHT', '2026-11-05');
        $this->makeCase('COMPLETED', '2026-11-04', 'COMPLETED');
        $dashboardUrl = '/api/v1/reports/dashboard-overview?from_date=2026-11-01&to_date=2026-11-10';

        foreach ([['2026-11-05 16:59:59', 0], ['2026-11-05 17:00:00', 1]] as [$instant, $count]) {
            Carbon::setTestNow(Carbon::parse($instant, 'UTC'));
            $this->getJson($dashboardUrl, $headers)->assertOk()
                ->assertJsonPath('data.warranty.overdue_count', $count)
                ->assertJsonPath('data.issues.warranty_overdue', $count);
            $this->getJson('/api/v1/warranty-cases?overdue=1', $headers)->assertOk()
                ->assertJsonPath('meta.total', $count);
        }
    }

    public function test_overdue_policy_excludes_legacy_terminal_aliases(): void
    {
        // PostgreSQL's current CHECK rejects legacy values on insert; query
        // literal rows to cover compatibility with historical schemas too.
        $rows = DB::query()->selectRaw("'RETURNED_TO_CUSTOMER' as status, '2026-11-05' as estimated_completion_date")
            ->unionAll(DB::query()->selectRaw("'CLOSED' as status, '2026-11-05' as estimated_completion_date"))
            ->unionAll(DB::query()->selectRaw("'REPAIRING' as status, '2026-11-05' as estimated_completion_date"));
        $query = DB::query()->fromSub($rows, 'legacy_cases');

        $this->assertSame(['REPAIRING'], WarrantyOverdue::apply($query)->pluck('status')->all());
    }

    private function makeCase(string $code, ?string $deadline, string $status = 'RECEIVED', ?string $defectId = null, string $identification = 'TEMP_ONLY'): string
    {
        $id = (string) Str::uuid();
        $productId = $identification === 'IDENTIFIED'
            ? (string) $this->productSkuFixture('OVERDUE-'.Str::upper(Str::random(8)))->sku_id
            : null;
        $itemId = $productId === null ? null : $this->seedItemInStock($productId);
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => $id, 'warranty_case_code' => $code, 'identification_status' => $identification,
            'temporary_item_code' => $identification === 'TEMP_ONLY' ? 'TEMP-'.Str::uuid() : null,
            'product_id' => $productId, 'item_id' => $itemId,
            'missing_code_reason' => 'Test fixture', 'manual_product_description' => 'Test product',
            'defect_id' => $defectId, 'status' => $status, 'estimated_completion_date' => $deadline,
            'customer_name' => 'Overdue Customer', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    private function viewerHeaders(?string $scope = null, array $permissions = ['warranty.view']): array
    {
        $suffix = substr((string) Str::uuid(), 0, 8);
        $role = Role::create(['role_code' => 'OVERDUE_'.$suffix, 'role_name' => 'Overdue viewer', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert([
                'role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $user = User::create([
            'username' => 'overdue_'.$suffix, 'display_name' => 'Overdue viewer', 'email' => 'overdue_'.$suffix.'@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $scope]);
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }
}
