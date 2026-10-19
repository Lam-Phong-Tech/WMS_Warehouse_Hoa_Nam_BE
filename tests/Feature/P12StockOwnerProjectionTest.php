<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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

/** Generated canonical fixtures in a guarded disposable DB; not DEV/TEST evidence. */
class P12StockOwnerProjectionTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const PROJECTION = 'data.p12.owner_projection';

    public function test_supplier_uses_record_snapshot_and_creator_is_not_the_caller(): void
    {
        [$caller, $token, $warehouse] = $this->reader(['inbound.view', 'iam.user.view']);
        $creator = $this->user('Persisted creator label');
        $id = $this->document($warehouse, 'inbound', $creator);
        $supplier = $this->supplierSnapshot($id, $warehouse, $creator, 'Supplier at RECORD');
        DB::table('inbound_suppliers')->where('id', $supplier)->update(['name' => 'Renamed current GLOBAL supplier', 'version' => 7]);
        DB::table('stock_documents')->where('id', $id)->update(['source_name' => 'Not a supplier snapshot', 'note' => 'Keep full owner note']);
        $before = $this->businessState();
        // PostgreSQL recorded_at is timestamptz(0); SQLite preserves the fixture text.
        $recordedAt = DB::getDriverName() === 'pgsql' ? '2026-10-18T14:40:07.000000Z' : '2026-10-18T14:40:07.123456Z';

        $response = $this->getJson($this->url('inbound', $id, $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.created_by', $creator->user_id)
            ->assertJsonPath('data.note', 'Keep full owner note')->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('meta.p12.contract', 'p12-documents-read-v2')
            ->assertJsonPath('meta.p12.owner_projection_contract', 'p12-owner-projection-v1')
            ->assertJsonPath(self::PROJECTION.'.supplier.id', $supplier)
            ->assertJsonPath(self::PROJECTION.'.supplier.name', 'Supplier at RECORD')
            ->assertJsonPath(self::PROJECTION.'.supplier.version', 2)
            ->assertJsonPath(self::PROJECTION.'.supplier.recorded_at', $recordedAt)
            ->assertJsonPath(self::PROJECTION.'.supplier.status', 'SNAPSHOT_RECORDED')
            ->assertJsonPath(self::PROJECTION.'.creator.actor_id', $creator->user_id)
            ->assertJsonPath(self::PROJECTION.'.creator.display_name', null)
            ->assertJsonPath(self::PROJECTION.'.creator.role', null)
            ->assertJsonPath(self::PROJECTION.'.creator.current_display_name', 'Persisted creator label')
            ->assertJsonPath(self::PROJECTION.'.creator.current_display_name_status', 'CURRENT_DIRECTORY_LABEL_NOT_HISTORICAL_SNAPSHOT');
        $this->assertNotSame($caller->user_id, $response->json(self::PROJECTION.'.creator.actor_id'));
        $this->assertSame($before, $this->businessState());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_legacy_detail_never_infers_supplier_from_source_title_or_current_directory(): void
    {
        [$creator, $token, $warehouse] = $this->reader(['inbound.view']);
        $id = $this->document($warehouse, 'inbound', $creator);
        DB::table('stock_documents')->where('id', $id)->update(['source_name' => 'Legacy source reference']);
        DB::table('inbound_suppliers')->insert(['id' => (string) Str::uuid(), 'warehouse_id' => $warehouse->id,
            'name' => 'Legacy source reference', 'status' => 'ACTIVE', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson($this->url('inbound', $id, $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath('data.source_reference', 'Legacy source reference')
            ->assertJsonPath(self::PROJECTION.'.supplier.status', 'NOT_RECORDED')
            ->assertJsonPath(self::PROJECTION.'.supplier.name', null)
            ->assertJsonPath(self::PROJECTION.'.supplier.id', null)
            ->assertJsonPath(self::PROJECTION.'.supplier.recorded_at', null);
    }

    public function test_directory_pii_is_not_read_without_permission_even_after_shared_detail_cache_hit(): void
    {
        [, $allowed, $warehouse] = $this->reader(['inbound.view', 'iam.user.view']);
        [, $denied] = $this->reader(['inbound.view'], $warehouse);
        $creator = $this->user('Private creator directory name');
        $id = $this->document($warehouse, 'inbound', $creator);
        $url = $this->url('inbound', $id, $warehouse);
        $this->getJson($url, $this->headers($allowed))->assertOk()
            ->assertJsonPath(self::PROJECTION.'.creator.current_display_name', 'Private creator directory name');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $response = $this->getJson($url, $this->headers($denied))->assertOk()
            ->assertJsonPath(self::PROJECTION.'.creator.actor_id', $creator->user_id)
            ->assertJsonPath(self::PROJECTION.'.creator.current_display_name', null)
            ->assertJsonPath(self::PROJECTION.'.creator.current_display_name_status', 'REDACTED_PII_PERMISSION');
        $this->assertStringNotContainsString('Private creator directory name', $response->getContent());
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains(strtolower($sql), 'display_name')));
    }

    public function test_outbound_uses_persisted_creator_but_never_inbound_supplier_metadata(): void
    {
        [, $token, $warehouse] = $this->reader(['outbound.view', 'iam.user.view']);
        $creator = $this->user('Outbound original creator');
        $id = $this->document($warehouse, 'outbound', $creator);
        $this->getJson($this->url('outbound', $id, $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath(self::PROJECTION.'.source', 'OUTBOUND')
            ->assertJsonPath(self::PROJECTION.'.creator.actor_id', $creator->user_id)
            ->assertJsonPath(self::PROJECTION.'.creator.current_display_name', 'Outbound original creator')
            ->assertJsonPath(self::PROJECTION.'.supplier.status', 'NOT_APPLICABLE')
            ->assertJsonPath(self::PROJECTION.'.supplier.name', null);
    }

    public function test_supplier_storage_failure_keeps_parent_and_explicit_unavailable_state_not_empty_data(): void
    {
        [$creator, $token, $warehouse] = $this->reader(['inbound.view']);
        $id = $this->document($warehouse, 'inbound', $creator);
        $url = $this->url('inbound', $id, $warehouse);
        $this->getJson($url, $this->headers($token))->assertOk();
        Schema::rename('mini_app_inbound_record_metadata', 'p12_test_unavailable_metadata');
        try {
            $response = $this->getJson($url, $this->headers($token))->assertOk()
                ->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'DRAFT')
                ->assertJsonPath(self::PROJECTION.'.supplier.status', 'SOURCE_UNAVAILABLE')
                ->assertJsonPath(self::PROJECTION.'.supplier.name', null);
            $this->assertCount(1, $response->json('data.lines'));
            $this->getJson('/api/v1/mini-app/inbound-documents?warehouse_id='.$warehouse->id, $this->headers($token))
                ->assertOk()->assertJsonMissingPath('data.0.p12.owner_projection');
        } finally {
            Schema::rename('p12_test_unavailable_metadata', 'mini_app_inbound_record_metadata');
        }
    }

    public function test_current_creator_rename_does_not_mutate_missing_historical_snapshot(): void
    {
        [, $token, $warehouse] = $this->reader(['inbound.view', 'iam.user.view']);
        $creator = $this->user('Creator before rename');
        $id = $this->document($warehouse, 'inbound', $creator);
        $url = $this->url('inbound', $id, $warehouse);
        $this->getJson($url, $this->headers($token))->assertOk()
            ->assertJsonPath(self::PROJECTION.'.creator.current_display_name', 'Creator before rename');
        $creator->forceFill(['display_name' => 'Creator current label'])->save();
        $this->getJson($url, $this->headers($token))->assertOk()
            ->assertJsonPath(self::PROJECTION.'.creator.current_display_name', 'Creator current label')
            ->assertJsonPath(self::PROJECTION.'.creator.display_name', null)
            ->assertJsonPath(self::PROJECTION.'.creator.display_name_status', 'HISTORICAL_SNAPSHOT_NOT_RECORDED')
            ->assertJsonPath(self::PROJECTION.'.creator.role', null);
        $creator->delete();
        $this->getJson($url, $this->headers($token))->assertOk()
            ->assertJsonPath(self::PROJECTION.'.creator.actor_id', $creator->user_id)
            ->assertJsonPath(self::PROJECTION.'.creator.current_display_name', null)
            ->assertJsonPath(self::PROJECTION.'.creator.current_display_name_status', 'NOT_RECORDED');
    }

    public function test_deleted_wrong_type_and_unknown_parent_are_not_exposed_from_cached_detail(): void
    {
        [$creator, $token, $warehouse] = $this->reader(['inbound.view', 'outbound.view']);
        foreach (['inbound', 'outbound'] as $source) {
            $id = $this->document($warehouse, $source, $creator);
            $url = $this->url($source, $id, $warehouse);
            $this->getJson($url, $this->headers($token))->assertOk();
            DB::table('stock_documents')->where('id', $id)->update(['deleted_at' => now()]);
            $this->getJson($url, $this->headers($token))->assertNotFound()->assertJsonMissingPath(self::PROJECTION);
            $other = $this->document($warehouse, $source === 'inbound' ? 'outbound' : 'inbound', $creator);
            foreach ([$other, (string) Str::uuid()] as $missing) {
                $this->getJson($this->url($source, $missing, $warehouse), $this->headers($token))->assertNotFound();
            }
        }
    }

    public function test_warehouse_permission_and_authentication_are_checked_before_projection(): void
    {
        [$creator, $token, $warehouse] = $this->reader(['inbound.view', 'outbound.view']);
        [, $noPermission] = $this->reader([], $warehouse);
        $other = Warehouse::create(['code' => 'P12-OTHER-'.Str::random(6), 'name' => 'Other isolated warehouse', 'status' => 'INACTIVE']);
        foreach (['inbound', 'outbound'] as $source) {
            $id = $this->document($warehouse, $source, $creator);
            $hidden = $this->document($other, $source, $creator);
            $this->getJson($this->url($source, $hidden, $other), $this->headers($token))->assertForbidden();
            $this->getJson($this->url($source, $id, $warehouse), $this->headers($noPermission))->assertForbidden();
            $this->headers('');
            $this->getJson($this->url($source, $id, $warehouse))->assertUnauthorized();
        }
    }

    public function test_explicit_no_supplier_selection_is_distinct_from_missing_legacy_snapshot(): void
    {
        [$creator, $token, $warehouse] = $this->reader(['inbound.view']);
        $id = $this->document($warehouse, 'inbound', $creator);
        $this->supplierSnapshot($id, $warehouse, $creator, null);
        $this->getJson($this->url('inbound', $id, $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath(self::PROJECTION.'.supplier.status', 'NOT_SELECTED')
            ->assertJsonPath(self::PROJECTION.'.supplier.name', null)
            ->assertJsonPath(self::PROJECTION.'.supplier.source', 'mini_app_inbound_record_metadata');
    }

    public function test_missing_snapshot_name_stays_null_even_with_a_valid_current_supplier(): void
    {
        [$creator, $token, $warehouse] = $this->reader(['inbound.view']);
        $id = $this->document($warehouse, 'inbound', $creator);
        $supplier = $this->supplierSnapshot($id, $warehouse, $creator, 'Current directory is not missing snapshot');
        DB::table('mini_app_inbound_record_metadata')->where('document_id', $id)->update(['supplier_name' => null]);
        $this->getJson($this->url('inbound', $id, $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath(self::PROJECTION.'.supplier.id', $supplier)
            ->assertJsonPath(self::PROJECTION.'.supplier.name', null)
            ->assertJsonPath(self::PROJECTION.'.supplier.name_status', 'NOT_RECORDED');
    }

    public function test_global_reader_still_requires_the_requested_document_warehouse_and_live_parent(): void
    {
        [$creator, $token, $warehouse] = $this->reader(['inbound.view', 'outbound.view']);
        $creator->roles()->updateExistingPivot($creator->roles()->firstOrFail()->role_id, ['scope_ref' => null]);
        $creator->increment('version'); // Invalidate existing RBAC/scope cache after fixture role change.
        $this->assertNull(User::findOrFail($creator->user_id)->warehouseScopeIds());
        $other = Warehouse::create(['code' => 'P12-GLOBAL-'.Str::random(6), 'name' => 'Other isolated warehouse', 'status' => 'INACTIVE']);
        foreach (['inbound', 'outbound'] as $source) {
            $id = $this->document($warehouse, $source, $creator);
            $this->getJson($this->url($source, $id, $warehouse), $this->headers($token))->assertOk();
            $this->getJson($this->url($source, $id, $other), $this->headers($token))->assertNotFound();
            DB::table('stock_documents')->where('id', $id)->update(['deleted_at' => now()]);
            $this->getJson($this->url($source, $id, $warehouse), $this->headers($token))->assertNotFound();
        }
    }

    private function user(string $label): User
    {
        return User::create(['username' => 'p12-owner-'.Str::uuid(), 'display_name' => $label,
            'email' => Str::uuid().'@example.test', 'password_hash' => Hash::make(bin2hex(random_bytes(24))),
            'status' => 'ACTIVE', 'version' => 1]);
    }

    private function reader(array $permissions, ?Warehouse $warehouse = null): array
    {
        $warehouse ??= $this->ensureActiveWarehouse('KHO-TONG-HN', 'P12 isolated projection warehouse');
        $user = $this->user('Caller is not document creator');
        $password = bin2hex(random_bytes(24));
        $user->forceFill(['password_hash' => Hash::make($password)])->save();
        $role = Role::create(['role_code' => 'P12-OWNER-'.Str::uuid(), 'role_name' => 'Scoped projection reader',
            'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->headers('');
        $login = $this->browserLogin($user->email, $password)->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->headers($login->json('data.access_token')))->assertOk();

        return [$user, $selected->json('data.access_token'), $warehouse];
    }

    private function document(Warehouse $warehouse, string $source, User $creator): string
    {
        $sku = $this->productSkuFixture('P12-PROJECTION-'.Str::upper(Str::random(9)));
        if ($source === 'outbound') {
            $request = $this->outboundRequestFixture($sku->sku_id, 1, 'P12-OWNER-'.Str::upper(Str::random(8)));
            $id = $this->outboundDocumentFixture($warehouse->id, $request)['document_id'];
            DB::table('stock_documents')->where('id', $id)->update(['created_by' => $creator->user_id]);

            return $id;
        }
        $document = StockDocument::create(['doc_type' => 'receipt', 'doc_no' => 'P12-OWNER-'.Str::uuid(),
            'doc_date' => '2026-10-18', 'status' => 'DRAFT', 'dst_warehouse_id' => $warehouse->id,
            'created_by' => $creator->user_id, 'updated_by' => $creator->user_id]);
        StockDocumentLine::create(['stock_document_id' => $document->id, 'line_no' => 1,
            'product_id' => $sku->sku_id, 'qty_planned' => 1, 'qty_actual' => 0, 'scanned_qty' => 0]);

        return (string) $document->id;
    }

    private function supplierSnapshot(string $id, Warehouse $warehouse, User $creator, ?string $label): ?string
    {
        $draft = (string) Str::uuid();
        DB::table('mini_app_drafts')->insert(['document_id' => $draft, 'actor_id' => $creator->user_id,
            'auth_session_id' => (string) Str::uuid(), 'warehouse_id' => $warehouse->id, 'scan_session_id' => (string) Str::uuid(),
            'operation' => 'INBOUND', 'status' => 'RECORDED', 'codes' => '[]', 'form' => '{}', 'business_document_id' => $id,
            'last_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $supplier = $label === null ? null : (string) Str::uuid();
        if ($supplier !== null) {
            DB::table('inbound_suppliers')->insert(['id' => $supplier, 'warehouse_id' => $warehouse->id,
                'name' => $label, 'status' => 'ACTIVE', 'version' => 2, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('mini_app_inbound_record_metadata')->insert(['document_id' => $id, 'draft_id' => $draft,
            'inbound_type' => 'receipt', 'supplier_id' => $supplier, 'supplier_name' => $label,
            'supplier_version' => $label === null ? null : 2, 'recorded_at' => '2026-10-18 21:40:07.123456+07:00']);

        return $supplier;
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
        $this->flushHeaders();

        return $token === '' ? [] : ['Authorization' => 'Bearer '.$token];
    }

    private function url(string $source, string $id, Warehouse $warehouse): string
    {
        return '/api/v1/mini-app/'.$source.'-documents/'.$id.'?warehouse_id='.$warehouse->id;
    }

    private function businessState(): array
    {
        $result = [];
        foreach (['stock_documents', 'stock_document_lines', 'stock_movements', 'inventory_balances',
            'mini_app_drafts', 'mini_app_draft_receipts', 'mini_app_inbound_record_metadata', 'inbound_suppliers'] as $table) {
            $result[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }
}
