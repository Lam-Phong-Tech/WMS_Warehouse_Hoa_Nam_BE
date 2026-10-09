<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
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
use Modules\Warehouse\Http\Middleware\P07NfcRecoveryGuard as Guard;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Isolated actors/items only. Never operates on a real NFC chip or DEV database. */
class P07NfcRecoveryTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_inactive_sku_cannot_acquire_or_extend_prepare_reservation(): void
    {
        [$actor, $token, $warehouse] = $this->actor();
        [$item, $sku] = $this->item($actor, $warehouse);
        $sku->update(['status' => 'INACTIVE']);
        $before = $this->stocks();
        $this->postJson($this->url($item).'/prepare', [], $this->headers($token))->assertStatus(422)->assertJsonPath('error.code', 'SKU_INACTIVE');
        $this->assertNull(Cache::get($this->hold($item)));
        $this->assertDatabaseCount(Guard::TABLE, 0);
        $this->assertSame($before, $this->stocks());
        $sku->update(['status' => 'ACTIVE']);
        $this->prepared($token, $item);
        $held = Cache::get($this->hold($item));
        $sku->update(['status' => 'INACTIVE']);
        $this->postJson($this->url($item).'/prepare', [], $this->headers($token))->assertStatus(422);
        $this->assertSame($held, Cache::get($this->hold($item)));
    }

    public function test_deactivation_after_prepare_has_a_durable_prewrite_rejection(): void
    {
        [$actor, $token, $warehouse] = $this->actor();
        [$item, $sku] = $this->item($actor, $warehouse);
        $body = $this->prepared($token, $item);
        $sku->update(['status' => 'INACTIVE']);
        $key = (string) Str::uuid();
        $trace = (string) Str::uuid();
        $before = $this->stocks();
        $response = $this->patchJson($this->url($item), $body, $this->headers($token, $key) + ['X-Request-ID' => $trace])
            ->assertStatus(422)->assertJsonPath('error.code', 'SKU_INACTIVE')
            ->assertJsonPath('data.confirmation.state', 'REJECTED')
            ->assertJsonPath('data.confirmation.evidence_type', 'SERVER_PREWRITE_SKU_CHECK')
            ->assertJsonPath('data.confirmation.original_request_id', $trace)
            ->assertJsonPath('data.confirmation.mapping_created_by_request', false);
        $this->assertStringNotContainsString($body['reservation_token'], $response->getContent());
        $this->assertStringNotContainsString($body['written_payload'], $response->getContent());
        $this->assertDatabaseCount(Guard::TABLE, 1);
        $this->assertSame(0, DB::table('physical_codes')->where('carrier_type', 'NFC')->count());
        $this->assertSame($before, $this->stocks());
    }

    public function test_sku_activation_never_executes_a_previously_rejected_key(): void
    {
        [, $token, , $item, $sku, $body, $key] = $this->rejected();
        $receipt = DB::table(Guard::TABLE)->first();
        $sku->update(['status' => 'ACTIVE']);
        $this->patchJson($this->url($item), $body, $this->headers($token, $key))
            ->assertStatus(422)->assertJsonPath('error.code', 'SKU_INACTIVE')
            ->assertJsonPath('data.confirmation.receipt_id', $receipt->receipt_id)->assertHeader('Idempotency-Replayed', 'true');
        $this->patchJson($this->url($item), [...$body, 'hardware_uid' => '0123459999'], $this->headers($token, $key))
            ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount(Guard::TABLE, 1);
        $this->assertSame(0, DB::table('physical_codes')->where('carrier_type', 'NFC')->count());
    }

    public function test_default_confirmation_is_compatible_but_opt_in_exposes_rejection(): void
    {
        [, $token, , $item, $sku, , $key] = $this->rejected();
        $url = $this->url($item).'/confirmation';
        $this->getJson($url, $this->headers($token, $key))->assertOk()->assertJsonPath('data.state', 'UNKNOWN')
            ->assertJsonPath('data.response', null)->assertJsonPath('data.may_create_new_request', false)
            ->assertJsonPath('data.recovery_contract_required', Guard::CONTRACT);
        $this->getJson($url, $this->headers($token, $key, true))->assertOk()->assertJsonPath('data.state', 'REJECTED')
            ->assertJsonPath('data.may_create_new_request', false)->assertJsonPath('data.receipt.request_key_sha256', hash('sha256', $key));
        $sku->update(['status' => 'ACTIVE']);
        $this->getJson($url, $this->headers($token, $key, true))->assertOk()->assertJsonPath('data.state', 'REJECTED')
            ->assertJsonPath('data.may_create_new_request', true)->assertJsonPath('data.recovery.next_action', 'PREPARE_WITH_RECOVERY_KEY');
    }

    public function test_legacy_unknown_cannot_be_promoted_to_a_recovery_receipt(): void
    {
        [$actor, $token, $warehouse] = $this->actor();
        [$item] = $this->item($actor, $warehouse);
        $key = (string) Str::uuid();
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $key, true))->assertOk()
            ->assertJsonPath('data.state', 'UNKNOWN')->assertJsonPath('data.response', null)->assertJsonPath('data.may_create_new_request', false);
        $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($token, $key))
            ->assertConflict()->assertJsonPath('error.code', 'NFC_RECOVERY_NOT_PROVEN');
        $this->assertDatabaseCount(Guard::TABLE, 0);
        $this->assertNull(Cache::get($this->hold($item)));
    }

    public function test_recovery_requires_explicit_old_key_and_replaces_only_its_own_hold(): void
    {
        [, $token, , $item, $sku, $oldBody, $key] = $this->rejected();
        $sku->update(['status' => 'ACTIVE']);
        $this->postJson($this->url($item).'/prepare', [], $this->headers($token))
            ->assertConflict()->assertJsonPath('error.code', 'NFC_RECOVERY_REQUIRED');
        $this->postJson($this->url($item).'/prepare', [], $this->headers($token) + ['X-NFC-Recovery-Key' => $key])
            ->assertStatus(422)->assertJsonPath('error.code', 'NFC_RECOVERY_CONTRACT_REQUIRED');
        $new = $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($token, $key))->assertOk()
            ->assertJsonPath('data.recovery.blank_new_tag_required', true)->assertJsonPath('data.recovery.old_tag_reuse_allowed', false);
        $this->assertNotSame($oldBody['reservation_token'], $new->json('data.reservation_token'));
        $again = $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($token, $key))->assertOk();
        $this->assertSame($new->json('data.reservation_token'), $again->json('data.reservation_token'));
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $key, true))->assertOk()
            ->assertJsonPath('data.may_create_new_request', false)->assertJsonPath('data.recovery.permit_consumed', true);
    }

    public function test_new_key_cannot_reuse_rejected_reservation_or_written_chip(): void
    {
        [, $token, , $item, $sku, $oldBody, $key] = $this->rejected();
        $sku->update(['status' => 'ACTIVE']);
        $this->patchJson($this->url($item), $oldBody, $this->headers($token, (string) Str::uuid()))
            ->assertConflict()->assertJsonPath('error.code', 'NFC_REJECTED_RESERVATION');
        $p = $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($token, $key))->assertOk();
        $body = [...$oldBody, 'reservation_token' => $p->json('data.reservation_token')];
        $this->patchJson($this->url($item), $body, $this->headers($token, (string) Str::uuid()))
            ->assertConflict()->assertJsonPath('error.code', 'NFC_REJECTED_TAG_REUSE');
        $this->assertNull(DB::table(Guard::TABLE)->first()->recovery_key_hash);
        $this->assertSame(0, DB::table('physical_codes')->where('carrier_type', 'NFC')->count());
    }

    public function test_recovery_confirm_commits_new_mapping_once_and_keeps_old_receipt(): void
    {
        [, $token, , $item, $sku, $oldBody, $oldKey] = $this->rejected();
        $sku->update(['status' => 'ACTIVE']);
        $p = $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($token, $oldKey))->assertOk();
        $body = ['hardware_uid' => '9988776655', 'written_payload' => $p->json('data.nfc_payload'), 'reservation_token' => $p->json('data.reservation_token')];
        $key = (string) Str::uuid();
        $before = $this->stocks();
        $first = $this->patchJson($this->url($item), $body, $this->headers($token, $key))->assertCreated();
        $this->patchJson($this->url($item), $body, $this->headers($token, $key))->assertCreated()
            ->assertJsonPath('data.nfc.physical_code_id', $first->json('data.nfc.physical_code_id'));
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $key, true))->assertOk()->assertJsonPath('data.state', 'SUCCEEDED');
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $oldKey, true))->assertOk()
            ->assertJsonPath('data.state', 'REJECTED')->assertJsonPath('data.recovery.completed', true)->assertJsonPath('data.may_create_new_request', false);
        $this->patchJson($this->url($item), $oldBody, $this->headers($token, $oldKey))->assertStatus(422);
        $this->assertSame(1, DB::table('physical_codes')->where('carrier_type', 'NFC')->count());
        $this->assertSame($before, $this->stocks());
    }

    public function test_success_receipt_replays_after_later_sku_deactivation(): void
    {
        [$actor, $token, $warehouse] = $this->actor();
        [$item, $sku] = $this->item($actor, $warehouse);
        $body = $this->prepared($token, $item);
        $key = (string) Str::uuid();
        $this->patchJson($this->url($item), $body, $this->headers($token, $key))->assertCreated();
        $sku->update(['status' => 'INACTIVE']);
        $this->patchJson($this->url($item), $body, $this->headers($token, $key))->assertCreated();
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $key, true))->assertOk()->assertJsonPath('data.state', 'SUCCEEDED');
        $this->assertDatabaseCount(Guard::TABLE, 0);
    }

    public function test_other_actor_and_new_login_cannot_inherit_a_rejection_or_recovery(): void
    {
        [$actor, $token, $warehouse, $item, $sku, $body, $key] = $this->rejected();
        $sku->update(['status' => 'ACTIVE']);
        [, $other] = $this->actor();
        $this->getJson($this->url($item).'/confirmation', $this->headers($other, $key, true))->assertOk()->assertJsonPath('data.state', 'UNKNOWN');
        $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($other, $key))->assertConflict()->assertJsonPath('error.code', 'NFC_RECOVERY_NOT_PROVEN');
        [$new] = $this->login($actor, $warehouse);
        $this->getJson($this->url($item).'/confirmation', $this->headers($new, $key, true))->assertConflict()->assertJsonPath('error.code', 'NFC_CONFIRMATION_CONTEXT_MISMATCH');
        $this->patchJson($this->url($item), $body, $this->headers($new, $key))->assertConflict();
        $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($new, $key))->assertConflict();
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $key, true))->assertOk()->assertJsonPath('data.state', 'REJECTED');
    }

    public function test_refresh_preserves_receipt_scope_and_logout_revokes_access(): void
    {
        [$actor, $token, $warehouse, $cookie] = $this->actor();
        [$item, $sku] = $this->item($actor, $warehouse);
        $body = $this->prepared($token, $item);
        $sku->update(['status' => 'INACTIVE']);
        $key = (string) Str::uuid();
        $this->patchJson($this->url($item), $body, $this->headers($token, $key))->assertStatus(422);
        $this->resetAuth();
        $refreshed = $this->browserRefresh($cookie)->assertOk();
        $next = $refreshed->json('data.access_token');
        $nextCookie = $this->refreshTokenFromCookie($refreshed);
        $this->getJson($this->url($item).'/confirmation', $this->headers($next, $key, true))->assertOk()->assertJsonPath('data.state', 'REJECTED');
        $this->withCredentials()->withUnencryptedCookie((string) config('refresh_token.cookie.name'), $nextCookie)
            ->postJson('/api/v1/auth/logout', [], $this->headers($next))->assertOk();
        $this->getJson($this->url($item).'/confirmation', $this->headers($next, $key, true))->assertUnauthorized();
    }

    public function test_permissions_and_wrong_context_are_checked_before_replay(): void
    {
        [$actor, $token, , $item, , $body, $key] = $this->rejected();
        $this->getJson($this->url($item).'/confirmation?warehouse_id='.Str::uuid(), $this->headers($token, $key, true))->assertConflict();
        $role = $actor->roles()->first();
        DB::table('role_permission')->where('role_id', $role->role_id)
            ->whereIn('permission_id', Permission::where('permission_key', 'physical_code.assign_rfid')->pluck('permission_id'))->delete();
        $actor->forceFill(['version' => $actor->version + 1])->save();
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $key, true))->assertForbidden();
        $this->patchJson($this->url($item), $body, $this->headers($token, $key))->assertForbidden();
    }

    public function test_release_or_expiry_does_not_erase_failed_request_or_unconsume_recovery(): void
    {
        [, $token, , $item, $sku, $body, $key] = $this->rejected();
        $sku->update(['status' => 'ACTIVE']);
        $this->postJson($this->url($item).'/release', ['reservation_token' => $body['reservation_token']], $this->headers($token))->assertOk();
        $this->patchJson($this->url($item), $body, $this->headers($token, $key))->assertStatus(422);
        $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($token, $key))->assertOk();
        Cache::forget($this->hold($item)); // Isolated expiry, not evidence of a failed new confirm.
        $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($token, $key))
            ->assertConflict()->assertJsonPath('error.code', 'NFC_RECOVERY_BLOCKED');
        $this->assertDatabaseCount(Guard::TABLE, 1);
    }

    public function test_foreign_reservation_is_never_released_by_recovery(): void
    {
        [, $token, , $item, $sku, , $key] = $this->rejected();
        $sku->update(['status' => 'ACTIVE']);
        $hold = Cache::get($this->hold($item));
        $hold['auth_session_id'] = (string) Str::uuid();
        Cache::put($this->hold($item), $hold, 300);
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $key, true))->assertOk()->assertJsonPath('data.may_create_new_request', false);
        $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($token, $key))->assertConflict();
        $this->assertSame($hold, Cache::get($this->hold($item)));
    }

    public function test_rejection_storage_failure_never_fabricates_a_terminal_outcome(): void
    {
        [$actor, $token, $warehouse] = $this->actor();
        [$item, $sku] = $this->item($actor, $warehouse);
        $body = $this->prepared($token, $item);
        $sku->update(['status' => 'INACTIVE']);
        $key = (string) Str::uuid();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, Guard::TABLE)) {
                throw new \RuntimeException('Isolated rejection receipt failure');
            }
        });
        $this->patchJson($this->url($item), $body, $this->headers($token, $key))->assertStatus(500);
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $key, true))->assertOk()->assertJsonPath('data.state', 'UNKNOWN');
        $this->assertDatabaseCount(Guard::TABLE, 0);
        $this->assertSame(0, DB::table('physical_codes')->where('carrier_type', 'NFC')->count());
    }

    public function test_recovery_writer_failure_keeps_consumed_permit_and_blocks_new_keys(): void
    {
        [, $token, , $item, $sku, , $oldKey] = $this->rejected();
        $sku->update(['status' => 'ACTIVE']);
        $p = $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($token, $oldKey))->assertOk();
        $body = ['hardware_uid' => '9988776655', 'written_payload' => $p->json('data.nfc_payload'), 'reservation_token' => $p->json('data.reservation_token')];
        $key = (string) Str::uuid();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'physical_codes')) {
                throw new \RuntimeException('Isolated mapping write failure');
            }
        });
        $this->patchJson($this->url($item), $body, $this->headers($token, $key))->assertStatus(500);
        $this->assertDatabaseHas(Guard::TABLE, ['recovery_key_hash' => hash('sha256', $key), 'recovery_state' => 'PROCESSING']);
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $key, true))->assertOk()->assertJsonPath('data.state', 'UNKNOWN');
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $oldKey, true))->assertOk()->assertJsonPath('data.may_create_new_request', false);
        $this->patchJson($this->url($item), $body, $this->headers($token, $key))->assertConflict()->assertJsonPath('error.code', 'NFC_CONFIRMATION_UNKNOWN');
        $this->patchJson($this->url($item), $body, $this->headers($token, (string) Str::uuid()))->assertConflict()->assertJsonPath('error.code', 'NFC_RECOVERY_ATTEMPT_LOCKED');
        $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($token, $oldKey))->assertConflict();
        $this->assertSame(0, DB::table('physical_codes')->where('carrier_type', 'NFC')->count());
    }

    public function test_a_second_prewrite_rejection_has_its_own_receipt_and_recovery_key(): void
    {
        [, $token, , $item, $sku, , $oldKey] = $this->rejected();
        $sku->update(['status' => 'ACTIVE']);
        $p = $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($token, $oldKey))->assertOk();
        $body = ['hardware_uid' => '9988776655', 'written_payload' => $p->json('data.nfc_payload'), 'reservation_token' => $p->json('data.reservation_token')];
        $key = (string) Str::uuid();
        $sku->update(['status' => 'INACTIVE']);
        $this->patchJson($this->url($item), $body, $this->headers($token, $key))->assertStatus(422)->assertJsonPath('error.code', 'SKU_INACTIVE');
        $sku->update(['status' => 'ACTIVE']);
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $oldKey, true))->assertOk()->assertJsonPath('data.may_create_new_request', false);
        $this->getJson($this->url($item).'/confirmation', $this->headers($token, $key, true))->assertOk()->assertJsonPath('data.may_create_new_request', true);
        $this->postJson($this->url($item).'/prepare', [], $this->recoveryHeaders($token, $key))->assertOk();
        $this->assertDatabaseCount(Guard::TABLE, 2);
    }

    public function test_new_guard_is_only_on_existing_owner_routes_and_requires_migration(): void
    {
        $paths = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array(Guard::class, $route->gatherMiddleware(), true)) {
                $paths[] = $route->uri();
                $this->assertContains('auth:api', $route->gatherMiddleware());
                $this->assertContains('permission:physical_code.assign_rfid', $route->gatherMiddleware());
            }
        }
        $this->assertEqualsCanonicalizing(['api/v1/mini-app/items/{itemId}/nfc', 'api/v1/mini-app/items/{itemId}/nfc/prepare',
            'api/v1/mini-app/items/{itemId}/nfc/confirmation', 'api/v1/mini-app/items/{itemId}/nfc/release'], $paths);
        [$actor, $token, $warehouse] = $this->actor();
        [$item] = $this->item($actor, $warehouse);
        Schema::rename(Guard::TABLE, 'p07_missing_migration_isolated');
        try {
            $this->postJson($this->url($item).'/prepare', [], $this->headers($token))->assertStatus(503)->assertJsonPath('error.code', 'NFC_RECOVERY_MIGRATION_REQUIRED');
            $this->assertNull(Cache::get($this->hold($item)));
        } finally {
            Schema::rename('p07_missing_migration_isolated', Guard::TABLE);
        }
    }

    private function rejected(): array
    {
        [$actor, $token, $warehouse] = $this->actor();
        [$item, $sku] = $this->item($actor, $warehouse);
        $body = $this->prepared($token, $item);
        $sku->update(['status' => 'INACTIVE']);
        $key = (string) Str::uuid();
        $this->patchJson($this->url($item), $body, $this->headers($token, $key))->assertStatus(422)->assertJsonPath('error.code', 'SKU_INACTIVE');

        return [$actor, $token, $warehouse, $item, $sku, $body, $key];
    }

    private function actor(): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'Isolated NFC recovery warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $actor = User::create(['username' => 'p07r_'.$suffix, 'display_name' => 'Isolated recovery actor',
            'email' => 'p07r_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P07R_'.$suffix, 'role_name' => 'Isolated recovery test', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $actor->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (['physical_code.assign_rfid', 'inventory.trace'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$token, $cookie] = $this->login($actor, $warehouse);

        return [$actor, $token, $warehouse, $cookie];
    }

    private function login(User $actor, object $warehouse): array
    {
        $this->resetAuth();
        $login = $this->browserLogin($actor->email, 'secret123')->assertOk();
        $cookie = $this->refreshTokenFromCookie($login);
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->headers($login->json('data.access_token')))->assertOk()->json('data.access_token');

        return [$token, $cookie];
    }

    private function item(User $actor, object $warehouse): array
    {
        $suffix = Str::upper(Str::random(8));
        $sku = Sku::create(['sku_code' => 'P07R-'.$suffix, 'sku_name' => 'Isolated SKU', 'sku_type' => 'PRODUCT',
            'unit' => 'cai', 'is_serial_tracked' => true, 'status' => 'ACTIVE']);
        $item = $this->seedItemInStock($sku->sku_id);
        DB::table('item_instances')->where('item_id', $item)->update(['serial_number' => 'P07R-SERIAL-'.$suffix]);
        $id = (string) Str::uuid();
        $row = ['id' => $id, 'movement_no' => 'P07R-'.Str::random(12), 'business_key' => 'P07R-'.$id,
            'movement_type' => 'OPENING', 'warehouse_id' => $warehouse->id, 'product_id' => $sku->sku_id,
            'stock_status' => 'AVAILABLE', 'qty_before' => 0, 'qty_delta' => 1, 'qty_after' => 1,
            'created_by' => $actor->user_id, 'occurred_at' => now()->subHour(), 'created_at' => now()->subHour()];
        foreach (['item_id' => $item, 'sku_id' => $sku->sku_id, 'quantity' => 1, 'source_type' => 'OPENING', 'source_id' => $id] as $field => $value) {
            if (Schema::hasColumn('stock_movements', $field)) {
                $row[$field] = $value;
            }
        }
        DB::table('stock_movements')->insert($row);

        return [$item, $sku];
    }

    private function prepared(string $token, string $item): array
    {
        $response = $this->postJson($this->url($item).'/prepare', [], $this->headers($token))->assertOk();

        return ['hardware_uid' => 'AABBCCDDEE', 'written_payload' => $response->json('data.nfc_payload'), 'reservation_token' => $response->json('data.reservation_token')];
    }

    private function url(string $item): string
    {
        return '/api/v1/mini-app/items/'.$item.'/nfc';
    }

    private function hold(string $item): string
    {
        return 'wms:nfc:write-reservation:'.$item;
    }

    private function stocks(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(), ['stock_documents', 'stock_movements', 'inventory_balances']);
    }

    private function headers(string $token, ?string $key = null, bool $recovery = false): array
    {
        $this->resetAuth();

        return ['Authorization' => 'Bearer '.$token] + ($key === null ? [] : ['Idempotency-Key' => $key])
            + ($recovery ? ['X-NFC-Recovery-Contract' => Guard::CONTRACT] : []);
    }

    private function recoveryHeaders(string $token, string $key): array
    {
        return $this->headers($token, recovery: true) + ['X-NFC-Recovery-Key' => $key];
    }

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
