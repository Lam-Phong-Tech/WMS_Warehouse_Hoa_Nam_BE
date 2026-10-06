<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/** Read regressions only; fixtures live in the disposable CI database. */
class P02ReadContractTest extends TestCase
{
    use RefreshDatabase;

    private const LISTS = [
        '/api/v1/inbound-documents' => 'receipt',
        '/api/v1/outbound-documents' => 'issue',
        '/api/v1/mini-app/inbound-documents' => 'receipt',
        '/api/v1/mini-app/outbound-documents' => 'issue',
    ];

    private string $actorId;

    public function test_document_lists_still_require_authentication(): void
    {
        foreach (self::LISTS as $path => $type) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    public function test_explicit_warehouse_filters_sql_total_before_pagination_and_cache(): void
    {
        $a = $this->ensureActiveWarehouse('P02-A');
        $b = Warehouse::create(['code' => 'P02-B', 'name' => 'Historical test warehouse', 'status' => 'INACTIVE']);
        $token = $this->token();
        foreach (['receipt', 'issue'] as $type) {
            $this->document($type, $a);
            $this->document($type, $a);
            $this->document($type, $b);
        }
        foreach (self::LISTS as $path => $type) {
            $column = $type === 'receipt' ? 'dst_warehouse_id' : 'src_warehouse_id';
            // Revisit A after B to cover cached reads as well as the SQL path.
            foreach ([[$a, 2], [$b, 1], [$a, 2]] as [$warehouse, $total]) {
                $this->getJson($path.'?'.http_build_query(['warehouse_id' => $warehouse->id, 'per_page' => 1]), $this->headers($token))
                    ->assertOk()->assertJsonPath('meta.total', $total)->assertJsonCount(1, 'data')
                    ->assertJsonPath('data.0.'.$column, (string) $warehouse->id);
            }
            $this->getJson($path.'?per_page=1', $this->headers($token))->assertOk()->assertJsonPath('meta.total', 3);
        }
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
        $this->assertDatabaseCount('auth_session_warehouse_contexts', 0);
    }

    public function test_explicit_filter_is_intersected_with_existing_user_scope(): void
    {
        $a = $this->ensureActiveWarehouse('P02-A');
        $b = Warehouse::create(['code' => 'P02-B', 'name' => 'Historical test warehouse', 'status' => 'INACTIVE']);
        $token = $this->token((string) $a->id);
        foreach (['receipt', 'issue'] as $type) {
            $this->document($type, $a);
            $this->document($type, $b);
        }
        foreach (self::LISTS as $path => $type) {
            $this->getJson($path, $this->headers($token))->assertOk()->assertJsonPath('meta.total', 1);
            $this->getJson($path.'?warehouse_id='.$a->id, $this->headers($token))->assertOk()->assertJsonPath('meta.total', 1);
            $this->getJson($path.'?warehouse_id='.$b->id, $this->headers($token))->assertForbidden();
        }
    }

    public function test_invalid_warehouse_uuid_is_not_silently_ignored(): void
    {
        $this->ensureActiveWarehouse('P02-A');
        $token = $this->token();
        foreach (self::LISTS as $path => $type) {
            $this->getJson($path.'?warehouse_id=WH-01', $this->headers($token))->assertUnprocessable();
        }
    }

    public function test_valid_but_absent_warehouse_returns_an_empty_filtered_list(): void
    {
        $a = $this->ensureActiveWarehouse('P02-A');
        $token = $this->token();
        foreach (['receipt', 'issue'] as $type) {
            $this->document($type, $a);
        }
        foreach (self::LISTS as $path => $type) {
            $this->getJson($path.'?warehouse_id='.Str::uuid(), $this->headers($token))
                ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
        }
    }

    public function test_default_creation_order_uses_id_for_ties_and_preserves_pages(): void
    {
        $a = $this->ensureActiveWarehouse('P02-A');
        $token = $this->token();
        $expected = [];
        foreach (['receipt', 'issue'] as $type) {
            $ids = [$this->document($type, $a), $this->document($type, $a), $this->document($type, $a)];
            rsort($ids, SORT_STRING);
            $expected[$type] = $ids;
        }
        foreach (self::LISTS as $path => $type) {
            foreach ($expected[$type] as $index => $id) {
                $this->getJson($path.'?'.http_build_query(['warehouse_id' => $a->id, 'per_page' => 1, 'page' => $index + 1]), $this->headers($token))
                    ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.id', $id);
            }
        }
    }

    public function test_mini_app_readiness_and_outbound_status_contract_remain_unchanged(): void
    {
        $a = $this->ensureActiveWarehouse('P02-A');
        $token = $this->token();
        $this->document('receipt', $a);
        $this->document('issue', $a);
        $this->getJson('/api/v1/mini-app/inbound-documents?status=WAITING_APPROVAL&warehouse_id='.$a->id, $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/mini-app/outbound-documents?ready_for_issue=1&warehouse_id='.$a->id, $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/mini-app/outbound-documents?status=WAITING_APPROVAL', $this->headers($token))
            ->assertUnprocessable();
    }

    public function test_warranty_default_order_is_stable_without_changing_status_or_pii_rules(): void
    {
        $warehouse = $this->ensureActiveWarehouse('P02-A');
        $token = $this->token();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/v1/mini-app/warranty-cases', [
                'customer_name' => 'P02 automated test', 'customer_phone' => '0900000000',
                'description' => 'Disposable test only', 'missing_code_reason' => 'Test without serial',
                'manual_product_description' => 'P02 isolated fixture',
                'warehouse_id' => (string) $warehouse->id,
            ], $this->headers($token))->assertCreated();
            $id = (string) $response->json('data.warranty_case_id');
            $this->assertTrue(Str::isUuid($id));
            $ids[] = $id;
        }
        DB::table('warranty_case')->whereIn('warranty_case_id', $ids)->update([
            'created_at' => '2026-01-15 08:00:00', 'updated_at' => '2026-01-15 08:00:00',
        ]);
        rsort($ids, SORT_STRING);
        foreach (['/api/v1/warranty-cases', '/api/v1/mini-app/warranty-cases'] as $path) {
            foreach ($ids as $index => $id) {
                $this->getJson($path.'?'.http_build_query(['status' => 'RECEIVED', 'per_page' => 1, 'page' => $index + 1]), $this->headers($token))
                    ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.warranty_case_id', $id)
                    ->assertJsonPath('data.0.status', 'RECEIVED')->assertJsonPath('data.0.pii_masked', true);
            }
        }
    }

    private function document(string $type, Warehouse $warehouse): string
    {
        $document = new StockDocument;
        $document->forceFill([
            'doc_type' => $type, 'doc_no' => 'P02-'.Str::uuid(), 'doc_date' => '2026-01-15',
            'created_by' => $this->actorId, 'updated_by' => $this->actorId,
            'recipient_name' => 'P02 isolated recipient', 'recipient_address' => 'P02 disposable test address',
            'status' => 'DRAFT', $type === 'receipt' ? 'dst_warehouse_id' : 'src_warehouse_id' => $warehouse->id,
            'created_at' => '2026-01-15 08:00:00', 'updated_at' => '2026-01-15 08:00:00',
        ])->save();

        return (string) $document->id;
    }

    private function token(?string $scope = null): string
    {
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create([
            'username' => 'p02_'.$suffix, 'display_name' => 'P02 test', 'email' => 'p02_'.$suffix.'@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1,
        ]);
        $this->actorId = (string) $user->user_id;
        $role = Role::create(['role_code' => 'P02_'.$suffix, 'role_name' => 'P02 test', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $scope]);
        foreach (['inbound.view', 'outbound.view', 'warranty.view', 'warranty.manage'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert([
                'role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // No refresh family or shift: the existing business read APIs must stay usable.
        $this->resetAuthentication();

        return app(RefreshTokenService::class)->accessTokenFor($user, null);
    }

    private function headers(string $token): array
    {
        $this->resetAuthentication();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function resetAuthentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
