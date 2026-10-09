<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\MiniAppNfcController;
use Modules\Warehouse\Http\Controllers\P07NfcController;
use Modules\Warehouse\Http\Middleware\P07NfcReadbackGuard;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** All fixture data lives in the isolated test database, never in application responses. */
class P07NfcContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/mini-app/';

    public function test_auth_context_and_permission_are_required(): void
    {
        $this->getJson(self::BASE.'nfc-tags')->assertUnauthorized();
        [, $token] = $this->actorSession(confirm: false);
        $this->getJson(self::BASE.'nfc-tags', $this->headers($token))->assertConflict();
        [, $token] = $this->actorSession(rights: ['inventory.trace']);
        $this->getJson(self::BASE.'nfc-tags', $this->headers($token))->assertOk();
        $this->postJson(self::BASE.'items/'.Str::uuid().'/nfc/prepare', [], $this->headers($token))->assertForbidden();
    }

    public function test_item_keyword_is_not_silently_replaced_by_search(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        [$item] = $this->itemFixture($user, $warehouse);
        $serial = DB::table('item_instances')->where('item_id', $item)->value('serial_number');
        $this->getJson(self::BASE.'items?keyword='.urlencode($serial), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.item_id', $item);
        $this->getJson(self::BASE.'items?search='.urlencode($serial), $this->headers($token))->assertUnprocessable();
    }

    public function test_confirm_replay_receipt_and_reconciliation_are_stock_neutral(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        [$item] = $this->itemFixture($user, $warehouse);
        $before = $this->stockCounts();
        $body = $this->preparedBody($token, $item);
        $key = (string) Str::uuid();
        $url = self::BASE.'items/'.$item.'/nfc';
        $first = $this->patchJson($url, $body, $this->headers($token, $key))->assertCreated()
            ->assertJsonPath('data.confirmation.item_id', $item)
            ->assertJsonPath('data.nfc.linker.user_id', $user->user_id)
            ->assertJsonPath('data.nfc.assigned_warehouse.warehouse_id', $warehouse->id)
            ->assertJsonPath('data.movement_created', false);
        $this->assertNull(Cache::get('wms:nfc:write-reservation:'.$item));
        $again = $this->patchJson($url, $body, $this->headers($token, $key))->assertCreated();
        $this->assertSame($first->json(), $again->json());
        $this->getJson($url.'/confirmation', $this->headers($token, $key))->assertOk()
            ->assertJsonPath('data.state', 'SUCCEEDED')->assertJsonPath('data.response.data.confirmation.item_id', $item);
        $changed = [...$body, 'hardware_uid' => 'AABBCCDD99'];
        $this->patchJson($url, $changed, $this->headers($token, $key))->assertConflict();
        $this->getJson($url.'/confirmation', $this->headers($token, (string) Str::uuid()))
            ->assertOk()->assertJsonPath('data.state', 'UNKNOWN')->assertJsonPath('data.may_create_new_request', false);
        $this->assertSame($before, $this->stockCounts());
        $this->assertSame(1, DB::table('physical_codes')->where('item_id', $item)->where('carrier_type', 'NFC')->count());
        fwrite(STDOUT, "\nP07_RECEIPT_EVIDENCE=".json_encode(['http_status' => 201, 'data' => $first->json('data'), 'meta' => $first->json('meta')], JSON_UNESCAPED_SLASHES)."\n");
    }

    public function test_no_replace_missing_readback_and_mismatched_payload_are_rejected(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        [$item] = $this->itemFixture($user, $warehouse);
        $body = $this->preparedBody($token, $item);
        $url = self::BASE.'items/'.$item.'/nfc';
        $this->patchJson($url, [...$body, 'replace_existing' => true], $this->headers($token, (string) Str::uuid()))->assertUnprocessable();
        $bad = $body;
        unset($bad['written_payload']);
        $this->patchJson($url, $bad, $this->headers($token, (string) Str::uuid()))->assertUnprocessable();
        $this->patchJson($url, [...$body, 'written_payload' => $body['written_payload'].'-WRONG'], $this->headers($token, (string) Str::uuid()))->assertUnprocessable();
        $this->patchJson($url, $body, $this->headers($token))
            ->assertStatus(428)->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');
        $this->assertSame(0, DB::table('physical_codes')->where('carrier_type', 'NFC')->count());
    }

    public function test_reservation_expiry_session_and_token_are_enforced_and_release_is_safe(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        [$item] = $this->itemFixture($user, $warehouse);
        $body = $this->preparedBody($token, $item);
        $url = self::BASE.'items/'.$item.'/nfc';
        $cacheKey = 'wms:nfc:write-reservation:'.$item;
        $held = Cache::get($cacheKey);
        $this->patchJson($url, [...$body, 'reservation_token' => (string) Str::uuid()], $this->headers($token, (string) Str::uuid()))->assertConflict();
        Cache::put($cacheKey, [...$held, 'auth_session_id' => (string) Str::uuid()], 300);
        $this->patchJson($url, $body, $this->headers($token, (string) Str::uuid()))->assertConflict();
        Cache::put($cacheKey, [...$held, 'expires_at' => now()->subSecond()->toIso8601String()], 300);
        $this->patchJson($url, $body, $this->headers($token, (string) Str::uuid()))->assertConflict();
        Cache::put($cacheKey, $held, 300);
        $this->postJson($url.'/release', ['reservation_token' => $body['reservation_token']], $this->headers($token))->assertOk()->assertJsonPath('data.released', true);
        $this->postJson($url.'/release', ['reservation_token' => $body['reservation_token']], $this->headers($token))->assertOk()->assertJsonPath('data.released', false);
        $this->patchJson($url, $body, $this->headers($token, (string) Str::uuid()))->assertConflict();
    }

    public function test_search_counts_detail_and_locked_label_come_from_the_same_scoped_data(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $ids = [];
        foreach (['ACTIVE', 'LOST', 'DAMAGED'] as $index => $status) {
            [$item] = $this->itemFixture($user, $warehouse);
            $body = $this->preparedBody($token, $item, 'AABBCCDD0'.$index);
            $response = $this->patchJson(self::BASE.'items/'.$item.'/nfc', $body, $this->headers($token, (string) Str::uuid()))->assertCreated();
            $id = $response->json('data.nfc.physical_code_id');
            $ids[] = $id;
            if ($status !== 'ACTIVE') {
                $this->postJson(self::BASE.'nfc-tags/'.$id.'/deactivate', ['status' => $status, 'reason' => 'Isolated test'], $this->headers($token))
                    ->assertOk();
                $this->getJson(self::BASE.'nfc-tags/'.$id, $this->headers($token))->assertOk()
                    ->assertJsonPath('data.tab_status', 'LOCKED')->assertJsonPath('data.status_label', 'Tạm khóa');
            }
        }
        $this->getJson(self::BASE.'nfc-tags?search=AABBCCDD&tab_status=LOCKED&per_page=1', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.tab_totals.ALL', 3)
            ->assertJsonPath('meta.tab_totals.ASSIGNED', 1)->assertJsonPath('meta.tab_totals.LOCKED', 2)
            ->assertJsonPath('meta.tab_totals.UNASSIGNED', 0)->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'nfc-tags?search=%25', $this->headers($token))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::BASE.'nfc-tags/'.$ids[0], $this->headers($token))->assertOk()->assertJsonPath('data.physical_code_id', $ids[0]);
        $this->getJson(self::BASE.'nfc-tags?physical_code_id='.$ids[0], $this->headers($token))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::BASE.'nfc-tags?tab_status=UNASSIGNED', $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.unscoped_unlinked_tags_included', false);
    }

    public function test_orphan_items_and_wrong_warehouse_are_not_exposed(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        [$visible, $sku] = $this->itemFixture($user, $warehouse);
        $orphan = $this->seedItemInStock($sku->sku_id);
        $this->postJson(self::BASE.'items/'.$orphan.'/nfc/prepare', [], $this->headers($token))->assertNotFound();
        $this->getJson(self::BASE.'items', $this->headers($token))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.item_id', $visible);
        $this->getJson(self::BASE.'items?warehouse_id='.Str::uuid(), $this->headers($token))->assertConflict();
        $this->getJson(self::BASE.'nfc-tags/not-a-uuid', $this->headers($token))->assertNotFound();
    }

    public function test_uid_payload_identity_inactive_tag_and_generated_alias_are_guarded(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        [$item] = $this->itemFixture($user, $warehouse);
        $body = $this->preparedBody($token, $item);
        $url = self::BASE.'items/'.$item.'/nfc';
        $this->patchJson($url, [...$body, 'hardware_uid' => str_repeat('A', 100)], $this->headers($token, (string) Str::uuid()))
            ->assertUnprocessable()->assertJsonPath('error_code', 'NFC_CODE_VALUE_INVALID');
        $first = $this->patchJson($url, $body, $this->headers($token, (string) Str::uuid()))->assertCreated();
        $resolve = self::BASE.'nfc/resolve-tag';
        $read = ['hardware_uid' => $body['hardware_uid'], 'raw_code' => $body['written_payload']];
        $this->postJson($resolve, $read, $this->headers($token))->assertOk()->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.item.item_id', $item);
        $this->postJson($resolve, [...$read, 'hardware_uid' => 'FFFFFFFFFF'], $this->headers($token))
            ->assertUnprocessable()->assertJsonPath('error_code', 'NFC_UID_PAYLOAD_MISMATCH');
        $this->postJson($resolve, [...$read, 'raw_code' => $read['raw_code'].'-WRONG'], $this->headers($token))
            ->assertUnprocessable()->assertJsonPath('error_code', 'NFC_PAYLOAD_ITEM_MISMATCH');
        $this->postJson(self::BASE.'nfc-tags/'.$first->json('data.nfc.physical_code_id').'/deactivate',
            ['status' => 'LOST', 'reason' => 'Isolated test'], $this->headers($token))->assertOk();
        $this->postJson($resolve, $read, $this->headers($token))->assertConflict()->assertJsonPath('error_code', 'NFC_TAG_INACTIVE');
    }

    public function test_routes_keep_the_canonical_writer_and_apply_readback_guard(): void
    {
        $this->assertInstanceOf(P07NfcController::class, app(MiniAppNfcController::class));
        $guarded = 0;
        foreach (Route::getRoutes() as $route) {
            if (in_array($route->uri(), ['api/v1/mini-app/nfc/resolve-tag', 'api/v1/mini-app/items/{itemId}/nfc'], true)) {
                $this->assertContains('auth:api', $route->gatherMiddleware());
                $this->assertContains(P07NfcReadbackGuard::class, $route->gatherMiddleware());
                $guarded++;
            }
        }
        $this->assertSame(2, $guarded);
    }

    private function preparedBody(string $token, string $item, string $uid = 'AABBCCDDEE'): array
    {
        $p = $this->postJson(self::BASE.'items/'.$item.'/nfc/prepare', [], $this->headers($token))->assertOk();

        return ['hardware_uid' => $uid, 'written_payload' => $p->json('data.nfc_payload'), 'reservation_token' => $p->json('data.reservation_token')];
    }

    private function actorSession(array $rights = ['physical_code.assign_rfid', 'inventory.trace'], bool $confirm = true): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P07 isolated warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p07_'.$suffix, 'display_name' => 'P07 CI', 'email' => 'p07_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P07_'.$suffix, 'role_name' => 'Arbitrary P07 test role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($rights as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $token = $login->json('data.access_token');
        if ($confirm) {
            $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], $this->headers($token))->assertOk()->json('data.access_token');
        }

        return [$user, $token, $warehouse];
    }

    private function itemFixture(User $user, object $warehouse): array
    {
        $suffix = Str::upper(Str::random(8));
        $sku = Sku::create(['sku_code' => 'P07-'.$suffix, 'sku_name' => 'P07 isolated SKU', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => true, 'status' => 'ACTIVE']);
        $item = $this->seedItemInStock($sku->sku_id);
        DB::table('item_instances')->where('item_id', $item)->update(['serial_number' => 'P07-SERIAL-'.$suffix]);
        $id = (string) Str::uuid();
        $row = ['id' => $id, 'movement_no' => 'P07-'.Str::random(12), 'business_key' => 'P07-'.$id,
            'movement_type' => 'OPENING', 'warehouse_id' => $warehouse->id, 'product_id' => $sku->sku_id,
            'stock_status' => 'AVAILABLE', 'qty_before' => 0, 'qty_delta' => 1, 'qty_after' => 1,
            'created_by' => $user->user_id, 'occurred_at' => now()->subHour(), 'created_at' => now()->subHour()];
        foreach (['item_id' => $item, 'sku_id' => $sku->sku_id, 'quantity' => 1, 'source_type' => 'OPENING', 'source_id' => $id] as $field => $value) {
            if (Schema::hasColumn('stock_movements', $field)) {
                $row[$field] = $value;
            }
        }
        DB::table('stock_movements')->insert($row);

        return [$item, $sku];
    }

    private function headers(string $token, ?string $key = null): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token] + ($key === null ? [] : ['Idempotency-Key' => $key]);
    }

    private function stockCounts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(), ['stock_documents', 'stock_movements', 'inventory_balances']);
    }
}
