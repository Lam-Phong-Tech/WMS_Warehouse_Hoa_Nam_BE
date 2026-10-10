<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\P08HistoryReadModel;
use ReflectionProperty;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Actual migrated stock-document schema, not a fabricated warehouse_id column. */
class P08DocumentHistoryTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_document_records_summary_detail_and_paging_use_directional_ownership(): void
    {
        [$user, $token, $warehouse] = $this->actor(['history.read', 'inbound.view', 'outbound.view']);
        $foreign = Warehouse::create(['code' => 'P08-FOREIGN', 'name' => 'Isolated foreign warehouse', 'status' => 'INACTIVE']);
        [$receipt, $events] = $this->document($user, 'receipt', null, $warehouse->id, 2);
        [$issue] = $this->document($user, 'issue', $warehouse->id, null, 1);
        [$otherReceipt] = $this->document($user, 'receipt', $warehouse->id, $foreign->id, 2);
        [$otherIssue] = $this->document($user, 'issue', $foreign->id, $warehouse->id, 2);
        $before = $this->snapshot();
        $first = $this->getJson($this->url('records', $warehouse->id, ['limit' => 1]), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.sources.DOCUMENT.status', 'INCLUDED')
            ->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data');
        $seen = $first->json('data');
        $cursor = $first->json('meta.nextCursor');
        for ($page = 0; $page < 3 && $cursor !== null; $page++) {
            $next = $this->getJson($this->url('records', $warehouse->id, ['limit' => 1, 'cursor' => $cursor]), $this->headers($token))
                ->assertOk()->assertJsonPath('meta.total', 3);
            array_push($seen, ...$next->json('data'));
            $cursor = $next->json('meta.nextCursor');
        }
        $this->assertNull($cursor);
        $this->assertCount(3, array_unique(array_column($seen, 'activityId')));
        $this->assertEqualsCanonicalizing(['DOCUMENT:'.$receipt, 'DOCUMENT:'.$issue], array_values(array_unique(array_column($seen, 'recordId'))));
        foreach ($seen as $row) {
            $this->assertNull($row['quantity']);
            $this->assertFalse($row['isConfirmationReceipt']);
            $this->assertFalse($row['detail']['canOpen']); // No documents.read grant.
            $this->assertSame('SOURCE_TIMESTAMP', $row['timeStatus']);
            $this->assertNotEmpty($row['time']);
        }
        $this->getJson($this->url('summary', $warehouse->id), $this->headers($token))
            ->assertOk()->assertJsonPath('data.grandTotal', 3)->assertJsonPath('data.availableTotal', 3)
            ->assertJsonPath('data.countUnit', 'ACTIVITY');
        $this->getJson($this->url('summary', $warehouse->id, ['business' => 'INBOUND']), $this->headers($token))
            ->assertOk()->assertJsonPath('data.availableTotal', 2);
        $this->getJson($this->url('summary', $warehouse->id, ['date_mode' => 'DATE', 'date' => now()->subDay()->toDateString()]), $this->headers($token))
            ->assertOk()->assertJsonPath('data.availableTotal', 3);
        $this->getJson($this->url('records/DOCUMENT:'.$receipt, $warehouse->id, ['activityId' => 'DOCUMENT:'.$events[0]]), $this->headers($token))
            ->assertOk()->assertJsonPath('data.recordId', 'DOCUMENT:'.$receipt)
            ->assertJsonPath('data.selectedActivity.eventId', $events[0])->assertJsonCount(2, 'data.events');
        foreach ([$otherReceipt, $otherIssue] as $foreignId) {
            $this->getJson($this->url('records/DOCUMENT:'.$foreignId, $warehouse->id), $this->headers($token))->assertNotFound();
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_inbound_and_outbound_permissions_are_applied_before_totals(): void
    {
        [$user, $token, $warehouse] = $this->actor(['history.read', 'inbound.view']);
        [$receipt] = $this->document($user, 'receipt', null, $warehouse->id, 2);
        [$issue] = $this->document($user, 'issue', $warehouse->id, null, 1);
        $this->getJson($this->url('records', $warehouse->id), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.recordId', 'DOCUMENT:'.$receipt);
        $this->getJson($this->url('records/DOCUMENT:'.$issue, $warehouse->id), $this->headers($token))->assertNotFound();
        [, $outbound] = $this->actor(['history.read', 'outbound.view']);
        $this->getJson($this->url('summary', $warehouse->id), $this->headers($outbound))
            ->assertOk()->assertJsonPath('data.availableTotal', 1);
        $this->getJson($this->url('records/DOCUMENT:'.$receipt, $warehouse->id), $this->headers($outbound))->assertNotFound();
        [, $none] = $this->actor(['history.read', 'inventory.view']);
        $this->getJson($this->url('records', $warehouse->id), $this->headers($none))->assertForbidden();
    }

    public function test_missing_document_schema_remains_unavailable_not_fake_empty(): void
    {
        [, $token, $warehouse] = $this->actor(['history.read', 'inbound.view']);
        $model = new P08HistoryReadModel;
        (new ReflectionProperty($model, 'columns'))->setValue($model, ['stock_documents' => []]);
        $this->app->instance(P08HistoryReadModel::class, $model);
        $this->getJson($this->url('records', $warehouse->id), $this->headers($token))
            ->assertStatus(503)->assertJsonPath('error_code', 'HISTORY_SOURCE_UNAVAILABLE');
    }

    private function actor(array $rights): array
    {
        $warehouse = $this->warehouseFixture();
        $suffix = Str::lower(Str::random(10));
        $secret = bin2hex(random_bytes(18));
        $user = User::create(['username' => 'p08doc_'.$suffix, 'display_name' => 'Isolated document reader',
            'email' => 'p08doc_'.$suffix.'@example.test', 'password_hash' => Hash::make($secret),
            'status' => 'ACTIVE', 'version' => 1, 'must_change_password' => false]);
        $role = Role::create(['role_code' => 'P08DOC_'.$suffix, 'role_name' => 'Isolated document role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($rights as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->headers('');
        $login = $this->browserLogin($user->email, $secret)->assertOk()->json('data.access_token');
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], $this->headers($login))
            ->assertOk()->json('data.access_token');

        return [$user, $token, $warehouse];
    }

    private function document(User $user, string $type, ?string $source, ?string $destination, int $count): array
    {
        $id = (string) Str::uuid();
        $at = now()->subDay()->setTime(12, 0);
        DB::table('stock_documents')->insert([
            'id' => $id, 'doc_type' => $type, 'doc_no' => 'P08DOC-'.Str::upper(Str::random(12)),
            'doc_date' => $at->toDateString(), 'status' => 'DRAFT', 'src_warehouse_id' => $source,
            'dst_warehouse_id' => $destination, 'created_by' => $user->user_id,
            'recipient_name' => 'Isolated P08 recipient', 'recipient_address' => 'Isolated P08 address',
            'version' => 1, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $event = (string) Str::uuid();
            DB::table('document_status_history')->insert([
                'id' => $event, 'stock_document_id' => $id, 'from_status' => null,
                'to_status' => 'DRAFT', 'action' => 'create', 'changed_by' => $user->user_id,
                'created_at' => $at,
            ]);
        }

        return [$id, $ids];
    }

    private function url(string $path, string $warehouse, array $filters = []): string
    {
        return '/api/v1/mini-app/history/'.$path.'?'.http_build_query(['warehouse_id' => $warehouse, 'source' => 'DOCUMENT', ...$filters]);
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['stock_documents', 'document_status_history', 'stock_movements', 'inventory_balances'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $result;
    }
}
