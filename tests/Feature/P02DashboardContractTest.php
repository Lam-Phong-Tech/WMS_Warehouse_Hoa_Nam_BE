<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\MiniAppDashboardController;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\WarrantyCasePii;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/** All fixtures are confined to RefreshDatabase's disposable CI database. */
class P02DashboardContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/mini-app/dashboard/summary';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_global_role_with_explicit_warehouse_reports_effective_scope_and_exact_open_statuses(): void
    {
        [$a, $b] = $this->warehouses();
        [$user, $token] = $this->actor();
        foreach (['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'] as $status) {
            $this->warranty($user, $a->id, $status);
        }
        $foreign = $this->warranty($user, $b->id);
        $legacy = $this->warranty($user, null);
        $response = $this->getJson($this->url($a->id), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.contract', 'mini-app-p02-v2')
            ->assertJsonPath('data.data_scope', 'WAREHOUSE_SCOPED')
            ->assertJsonPath('data.authorization_scope', 'GLOBAL')
            ->assertJsonPath('data.warehouse_id', (string) $a->id)
            ->assertJsonPath('data.warranty.open_count', 4)
            ->assertJsonPath('data.warranty.open_statuses', ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED'])
            ->assertJsonPath('data.warranty.warehouse_scope.contract', 'warranty-owner-v1')
            ->assertJsonPath('data.warranty.warehouse_scope.warehouse_id', (string) $a->id)
            ->assertJsonPath('data.warranty.warehouse_scope.unassigned_policy', 'EXCLUDED')
            ->assertJsonPath('data.warranty.warehouse_scope.ownership_complete', false);
        $this->assertStringNotContainsString($foreign, $response->getContent());
        $this->assertStringNotContainsString($legacy, $response->getContent());
        $this->assertSame(8, DB::table('warranty_case')->whereNull('item_id')->count());
    }

    public function test_global_unfiltered_read_keeps_owner_v1_unassigned_policy(): void
    {
        [$a, $b] = $this->warehouses();
        [$user, $token] = $this->actor();
        $this->warranty($user, $a->id);
        $this->warranty($user, $b->id);
        $this->warranty($user, null);
        $this->getJson(self::URL, $this->headers($token))->assertOk()
            ->assertJsonPath('data.data_scope', 'GLOBAL')->assertJsonPath('data.authorization_scope', 'GLOBAL')
            ->assertJsonPath('data.warehouse_id', null)->assertJsonPath('data.warranty.open_count', 3)
            ->assertJsonPath('data.warranty.warehouse_scope.unassigned_policy', 'INCLUDED');
    }

    public function test_scoped_role_without_explicit_filter_does_not_invent_a_selected_warehouse(): void
    {
        [$a, $b] = $this->warehouses();
        [$user, $token] = $this->actor(['warranty.view'], $a->id);
        $this->warranty($user, $a->id);
        $this->warranty($user, $b->id);
        $this->warranty($user, null);
        $this->getJson(self::URL, $this->headers($token))->assertOk()
            ->assertJsonPath('data.data_scope', 'WAREHOUSE_SCOPED')->assertJsonPath('data.authorization_scope', 'WAREHOUSE_SCOPED')
            ->assertJsonPath('data.warehouse_id', null)->assertJsonPath('data.warranty.open_count', 1)
            ->assertJsonPath('data.warranty.warehouse_scope.warehouse_id', null)
            ->assertJsonPath('data.warranty.warehouse_scope.unassigned_policy', 'EXCLUDED');
        $this->getJson($this->url($a->id), $this->headers($token))->assertOk()
            ->assertJsonPath('data.data_scope', 'WAREHOUSE_SCOPED')->assertJsonPath('data.warranty.open_count', 1);
        $this->getJson($this->url($b->id), $this->headers($token))->assertForbidden()
            ->assertJsonPath('error.code', 'DATA_SCOPE_DENIED');
    }

    public function test_unreadable_warranty_is_null_even_after_a_permitted_read_warms_stock_cache(): void
    {
        [$a] = $this->warehouses();
        [$user, $allowed] = $this->actor(['inbound.view', 'warranty.view']);
        $this->warranty($user, $a->id);
        $this->getJson($this->url($a->id), $this->headers($allowed))->assertOk()->assertJsonPath('data.warranty.open_count', 1);
        [, $denied] = $this->actor(['inbound.view']);
        $this->getJson($this->url($a->id), $this->headers($denied))->assertOk()
            ->assertJsonPath('data.warranty', null)->assertJsonPath('data.outbound', null)
            ->assertJsonPath('data.inbound.pending_approval_count', 0)->assertJsonPath('data.pending_approval_count', 0);
        $this->getJson($this->url($a->id), $this->headers($allowed))->assertOk()->assertJsonPath('data.warranty.open_count', 1);
    }

    public function test_warranty_only_and_manage_only_readers_do_not_add_cases_to_document_totals(): void
    {
        [$a] = $this->warehouses();
        foreach (['warranty.view', 'warranty.manage'] as $permission) {
            [$user, $token] = $this->actor([$permission]);
            if ($permission === 'warranty.view') {
                $this->warranty($user, $a->id);
            }
            $this->getJson($this->url($a->id), $this->headers($token))->assertOk()
                ->assertJsonPath('data.warranty.open_count', 1)
                ->assertJsonPath('data.inbound', null)->assertJsonPath('data.outbound', null)
                ->assertJsonPath('data.pending_approval_count', 0)->assertJsonPath('data.posted_count', 0);
        }
    }

    public function test_existing_empty_warehouse_returns_verified_zero_not_unavailable_or_null(): void
    {
        [$a] = $this->warehouses();
        [, $token] = $this->actor();
        $this->getJson($this->url($a->id), $this->headers($token))->assertOk()
            ->assertJsonPath('data.warranty.open_count', 0)->assertJsonPath('data.warranty.warehouse_scope.ownership_complete', true)
            ->assertJsonPath('data.data_scope', 'WAREHOUSE_SCOPED')->assertJsonPath('meta.contract', 'mini-app-p02-v2');
    }

    public function test_date_only_applies_to_posted_documents_not_open_warranty_snapshot(): void
    {
        [$a] = $this->warehouses();
        [$user, $token] = $this->actor();
        $this->warranty($user, $a->id, 'COMPLETED');
        foreach (['2026-09-01', '2026-10-12'] as $date) {
            $this->getJson($this->url($a->id, ['date' => $date]), $this->headers($token))->assertOk()
                ->assertJsonPath('data.date', $date)->assertJsonPath('data.warranty.open_count', 1);
        }
    }

    public function test_query_validation_does_not_allow_a_get_body_to_split_stock_and_warranty_scope(): void
    {
        [$a, $b] = $this->warehouses();
        [, $token] = $this->actor(['inbound.view']);
        $this->json('GET', $this->url($a->id), ['warehouse_id' => $b->id], $this->headers($token))
            ->assertUnprocessable()->assertJsonPath('error.code', 'P02_QUERY_ONLY');
        foreach (['page' => '1', 'status' => 'RECEIVED', 'tenant_id' => (string) Str::uuid()] as $key => $value) {
            $this->getJson($this->url($a->id, [$key => $value]), $this->headers($token))
                ->assertUnprocessable()->assertJsonPath('error.code', 'P02_UNKNOWN_FILTER');
        }
        foreach (['broken', ''] as $warehouse) {
            $this->getJson(self::URL.'?warehouse_id='.$warehouse, $this->headers($token))->assertUnprocessable();
        }
        $this->getJson($this->url($a->id, ['date' => '12-10-2026']), $this->headers($token))->assertUnprocessable();
        // Consistent whether or not the actor can read warranty: no invented zero for an absent warehouse.
        $this->getJson($this->url((string) Str::uuid()), $this->headers($token))->assertNotFound()
            ->assertJsonPath('error.code', 'WAREHOUSE_NOT_FOUND');
    }

    public function test_separate_warehouse_and_global_cache_keys_preserve_effective_scope(): void
    {
        [$a, $b] = $this->warehouses();
        [$user, $token] = $this->actor(['inbound.view', 'outbound.view', 'warranty.view']);
        $this->warranty($user, $a->id);
        $this->warranty($user, $b->id);
        $this->warranty($user, $b->id);
        foreach ([[$a->id, 1], [$b->id, 2], [null, 3], [$a->id, 1]] as [$id, $count]) {
            $this->getJson($this->url($id), $this->headers($token))->assertOk()
                ->assertJsonPath('data.warranty.open_count', $count)->assertJsonPath('data.warehouse_id', $id)
                ->assertJsonPath('data.data_scope', $id === null ? 'GLOBAL' : 'WAREHOUSE_SCOPED')
                ->assertJsonPath('data.authorization_scope', 'GLOBAL');
        }
    }

    public function test_authentication_and_endpoint_permission_are_still_enforced(): void
    {
        $this->resetAuth();
        $this->getJson(self::URL)->assertUnauthorized();
        [, $token] = $this->actor(['sku.view']);
        $this->getJson(self::URL, $this->headers($token))->assertForbidden();
    }

    public function test_reads_do_not_mutate_business_rows_and_do_not_expose_case_pii(): void
    {
        [$a] = $this->warehouses();
        [$user, $token] = $this->actor();
        $this->warranty($user, $a->id);
        $tables = ['warranty_case', WarrantyWarehouseScope::TABLE, 'stock_documents', 'stock_movements', 'inventory_balances', 'mini_app_work_shifts'];
        $snapshot = fn (): array => array_map(fn (string $table): string => hash('sha256', DB::table($table)->get()->toJson()), $tables);
        $before = $snapshot();
        for ($i = 0; $i < 2; $i++) {
            $response = $this->getJson($this->url($a->id), $this->headers($token))->assertOk();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            foreach (['p02-private-marker', '0900000000', 'customer_name', 'auth_session_id', 'refresh_token'] as $private) {
                $this->assertStringNotContainsString($private, $response->getContent());
            }
        }
        $this->assertSame($before, $snapshot());
    }

    public function test_browser_login_can_read_without_creating_a_work_shift(): void
    {
        [$a] = $this->warehouses();
        [$user] = $this->actor();
        $this->warranty($user, $a->id);
        $this->resetAuth();
        $token = $this->browserLogin($user->email, 'secret123')->assertOk()->json('data.access_token');
        $this->getJson($this->url($a->id), $this->headers($token))->assertOk()->assertJsonPath('data.warranty.open_count', 1);
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_contract_and_existing_read_route_are_versioned_without_new_operations(): void
    {
        $contract = json_decode(file_get_contents(base_path('contracts/p02/dashboard-summary-v2.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(MiniAppDashboardController::CONTRACT, $contract['contract']);
        $this->assertSame(WarrantyWarehouseScope::VERSION, $contract['ownership_contract']);
        $this->assertSame(WarrantyWarehouseScope::OPEN_STATUSES, $contract['warranty']['open_statuses']);
        $this->assertFalse($contract['production_activation']);
        $this->assertSame([], $contract['new_routes']);
        $this->assertSame([], $contract['new_migrations']);
        $routes = collect(Route::getRoutes())->filter(fn ($route): bool => $route->uri() === ltrim(self::URL, '/'));
        $this->assertCount(1, $routes);
        $route = $routes->first();
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth:api', $route->gatherMiddleware());
        $this->assertContains('permission:inbound.view|outbound.view|warranty.view|warranty.manage', $route->gatherMiddleware());
    }

    private function warehouses(): array
    {
        return [$this->warehouseFixture(), Warehouse::create(['code' => 'P02-OTHER', 'name' => 'Isolated other warehouse', 'status' => 'INACTIVE'])];
    }

    private function actor(array $permissions = ['warranty.view'], ?string $warehouseScope = null): array
    {
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p02_'.$suffix, 'display_name' => 'P02 isolated actor', 'email' => 'p02_'.$suffix.'@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P02_'.$suffix, 'role_name' => 'P02 isolated role', 'scope_type' => $warehouseScope === null ? 'SYSTEM' : 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouseScope]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        }
        $this->resetAuth();

        return [$user, JWTAuth::fromUser($user)];
    }

    private function warranty(User $user, ?string $warehouse, string $status = 'RECEIVED'): string
    {
        $id = (string) Str::uuid();
        $closed = in_array($status, ['RETURNED', 'CANCELLED'], true);
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => $id, 'warranty_case_code' => 'P02-'.$id, 'item_id' => null,
            'inventory_serial_id' => null, 'product_id' => null, 'temporary_item_code' => 'TEMP-'.$id,
            'identification_status' => 'TEMP_ONLY', 'sales_trace_status' => 'NOT_TRACEABLE',
            'missing_code_reason' => 'Isolated test only', 'manual_product_description' => 'p02-private-marker',
            'customer_name' => 'p02-private-marker', 'customer_phone' => '0900000000', 'customer_address' => 'p02-private-marker',
            'description' => 'p02-private-marker', 'status' => $status, 'received_at' => '2026-08-01 08:00:00',
            'completed_at' => in_array($status, ['COMPLETED', 'RETURNED'], true) ? now() : null,
            'returned_at' => $status === 'RETURNED' ? now() : null, 'closed_at' => $closed ? now() : null,
            'retention_until' => $closed ? now()->addYears(5) : null, 'created_by' => $user->user_id,
            'version' => 1, 'legal_hold_flag' => false, 'created_at' => '2026-08-01 08:00:00', 'updated_at' => now(),
        ]));
        if ($warehouse !== null) {
            DB::table(WarrantyWarehouseScope::TABLE)->insert(['warranty_case_id' => $id, 'warehouse_id' => $warehouse,
                'source' => 'EXPLICIT_REQUEST', 'actor_id' => $user->user_id, 'auth_session_id' => null, 'assigned_at' => now()->utc(), 'evidence' => null]);
        }

        return $id;
    }

    private function url(?string $warehouse, array $query = []): string
    {
        return self::URL.'?'.http_build_query(($warehouse === null ? [] : ['warehouse_id' => $warehouse]) + $query);
    }

    private function headers(string $token): array
    {
        $this->resetAuth();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
