<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\PvRelations;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class P06CatalogContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/mini-app/catalog';

    private const RIGHTS = ['sku.view', 'item_instance.view', 'inventory.view', 'inventory.trace', 'warranty.view', 'warranty.manage', 'label.print'];

    public function test_all_p06_routes_are_read_only_and_require_authentication(): void
    {
        $id = (string) Str::uuid();
        foreach (['context', 'skus', 'items', 'skus/'.$id, 'items/'.$id, 'skus/'.$id.'/stock', 'skus/'.$id.'/gallery', 'skus/'.$id.'/timeline', 'items/'.$id.'/timeline'] as $path) {
            $this->catalogGet(self::BASE.'/'.$path)->assertUnauthorized();
        }
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/mini-app/catalog/')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods());
            }
        }
    }

    public function test_context_is_explicit_does_not_create_shift_or_invent_location_or_address(): void
    {
        [, $token, $warehouse] = $this->actorSession(confirm: false);
        $this->catalogGet(self::BASE.'/context', $this->headers($token))->assertOk()
            ->assertJsonPath('meta.contract', 'mini-app-p06-v1')->assertJsonPath('data.active_warehouse_id', null)
            ->assertJsonPath('data.read_only', true)->assertJsonPath('data.location_breakdown.supported', false)
            ->assertJsonPath('data.address_contract.dataset_version', null)->assertJsonPath('data.label_print.action_exposed', false);
        $this->catalogGet($this->url('skus', $warehouse), $this->headers($token))->assertConflict();
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_tab_counts_use_one_filter_and_snapshot_before_paging(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $a = $this->skuFixture('P06-COUNT-A');
        $b = $this->skuFixture('P06-COUNT-B');
        $c = $this->skuFixture('P06-COUNT-C', 'COMPONENT');
        $this->skuFixture('P06-COUNT-OFF', status: 'INACTIVE');
        $ids = [$a->sku_id, $b->sku_id];
        sort($ids);
        foreach ([1, 2] as $page) {
            $response = $this->catalogGet($this->url('skus', $warehouse, ['keyword' => 'P06-COUNT', 'sku_type' => 'PRODUCT', 'per_page' => 1, 'page' => $page]), $this->headers($token))
                ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.tab_totals.ALL', 3)
                ->assertJsonPath('meta.tab_totals.PRODUCT', 2)->assertJsonPath('meta.tab_totals.COMPONENT', 1)
                ->assertJsonPath('meta.count_unit', 'SKU')->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.sku_id', $ids[$page - 1]);
            $this->assertSame($response->json('meta.snapshot.id'), $response->json('data.0.stock.snapshot_id'));
        }
        $this->catalogGet($this->url('skus', $warehouse, ['keyword' => 'P06-COUNT', 'status' => 'ALL']), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_zero_empty_null_and_missing_resources_are_distinct(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-ZERO');
        $this->catalogGet($this->url('skus/'.$sku->sku_id, $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath('data.card.card_type', 'SKU')->assertJsonPath('data.card.item_id', null)
            ->assertJsonPath('data.card.serial_number', null)->assertJsonPath('data.stock.status', 'EMPTY')
            ->assertJsonPath('data.stock.total_qty', 0)->assertJsonPath('data.stock.location_breakdown', null)
            ->assertJsonCount(0, 'data.gallery');
        $this->catalogGet($this->url('skus', $warehouse, ['keyword' => 'NO-SUCH-P06']), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        $this->catalogGet($this->url('skus/'.Str::uuid(), $warehouse), $this->headers($token))->assertNotFound();
    }

    public function test_stock_is_scoped_and_never_allocated_to_a_location(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-STOCK');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 5);
        $this->movement($user, $warehouse, $sku, 5);
        DB::table('inventory_balances')->where('product_id', $sku->sku_id)->update(['available_qty' => 2, 'reserved_qty' => 1]);
        $before = $this->domainCounts();
        $response = $this->catalogGet($this->url('skus/'.$sku->sku_id.'/stock', $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED')->assertJsonPath('data.total_qty', 5)
            ->assertJsonPath('data.available_qty', 2)->assertJsonPath('data.reserved_qty', 1)->assertJsonPath('data.unavailable_qty', 2)
            ->assertJsonPath('data.location_level', 'WAREHOUSE')->assertJsonPath('data.location_breakdown_status', 'NOT_TRACKED');
        $this->assertSame($before, $this->domainCounts());
        $this->assertSame($response->json('meta.snapshot.id'), $response->json('data.snapshot_id'));
        fwrite(STDOUT, "\nP06_STOCK_EVIDENCE=".json_encode(['live_dev' => false, 'http_status' => 200, 'data' => $response->json('data'), 'snapshot' => $response->json('meta.snapshot')], JSON_UNESCAPED_SLASHES)."\n");
    }

    public function test_missing_projection_is_null_not_false_zero_and_mismatch_is_visible(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-INCOMPLETE');
        $this->movement($user, $warehouse, $sku, 2);
        $this->catalogGet($this->url('skus/'.$sku->sku_id.'/stock', $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath('data.status', 'INCOMPLETE')->assertJsonPath('data.total_qty', null)->assertJsonPath('data.ledger_total', 2);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $this->catalogGet($this->url('skus/'.$sku->sku_id.'/stock', $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath('data.status', 'MISMATCH')->assertJsonPath('data.total_qty', 1)->assertJsonPath('data.ledger_total', 2);
    }

    public function test_stock_fingerprint_tracks_data_not_observation_time(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-FINGERPRINT');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $url = $this->url('skus/'.$sku->sku_id.'/stock', $warehouse);
        $a = $this->catalogGet($url, $this->headers($token))->assertOk();
        $b = $this->catalogGet($url, $this->headers($token))->assertOk();
        $this->assertSame($a->json('data.version'), $b->json('data.version'));
        $this->assertNotSame($a->json('meta.snapshot.id'), $b->json('meta.snapshot.id'));
        DB::table('inventory_balances')->where('product_id', $sku->sku_id)->increment('version');
        $c = $this->catalogGet($url, $this->headers($token))->assertOk();
        $this->assertNotSame($a->json('data.version'), $c->json('data.version'));
    }

    public function test_sku_read_permission_does_not_grant_stock_item_or_timeline_access(): void
    {
        [, $token, $warehouse] = $this->actorSession(['sku.view']);
        $sku = $this->skuFixture('P06-RIGHTS');
        $this->catalogGet($this->url('skus/'.$sku->sku_id, $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath('data.stock', null)->assertJsonPath('data.stock_availability', 'FORBIDDEN');
        foreach (['items', 'skus/'.$sku->sku_id.'/stock', 'skus/'.$sku->sku_id.'/timeline'] as $path) {
            $this->catalogGet($this->url($path, $warehouse), $this->headers($token))->assertForbidden();
        }
        $this->catalogGet($this->url('skus', $warehouse, ['serial' => 'private-serial']), $this->headers($token))->assertForbidden();
    }

    public function test_item_identity_search_and_scope_do_not_duplicate_rows_or_infer_current_location(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-ITEM');
        $item = $this->seedItemInStock($sku->sku_id);
        DB::table('item_instances')->where('item_id', $item)->update(['item_code' => 'P06-ITEM-CODE', 'serial_number' => 'P06-SERIAL']);
        $this->movement($user, $warehouse, $sku, 1, $item);
        $this->seedScanCode($item, 'P06-PHYSICAL-1');
        $this->seedScanCode($item, 'P06-PHYSICAL-2');
        $this->catalogGet($this->url('items', $warehouse, ['serial' => 'p06-serial']), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.count_unit', 'ITEM')->assertJsonPath('data.0.item_id', $item)
            ->assertJsonPath('data.0.current_location', null)->assertJsonPath('data.0.stock', null);
        $this->catalogGet($this->url('skus', $warehouse, ['item_code' => 'P06-PHYSICAL-2']), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.sku_id', $sku->sku_id);
        $orphan = $this->seedItemInStock($sku->sku_id);
        $this->catalogGet($this->url('items/'.$orphan, $warehouse), $this->headers($token))->assertNotFound();
    }

    public function test_other_warehouse_item_and_ledger_never_leak_into_selected_scope(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $other = Warehouse::create(['code' => 'P06-OTHER', 'name' => 'Isolated other warehouse', 'status' => 'INACTIVE']);
        $sku = $this->skuFixture('P06-OTHER-SKU');
        $item = $this->seedItemInStock($sku->sku_id);
        $this->movement($user, $other, $sku, 1, $item);
        $this->catalogGet($this->url('items/'.$item, $warehouse), $this->headers($token))->assertNotFound();
        $this->catalogGet($this->url('skus/'.$sku->sku_id.'/timeline', $warehouse), $this->headers($token))->assertOk()->assertJsonPath('meta.total', 0);
        $this->catalogGet($this->url('skus', $other), $this->headers($token))->assertForbidden();
    }

    public function test_gallery_returns_real_ids_safe_urls_and_no_invented_thumbnail_or_expiry(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-GALLERY');
        $profile = (string) Str::uuid();
        $relation = PvRelations::profile();
        DB::table($relation['table'])->insert([$relation['key'] => $profile, $relation['sku'] => $sku->sku_id, 'display_name' => 'P06 gallery', 'sort_order' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        foreach ([url('/api/v1/pv/product-images/'.Str::uuid().'.png'), 'https://example.test/p06.jpg', 'javascript:alert(1)'] as $order => $url) {
            $id = (string) Str::uuid();
            $ids[] = $id;
            DB::table('pv_product_images')->insert(['id' => $id, 'profile_id' => $profile, 'image_url' => $url, 'storage_url' => $url,
                'alt_text' => null, 'is_primary' => $order === 0, 'sort_order' => $order, 'status' => 'ACTIVE', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->catalogGet($this->url('skus/'.$sku->sku_id.'/gallery', $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.image_id', $ids[0])->assertJsonPath('data.0.access', 'PUBLIC')
            ->assertJsonPath('data.0.thumbnail_url', null)->assertJsonPath('data.0.expires_at', null)
            ->assertJsonPath('data.1.access', 'UNKNOWN')->assertJsonPath('data.2.access', 'UNAVAILABLE')->assertJsonPath('data.2.full_url', null);
    }

    public function test_timeline_preserves_signed_ledger_and_null_warranty_quantities_with_paging(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-TIMELINE');
        $item = $this->seedItemInStock($sku->sku_id);
        $first = $this->movement($user, $warehouse, $sku, 3, $item);
        $second = $this->movement($user, $warehouse, $sku, -1, $item);
        DB::table('item_instances')->where('item_id', $item)->update(['item_status' => 'ISSUED']);
        // Owner P09 creates this fixture only in the isolated test database.
        $case = $this->postJson('/api/v1/mini-app/warranty-cases', ['warehouse_id' => $warehouse->id, 'item_id' => $item, 'customer_name' => 'P06 CI', 'customer_phone' => '0900000000', 'description' => 'P06 isolated warranty'], $this->commandHeaders($token))->assertCreated();
        $response = $this->catalogGet($this->url('items/'.$item.'/timeline', $warehouse), $this->headers($token))->assertOk()->assertJsonPath('meta.total', 3);
        $events = collect($response->json('data'));
        $this->assertEquals(-1, $events->firstWhere('source_event_id', $second)['signed_quantity']);
        $this->assertNull($events->firstWhere('source', 'WARRANTY')['signed_quantity']);
        $this->assertArrayNotHasKey('note', $events->firstWhere('source', 'WARRANTY'));
        $this->assertStringNotContainsString('0900000000', $response->getContent());
        $this->catalogGet($this->url('items/'.$item.'/timeline', $warehouse, ['per_page' => 1, 'page' => 2]), $this->headers($token))->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data');
    }

    public function test_signed_integer_ledger_quantity_is_preserved_for_component_skus(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-INTEGER', 'COMPONENT');
        // Canonical qty_delta is BIGINT and movements have a nonzero magnitude.
        // Test a real zero aggregate with 3 - 1 - 2, not an invalid quantity=0
        // movement that only SQLite's less strict schema used to accept.
        $positive = $this->movement($user, $warehouse, $sku, 3);
        $negative = $this->movement($user, $warehouse, $sku, -1);
        $closing = $this->movement($user, $warehouse, $sku, -2, before: 2);
        $response = $this->catalogGet($this->url('skus/'.$sku->sku_id.'/timeline', $warehouse, ['source' => 'LEDGER']), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.sources.WARRANTY', 'NOT_REQUESTED');
        $events = collect($response->json('data'))->keyBy('source_event_id');
        $this->assertSame(3, $events[$positive]['signed_quantity']);
        $this->assertSame(-1, $events[$negative]['signed_quantity']);
        $this->assertSame(-2, $events[$closing]['signed_quantity']);
        $this->assertSame(0, $events->sum('signed_quantity'));
        // A known zero ledger is not a confirmed zero stock projection.
        $this->catalogGet($this->url('skus/'.$sku->sku_id.'/stock', $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath('data.ledger_total', 0)->assertJsonPath('data.total_qty', null)->assertJsonPath('data.status', 'INCOMPLETE');
    }

    public function test_timeline_excludes_unpermitted_source_instead_of_fabricating_zero_total(): void
    {
        [$user, $token, $warehouse] = $this->actorSession(['sku.view', 'inventory.trace']);
        $sku = $this->skuFixture('P06-SOURCES');
        $this->movement($user, $warehouse, $sku, 1);
        $this->catalogGet($this->url('skus/'.$sku->sku_id.'/timeline', $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.sources.LEDGER', 'INCLUDED')->assertJsonPath('meta.sources.WARRANTY', 'FORBIDDEN');
        $this->catalogGet($this->url('skus/'.$sku->sku_id.'/timeline', $warehouse, ['source' => 'WARRANTY']), $this->headers($token))->assertForbidden();
    }

    public function test_temp_only_is_not_attached_to_a_sku_by_manual_description(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-TEMP');
        $this->postJson('/api/v1/mini-app/warranty-cases', ['warehouse_id' => $warehouse->id, 'customer_name' => 'P06 CI', 'customer_phone' => '0900000000',
            'description' => 'P06', 'missing_code_reason' => 'No code', 'manual_product_description' => $sku->sku_code], $this->commandHeaders($token))->assertCreated();
        $this->catalogGet($this->url('skus/'.$sku->sku_id.'/timeline', $warehouse, ['source' => 'WARRANTY']), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_invalid_unknown_filters_and_literal_wildcards_are_not_silently_ignored(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-WILDCARD');
        foreach ([['status' => 'PAUSED'], ['sku_type' => 'FAKE'], ['location_id' => (string) Str::uuid()], ['per_page' => 101]] as $filter) {
            $this->catalogGet($this->url('skus', $warehouse, $filter), $this->headers($token))->assertStatus(422);
        }
        foreach ([['date_from' => '2026-10-08'], ['type' => 'INBOUND'], ['cursor' => 'not-a-v1-cursor']] as $filter) {
            $this->catalogGet($this->url('skus/'.$sku->sku_id.'/timeline', $warehouse, $filter), $this->headers($token))
                ->assertStatus(422)->assertJsonPath('error.code', 'UNEXPECTED_FIELD');
        }
        $this->catalogGet($this->url('skus', $warehouse, ['keyword' => '%']), $this->headers($token))->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson(self::BASE.'/skus', [], $this->headers($token))->assertStatus(405);
    }

    public function test_permission_withdrawal_refresh_and_logout_are_respected_without_read_cache(): void
    {
        [$user, $token, $warehouse, $cookie] = $this->actorSession();
        $before = $this->catalogGet(self::BASE.'/context', $this->headers($token))->assertOk();
        $this->headers($token);
        $next = $this->browserRefresh($cookie)->assertOk();
        $newToken = $next->json('data.access_token');
        $this->catalogGet(self::BASE.'/context', $this->headers($newToken))->assertOk()->assertJsonPath('data.auth_session_id', $before->json('data.auth_session_id'));
        $permission = Permission::where('permission_key', 'sku.view')->value('permission_id');
        DB::table('role_permission')->where('role_id', $user->roles()->first()->role_id)->where('permission_id', $permission)->delete();
        $user->increment('version');
        $this->catalogGet($this->url('skus', $warehouse), $this->headers($newToken))->assertForbidden();
        $this->withCredentials()->withUnencryptedCookie((string) config('refresh_token.cookie.name'), $this->refreshTokenFromCookie($next))
            ->postJson('/api/v1/auth/logout', [], $this->headers($newToken))->assertOk();
        $this->catalogGet(self::BASE.'/context', $this->headers($newToken))->assertUnauthorized();
    }

    public function test_print_permission_never_creates_a_print_action_for_a_sku(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-PRINT');
        $before = $this->domainCounts();
        $this->catalogGet($this->url('skus/'.$sku->sku_id, $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath('data.capabilities.label_print.permission_granted', true)
            ->assertJsonPath('data.capabilities.label_print.target_type', 'LABEL_SNAPSHOT')
            ->assertJsonPath('data.capabilities.label_print.target_id', null)
            ->assertJsonPath('data.capabilities.label_print.action_exposed', false);
        $this->assertSame($before, $this->domainCounts());
    }

    public function test_authenticated_nine_route_smoke_is_isolated_and_stock_neutral(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-NINE-READS');
        $item = $this->seedItemInStock($sku->sku_id);
        $this->movement($user, $warehouse, $sku, 1, $item);
        $before = $this->domainCounts();
        $paths = ['context', 'skus', 'items', 'skus/'.$sku->sku_id, 'items/'.$item,
            'skus/'.$sku->sku_id.'/stock', 'skus/'.$sku->sku_id.'/gallery',
            'skus/'.$sku->sku_id.'/timeline', 'items/'.$item.'/timeline'];
        foreach ($paths as $path) {
            $url = $path === 'context' ? self::BASE.'/context' : $this->url($path, $warehouse);
            $response = $this->catalogGet($url, $this->headers($token))->assertOk()
                ->assertJsonPath('meta.contract', 'mini-app-p06-v1');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertSame($before, $this->domainCounts());
        }
        fwrite(STDOUT, "\nP06_ISOLATED_ROUTE_SMOKE=".json_encode(['live_dev' => false, 'routes' => 9, 'http_status' => 200, 'stock_mutations' => 0], JSON_THROW_ON_ERROR)."\n");
    }

    public function test_get_bodies_remain_rejected_instead_of_relaxing_the_runtime_guard(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $url = $this->url('skus', $warehouse);
        $this->catalogGet($url, $this->headers($token))->assertOk();
        // getJson() sends an empty JSON array; query-only GET must reject it too.
        $this->getJson($url, $this->headers($token))->assertStatus(422)->assertJsonPath('error.code', 'P06_QUERY_ONLY');
        $this->json('GET', $url, ['warehouse_id' => (string) Str::uuid()], $this->headers($token))
            ->assertStatus(422)->assertJsonPath('error.code', 'P06_QUERY_ONLY');
        $this->catalogGet($url, $this->headers($token))->assertOk();
    }

    public function test_suspended_selected_warehouse_is_not_rendered_as_empty_stock(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $sku = $this->skuFixture('P06-SUSPENDED');
        DB::table('warehouses')->where('id', $warehouse->id)->update(['status' => 'INACTIVE']);
        $this->catalogGet($this->url('skus/'.$sku->sku_id.'/stock', $warehouse), $this->headers($token))->assertConflict();
        $this->catalogGet(self::BASE.'/context', $this->headers($token))->assertOk()
            ->assertJsonPath('data.active_warehouse_id', null)->assertJsonPath('data.warehouse_status', 'INACTIVE');
    }

    private function actorSession(array $permissions = self::RIGHTS, bool $confirm = true): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P06 isolated warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p06_'.$suffix, 'display_name' => 'P06 CI', 'email' => 'p06_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P06_'.$suffix, 'role_name' => 'Arbitrary P06 test role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $token = $login->json('data.access_token');
        if ($confirm) {
            $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], $this->headers($token))->assertOk()->json('data.access_token');
        }

        return [$user, $token, $warehouse, $this->refreshTokenFromCookie($login)];
    }

    private function skuFixture(string $code, string $type = 'PRODUCT', string $status = 'ACTIVE'): Sku
    {
        return Sku::create(['sku_code' => $code, 'sku_name' => 'Same P06 card name', 'sku_type' => $type, 'unit' => 'cai', 'is_serial_tracked' => $type === 'PRODUCT', 'status' => $status]);
    }

    private function movement(User $user, object $warehouse, Sku $sku, int $delta, ?string $item = null, ?int $before = null): string
    {
        $id = (string) Str::uuid();
        $before ??= $delta < 0 ? 3 : 0;
        $row = ['id' => $id, 'movement_no' => 'P06-'.Str::random(12), 'business_key' => 'P06-'.$id,
            'movement_type' => $delta < 0 ? 'ISSUE' : 'OPENING', 'warehouse_id' => $warehouse->id, 'product_id' => $sku->sku_id,
            'stock_status' => 'AVAILABLE', 'qty_before' => $before, 'qty_delta' => $delta, 'qty_after' => $before + $delta,
            'created_by' => $user->user_id, 'occurred_at' => now()->subHour(), 'created_at' => now()->subHour()];
        foreach (['item_id' => $item, 'sku_id' => $sku->sku_id, 'quantity' => abs($delta), 'source_type' => 'OPENING', 'source_id' => $id] as $field => $value) {
            if (Schema::hasColumn('stock_movements', $field)) {
                $row[$field] = $value;
            }
        }
        DB::table('stock_movements')->insert($row);

        return $id;
    }

    private function domainCounts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(), ['stock_documents', 'stock_movements', 'inventory_balances', 'warranty_case', 'label_snapshots', 'mini_app_drafts', 'mini_app_work_shifts']);
    }

    private function url(string $path, object $warehouse, array $query = []): string
    {
        return self::BASE.'/'.$path.'?'.http_build_query(['warehouse_id' => $warehouse->id] + $query);
    }

    /** Accept JSON responses without sending a JSON body on GET. */
    private function catalogGet(string $url, array $headers = []): TestResponse
    {
        return $this->get($url, ['Accept' => 'application/json'] + $headers);
    }

    private function commandHeaders(string $token): array
    {
        return $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid(), 'X-Request-ID' => (string) Str::uuid()];
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }
}
