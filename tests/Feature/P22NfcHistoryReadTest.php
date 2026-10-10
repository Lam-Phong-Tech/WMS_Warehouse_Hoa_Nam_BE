<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Support\P22NfcHistory;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

/** Read-only HTTP tests with synthetic audit rows on the disposable CI database. */
class P22NfcHistoryReadTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    public function test_routes_remain_get_only_and_unopted_p08_is_unchanged(): void
    {
        [, $token, $w] = $this->reader();
        $routes = collect(Route::getRoutes())->filter(fn ($r): bool => str_starts_with($r->uri(), 'api/v1/mini-app/history/'));
        $this->assertCount(4, $routes);
        foreach ($routes as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods());
        }
        $this->getJson($this->url($w), $this->headers($token, false))->assertOk()->assertJsonPath('meta.contract', 'mini-app-p08-v1');
        $this->getJson($this->url($w, ['type' => 'ASSIGN']), $this->headers($token, false))->assertUnprocessable();
    }

    public function test_snapshot_search_and_type_filter_apply_before_total(): void
    {
        [$u, $token, $w] = $this->reader();
        $one = $this->audit($u, $w, ['hardware_uid' => 'AA%_!END', 'serial_number' => 'SERIAL-SNAPSHOT']);
        $this->audit($u, $w, ['hardware_uid' => 'AAxyzEND']);
        $old = $this->audit($u, $w, ['hardware_uid' => 'NEW-UID', 'previous_hardware_uid' => 'OLD-SNAPSHOT',
            'replacement_requested' => true, 'replacement_verified' => true, 'previous_physical_code_id' => (string) Str::uuid()]);
        $this->audit($u, $w, ['warehouse_id' => (string) Str::uuid(), 'hardware_uid' => 'AA%_!END']);
        foreach (['%_!' => $one, 'serial-snapshot' => $one, 'old-snapshot' => $old] as $keyword => $event) {
            $this->getJson($this->url($w, ['keyword' => $keyword, 'limit' => 1]), $this->headers($token))
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.eventId', $event->id);
            $this->getJson($this->url($w, ['keyword' => $keyword], 'summary'), $this->headers($token))
                ->assertOk()->assertJsonPath('data.availableTotal', 1);
        }
        $this->getJson($this->url($w, ['type' => 'REPLACE']), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.type', 'REPLACE');
    }

    public function test_parent_and_bare_event_lookup_are_exact(): void
    {
        [$u, $token, $w] = $this->reader();
        $e = $this->audit($u, $w);
        $other = $this->audit($u, $w);
        $path = 'records/NFC:'.$e->object_id;
        $this->getJson($this->url($w, ['activityId' => 'NFC:'.$e->id], $path), $this->headers($token))
            ->assertOk()->assertJsonPath('data.selectedActivity.eventId', $e->id);
        $this->getJson($this->url($w, ['event_id' => $e->id]), $this->headers($token))
            ->assertOk()->assertJsonPath('data.0.recordId', 'NFC:'.$e->object_id);
        $this->getJson($this->url($w, [], $path), $this->headers($token))->assertUnprocessable();
        $this->getJson($this->url($w, ['activityId' => 'NFC:'.$other->id], $path), $this->headers($token))->assertNotFound();
        $this->getJson($this->url($w, ['event_id' => (string) Str::uuid()]), $this->headers($token))->assertNotFound();
    }

    public function test_unproven_legacy_foreign_and_mismatched_snapshots_are_not_visible(): void
    {
        [$u, $token, $w] = $this->reader();
        AuditLog::create(['actor_id' => $u->user_id, 'object_type' => 'physical_code', 'object_id' => (string) Str::uuid(),
            'action' => 'ASSIGN_ITEM_NFC', 'created_at' => now()->subMinute(), 'after_data' => ['hardware_uid' => 'Legacy']]);
        foreach (['warehouse_id', 'actor_id', 'physical_code_id'] as $field) {
            $this->audit($u, $w, [$field => (string) Str::uuid()]);
        }
        $before = DB::table('audit_log')->count();
        $this->getJson($this->url($w), $this->headers($token))->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame($before, DB::table('audit_log')->count());
    }

    public function test_null_redaction_and_snapshot_values_do_not_fall_back_to_current_actor(): void
    {
        [$u, $token, $w] = $this->reader();
        $this->audit($u, $w, ['serial_number' => null, 'actor_name' => 'Historical actor', 'sku_name' => 'Historical product']);
        $r = $this->getJson($this->url($w), $this->headers($token))->assertOk()
            ->assertJsonPath('data.0.actor', null)->assertJsonPath('data.0.reason', null)
            ->assertJsonPath('data.0.serial_number', null)->assertJsonPath('data.0.sku.sku_name', 'Historical product')
            ->assertJsonPath('data.0.status', null)->assertJsonPath('data.0.current_tag_status', null)
            ->assertJsonPath('data.0.raw_content', null)->assertJsonPath('data.0.isConfirmationReceipt', false);
        $this->assertStringNotContainsString('PRIVATE_SENTINEL', $r->getContent());
        $this->assertStringNotContainsString('Historical actor', $r->getContent());
    }

    public function test_keyset_expiry_scope_and_filter_binding(): void
    {
        [$u, $token, $w] = $this->reader();
        $expected = [];
        for ($i = 0; $i < 3; $i++) {
            $expected[] = $this->audit($u, $w)->id;
        }
        $r = $this->getJson($this->url($w, ['limit' => 1]), $this->headers($token))->assertOk()->assertJsonPath('meta.total', 3);
        $original = $cursor = $r->json('meta.nextCursor');
        $ids = [$r->json('data.0.eventId')];
        for ($i = 0; $cursor !== null && $i < 3; $i++) {
            $r = $this->getJson($this->url($w, ['limit' => 1, 'cursor' => $cursor]), $this->headers($token))->assertOk()->assertJsonPath('meta.total', 3);
            $ids[] = $r->json('data.0.eventId');
            $cursor = $r->json('meta.nextCursor');
        }
        $this->assertNull($cursor);
        $this->assertEqualsCanonicalizing($expected, $ids);
        $this->getJson($this->url($w, ['limit' => 1, 'cursor' => $original, 'type' => 'REVOKE']), $this->headers($token))->assertUnprocessable();
        [, $other] = $this->reader();
        $this->getJson($this->url($w, ['limit' => 1, 'cursor' => $original]), $this->headers($other))->assertUnprocessable();
        $payload = json_decode(Crypt::decryptString($original), true);
        $payload['expires'] = time() - 1;
        $expired = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->getJson($this->url($w, ['limit' => 1, 'cursor' => $expired]), $this->headers($token))->assertUnprocessable();
    }

    public function test_date_summary_and_identity_controls_are_strict(): void
    {
        [$u, $token, $w] = $this->reader();
        $this->audit($u, $w);
        $this->getJson($this->url($w, ['date_mode' => 'DATE', 'date' => now()->subDay()->toDateString()]), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 1);
        foreach ([['date_mode' => 'ALL', 'date' => '2026-10-10'], ['page' => 1], ['type' => 'ACTIVE'], ['source' => 'ALL']] as $filter) {
            $this->getJson($this->url($w, $filter), $this->headers($token))->assertUnprocessable();
        }
        $this->getJson($this->url($w, ['limit' => 1], 'summary'), $this->headers($token))->assertUnprocessable();
        $this->getJson($this->url($w, ['status' => 'UNKNOWN']), $this->headers($token))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->url($w, ['status' => 'ACTIVE']), $this->headers($token))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_read_permission_and_snapshot_details_permission_are_independent(): void
    {
        $this->getJson('/api/v1/mini-app/history/records')->assertUnauthorized();
        [$u, $token, $w] = $this->reader(['history.read', 'inventory.trace', 'audit_log.view']);
        $this->audit($u, $w);
        $this->getJson($this->url($w), $this->headers($token))->assertOk()->assertJsonPath('data.0.audit_details_status', 'PERMITTED');
        [, $none] = $this->reader(['history.read']);
        $this->getJson($this->url($w), $this->headers($none))->assertForbidden();
        [, $noHistory] = $this->reader(['inventory.trace']);
        $this->getJson($this->url($w), $this->headers($noHistory))->assertForbidden();
    }

    private function reader(array $rights = ['history.read', 'inventory.trace']): array
    {
        $w = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P22 isolated warehouse');
        $suffix = Str::lower(Str::random(12));
        $password = bin2hex(random_bytes(18));
        $u = User::create(['username' => 'p22_'.$suffix, 'display_name' => 'Current actor', 'email' => 'p22_'.$suffix.'@example.test',
            'password_hash' => Hash::make($password), 'status' => 'ACTIVE', 'version' => 1, 'must_change_password' => false]);
        $role = Role::create(['role_code' => 'P22_'.$suffix, 'role_name' => 'Isolated test role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $u->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $w->id]);
        foreach ($rights as $key) {
            $p = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $p->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->headers('', false);
        $token = $this->browserLogin($u->email, $password)->assertOk()->json('data.access_token');
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $w->id], $this->headers($token, false))
            ->assertOk()->json('data.access_token');

        return [$u, $token, $w];
    }

    private function audit(User $u, object $w, array $overrides = []): AuditLog
    {
        $id = (string) Str::uuid();
        $item = (string) Str::uuid();
        $data = ['schema' => 'nfc-audit-snapshot-v1', 'physical_code_id' => $id, 'carrier_type' => 'NFC',
            'warehouse_id' => $w->id, 'warehouse_code' => $w->code, 'warehouse_name' => $w->name,
            'actor_id' => $u->user_id, 'actor_name' => 'Historical actor', 'hardware_uid' => 'UID-'.$id,
            'item_id' => $item, 'serial_number' => 'SERIAL-'.$item, 'tag_status_at_event' => 'ACTIVE',
            'replacement_requested' => false, 'replacement_verified' => false,
            'previous_physical_code_id' => null, 'previous_hardware_uid' => null, 'previous_item_id' => $item,
            'previous_warehouse_id' => $w->id, ...$overrides];

        return AuditLog::create(['actor_id' => $u->user_id, 'object_type' => 'physical_code', 'object_id' => $id,
            'action' => 'ASSIGN_ITEM_NFC', 'result' => null, 'reason' => 'Historical reason',
            'created_at' => now()->subDay()->startOfDay()->addHours(12),
            'after_data' => ['nfc_history_v1' => $data, 'arbitrary_private_field' => 'PRIVATE_SENTINEL']]);
    }

    private function url(object $w, array $query = [], string $path = 'records'): string
    {
        return '/api/v1/mini-app/history/'.$path.'?'.http_build_query(['warehouse_id' => $w->id, 'source' => 'NFC', ...$query]);
    }

    private function headers(string $token, bool $optIn = true): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token] + ($optIn ? [P22NfcHistory::HEADER => P22NfcHistory::CONTRACT] : []);
    }
}
