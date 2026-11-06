<?php

namespace Tests\Feature;

use App\Support\Cache\CacheService;
use Closure;
use Illuminate\Broadcasting\Broadcasters\RedisBroadcaster;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Support\ProfilePreferences;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use RuntimeException;
use Tests\Feature\Concerns\PreparesPutawayFixtures;
use Tests\TestCase;

/** Real commit boundaries in a disposable database; only the external transport is replaced. */
class WarehouseDataRealtimeTest extends TestCase
{
    use DatabaseMigrations;
    use PreparesPutawayFixtures;

    private WarehouseRealtimeBroadcaster $transport;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->transport = new WarehouseRealtimeBroadcaster($this->app->make('redis'));
        $transport = $this->transport;
        Broadcast::extend('warehouse-test', fn () => $transport);
        config(['broadcasting.default' => 'warehouse-test', 'broadcasting.connections.warehouse-test' => ['driver' => 'warehouse-test']]);
        require base_path('routes/channels.php');
    }

    private function actor(?string $permission = null, bool $superAdmin = false): User
    {
        $user = User::create([
            'username' => 'realtime-'.Str::lower(Str::random(10)),
            'display_name' => 'Warehouse realtime test',
            'password_hash' => Hash::make(Str::random(32)),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $roleCode = $superAdmin ? 'SUPER_ADMIN' : 'REALTIME_'.Str::upper(Str::random(10));
        $role = Role::firstOrCreate(['role_code' => $roleCode], ['role_name' => $roleCode, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        if ($permission !== null) {
            $grant = Permission::firstOrCreate(['permission_key' => $permission], ['permission_name' => $permission, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
            $role->permissions()->attach($grant->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        }

        return $user;
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function channelAuth(string $channel, array $headers = [])
    {
        return $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel], $headers);
    }

    private function finishRequest(int $status = 200): void
    {
        (new InvokeDeferredCallbacks)->terminate(Request::create('/api/v1/inbound-documents', 'POST'), new Response('', $status));
    }

    public function test_channel_requires_api_identity_and_existing_warehouse_read_permission(): void
    {
        $this->channelAuth('private-warehouse-data')->assertUnauthorized();
        foreach ([null, 'iam.user.manage'] as $permission) {
            $this->channelAuth('private-warehouse-data', $this->headers($this->actor($permission)))->assertForbidden();
        }
        foreach (['inbound.view', 'report.warranty.view', 'catalog.view', 'sku.manage', 'putaway.manage'] as $permission) {
            $this->channelAuth('private-warehouse-data', $this->headers($this->actor($permission)))->assertOk();
        }
        $this->channelAuth('private-warehouse-data', $this->headers($this->actor(superAdmin: true)))->assertOk();
    }

    public function test_channel_does_not_accept_web_session_or_widen_private_user_access(): void
    {
        $user = $this->actor('inbound.view');
        $this->actingAs($user, 'web');
        $this->channelAuth('private-warehouse-data')->assertUnauthorized();
        $headers = $this->headers($user);
        $this->channelAuth('private-user.'.$user->user_id, $headers)->assertOk();
        $this->channelAuth('private-user.'.Str::uuid(), $headers)->assertForbidden();
        $this->channelAuth('private-warehouse-data.foreign', $headers)->assertForbidden();
    }

    public function test_stock_invalidation_is_published_only_after_outer_commit_and_cache_bump(): void
    {
        $cache = app(CacheService::class);
        DB::beginTransaction();
        $cache->bumpVersion('stock:version');
        DB::beginTransaction();
        DB::commit();
        $this->assertSame(1, $cache->currentVersion('stock:version'));
        $this->assertSame([], $this->transport->messages);
        DB::commit();
        $this->assertSame(2, $cache->currentVersion('stock:version'));
        $this->assertSame([], $this->transport->messages);
        $this->finishRequest();
        $this->assertSame([[
            'channels' => ['private-warehouse-data'],
            'event' => 'warehouse.data.changed',
            'payload' => ['domain' => 'warehouse'],
            'transaction_level' => 0,
            'stock_version' => 2,
        ]], $this->transport->messages);
    }

    public function test_rollback_discards_pending_invalidation_and_unrelated_namespaces_do_not_broadcast(): void
    {
        $cache = app(CacheService::class);
        DB::beginTransaction();
        DB::beginTransaction();
        $cache->bumpVersion('stock:version');
        DB::commit();
        DB::rollBack();
        $this->assertSame(1, $cache->currentVersion('stock:version'));
        foreach (['auth:user:test', 'warehouse.catalog_master.brands:list', 'unknown:version'] as $namespace) {
            $this->assertSame(2, $cache->bumpVersion($namespace));
        }
        $this->finishRequest();
        $this->assertSame([], $this->transport->messages);
    }

    public function test_committed_bulk_bumps_coalesce_without_losing_hint_after_later_rollback_or_across_lifecycles(): void
    {
        $cache = app(CacheService::class);
        DB::transaction(function () use ($cache): void {
            for ($line = 0; $line < 10; $line++) {
                $cache->bumpVersion('stock:version');
            }
        });
        $this->assertSame(11, $cache->currentVersion('stock:version'));
        $this->assertSame([], $this->transport->messages);
        DB::beginTransaction();
        $cache->bumpVersion('stock:version');
        DB::rollBack();
        // A failed response must still publish changes that committed earlier.
        $this->finishRequest(500);
        $this->assertCount(1, $this->transport->messages);
        $this->assertSame(11, $this->transport->messages[0]['stock_version']);
        DB::transaction(fn () => $cache->bumpVersion('stock:version'));
        $this->finishRequest();
        $this->assertCount(2, $this->transport->messages);
        $this->assertSame(12, $this->transport->messages[1]['stock_version']);

        $job = new SyncJob($this->app, '{}', 'redis', 'default');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            DB::transaction(function () use ($cache): void {
                for ($line = 0; $line < 10; $line++) {
                    $cache->bumpVersion('stock:version');
                }
            });
            $this->assertCount(2 + $attempt, $this->transport->messages);
            event(new JobAttempted('redis', $job, new RuntimeException('Failure after earlier commit')));
            $this->assertCount(3 + $attempt, $this->transport->messages);
        }
    }

    public function test_actor_receives_invalidation_with_notifications_disabled_and_no_other_audience(): void
    {
        $actor = $this->actor(superAdmin: true);
        ProfilePreferences::put((string) $actor->user_id, ['notifications' => ['stock_documents' => ['in_app' => false, 'email' => false]]], 1);
        $headers = $this->headers($actor);
        $this->channelAuth('private-warehouse-data', $headers)->assertOk();
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
        $this->postJson('/api/v1/inbound-documents', ['dst_warehouse_id' => $warehouse->id], $headers)->assertCreated();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertNotEmpty($this->transport->messages);
        foreach ($this->transport->messages as $message) {
            $this->assertSame(['private-warehouse-data'], $message['channels']);
            $this->assertSame('warehouse.data.changed', $message['event']);
            $this->assertSame(['domain' => 'warehouse'], $message['payload']);
            $this->assertSame(0, $message['transaction_level']);
        }
    }

    public function test_broadcast_outage_does_not_fail_mutation_or_its_committed_cache_invalidation(): void
    {
        $actor = $this->actor(superAdmin: true);
        $headers = $this->headers($actor);
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
        $before = app(CacheService::class)->currentVersion('stock:version');
        Log::spy();
        $this->transport->fail = true;
        $response = $this->postJson('/api/v1/inbound-documents', ['dst_warehouse_id' => $warehouse->id], $headers)->assertCreated();
        $this->assertDatabaseHas('stock_documents', ['id' => $response->json('data.id'), 'status' => 'DRAFT']);
        $this->assertGreaterThan($before, app(CacheService::class)->currentVersion('stock:version'));
        Log::shouldHaveReceived('warning')->with('Warehouse realtime unavailable', ['exception' => RuntimeException::class])->once();
    }

    public function test_cache_failure_preserves_fail_open_invalidation_signal(): void
    {
        Log::spy();
        Cache::partialMock()->shouldReceive('add')->once()->andThrow(new RuntimeException('Synthetic cache outage'));
        $this->assertSame(1, app(CacheService::class)->bumpVersion('stock:version'));
        $this->finishRequest();
        $this->assertCount(1, $this->transport->messages);
        $this->assertSame(['domain' => 'warehouse'], $this->transport->messages[0]['payload']);
    }

    public function test_putaway_draft_creation_and_cancellation_invalidate_after_committed_state_change(): void
    {
        $actor = $this->actor(superAdmin: true);
        $headers = $this->headers($actor);
        $sku = Sku::create(['sku_code' => 'COMP-REALTIME', 'sku_name' => 'Component realtime', 'sku_type' => 'COMPONENT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $box = Container::create(['container_code' => 'BOX-REALTIME', 'container_type' => 'BOX', 'primary_sku_id' => $sku->sku_id, 'status' => 'RECEIVED_PENDING_PUTAWAY', 'version' => 1]);
        $tray = Container::create(['container_code' => 'TRAY-REALTIME', 'container_type' => 'TRAY', 'status' => 'EMPTY', 'version' => 1]);
        $this->seedPostedReceiptForBox((string) $box->container_id, (string) $sku->sku_id, actorId: (string) $actor->user_id);
        $this->transport->messages = [];
        $observed = [];
        $this->transport->observe = function () use (&$observed): void {
            $observed[] = DB::table('putaway_events')->value('status');
        };
        $response = $this->postJson('/api/v1/putaway/tasks', ['box_container_id' => $box->container_id, 'to_tray_id' => $tray->container_id], $headers)->assertCreated();
        $id = (string) $response->json('data.id');
        $this->assertSame(['DRAFT'], $observed);
        $this->postJson('/api/v1/putaway/tasks/'.$id.'/cancel', [], $headers + ['If-Match' => '1'])->assertOk();
        $this->assertSame(['DRAFT', 'CANCELLED'], $observed);
        $this->assertCount(2, $this->transport->messages);
        $this->assertSame([0, 0], array_column($this->transport->messages, 'transaction_level'));
    }
}

/** Uses Laravel's real private-channel authorization; replaces only the external transport. */
class WarehouseRealtimeBroadcaster extends RedisBroadcaster
{
    public array $messages = [];

    public bool $fail = false;

    public ?Closure $observe = null;

    public function broadcast(array $channels, $event, array $payload = []): void
    {
        if ($this->fail) {
            throw new RuntimeException('Synthetic broadcaster outage');
        }
        if ($this->observe !== null) {
            ($this->observe)();
        }
        $this->messages[] = [
            'channels' => array_map(strval(...), $channels),
            'event' => $event,
            'payload' => $payload,
            'transaction_level' => DB::transactionLevel(),
            'stock_version' => app(CacheService::class)->currentVersion('stock:version'),
        ];
    }
}
