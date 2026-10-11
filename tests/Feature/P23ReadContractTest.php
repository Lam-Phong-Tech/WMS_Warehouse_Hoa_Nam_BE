<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Synthetic fixtures ONLY in disposable CI databases, never DEV/live evidence. */
final class P23ReadContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/mini-app/';

    public function test_only_one_aggregate_get_is_added_and_p08_routes_remain_four(): void
    {
        $aggregate = collect(Route::getRoutes())->filter(fn ($r) => $r->uri() === 'api/v1/mini-app/scan-sessions');
        $this->assertCount(1, $aggregate);
        $route = $aggregate->first();
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        foreach (['auth:api', 'permission:history.read', 'permission:inventory.view'] as $guard) {
            $this->assertContains($guard, $route->gatherMiddleware());
        }
        $this->assertCount(4, collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/mini-app/history/')));
    }

    public function test_permissions_and_direct_ids_cannot_bypass_scope(): void
    {
        $this->getJson(self::BASE.'scan-sessions')->assertUnauthorized();
        [$user, $token, $warehouse] = $this->actorSession(['inventory.view']);
        $this->getJson($this->url('scan-sessions', $warehouse->id), $this->headers($token))->assertForbidden();
        [, $token] = $this->actorSession(['history.read']);
        $this->getJson($this->url('scan-sessions', $warehouse->id), $this->headers($token))->assertForbidden();
        [$user, $token] = $this->actorSession();
        $foreign = Warehouse::create(['code' => 'P23-OTHER', 'name' => 'Isolated foreign warehouse', 'status' => 'INACTIVE']);
        $batch = $this->batch($user, $foreign, []);
        $this->getJson($this->url('scan-sessions', $warehouse->id), $this->headers($token))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->url('history/scan-sessions/'.$batch, $warehouse->id), $this->headers($token))->assertNotFound();
        $this->getJson($this->url('scan-sessions', $foreign->id), $this->headers($token))->assertForbidden();
    }

    public function test_batch_total_includes_zero_events_and_mirrors_do_not_double_count(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $empty = $this->batch($user, $warehouse, []);
        $busy = $this->batch($user, $warehouse, ['ACCEPTED', 'DUPLICATE', 'RESOLVED', 'REJECTED']);
        $response = $this->getJson($this->url('scan-sessions', $warehouse->id), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.countUnit', 'SCAN_SESSION')->assertJsonCount(2, 'data');
        $rows = collect($response->json('data'))->keyBy('sessionId');
        $this->assertSame(0, $rows[$empty]['counts']['total']);
        $this->assertSame(4, $rows[$busy]['counts']['total']);
        $this->assertSame(1, $rows[$busy]['counts']['accepted']);
        $this->assertSame(1, $rows[$busy]['counts']['duplicate']);
        $this->assertSame(2, $rows[$busy]['counts']['other']);
        $this->assertSame(1, $rows[$busy]['counts']['explicitRejected']);
        foreach ([$empty, $busy] as $id) {
            $this->assertNull($rows[$id]['counts']['rejected']);
            $this->assertNull($rows[$id]['counts']['quantity']);
            $this->assertNull($rows[$id]['counts']['issuedQuantity']);
            $this->assertNull($rows[$id]['startedAt']);
            $this->assertNull($rows[$id]['endedAt']);
            $this->getJson($this->url('history/scan-sessions/'.$id, $warehouse->id, ['limit' => 1]), $this->headers($token))
                ->assertOk()->assertJsonPath('data.p23.batch.sessionId', $id)
                ->assertJsonPath('data.p23.counts.total', $rows[$id]['counts']['total']);
        }
    }

    public function test_batch_cursor_is_source_actor_and_filter_bound(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        for ($i = 0; $i < 3; $i++) {
            $this->batch($user, $warehouse, ['ACCEPTED', 'DUPLICATE']);
        }
        $first = $this->getJson($this->url('scan-sessions', $warehouse->id, ['per_page' => 1]), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.hasMore', true);
        $cursor = $first->json('meta.nextCursor');
        $ids = [$first->json('data.0.sessionId')];
        $this->assertIsString($cursor);
        $this->getJson($this->url('scan-sessions', $warehouse->id, ['per_page' => 1, 'cursor' => $cursor, 'keyword' => 'MANUAL']), $this->headers($token))->assertUnprocessable();
        $this->getJson($this->url('scan-sessions', $warehouse->id, ['per_page' => 1, 'cursor' => $cursor, 'page' => 1]), $this->headers($token))->assertUnprocessable();
        [, $otherToken] = $this->actorSession();
        $this->getJson($this->url('scan-sessions', $warehouse->id, ['per_page' => 1, 'cursor' => $cursor]), $this->headers($otherToken))->assertUnprocessable();
        for ($i = 0; $i < 3 && $cursor !== null; $i++) {
            $next = $this->getJson($this->url('scan-sessions', $warehouse->id, ['per_page' => 1, 'cursor' => $cursor]), $this->headers($token))
                ->assertOk()->assertJsonPath('meta.total', 3);
            $ids[] = $next->json('data.0.sessionId');
            $cursor = $next->json('meta.nextCursor');
        }
        $this->assertNull($cursor);
        $this->assertCount(3, array_unique($ids));
        $eventCursor = $this->getJson($this->url('history/records', $warehouse->id, ['source' => 'SCAN', 'limit' => 1]), $this->headers($token))->assertOk()->json('meta.nextCursor');
        $this->getJson($this->url('scan-sessions', $warehouse->id, ['cursor' => $eventCursor]), $this->headers($token))->assertUnprocessable();
    }

    public function test_literal_search_dates_and_unavailable_status_are_not_local_filters(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $special = $this->batch($user, $warehouse, [], 'MANUAL_%');
        $this->batch($user, $warehouse, [], 'MANUAL-OTHER');
        $this->getJson($this->url('scan-sessions', $warehouse->id, ['keyword' => '%', 'per_page' => 1]), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.sessionId', $special);
        $this->getJson($this->url('scan-sessions', $warehouse->id, ['date_mode' => 'DATE', 'date_field' => 'created_at', 'date' => now()->subDay()->toDateString()]), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->url('scan-sessions', $warehouse->id, ['date_mode' => 'DATE', 'date_field' => 'created_at', 'date' => now()->subDays(2)->toDateString()]), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 0);
        foreach ([['mine' => '1'], ['actor_id' => $user->user_id], ['date_mode' => 'ALL', 'date' => '2026-10-10'],
            ['date_mode' => 'DATE', 'date_field' => 'ended_at', 'date' => '2026-10-10']] as $invalid) {
            $this->getJson($this->url('scan-sessions', $warehouse->id, $invalid), $this->headers($token))->assertUnprocessable();
        }
        if (! Schema::hasColumn('scan_batches', 'status') || ! Schema::hasColumn('scan_batch', 'status')) {
            $this->getJson($this->url('scan-sessions', $warehouse->id, ['status' => 'ENDED']), $this->headers($token))->assertStatus(503);
        }
    }

    public function test_case_date_filter_precedes_total_and_timeline_latest_is_full_source(): void
    {
        [$user, $token, $warehouse] = $this->actorSession(['warranty.view']);
        $yesterday = now()->subDay()->setTime(12, 0);
        $today = now()->setTime(0, 0);
        $one = $this->warranty($user, $warehouse, $yesterday);
        $two = $this->warranty($user, $warehouse, $yesterday);
        $this->warranty($user, $warehouse, $today);
        $query = ['date_mode' => 'DATE', 'date_field' => 'created_at', 'date' => $yesterday->toDateString(), 'per_page' => 1];
        $first = $this->getJson($this->url('warranty-cases', $warehouse->id, $query), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data')->assertJsonPath('meta.date_filter_contract', 'p23-case-dates-v2');
        $this->assertIsString($first->json('meta.nextCursor'));
        $this->getJson($this->url('warranty-cases', $warehouse->id, array_replace($query, ['date' => $today->toDateString(), 'cursor' => $first->json('meta.nextCursor')])), $this->headers($token))->assertUnprocessable();
        $old = (string) Str::uuid();
        $latest = (string) Str::uuid();
        foreach ([[$old, $yesterday], [$latest, $today]] as [$id, $time]) {
            DB::table('warranty_event')->insert(['warranty_event_id' => $id, 'warranty_case_id' => $one, 'event_type' => 'NOTE',
                'created_by' => $user->user_id, 'event_at' => $time, 'note' => 'p23-private-message']);
        }
        $this->getJson($this->url('warranty-cases/'.$one.'/events', $warehouse->id, ['per_page' => 1, 'sort' => 'event_at_asc']), $this->headers($token))
            ->assertOk()->assertJsonPath('data.0.event_id', $old)->assertJsonPath('meta.latest_recorded_event_id', $latest)
            ->assertJsonPath('data.0.message', null)->assertJsonPath('data.0.message_redacted', true)
            ->assertJsonPath('data.0.actor.user_id', $user->user_id)->assertJsonPath('data.0.actor.display_name', null);
    }

    public function test_read_calls_do_not_write_business_data_or_expose_raw_secrets(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $id = $this->batch($user, $warehouse, ['ACCEPTED']);
        $tables = ['stock_documents', 'stock_movements', 'inventory_balances', 'scan_record_events', 'scan_batches', 'physical_codes'];
        $fingerprint = fn () => array_map(fn ($t) => hash('sha256', json_encode(DB::table($t)->get()->all())), $tables);
        $before = $fingerprint();
        foreach (['scan-sessions', 'history/scan-sessions/'.$id] as $path) {
            $response = $this->getJson($this->url($path, $warehouse->id), $this->headers($token))->assertOk();
            $this->assertStringNotContainsString('p23-private-raw-marker', $response->getContent());
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertSame($before, $fingerprint());
    }

    private function actorSession(array $rights = ['history.read', 'inventory.view']): array
    {
        $warehouse = $this->warehouseFixture();
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p23_'.$suffix, 'display_name' => 'P23 isolated test', 'email' => 'p23_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P23_'.$suffix, 'role_name' => 'P23 isolated test', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($rights as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $token = $this->browserLogin($user->email, 'secret123')->assertOk()->json('data.access_token');
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], $this->headers($token))->assertOk()->json('data.access_token');
        return [$user, $token, $warehouse];
    }

    private function batch(User $user, object $warehouse, array $statuses, string $type = 'MANUAL'): string
    {
        $id = (string) Str::uuid();
        DB::table('scan_batches')->insert(['scan_batch_id' => $id, 'source_type' => $type,
            'warehouse_id' => $warehouse->id, 'created_by' => $user->user_id, 'created_at' => now()->subDay()->setTime(12, 0)]);
        foreach ($statuses as $status) {
            DB::table('scan_record_events')->insert(['scan_event_id' => (string) Str::uuid(), 'scan_batch_id' => $id,
                'raw_code' => 'p23-private-raw-marker', 'result_status' => $status, 'created_at' => now()->subDay()->setTime(12, 0)]);
        }
        return $id;
    }

    private function warranty(User $user, object $warehouse, mixed $created): string
    {
        $id = (string) Str::uuid();
        DB::table('warranty_case')->insert(['warranty_case_id' => $id, 'warranty_case_code' => 'P23-'.$id,
            'identification_status' => 'TEMP_ONLY', 'temporary_item_code' => 'TEMP-'.$id,
            'created_by' => $user->user_id, 'status' => 'RECEIVED', 'version' => 1, 'created_at' => $created, 'updated_at' => $created]);
        DB::table(WarrantyWarehouseScope::TABLE)->insert(['warranty_case_id' => $id, 'warehouse_id' => $warehouse->id,
            'source' => 'EXPLICIT_REQUEST', 'actor_id' => $user->user_id, 'auth_session_id' => null, 'assigned_at' => $created, 'evidence' => null]);
        return $id;
    }

    private function url(string $path, string $warehouse, array $query = []): string
    {
        return self::BASE.$path.'?'.http_build_query(['warehouse_id' => $warehouse, ...$query]);
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
        return ['Authorization' => 'Bearer '.$token];
    }
}
