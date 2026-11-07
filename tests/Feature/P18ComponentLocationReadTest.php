<?php

namespace Tests\Feature;

use App\Support\ExplicitReadFields;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\Feature\Concerns\PreparesPutawayFixtures;
use Tests\TestCase;

class P18ComponentLocationReadTest extends TestCase
{
    use InteractsWithBrowserSession, PreparesOutboundFixtures, PreparesPutawayFixtures, RefreshDatabase;

    private const HEADER = 'X-Component-Location-Contract';

    private const CONTRACT = 'p18-component-location-read-v1';

    public function test_box_and_item_preserve_trusted_read_correlation_and_observation_timestamp(): void
    {
        $f = $this->fixture();
        $itemId = $this->member($f);
        $this->freezeTime();
        $before = $this->domain();
        foreach ([null, $itemId] as $id) {
            $requestId = (string) Str::uuid();
            $response = $this->get($this->url($f, $id), $this->headers($f) + ['X-Request-ID' => $requestId])
                ->assertOk()->assertHeader('X-Request-ID', $requestId)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertJsonPath('meta.request_id', $requestId)
                ->assertJsonPath('meta.revision', 'p18-component-location-read-r1')
                ->assertJsonPath('meta.read_fields.owner_contract', self::CONTRACT);
            $timestamp = $response->json('meta.timestamp');
            $this->assertIsString($timestamp);
            $this->assertSame(now()->getTimestamp(), (new \DateTimeImmutable($timestamp))->getTimestamp());
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('Authorization', $response->headers->get('Vary'));
            $this->assertNoNull($response->json());
        }
        $this->assertSame($before, $this->domain());
    }

