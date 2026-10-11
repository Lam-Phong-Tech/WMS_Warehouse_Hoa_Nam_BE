<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;
use Tests\TestCredentials;

/** Disposable fixtures only. Never a live migration, repair, or release approval. */
class P12ScanParentSafetyTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $app = $this->createApplication();
        $name = $app['config']->get('database.default');
        $config = $app['config']->get('database.connections.'.$name);
        $safe = $app['config']->get('app.env') === 'testing'
            && empty($config['url'])
            && (($config['driver'] === 'sqlite' && $config['database'] === ':memory:')
                || ($config['driver'] === 'pgsql' && $config['database'] === 'p12_contract_test'
                    && $config['host'] === '127.0.0.1'));
        $app->flush();
        if (! $safe) {
            throw new \LogicException('P12 safety tests require isolated memory or localhost p12_contract_test.');
        }
        parent::setUp();
    }

    public function test_deleted_parent_is_rejected_after_successful_cached_scan_reads(): void
    {
        [$token, $warehouse] = $this->actor();
        foreach (['inbound', 'outbound'] as $type) {
            $id = $this->document($warehouse, $type);
            $url = $this->url($type, $id);
            for ($read = 0; $read < 2; $read++) {
                $this->getJson($url, $this->headers($token))->assertOk()
                    ->assertJsonPath('meta.contract', 'p12-scan-evidence-v2')
                    ->assertJsonPath('meta.document_id', $id);
            }
            // Direct isolated mutation intentionally leaves the short read cache intact.
            DB::table('stock_documents')->where('id', $id)->update(['deleted_at' => now()]);
            $before = $this->businessCounts();
            $this->getJson($url, $this->headers($token))->assertNotFound()
                ->assertJsonMissingPath('meta.summary')->assertJsonMissingPath('data.0');
            $this->assertSame($before, $this->businessCounts());
        }
    }

    public function test_missing_persisted_warehouse_never_becomes_a_global_scan_read(): void
    {
        [$token, $warehouse] = $this->actor();
        foreach (['inbound' => 'dst_warehouse_id', 'outbound' => 'src_warehouse_id'] as $type => $field) {
            $id = $this->document($warehouse, $type);
            $this->getJson($this->url($type, $id), $this->headers($token))->assertOk();
            DB::table('stock_documents')->where('id', $id)->update([$field => null]);
            $before = $this->businessCounts();
            $this->getJson($this->url($type, $id), $this->headers($token))->assertNotFound()
                ->assertJsonMissingPath('meta.summary');
            $this->assertSame($before, $this->businessCounts());
        }
    }

    public function test_wrong_type_and_missing_parent_do_not_return_empty_success(): void
    {
        [$token, $warehouse] = $this->actor();
        foreach (['inbound' => 'outbound', 'outbound' => 'inbound'] as $type => $otherType) {
            $wrongTypeId = $this->document($warehouse, $otherType);
            foreach ([$wrongTypeId, (string) Str::uuid()] as $id) {
                $this->getJson($this->url($type, $id), $this->headers($token))->assertNotFound()
                    ->assertJsonMissingPath('meta.summary');
            }
        }
    }

    public function test_scan_read_still_requires_authentication_and_current_warehouse_scope(): void
    {
        [$token, $warehouse] = $this->actor();
        $other = Warehouse::create(['code' => 'P12SAFE-OTHER', 'name' => 'Isolated other warehouse', 'status' => 'INACTIVE']);
        foreach (['inbound', 'outbound'] as $type) {
            $id = $this->document($warehouse, $type);
            $hidden = $this->document($other, $type);
            $this->getJson($this->url($type, $id), $this->headers($token))->assertOk();
            $this->getJson($this->url($type, $hidden), $this->headers($token))->assertForbidden()
                ->assertJsonMissingPath('meta.summary');
            $this->headers($token);
            $this->getJson($this->url($type, $id))->assertUnauthorized()
                ->assertJsonMissingPath('meta.summary');
        }
    }

    private function actor(): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P12 safety isolated warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p12safe_'.$suffix, 'display_name' => 'P12 isolated actor',
            'email' => 'p12safe_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make(TestCredentials::PASSWORD), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P12SAFE_'.$suffix, 'role_name' => 'P12 scoped role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (['inbound.view', 'outbound.view'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $login = $this->browserLogin($user->email, TestCredentials::PASSWORD)->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->headers($login->json('data.access_token')))->assertOk();

        return [$selected->json('data.access_token'), $warehouse];
    }

    private function document(object $warehouse, string $type): string
    {
        $sku = $this->productSkuFixture('P12SAFE-'.Str::upper(Str::random(10)));
        if ($type === 'inbound') {
            $actor = User::query()->firstOrFail()->user_id;
            $doc = StockDocument::create(['doc_type' => 'receipt', 'doc_no' => 'P12SAFE-IN-'.Str::upper(Str::random(12)),
                'doc_date' => '2026-10-11', 'status' => 'DRAFT', 'dst_warehouse_id' => $warehouse->id, 'created_by' => $actor, 'updated_by' => $actor]);
            StockDocumentLine::create(['stock_document_id' => $doc->id, 'line_no' => 1, 'product_id' => $sku->sku_id,
                'qty_planned' => 1, 'qty_actual' => 0, 'scanned_qty' => 0]);

            return (string) $doc->id;
        }
        $request = $this->outboundRequestFixture($sku->sku_id, 1, 'P12SAFE-REQ-'.Str::upper(Str::random(10)));

        return $this->outboundDocumentFixture($warehouse->id, $request)['document_id'];
    }

    private function url(string $type, string $id): string
    {
        return '/api/v1/mini-app/'.$type.'-documents/'.$id.'/scan-entries?per_page=1';
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function businessCounts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(),
            ['stock_documents', 'stock_movements', 'inventory_balances', 'mini_app_drafts', 'mini_app_draft_receipts']);
    }
}
