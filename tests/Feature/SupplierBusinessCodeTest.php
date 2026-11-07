<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class SupplierBusinessCodeTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const URL = '/api/v1/mini-app/inbound/suppliers';

    private const MIGRATION = 'migrations/2026_11_07_235900_add_inbound_supplier_codes.php';

    protected function setUp(): void
    {
        // Validate the resolved target before RefreshDatabase can run; never rewrite DB environment variables.
        $app = $this->createApplication();
        $connection = $app['config']->get('database.connections.'.$app['config']->get('database.default'), []);
        $isolatedSqlite = ($connection['driver'] ?? null) === 'sqlite' && ($connection['database'] ?? null) === ':memory:';
        $isolatedPg = ($connection['driver'] ?? null) === 'pgsql'
            && ($connection['host'] ?? null) === '127.0.0.1'
            && (int) ($connection['port'] ?? 0) === 55437
            && ($connection['database'] ?? null) === 'hn_fcm_test_20261105'
            && ($connection['username'] ?? null) === 'hn_fcm_test';
        $safe = $app['config']->get('app.env') === 'testing' && empty($connection['url']) && ($isolatedSqlite || $isolatedPg);
        $app->flush();
        if (! $safe) {
            throw new \LogicException('Supplier tests require SQLite memory or the dedicated local FCM test cluster.');
        }
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_migration_assigns_six_codes_in_name_order_without_changing_existing_fields(): void
    {
        $migration = $this->codeMigration();
        $this->legacySupplierSchema();
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
        $expected = [
            'Honda' => 'NCC-001', 'Husqvarna' => 'NCC-002', 'Kubota' => 'NCC-003',
            'Linh kien Hoa Nam' => 'NCC-004', 'TC26 TEST SUPPLIER' => 'NCC-005', 'Yanmar' => 'NCC-006',
        ];
        foreach (array_reverse(array_keys($expected)) as $index => $name) {
            $this->supplier($warehouse, $name, ['status' => $index === 0 ? 'INACTIVE' : 'ACTIVE', 'version' => $index + 3]);
        }
        $before = $this->supplierSnapshot();

        $migration->up();

        $this->assertSame($expected, DB::table('inbound_suppliers')->orderBy('name')->orderBy('id')->pluck('code', 'name')->all());
        $legacyFields = array_keys($before[0]);
        $this->assertSame($before, $this->supplierSnapshot($legacyFields));
        $this->assertDatabaseCount('inbound_suppliers', 6);
    }

    public function test_migration_breaks_equal_name_ties_by_uuid(): void
    {
        $migration = $this->codeMigration();
        $this->legacySupplierSchema();
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
        $first = '10000000-0000-4000-8000-000000000001';
        $second = '10000000-0000-4000-8000-000000000002';
        $this->supplier($warehouse, 'Same supplier', ['id' => $second]);
        $this->supplier($warehouse, 'Same supplier', ['id' => $first]);

        $migration->up();

        $this->assertDatabaseHas('inbound_suppliers', ['id' => $first, 'code' => 'NCC-001']);
        $this->assertDatabaseHas('inbound_suppliers', ['id' => $second, 'code' => 'NCC-002']);
    }

    public function test_reentry_preserves_all_assigned_codes_and_rename_does_not_reallocate_them(): void
    {
        $migration = $this->codeMigration();
        $migration->up();
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
        $reserved = $this->supplier($warehouse, 'Zulu reserved', ['code' => 'NCC-001']);
        $custom = $this->supplier($warehouse, 'Beta existing', ['code' => 'LEGACY-KEEP']);
        $missing = $this->supplier($warehouse, 'Alpha missing');
        $assignedBefore = $this->supplierSnapshot();

        $migration->up();

        $this->assertDatabaseHas('inbound_suppliers', ['id' => $reserved, 'code' => 'NCC-001']);
        $this->assertDatabaseHas('inbound_suppliers', ['id' => $custom, 'code' => 'LEGACY-KEEP']);
        $newCode = DB::table('inbound_suppliers')->where('id', $missing)->value('code');
        $this->assertMatchesRegularExpression('/^NCC-[0-9]{3,}$/D', (string) $newCode);
        $this->assertNotSame('NCC-001', $newCode);
        foreach ($assignedBefore as $row) {
            unset($row['code']);
            $this->assertDatabaseHas('inbound_suppliers', $row);
        }
        DB::table('inbound_suppliers')->where('id', $reserved)->update(['name' => 'A renamed supplier']);
        $beforeRerun = $this->supplierSnapshot();

        $migration->up();
        $migration->up();

        $this->assertSame($beforeRerun, $this->supplierSnapshot());
    }

    public function test_code_column_allows_multiple_unassigned_suppliers(): void
    {
        $this->codeMigration()->up();
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
        $this->supplier($warehouse, 'First unassigned');
        $this->supplier($warehouse, 'Second unassigned');

        $this->assertSame(2, DB::table('inbound_suppliers')->whereNull('code')->count());
    }

    public function test_exhausted_numeric_namespace_does_not_assign_an_invalid_code(): void
    {
        $migration = $this->codeMigration();
        $migration->up();
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
        $this->supplier($warehouse, 'Reserved large identifier', ['code' => 'NCC-99999999999999999999999999']);
        $missing = $this->supplier($warehouse, 'Missing code');
        try {
            $migration->up();
            $this->fail('Expected a fail-closed namespace bound.');
        } catch (\RuntimeException $error) {
            $this->assertSame('SUPPLIER_CODE_NAMESPACE_EXHAUSTED', $error->getMessage());
        }
        $this->assertNull(DB::table('inbound_suppliers')->where('id', $missing)->value('code'));
    }

    public function test_database_rejects_duplicate_assigned_codes(): void
    {
        $this->codeMigration()->up();
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
        $this->supplier($warehouse, 'First supplier', ['code' => 'NCC-001']);
        $other = $this->supplier($warehouse, 'Other supplier');

        $this->expectException(QueryException::class);
        DB::table('inbound_suppliers')->where('id', $other)->update(['code' => 'NCC-001']);
    }

    public function test_get_adds_code_display_and_meta_but_keeps_uuid_and_legacy_fields(): void
    {
        $this->codeMigration()->up();
        [$token, $warehouse] = $this->actor();
        $id = $this->supplier($warehouse, 'Honda', ['code' => 'NCC-001', 'version' => 7]);
        $before = $this->supplierSnapshot();

        $this->getJson(self::URL, $this->headers($token))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.name', 'Honda')->assertJsonPath('data.0.status', 'ACTIVE')
            ->assertJsonPath('data.0.version', 7)->assertJsonPath('data.0.updated_at', $before[0]['updated_at'])
            ->assertJsonPath('data.0.code', 'NCC-001')->assertJsonPath('data.0.display_name', 'NCC-001 - Honda')
            ->assertJsonPath('data.0.code_status', 'ASSIGNED')
            ->assertJsonPath('meta.contract', 'mini-app-p04-v1')
            ->assertJsonPath('meta.supplier_code_contract', 'supplier-business-code-v1')
            ->assertJsonPath('meta.scope', 'GLOBAL')->assertJsonPath('meta.source', 'inbound_suppliers');

        $this->assertTrue(Str::isUuid($id));
        $this->assertSame($before, $this->supplierSnapshot());
    }

    public function test_get_leaves_unassigned_rows_unassigned_and_executes_no_database_writes(): void
    {
        [$token, $warehouse] = $this->actor();
        $id = $this->supplier($warehouse, 'Late supplier');
        $before = $this->supplierSnapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $response = $this->getJson(self::URL, $this->headers($token))->assertOk();
            $this->getJson(self::URL, $this->headers($token))->assertOk();
            $writes = array_filter(DB::getQueryLog(), fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']) === 1);
            $this->assertSame([], array_values($writes), 'Supplier GET must be read-only, including unassigned rows.');
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame($before, $this->supplierSnapshot());
        $response->assertJsonPath('data.0.id', $id)->assertJsonStructure(['data' => [['code', 'display_name', 'code_status']]])
            ->assertJsonPath('data.0.code', null)->assertJsonPath('data.0.display_name', 'Late supplier')
            ->assertJsonPath('data.0.code_status', 'NOT_ASSIGNED');
    }

    public function test_query_matches_code_or_name_without_bypassing_status_filter(): void
    {
        $this->codeMigration()->up();
        [$token, $warehouse] = $this->actor();
        $active = $this->supplier($warehouse, 'Honda', ['code' => 'NCC-001']);
        $inactive = $this->supplier($warehouse, 'Yanmar', ['code' => 'NCC-002', 'status' => 'INACTIVE']);

        foreach ([['q' => 'ncc-001'], ['q' => 'hOnDa']] as $query) {
            $this->getJson(self::URL.'?'.http_build_query($query), $this->headers($token))->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $active);
        }
        $this->getJson(self::URL.'?q=NCC-002&status=ACTIVE', $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        $this->getJson(self::URL.'?q=NCC-002&status=INACTIVE', $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $inactive);
    }

    public function test_query_treats_percent_underscore_and_escape_characters_literally_in_both_fields(): void
    {
        $this->codeMigration()->up();
        [$token, $warehouse] = $this->actor();
        $code = $this->supplier($warehouse, 'Code sentinel', ['code' => 'NCC-10%_!']);
        $name = $this->supplier($warehouse, 'Name 20%_!', ['code' => 'NCC-002']);
        $this->supplier($warehouse, 'Name 2000X', ['code' => 'NCC-1000X']);

        foreach (['10%_!' => $code, '20%_!' => $name] as $term => $id) {
            $this->getJson(self::URL.'?'.http_build_query(['q' => $term]), $this->headers($token))->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        }
    }

    public function test_directory_remains_global_and_paged_by_name_then_uuid_not_code(): void
    {
        $this->codeMigration()->up();
        [$token, $warehouse] = $this->actor();
        $first = '20000000-0000-4000-8000-000000000001';
        $second = '20000000-0000-4000-8000-000000000002';
        $this->supplier($warehouse, 'Alpha', ['id' => $second, 'code' => 'NCC-001']);
        $this->supplier($warehouse, 'Alpha', ['id' => $first, 'code' => 'NCC-009']);
        $other = Warehouse::create(['code' => 'SUPPLIER-OTHER', 'name' => 'Other warehouse', 'status' => 'INACTIVE']);
        $last = $this->supplier($other, 'Zulu', ['code' => 'NCC-003']);

        foreach ([1 => $first, 2 => $second, 3 => $last] as $page => $id) {
            $this->getJson(self::URL.'?per_page=1&page='.$page, $this->headers($token))->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)
                ->assertJsonPath('meta.scope', 'GLOBAL')->assertJsonPath('meta.total', 3)
                ->assertJsonPath('meta.page', $page)->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.last_page', 3);
        }
        $this->getJson(self::URL.'?per_page=101', $this->headers($token))->assertUnprocessable();
        $this->getJson(self::URL.'?status=UNKNOWN', $this->headers($token))->assertUnprocessable();
    }

    public function test_supplier_directory_still_requires_authentication_and_inbound_view_permission(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        [$token] = $this->actor(false);
        $this->getJson(self::URL, $this->headers($token))->assertForbidden()
            ->assertJsonPath('error.code', 'P04_OPERATION_DENIED');
    }

    private function codeMigration(): Migration
    {
        $path = database_path(self::MIGRATION);
        $this->assertFileExists($path, 'Supplier business-code migration has not been implemented yet.');

        return require $path;
    }

    private function legacySupplierSchema(): void
    {
        // RefreshDatabase already ran new migrations on an empty database; recreate the old schema for upgrade coverage.
        if (Schema::hasColumn('inbound_suppliers', 'code')) {
            foreach (Schema::getIndexes('inbound_suppliers') as $index) {
                if ($index['unique'] && $index['columns'] === ['code']) {
                    Schema::table('inbound_suppliers', fn (Blueprint $table) => $table->dropUnique($index['name']));
                }
            }
            Schema::table('inbound_suppliers', fn (Blueprint $table) => $table->dropColumn('code'));
        }
    }

    private function supplier(Warehouse $warehouse, string $name, array $overrides = []): string
    {
        $row = array_replace([
            'id' => (string) Str::uuid(), 'warehouse_id' => (string) $warehouse->id,
            'name' => $name, 'status' => 'ACTIVE', 'version' => 1,
            'created_at' => '2026-10-01 01:02:03', 'updated_at' => '2026-10-02 03:04:05',
        ], $overrides);
        DB::table('inbound_suppliers')->insert($row);

        return $row['id'];
    }

    private function supplierSnapshot(array $columns = ['*']): array
    {
        return DB::table('inbound_suppliers')->orderBy('id')->get($columns)->map(fn (object $row): array => (array) $row)->all();
    }

    private function actor(bool $canRead = true): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'supplier_'.$suffix, 'display_name' => 'Supplier CI actor',
            'email' => 'supplier_'.$suffix.'@example.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'SUPPLIER_'.$suffix, 'role_name' => 'Supplier scoped role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => (string) $warehouse->id]);
        if ($canRead) {
            $permission = Permission::firstOrCreate(['permission_key' => 'inbound.view'], ['permission_name' => 'inbound.view', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->headers('');
        $token = $this->browserLogin($user->email, 'secret123')->assertOk()->json('data.access_token');

        return [$token, $warehouse];
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }
}