    public function test_box_returns_only_confirmed_current_putaway_location_and_never_writes(): void
    {
        $f = $this->fixture();
        $event = $this->placement($f);
        $before = $this->domain();
        $response = $this->get($this->url($f), $this->headers($f))->assertOk()
            ->assertJsonPath('meta.contract', ExplicitReadFields::CONTRACT)
            ->assertJsonPath('meta.read_fields.owner_contract', self::CONTRACT)
            ->assertJsonPath('data.identity.container_id', $f['box']->container_id)
            ->assertJsonPath('data.identity.physical_code', $f['box']->container_code)
            ->assertJsonPath('data.identity.physical_code_status', 'RECORDED_PHYSICAL_CODE')
            ->assertJsonPath('data.unit_status', 'CURRENT_DIRECTORY')
            ->assertJsonPath('data.current_location.location_id', $f['tray']->container_id)
            ->assertJsonPath('data.current_location.location_code', $f['tray']->container_code)
            ->assertJsonPath('data.current_location.provenance.event_id', $event)
            ->assertJsonPath('data.current_location.effective_at', '2026-11-02T01:00:00.000000Z')
            ->assertJsonPath('data.current_location.placement_version', 2)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('Authorization', $response->headers->get('Vary'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertMissing($response, '/data/hierarchy', 'NOT_CONFIGURED');
        $this->assertMissing($response, '/data/capacity', 'NOT_CONFIGURED');
        $this->assertNoNull($response->json());
        $this->assertSame($before, $this->domain());
    }

    public function test_item_inherits_only_from_exact_active_membership_and_box_placement(): void
    {
        $f = $this->fixture();
        $this->placement($f);
        $id = $this->member($f);
        $this->get($this->url($f, $id), $this->headers($f))->assertOk()
            ->assertJsonPath('data.identity.kind', 'ITEM')->assertJsonPath('data.identity.item_id', $id)
            ->assertJsonPath('data.identity.physical_code', 'P18-ITEM-EXACT')
            ->assertJsonPath('data.current_location.provenance.basis', 'ACTIVE_ITEM_MEMBERSHIP_AND_CONFIRMED_BOX_PUTAWAY')
            ->assertJsonPath('data.parent_container_identity.container_id', $f['box']->container_id);
    }

    public function test_hint_or_parent_without_confirmed_evidence_is_not_a_location(): void
    {
        $f = $this->fixture();
        DB::table('containers')->where('container_id', $f['box']->container_id)->update(['location_hint' => 'RACK-FAKE-001']);
        $r = $this->get($this->url($f), $this->headers($f))->assertOk()->assertJsonMissingPath('data.current_location');
        $this->assertMissing($r, '/data/current_location', 'NOT_RECORDED');
        $this->assertStringNotContainsString('RACK-FAKE-001', $r->getContent());
    }

    public function test_mismatching_parent_and_equal_time_confirmations_fail_closed(): void
    {
        $f = $this->fixture();
        $this->placement($f);
        DB::table('containers')->where('container_id', $f['box']->container_id)->update(['parent_container_id' => null]);
        $r = $this->get($this->url($f), $this->headers($f))->assertOk();
        $this->assertMissing($r, '/data/current_location', 'SOURCE_INCONSISTENT');
        DB::table('containers')->where('container_id', $f['box']->container_id)->update(['parent_container_id' => $f['tray']->container_id]);
        $this->placement($f);
        $r = $this->get($this->url($f), $this->headers($f))->assertOk();
        $this->assertMissing($r, '/data/current_location', 'SOURCE_INCONSISTENT');
    }

    public function test_removed_item_parent_never_falls_back_to_box_hint(): void
    {
        $f = $this->fixture();
        $this->placement($f);
        $id = $this->member($f);
        $removed = ['container_id' => null];
        if (Schema::hasColumn('item_instances', 'current_container_id')) {
            $removed['current_container_id'] = null;
        }
        DB::table('item_instances')->where('item_id', $id)->update($removed);
        $this->assertNull(DB::table('item_instances')->where('item_id', $id)->value('container_id'));
        $r = $this->get($this->url($f, $id), $this->headers($f))->assertOk();
        // The canonical/compatibility parent must both be cleared. A remaining
        // ACTIVE membership is inconsistent; no membership means unrecorded.
        $active = DB::table('container_items')->where('item_id', $id)->where('is_active', true)->count();
        $r->assertJsonMissingPath('data.current_location');
        $this->assertMissing($r, '/data/current_location', $active === 0 ? 'NOT_RECORDED' : 'SOURCE_INCONSISTENT');
    }

    public function test_identity_permission_does_not_grant_location_or_leak_source(): void
    {
        $f = $this->fixture(['container.view', 'sku.view']);
        $this->placement($f);
        $r = $this->get($this->url($f), $this->headers($f))->assertOk();
        $this->assertMissing($r, '/data/current_location', 'REDACTED_PERMISSION');
        $this->assertStringNotContainsString($f['tray']->container_code, $r->getContent());
        $this->assertStringNotContainsString($f['tray']->container_id, $r->getContent());
    }

    public function test_bad_contract_query_and_unauthenticated_requests_are_rejected(): void
    {
        $f = $this->fixture();
        $this->headers($f); // Clear the test runner's cached guard before anonymous GET.
        $this->get($this->url($f), [self::HEADER => self::CONTRACT])->assertUnauthorized();
        $this->get($this->url($f), array_replace($this->headers($f), [self::HEADER => 'unknown']))->assertStatus(409);
        $this->get($this->url($f).'&sku_id='.$f['sku']->sku_id, $this->headers($f))->assertStatus(422);
        $this->get('/api/v1/containers/'.$f['box']->container_id, $this->headers($f))->assertStatus(422);
        $this->get($this->url($f), array_replace($this->headers($f), [ExplicitReadFields::HEADER => 'unknown']))->assertStatus(422);
        $this->get($this->url($f), $this->headers($f) + ['If-None-Match' => '"old"'])->assertStatus(422);
    }

    public function test_unknown_id_or_another_warehouse_does_not_select_the_first_sku_match(): void
    {
        $f = $this->fixture();
        $this->get('/api/v1/containers/'.Str::uuid().'?warehouse_id='.$f['warehouse']->id, $this->headers($f))->assertNotFound();
        $this->get('/api/v1/containers/'.$f['box']->container_id.'?warehouse_id='.Str::uuid(), $this->headers($f))->assertForbidden();
        $other = Container::create(['container_code' => 'BOX-P18-SECOND', 'container_type' => 'BOX', 'primary_sku_id' => $f['sku']->sku_id, 'status' => 'EMPTY', 'version' => 1]);
        $this->placement($f);
        $r = $this->get('/api/v1/containers/'.$other->container_id.'?warehouse_id='.$f['warehouse']->id, $this->headers($f))->assertOk();
        $this->assertMissing($r, '/data/current_location', 'NOT_RECORDED');
    }

    public function test_scope_and_permission_revocation_are_checked_on_each_read(): void
    {
        $f = $this->fixture();
        $this->placement($f);
        $this->get($this->url($f), $this->headers($f))->assertOk();
        DB::table('role_permission')->where('role_id', $f['role']->role_id)->delete();
        $f['user']->increment('version');
        $this->get($this->url($f), $this->headers($f))->assertForbidden();
    }

    public function test_revoked_auth_family_cannot_read_even_with_unexpired_access_token(): void
    {
        $f = $this->fixture();
        DB::table('refresh_tokens')->where('user_id', $f['user']->user_id)->update(['revoked_at' => now()->utc()]);
        $this->get($this->url($f), $this->headers($f))->assertUnauthorized();
    }

    public function test_permission_revoked_during_snapshot_never_leaks_the_prepared_location(): void
    {
        $f = $this->fixture();
        $this->placement($f);
        $revoked = false;
        DB::listen(function (QueryExecuted $query) use ($f, &$revoked): void {
            if (! $revoked && str_contains($query->sql, 'from "putaway_events" as "p"')) {
                $revoked = true;
                DB::table('role_permission')->where('role_id', $f['role']->role_id)->delete();
            }
        });
        $r = $this->get($this->url($f), $this->headers($f))->assertForbidden();
        $this->assertTrue($revoked);
        $this->assertStringNotContainsString($f['tray']->container_code, $r->getContent());
    }

    public function test_missing_source_is_503_not_an_absent_location(): void
    {
        $f = $this->fixture();
        Schema::rename('putaway_events', 'p18_isolated_unavailable');
        try {
            $this->get($this->url($f), $this->headers($f))->assertStatus(503)->assertJsonPath('error.code', 'P18_LOCATION_SOURCE_UNAVAILABLE');
        } finally {
            Schema::rename('p18_isolated_unavailable', 'putaway_events');
        }
    }

    public function test_admin_role_name_does_not_replace_actual_permission_grants(): void
    {
        $f = $this->fixture();
        $this->placement($f);
        $f['role']->update(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin']);
        DB::table('role_permission')->where('role_id', $f['role']->role_id)->delete();
        $f['user']->increment('version');
        $this->get($this->url($f), $this->headers($f))->assertForbidden();
    }

    public function test_a_tenant_scoped_source_is_never_read_without_matching_trusted_dimensions(): void
    {
        $f = $this->fixture();
        Schema::table('containers', fn ($table) => $table->uuid('tenant_id')->nullable());
        DB::table('containers')->where('container_id', $f['box']->container_id)->update(['tenant_id' => (string) Str::uuid()]);
        $this->get($this->url($f), $this->headers($f))->assertStatus(503);
    }

    public function test_ambiguous_item_codes_are_omitted_instead_of_choosing_first(): void
    {
        $f = $this->fixture();
        $id = $this->member($f);
        $this->seedScanCode($id, 'P18-ITEM-SECOND-CODE');
        $r = $this->get($this->url($f, $id), $this->headers($f))->assertOk();
        $this->assertMissing($r, '/data/identity/physical_code', 'SOURCE_INCONSISTENT');
    }

    public function test_compatibility_item_code_does_not_replace_an_active_physical_code(): void
    {
        $f = $this->fixture();
        $id = $this->member($f);
        DB::table('item_instances')->where('item_id', $id)->update(['item_code' => 'ITEM-SYNTHETIC-NOT-A-PHYSICAL-CODE']);
        $this->get($this->url($f, $id), $this->headers($f))->assertOk()->assertJsonPath('data.identity.physical_code', 'P18-ITEM-EXACT');
        DB::table('physical_codes')->where('assigned_object_id', $id)->update(['status' => 'INACTIVE']);
        $r = $this->get($this->url($f, $id), $this->headers($f))->assertOk();
        $this->assertMissing($r, '/data/identity/physical_code', 'NOT_RECORDED');
    }

    public function test_partial_box_keeps_confirmed_location_without_claiming_availability(): void
    {
        $f = $this->fixture();
        $event = $this->placement($f);
        DB::table('containers')->where('container_id', $f['box']->container_id)->update(['status' => 'PARTIAL']);
        $this->get($this->url($f), $this->headers($f))->assertOk()
            ->assertJsonPath('data.current_location.provenance.event_id', $event)
            ->assertJsonMissingPath('data.available_qty')->assertJsonMissingPath('data.reserved_qty');
    }

    public function test_future_confirmation_is_not_presented_as_current_location(): void
    {
        $f = $this->fixture();
        $this->placement($f, ['confirmed_at' => '2099-01-01 12:00:00']);
        $r = $this->get($this->url($f), $this->headers($f))->assertOk();
        $this->assertMissing($r, '/data/current_location', 'SOURCE_INCONSISTENT');
    }

    public function test_closed_tray_is_not_presented_as_current_location(): void
    {
        $f = $this->fixture();
        $this->placement($f);
        DB::table('containers')->where('container_id', $f['tray']->container_id)->update(['status' => 'CLOSED']);
        $r = $this->get($this->url($f), $this->headers($f))->assertOk();
        $this->assertMissing($r, '/data/current_location', 'SOURCE_INCONSISTENT');
    }

    public function test_missing_labels_remain_explicit_and_do_not_get_synthetic_values(): void
    {
        $f = $this->fixture();
        DB::table('sku')->where('sku_id', $f['sku']->sku_id)->update(['unit' => '']);
        $r = $this->get($this->url($f), $this->headers($f))->assertOk()->assertJsonMissingPath('data.unit');
        $this->assertMissing($r, '/data/unit', 'NOT_RECORDED');
        $this->assertNoNull($r->json());
    }

    public function test_draft_or_cancelled_putaway_does_not_replace_confirmed_placement(): void
    {
        $f = $this->fixture();
        $event = $this->placement($f);
        $this->placement($f, ['status' => 'DRAFT', 'confirmed_at' => null, 'confirmed_by' => null]);
        $this->placement($f, ['status' => 'CANCELLED', 'confirmed_at' => null, 'confirmed_by' => null]);
        $this->get($this->url($f), $this->headers($f))->assertOk()->assertJsonPath('data.current_location.provenance.event_id', $event);
    }

    public function test_legacy_box_and_item_contracts_are_unchanged_without_new_header(): void
    {
        $f = $this->fixture();
        $headers = $this->headers($f);
        unset($headers[self::HEADER]);
        $headers['X-Container-Read-Contract'] = 'p18-single-warehouse-boxes-v1';
        $this->get($this->url($f), $headers)->assertOk()
            ->assertJsonPath('meta.read_fields.owner_contract', 'p18-single-warehouse-boxes-v1')
            ->assertJsonPath('data.warehouse_scope_basis', 'SINGLE_WAREHOUSE_REGISTER_NOT_PHYSICAL_PLACEMENT');
        $id = $this->member($f);
        unset($headers['X-Container-Read-Contract'], $headers[ExplicitReadFields::HEADER]);
        $this->get('/api/v1/item-instances/'.$id, $headers)->assertOk()->assertJsonPath('data.item_id', $id)->assertJsonMissingPath('data.current_location');
    }

    private function fixture(?array $rights = null): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN');
        $user = User::create(['username' => 'p18_location', 'email' => 'p18-location@wms-hoanam.test', 'display_name' => 'Location reader', 'password_hash' => Hash::make('isolated-test-only'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P18_READER', 'role_name' => 'Not an administrator bypass', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($rights ?? ['sku.view', 'container.view', 'item_instance.view', 'putaway.view'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        }
        $token = $this->browserLogin($user->email, 'isolated-test-only')->assertOk()->json('data.access_token');
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], ['Authorization' => 'Bearer '.$token])->assertOk()->json('data.access_token');
        $sku = Sku::create(['sku_code' => 'P18-COMP', 'sku_name' => 'Current component master', 'sku_type' => 'COMPONENT', 'is_serial_tracked' => false, 'status' => 'ACTIVE', 'unit' => 'cai']);
        $tray = Container::create(['container_code' => 'TRAY-P18-REAL-FIXTURE', 'container_type' => 'TRAY', 'status' => 'EMPTY', 'version' => 1]);
        $box = Container::create(['container_code' => 'BOX-P18-EXACT', 'container_type' => 'BOX', 'primary_sku_id' => $sku->sku_id, 'status' => 'STORED', 'version' => 3]);

        return compact('warehouse', 'user', 'role', 'token', 'sku', 'tray', 'box');
    }

    private function placement(array $f, array $overrides = []): string
    {
        $receipt = $this->seedPostedReceiptForBox($f['box']->container_id, $f['sku']->sku_id, 1, $f['user']->user_id);
        DB::table('containers')->where('container_id', $f['box']->container_id)->update(['parent_container_id' => $f['tray']->container_id]);
        $id = (string) Str::uuid();
        DB::table('putaway_events')->insert(array_replace(['id' => $id, 'warehouse_id' => $f['warehouse']->id, 'stock_document_id' => $receipt['document_id'], 'container_id' => $f['box']->container_id, 'box_container_id' => $f['box']->container_id, 'to_tray_id' => $f['tray']->container_id, 'to_parent_container_id' => $f['tray']->container_id, 'status' => 'CONFIRMED', 'confirmed_at' => '2026-11-02 08:00:00', 'confirmed_by' => $f['user']->user_id, 'created_at' => now(), 'updated_at' => now(), 'version' => 2], $overrides));

        return $id;
    }

    private function member(array $f): string
    {
        $id = $this->seedItemInStock($f['sku']->sku_id);
        $this->seedScanCode($id, 'P18-ITEM-EXACT');
        DB::table('item_instances')->where('item_id', $id)->update(['container_id' => $f['box']->container_id]);
        DB::table('container_items')->insert(['container_item_id' => (string) Str::uuid(), 'container_id' => $f['box']->container_id, 'sku_id' => null, 'item_id' => $id, 'quantity' => null, 'status' => 'ACTIVE', 'is_active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function url(array $f, ?string $item = null): string
    {
        return '/api/v1/'.($item === null ? 'containers/'.$f['box']->container_id : 'item-instances/'.$item).'?warehouse_id='.$f['warehouse']->id;
    }

    private function headers(array $f): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$f['token'], self::HEADER => self::CONTRACT, ExplicitReadFields::HEADER => ExplicitReadFields::CONTRACT];
    }

    private function assertMissing($response, string $path, string $status): void
    {
        $missing = collect($response->json('meta.read_fields.missing_fields'))->keyBy('path');
        $this->assertSame($status, $missing[$path]['status'] ?? null);
    }

    private function assertNoNull(mixed $value): void
    {
        $this->assertNotNull($value);
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->assertNoNull($child);
            }
        }
    }

    private function domain(): array
    {
        $rows = [];
        foreach (['putaway_events', 'containers', 'container_items', 'item_instances', 'stock_documents', 'stock_movements', 'inventory_balances'] as $table) {
            $rows[$table] = DB::table($table)->get()->toArray();
        }

        return json_decode(json_encode($rows), true);
    }
}
