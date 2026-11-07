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
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\WarrantyCasePii;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Disposable SQLite evidence only; no live database or business command. */
class P06ComponentIssueLinksTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const HEADER = 'X-P06-Timeline-Link-Contract';
    private const CONTRACT = 'p06-component-issue-link-v1';
    private const RIGHTS = ['sku.view', 'item_instance.view', 'inventory.trace', 'warranty.view', 'warranty.manage', 'documents.read', 'warranty.component_issue'];

    public function test_opt_in_links_exact_posted_cid_on_sku_and_item_pages_without_changing_legacy_rows_or_writing(): void
    {
        $f = $this->fixture();
        $before = $this->domainSnapshot();
        foreach (['skus/'.$f['sku'], 'items/'.$f['item']] as $identity) {
            $legacy = $this->read($f, $identity, false)->assertOk()->assertJsonPath('data.0.document', null);
            $this->assertArrayNotHasKey('component_issue_link', $legacy->json('data.0'));
            $this->assertArrayNotHasKey('timeline_link_contract', $legacy->json('meta'));
            $linked = $this->read($f, $identity)->assertOk()
                ->assertJsonPath('meta.timeline_link_contract', self::CONTRACT)
                ->assertJsonPath('meta.component_issue_links.read_permitted', true)
                ->assertJsonPath('data.0.component_issue_link_status', 'AVAILABLE')
                ->assertJsonPath('data.0.component_issue_link.id', $f['cid'])
                ->assertJsonPath('data.0.component_issue_link.warranty_case_id', $f['case'])
                ->assertJsonPath('data.0.component_issue_link.warehouse_id', $f['warehouse'])
                ->assertJsonPath('data.0.component_issue_link.kind', 'COMPONENT_ISSUE_DOCUMENT')
                ->assertJsonPath('data.0.component_issue_link.current_status', 'POSTED')
                ->assertJsonPath('data.0.component_issue_link.can_open', true)
                ->assertJsonPath('data.0.signed_quantity', -2)->assertJsonPath('data.0.document', null);
            $row = $linked->json('data.0');
            unset($row['component_issue_link'], $row['component_issue_link_status']);
            $this->assertSame($legacy->json('data.0'), $row);
            $this->assertSame($legacy->json('meta.total'), $linked->json('meta.total'));
            foreach ([self::HEADER, 'Authorization', 'Origin'] as $vary) {
                $this->assertContains(strtolower($vary), array_map('strtolower', $linked->getVary()));
            }
        }
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_every_component_owner_permission_is_required_without_hiding_ledger_events(): void
    {
        foreach ([['sku.view', 'inventory.trace'], ['sku.view', 'inventory.trace', 'warranty.view', 'documents.read'],
            ['sku.view', 'inventory.trace', 'warranty.manage', 'warranty.component_issue'],
            ['sku.view', 'inventory.trace', 'documents.read', 'warranty.component_issue']] as $rights) {
            $f = $this->fixture($rights);
            $r = $this->read($f)->assertOk()->assertJsonPath('meta.total', 1)
                ->assertJsonPath('meta.component_issue_links.read_permitted', false)
                ->assertJsonPath('data.0.component_issue_link', null)->assertJsonPath('data.0.component_issue_link_status', 'FORBIDDEN');
            foreach ([$f['cid'], $f['case'], 'CID-PRIVATE-'] as $private) {
                $this->assertStringNotContainsString($private, $r->getContent());
            }
        }
    }

    public function test_wrong_document_line_sku_case_owner_or_source_never_creates_a_link(): void
    {
        foreach (['source_type', 'source_id', 'source_line_id', 'line_parent', 'line_sku', 'document_warehouse', 'case_owner', 'not_posted', 'movement_sign'] as $fault) {
            $f = $this->fixture(fault: $fault);
            $r = $this->read($f)->assertOk()->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.component_issue_link', null)->assertJsonPath('data.0.component_issue_link_status', 'NOT_ESTABLISHED');
            $this->assertStringNotContainsString($f['cid'], $r->getContent(), $fault);
        }
    }

    public function test_header_validation_and_explicit_fields_do_not_silently_negotiate_links(): void
    {
        $f = $this->fixture();
        $this->get($this->url($f), $this->headers($f) + [self::HEADER => 'p06-component-issue-link-v999'])
            ->assertStatus(422)->assertJsonPath('error_code', 'P06_TIMELINE_LINK_CONTRACT_UNSUPPORTED');
        $this->get('/api/v1/mini-app/catalog/context', $this->headers($f) + [self::HEADER => self::CONTRACT])
            ->assertStatus(422)->assertJsonPath('error_code', 'P06_TIMELINE_LINK_ROUTE_UNSUPPORTED');
        $explicit = ['X-Read-Fields-Contract' => 'explicit-read-fields-v1'];
        $only = $this->get($this->url($f), $this->headers($f) + $explicit)->assertOk();
        $this->assertArrayNotHasKey('component_issue_link', $only->json('data.0'));
        $both = $this->get($this->url($f), $this->headers($f) + $explicit + [self::HEADER => self::CONTRACT])->assertOk()
            ->assertJsonPath('meta.contract', 'explicit-read-fields-v1')
            ->assertJsonPath('meta.timeline_link_contract', self::CONTRACT)
            ->assertJsonPath('data.0.component_issue_link.id', $f['cid']);
        $this->assertContains(strtolower(self::HEADER), array_map('strtolower', $both->getVary()));
    }

    public function test_enrichment_is_page_batched_and_preserves_paging(): void
    {
        $f = $this->fixture();
        $row = (array) DB::table('stock_movements')->where('id', $f['movement'])->first();
        for ($n = 0; $n < 4; $n++) {
            DB::table('stock_movements')->insert(array_replace($row, ['id' => (string) Str::uuid(), 'movement_no' => 'MV-'.Str::random(16), 'business_key' => 'test-'.Str::uuid()]));
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->get($this->url($f).'&per_page=3', $this->headers($f) + [self::HEADER => self::CONTRACT])
                ->assertOk()->assertJsonPath('meta.total', 5)->assertJsonCount(3, 'data');
            $queries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'p06_component_document'));
            $this->assertCount(1, $queries);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_unresolved_source_tenancy_and_missing_schema_fail_closed_instead_of_becoming_unlinked_success(): void
    {
        Schema::table('component_issue_documents', fn ($table) => $table->uuid('tenant_id')->nullable());
        $f = $this->fixture();
        $this->read($f)->assertStatus(503)->assertJsonPath('error_code', 'WARRANTY_TENANCY_UNRESOLVED');
        Schema::drop('component_issue_lines');
        $this->read($f)->assertStatus(503)->assertJsonPath('error_code', 'P06_COMPONENT_LINK_SOURCE_UNAVAILABLE');
        $this->read($f, extension: false)->assertOk()->assertJsonPath('meta.total', 1);
    }

    private function fixture(array $rights = self::RIGHTS, ?string $fault = null): array
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $warehouse = $this->ensureActiveWarehouse('P06-LINKS', 'Isolated link test');
        $suffix = Str::lower(Str::random(10));
        $user = User::create(['username' => 'p06link_'.$suffix, 'display_name' => 'Link test', 'email' => $suffix.'@example.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'LINK_'.$suffix, 'role_name' => 'Link fixture', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($rights as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->headers(['token' => '']);
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], $this->headers(['token' => $login->json('data.access_token')]))->assertOk()->json('data.access_token');
        $sku = Sku::create(['sku_code' => 'LINK-'.$suffix, 'sku_name' => 'Component test', 'sku_type' => 'COMPONENT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $item = $this->seedItemInStock($sku->sku_id);
        $case = (string) Str::uuid();
        DB::table('warranty_case')->insert(WarrantyCasePii::encode(['warranty_case_id' => $case, 'warranty_case_code' => 'CASE-'.$suffix, 'product_id' => $sku->sku_id, 'item_id' => $item,
            'identification_status' => 'IDENTIFIED', 'status' => 'CHECKING', 'version' => 1, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
        if ($fault !== 'case_owner') {
            DB::table(WarrantyWarehouseScope::TABLE)->insert(['warranty_case_id' => $case, 'warehouse_id' => $warehouse->id, 'actor_id' => $user->user_id, 'auth_session_id' => (string) Str::uuid(), 'source' => 'EXPLICIT_REQUEST', 'assigned_at' => now()]);
        }
        $cid = (string) Str::uuid();
        $line = (string) Str::uuid();
        $other = (string) Str::uuid();
        DB::table('component_issue_documents')->insert(['id' => $cid, 'component_issue_no' => 'CID-PRIVATE-'.$suffix, 'warehouse_id' => $fault === 'document_warehouse' ? $other : $warehouse->id, 'warranty_case_id' => $case,
            'document_date' => now()->toDateString(), 'status' => $fault === 'not_posted' ? 'DRAFT' : 'POSTED', 'posted_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('component_issue_lines')->insert(['id' => $line, 'component_issue_document_id' => $fault === 'line_parent' ? $other : $cid, 'sku_id' => $fault === 'line_sku' ? $other : $sku->sku_id, 'line_no' => 1, 'required_qty' => 2, 'scanned_qty' => 2, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $movement = (string) Str::uuid();
        DB::table('stock_movements')->insert(['id' => $movement, 'movement_no' => 'MV-'.$suffix, 'business_key' => 'LINK-'.$suffix, 'movement_type' => 'COMPONENT_ISSUE',
            'warehouse_id' => $warehouse->id, 'product_id' => $sku->sku_id, 'sku_id' => $sku->sku_id, 'item_id' => $item, 'stock_status' => 'AVAILABLE', 'quantity' => 2,
            'qty_before' => 3, 'qty_delta' => $fault === 'movement_sign' ? 2 : -2, 'qty_after' => $fault === 'movement_sign' ? 5 : 1,
            'source_type' => $fault === 'source_type' ? 'OPENING' : 'COMPONENT_ISSUE_DOCUMENT', 'source_id' => $fault === 'source_id' ? $other : $cid, 'source_line_id' => $fault === 'source_line_id' ? $other : $line,
            'stock_document_id' => null, 'created_by' => $user->user_id, 'occurred_at' => now()->subHour(), 'created_at' => now()->subHour()]);
        return ['token' => $token, 'warehouse' => (string) $warehouse->id, 'sku' => (string) $sku->sku_id, 'item' => $item, 'case' => $case, 'cid' => $cid, 'line' => $line, 'movement' => $movement];
    }

    private function url(array $f, ?string $identity = null): string
    {
        return '/api/v1/mini-app/catalog/'.($identity ?? 'skus/'.$f['sku']).'/timeline?warehouse_id='.$f['warehouse'].'&source=LEDGER';
    }

    private function read(array $f, ?string $identity = null, bool $extension = true)
    {
        return $this->get($this->url($f, $identity), $this->headers($f) + ($extension ? [self::HEADER => self::CONTRACT] : []));
    }

    private function headers(array $f): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
        return ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$f['token']];
    }

    private function domainSnapshot(): array
    {
        $result = [];
        foreach (['stock_movements', 'inventory_balances', 'component_issue_documents', 'component_issue_lines', 'warranty_case', 'mini_app_draft_receipts'] as $table) {
            $result[$table] = hash('sha256', json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR));
        }
        return $result;
    }
}
