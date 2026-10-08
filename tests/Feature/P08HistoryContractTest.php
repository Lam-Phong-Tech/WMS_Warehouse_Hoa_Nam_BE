<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Middleware\P08HistoryReadContract;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Fixtures are created only in the isolated test database. No live/DEV data is seeded. */
class P08HistoryContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/mini-app/history/';

    public function test_only_the_four_read_operations_are_registered(): void
    {
        $count = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/mini-app/history/')) {
                continue;
            }
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertContains('auth:api', $route->gatherMiddleware());
            $this->assertContains('permission:history.read', $route->gatherMiddleware());
            $this->assertContains(P08HistoryReadContract::class, $route->gatherMiddleware());
            $this->assertStringNotContainsString('export', $route->uri());
            $count++;
        }
        $this->assertSame(4, $count);
    }

    public function test_auth_history_permission_and_source_permission_are_independent(): void
    {
        $this->getJson(self::BASE.'records')->assertUnauthorized();
        [, $token, $warehouse] = $this->actorSession(['inventory.view']);
        $this->getJson($this->url('records', $warehouse->id), $this->headers($token))->assertForbidden();
        [, $token, $warehouse] = $this->actorSession(['history.read']);
        $this->getJson($this->url('records', $warehouse->id, ['source' => 'SCAN']), $this->headers($token))->assertForbidden();
        [, $token, $warehouse] = $this->actorSession();
        $this->getJson($this->url('records', $warehouse->id, ['source' => 'WARRANTY']), $this->headers($token))->assertForbidden();
    }

    public function test_scope_dedup_and_total_are_applied_before_keyset_paging(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $batch = $this->batch($user, $warehouse, ['ACCEPTED', 'DUPLICATE', 'RESOLVED']);
        $foreign = Warehouse::create(['code' => 'P08-OTHER', 'name' => 'Other test warehouse', 'status' => 'INACTIVE']);
        $foreignBatch = $this->batch($user, $foreign, ['ACCEPTED', 'ACCEPTED']);
        $first = $this->getJson($this->url('records', $warehouse->id, ['source' => 'SCAN', 'limit' => 1]), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.hasMore', true)->assertJsonCount(1, 'data');
        $ids = [$first->json('data.0.activityId')];
        $cursor = $first->json('meta.nextCursor');
        for ($page = 0; $page < 3 && $cursor !== null; $page++) {
            $next = $this->getJson($this->url('records', $warehouse->id, ['source' => 'SCAN', 'limit' => 1, 'cursor' => $cursor]), $this->headers($token))
                ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.recordId', 'SCAN:'.$batch);
            $ids[] = $next->json('data.0.activityId');
            $cursor = $next->json('meta.nextCursor');
        }
        $this->assertNull($cursor);
        $this->assertCount(3, array_unique($ids));
        $this->getJson($this->url('scan-sessions/'.$foreignBatch, $warehouse->id), $this->headers($token))->assertNotFound();
        $this->getJson($this->url('records', $foreign->id), $this->headers($token))->assertForbidden();
    }

    public function test_cursor_cannot_be_tampered_reused_with_other_filters_or_another_actor(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $this->batch($user, $warehouse, ['ACCEPTED', 'DUPLICATE']);
        $cursor = $this->getJson($this->url('records', $warehouse->id, ['source' => 'SCAN', 'limit' => 1]), $this->headers($token))
            ->assertOk()->json('meta.nextCursor');
        $this->assertIsString($cursor);
        $this->getJson($this->url('records', $warehouse->id, ['source' => 'SCAN', 'limit' => 1, 'cursor' => 'invalid-'.$cursor]), $this->headers($token))->assertUnprocessable();
        $this->getJson($this->url('records', $warehouse->id, ['source' => 'SCAN', 'limit' => 1, 'cursor' => $cursor, 'status' => 'ACCEPTED']), $this->headers($token))->assertUnprocessable();
        [, $otherToken] = $this->actorSession();
        $this->getJson($this->url('records', $warehouse->id, ['source' => 'SCAN', 'limit' => 1, 'cursor' => $cursor]), $this->headers($otherToken))->assertUnprocessable();
    }

    public function test_record_and_activity_ids_never_fall_back_to_one_another(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $batch = $this->batch($user, $warehouse, ['ACCEPTED']);
        $row = $this->getJson($this->url('records', $warehouse->id, ['source' => 'SCAN']), $this->headers($token))->assertOk()->json('data.0');
        $this->getJson($this->url('records/'.$row['recordId'], $warehouse->id, ['activityId' => $row['activityId']]), $this->headers($token))
            ->assertOk()->assertJsonPath('data.selectedActivity.eventId', $row['eventId'])
            ->assertJsonPath('data.selectedActivity.status', 'ACCEPTED')->assertJsonPath('data.recordId', 'SCAN:'.$batch);
        $this->getJson($this->url('records/'.$row['activityId'], $warehouse->id), $this->headers($token))->assertNotFound();
        $this->getJson($this->url('records/'.$batch, $warehouse->id), $this->headers($token))->assertNotFound();
        $this->getJson($this->url('records/'.$row['recordId'], $warehouse->id, ['activityId' => 'SCAN:'.Str::uuid()]), $this->headers($token))->assertNotFound();
    }

    public function test_session_metrics_are_full_batch_totals_and_quantity_is_not_invented(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $batch = $this->batch($user, $warehouse, ['ACCEPTED', 'ACCEPTED', 'DUPLICATE', 'RESOLVED']);
        $this->getJson($this->url('scan-sessions/'.$batch, $warehouse->id, ['limit' => 1]), $this->headers($token))->assertOk()
            ->assertJsonPath('data.sessionId', $batch)->assertJsonPath('data.sessionType', 'SCAN_BATCH')
            ->assertJsonPath('data.total', 4)->assertJsonPath('data.accepted', 2)->assertJsonPath('data.duplicate', 1)
            ->assertJsonPath('data.other', 1)->assertJsonPath('data.quantity', null)->assertJsonCount(1, 'data.events');
        foreach ([['status' => 'ACCEPTED'], ['date_mode' => 'DATE', 'date' => now()->toDateString()], ['keyword' => 'x']] as $filter) {
            $this->getJson($this->url('scan-sessions/'.$batch, $warehouse->id, $filter), $this->headers($token))->assertUnprocessable();
        }
        $empty = $this->batch($user, $warehouse, []);
        $this->getJson($this->url('scan-sessions/'.$empty, $warehouse->id), $this->headers($token))->assertOk()
            ->assertJsonPath('data.total', 0)->assertJsonPath('data.quantity', null)->assertJsonCount(0, 'data.events');
        $this->getJson($this->url('scan-sessions/'.Str::uuid(), $warehouse->id), $this->headers($token))->assertNotFound();
    }

    public function test_summary_uses_full_filtered_source_not_the_page_length(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $this->batch($user, $warehouse, ['ACCEPTED', 'DUPLICATE', 'RESOLVED']);
        $this->getJson($this->url('records', $warehouse->id, ['source' => 'SCAN', 'limit' => 1]), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data');
        $summary = $this->getJson($this->url('summary', $warehouse->id, ['source' => 'SCAN']), $this->headers($token))->assertOk()
            ->assertJsonPath('data.availableTotal', 3)->assertJsonPath('data.countUnit', 'ACTIVITY');
        $this->assertSame(3, array_sum(array_column($summary->json('data.groups'), 'availableTotal')));
        $this->getJson($this->url('summary', $warehouse->id, ['source' => 'SCAN', 'status' => 'ACCEPTED']), $this->headers($token))
            ->assertOk()->assertJsonPath('data.availableTotal', 1);
        $this->getJson($this->url('summary', $warehouse->id, ['limit' => 1]), $this->headers($token))->assertUnprocessable();
    }

    public function test_date_range_is_validated_and_never_means_auth_session_range(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $this->batch($user, $warehouse, ['ACCEPTED']);
        $day = now()->subDay()->toDateString();
        $this->getJson($this->url('summary', $warehouse->id, ['source' => 'SCAN', 'date_mode' => 'DATE', 'date' => $day]), $this->headers($token))
            ->assertOk()->assertJsonPath('data.availableTotal', 1);
        $this->getJson($this->url('summary', $warehouse->id, ['date_mode' => 'RANGE', 'from' => '2026-10-09', 'to' => '2026-10-08']), $this->headers($token))->assertUnprocessable();
        $this->getJson($this->url('summary', $warehouse->id, ['date_mode' => 'ALL', 'date' => $day]), $this->headers($token))->assertUnprocessable();
    }

    public function test_read_surfaces_do_not_mutate_evidence_or_expose_raw_code_secrets(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $batch = $this->batch($user, $warehouse, ['ACCEPTED']);
        $tables = ['stock_documents', 'stock_movements', 'inventory_balances', 'scan_record_events', 'scan_batches', 'physical_codes'];
        $before = array_map(fn (string $t): int => DB::table($t)->count(), $tables);
        foreach (['records', 'summary', 'scan-sessions/'.$batch, 'records/SCAN:'.$batch] as $path) {
            $result = $this->getJson($this->url($path, $warehouse->id), $this->headers($token))->assertOk();
            $this->assertStringContainsString('no-store', $result->headers->get('Cache-Control'));
            $this->assertStringNotContainsString('p08-private-raw-marker', $result->getContent());
            $this->assertStringNotContainsString('access_token', $result->getContent());
        }
        $this->assertSame($before, array_map(fn (string $t): int => DB::table($t)->count(), $tables));
        foreach (['tenant_id', 'org_id', 'user_id'] as $field) {
            $this->getJson($this->url('records', $warehouse->id, [$field => (string) Str::uuid()]), $this->headers($token))->assertUnprocessable();
        }
    }

    private function actorSession(array $rights = ['history.read', 'inventory.view']): array
    {
        $warehouse = $this->warehouseFixture();
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p08_'.$suffix, 'display_name' => 'P08 isolated test', 'email' => 'p08_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P08_'.$suffix, 'role_name' => 'P08 isolated test role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
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

    private function batch(User $user, object $warehouse, array $statuses): string
    {
        $id = (string) Str::uuid();
        DB::table('scan_batches')->insert(['scan_batch_id' => $id, 'source_type' => 'MANUAL',
            'warehouse_id' => $warehouse->id, 'created_by' => $user->user_id, 'created_at' => now()->subDay()->setTime(12, 0)]);
        foreach ($statuses as $status) {
            // Identical timestamps exercise the immutable activityId tie-breaker.
            DB::table('scan_record_events')->insert(['scan_event_id' => (string) Str::uuid(),
                'scan_batch_id' => $id, 'raw_code' => 'p08-private-raw-marker', 'result_status' => $status,
                'created_at' => now()->subDay()->setTime(12, 0)]);
        }

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
