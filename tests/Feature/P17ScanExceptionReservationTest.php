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
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Disposable-database regression tests. Never run against a live warehouse. */
class P17ScanExceptionReservationTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const RESOLVE = '/api/v1/mini-app/outbound/resolve-code';

    public function test_scan_permission_does_not_disclose_the_reserving_document(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $source = $this->reservedFixture($token, $warehouse);
        $this->revokeRead($user);
        $before = $this->domainSnapshot();

        $response = $this->postJson(self::RESOLVE, [
            'warehouse_id' => $warehouse->id, 'raw_code' => $source['raw_code'],
        ], $this->headers($token))->assertOk()
            ->assertJsonPath('data.eligible_for_outbound', false)
            ->assertJsonPath('data.eligibility_code', 'ITEM_ALREADY_MATCHED')
            ->assertJsonPath('data.reservation', ['redacted' => true, 'can_open' => false]);

        foreach (['document_id', 'match_id', 'doc_no', 'outbound_request_id'] as $key) {
            $this->assertStringNotContainsString($source[$key], $response->getContent());
        }
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_authorized_same_scope_projection_agrees_with_owner_document_read(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $source = $this->reservedFixture($token, $warehouse);
        $before = $this->domainSnapshot();

        $response = $this->postJson(self::RESOLVE, [
            'warehouse_id' => $warehouse->id, 'raw_code' => $source['raw_code'],
        ], $this->headers($token))->assertOk()
            ->assertJsonPath('data.eligible_for_outbound', false)
            ->assertJsonPath('data.reservation.document_id', $source['document_id'])
            ->assertJsonPath('data.reservation.doc_no', $source['doc_no'])
            ->assertJsonPath('data.reservation.redacted', false)
            ->assertJsonPath('data.reservation.can_open', true);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->getJson('/api/v1/mini-app/outbound-documents/'.$source['document_id'],
            $this->headers($token))->assertOk();
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_related_document_in_another_warehouse_is_redacted_even_with_view_permission(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $source = $this->reservedFixture($token, $warehouse);
        $foreign = Warehouse::create([
            'code' => 'P17-'.Str::upper(Str::random(8)), 'name' => 'Isolated foreign scope', 'status' => 'INACTIVE',
        ]);
        // Isolated authorization fixture; no live document is moved by this test.
        DB::table('stock_documents')->where('id', $source['document_id'])->update(['src_warehouse_id' => $foreign->id]);
        $before = $this->domainSnapshot();

        $this->postJson(self::RESOLVE, [
            'warehouse_id' => $warehouse->id, 'raw_code' => $source['raw_code'],
        ], $this->headers($token))->assertOk()
            ->assertJsonPath('data.eligibility_code', 'ITEM_ALREADY_MATCHED')
            ->assertJsonPath('data.reservation', ['redacted' => true, 'can_open' => false]);
        $this->getJson('/api/v1/mini-app/outbound-documents/'.$source['document_id'],
            $this->headers($token))->assertForbidden();
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_revoked_read_permission_is_not_restored_by_a_previous_resolve_response(): void
    {
        [$user, $token, $warehouse] = $this->actorSession();
        $source = $this->reservedFixture($token, $warehouse);
        $body = ['warehouse_id' => $warehouse->id, 'raw_code' => $source['raw_code']];
        $this->postJson(self::RESOLVE, $body, $this->headers($token))->assertOk()
            ->assertJsonPath('data.reservation.can_open', true);
        $this->revokeRead($user);
        $before = $this->domainSnapshot();
        $this->postJson(self::RESOLVE, $body, $this->headers($token))->assertOk()
            ->assertJsonPath('data.reservation', ['redacted' => true, 'can_open' => false])
            ->assertJsonPath('data.eligible_for_outbound', false);
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_unknown_identity_is_an_owner_error_not_a_fake_item_with_zero_stock(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $source = $this->sourceFixture($warehouse);
        $before = $this->domainSnapshot();
        $this->postJson(self::RESOLVE, [
            'warehouse_id' => $warehouse->id, 'raw_code' => $source['raw_code'],
        ], $this->headers($token))->assertOk()->assertJsonPath('data.eligible_for_outbound', true)
            ->assertJsonPath('data.reservation', null)->assertJsonPath('data.stock_effect', 'NONE');
        $response = $this->postJson(self::RESOLVE, [
            'warehouse_id' => $warehouse->id,
            'raw_code' => 'HN1|SKU='.$source['sku_code'].'|ITEM=P17-UNKNOWN-'.Str::upper(Str::random(12)),
        ], $this->headers($token))->assertStatus(422)->assertJsonPath('error.code', 'PHYSICAL_CODE_NOT_FOUND');
        $data = $response->json('data');
        $this->assertFalse(is_array($data) && (array_key_exists('item_id', $data) || array_key_exists('available_qty', $data)));
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_standalone_owner_rejection_redacts_the_same_reservation_without_writes(): void
    {
        [$user, $token, $warehouse] = $this->actorSession([
            'outbound.view', 'outbound.scan', 'outbound.request.manual_create',
        ]);
        $source = $this->reservedFixture($token, $warehouse);
        $this->revokeRead($user);
        $before = $this->domainSnapshot();
        $response = $this->postJson('/api/v1/mini-app/outbound/record', [
            'name' => 'P17 isolated denied owner record',
            'warehouse_id' => $warehouse->id,
            'recipient_name' => 'P17 isolated recipient',
            'expected_total_qty' => 1,
            'items' => [['raw_code' => $source['raw_code'], 'scan_source' => 'CAMERA']],
        ], $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('error.code', 'ITEM_ALREADY_MATCHED');

        $this->assertStringContainsString('"redacted":true', $response->getContent());
        foreach (['document_id', 'match_id', 'doc_no', 'outbound_request_id'] as $key) {
            $this->assertStringNotContainsString($source[$key], $response->getContent());
        }
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_missing_scan_permission_denies_resolve_before_disclosure(): void
    {
        [, $token, $warehouse] = $this->actorSession(['outbound.view']);
        $source = $this->sourceFixture($warehouse);
        $before = $this->domainSnapshot();
        $response = $this->postJson(self::RESOLVE, [
            'warehouse_id' => $warehouse->id, 'raw_code' => $source['raw_code'],
        ], $this->headers($token))->assertForbidden();

        $this->assertStringNotContainsString($source['item_id'], $response->getContent());
        $this->assertStringNotContainsString($source['document_id'], $response->getContent());
        $this->assertSame($before, $this->domainSnapshot());
    }

    private function actorSession(array $permissions = ['outbound.view', 'outbound.scan']): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P17 isolated test warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create([
            'username' => 'p17_'.$suffix, 'display_name' => 'P17 isolated actor', 'email' => 'p17_'.$suffix.'@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1,
        ]);
        $role = Role::create([
            'role_code' => 'P17_'.$suffix, 'role_name' => 'Isolated owner-test role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE',
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], [
                'permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE',
            ]);
            DB::table('role_permission')->insert([
                'role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->app['auth']->forgetGuards();
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', [
            'context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id,
        ], $this->headers($login->json('data.access_token')))->assertOk();

        return [$user, $selected->json('data.access_token'), $warehouse];
    }

    private function sourceFixture(object $warehouse): array
    {
        $sku = $this->productSkuFixture('P17-'.Str::upper(Str::random(8)));
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $item = $this->seedItemInStock($sku->sku_id);
        $code = 'P17-ITEM-'.Str::upper(Str::random(10));
        $this->seedScanCode($item, $code);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'P17-REQ-'.Str::upper(Str::random(10)));
        $document = $this->outboundDocumentFixture($warehouse->id, $requestId);
        $row = DB::table('stock_documents')->where('id', $document['document_id'])->first();

        return [
            'document_id' => (string) $row->id, 'doc_no' => (string) $row->doc_no,
            'outbound_request_id' => (string) $row->outbound_request_id,
            'item_id' => $item, 'sku_code' => $sku->sku_code,
            'raw_code' => 'HN1|SKU='.$sku->sku_code.'|ITEM='.$code,
        ];
    }

    private function reservedFixture(string $token, object $warehouse): array
    {
        $source = $this->sourceFixture($warehouse);
        // Arrange via the existing owner scan, on the disposable test DB only.
        $this->postJson('/api/v1/mini-app/outbound-documents/'.$source['document_id'].'/scan', [
            'code_value' => $source['raw_code'], 'quantity' => 1, 'scan_source' => 'CAMERA',
        ], $this->headers($token) + ['If-Match' => '"1"'])->assertOk();
        $source['match_id'] = (string) DB::table('outbound_item_matches')->where('item_id', $source['item_id'])->value('id');

        return $source;
    }

    private function revokeRead(User $user): void
    {
        $role = $user->roles()->firstOrFail();
        $permissionId = Permission::where('permission_key', 'outbound.view')->value('permission_id');
        DB::table('role_permission')->where('role_id', $role->role_id)->where('permission_id', $permissionId)->delete();
        // Match the production RoleController invalidation after commit. The
        // RefreshDatabase fixture keeps an outer transaction open, so advance
        // the same per-user cache version here; never flush shared caches.
        $user->newQueryWithoutScopes()->whereKey($user->getKey())->increment('version');
    }

    private function domainSnapshot(): array
    {
        $tables = [
            'stock_documents', 'stock_document_lines', 'outbound_item_matches',
            'stock_movements', 'inventory_balances', 'physical_codes', 'item_instances',
        ];
        $snapshot = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }
}
