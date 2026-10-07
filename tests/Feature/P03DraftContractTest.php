<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Middleware\MiniAppDraftHandoff;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Repositories\Contracts\WarehouseRepositoryInterface;
use Modules\Warehouse\Services\MiniAppDraftService;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class P03DraftContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const BASE = '/api/v1/mini-app/drafts';

    private const CONTEXT = '/api/v1/mini-app/p03/context';

    private const PERMISSIONS = ['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.cancel', 'outbound.view', 'outbound.request.manual_create', 'outbound.scan', 'outbound.cancel', 'warranty.view', 'warranty.manage', 'warranty.temp_only', 'warranty.component_issue', 'warehouse.view'];

    public function test_every_p03_endpoint_requires_authentication(): void
    {
        $id = (string) Str::uuid();
        $this->getJson(self::CONTEXT)->assertUnauthorized();
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->getJson(self::BASE.'/'.$id)->assertUnauthorized();
        $this->putJson(self::BASE.'/'.$id, [])->assertUnauthorized();
        $this->postJson(self::BASE.'/'.$id.'/cancel', [])->assertUnauthorized();
        $this->getJson(self::BASE.'/'.$id.'/requests/'.Str::uuid())->assertUnauthorized();
    }

    public function test_context_requires_explicit_confirmation_and_has_no_invented_contact(): void
    {
        [$actor, $token, , , $warehouse] = $this->actor(confirm: false);
        $this->getJson(self::CONTEXT, $this->headers($token))->assertOk()
            ->assertJsonPath('data.warehouse_context.status', 'REQUIRED')
            ->assertJsonPath('data.active_warehouse_id', null)
            ->assertJsonPath('data.permissions.INBOUND.save', false)
            ->assertJsonPath('data.admin_contact.status', 'NOT_CONFIGURED')->assertJsonCount(0, 'data.admin_contact.channels');
        $this->putJson(self::BASE.'/'.Str::uuid(), $this->body($warehouse), $this->headers($token, (string) Str::uuid(), 0))
            ->assertConflict()->assertJsonPath('error.code', 'WAREHOUSE_CONTEXT_REQUIRED');
        $this->assertDatabaseCount(MiniAppDraftService::TABLE, 0);
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_save_persists_real_personal_snapshot_and_never_creates_stock_data(): void
    {
        [, $token, , $session, $warehouse] = $this->actor();
        $before = $this->stockCounts();
        [$id, $scan, $key, $body] = $this->create($token, $warehouse);
        $this->getJson($this->detailUrl($id, $warehouse), $this->headers($token))->assertOk()
            ->assertJsonPath('data.document_id', $id)->assertJsonPath('data.scan_session_id', $scan)
            ->assertJsonPath('data.auth_session_id', $session)->assertJsonPath('data.warehouse_id', (string) $warehouse->id)
            ->assertJsonPath('data.document_kind', 'STAGING_DRAFT')->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.summary.code_count', 2)->assertJsonPath('data.summary.quantity_total', 3)
            ->assertJsonPath('data.codes_validated', false)->assertJsonPath('data.business_document_id', null)->assertHeader('ETag', '"1"');
        $this->assertSame($before, $this->stockCounts());
        $this->assertDatabaseCount(MiniAppDraftService::TABLE, 1);
        $this->assertDatabaseCount(MiniAppDraftService::RECEIPTS, 1);
    }

    public function test_same_key_and_body_replay_once_but_changed_body_or_version_conflicts(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$id, , $key, $body] = $this->create($token, $warehouse);
        $auditCount = DB::table('audit_log')->where('action', 'P03_DRAFT_SAVE')->count();
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, $key, 0))->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.document.version', 1);
        $changed = $body;
        $changed['title'] = 'Changed snapshot';
        $this->putJson(self::BASE.'/'.$id, $changed, $this->headers($token, $key, 0))
            ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, $key, 1))
            ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount(MiniAppDraftService::RECEIPTS, 1);
        $this->assertSame($auditCount, DB::table('audit_log')->where('action', 'P03_DRAFT_SAVE')->count());
    }

    public function test_optimistic_update_does_not_overwrite_another_version(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$id, , $firstKey, $body] = $this->create($token, $warehouse);
        $body['title'] = 'Updated title';
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, (string) Str::uuid(), 1))->assertOk()
            ->assertJsonPath('data.document.version', 2)->assertHeader('ETag', '"2"');
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, (string) Str::uuid(), 1))
            ->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');
        $this->getJson($this->detailUrl($id, $warehouse), $this->headers($token, version: 1))->assertStatus(412);
        $this->getJson($this->detailUrl($id, $warehouse), $this->headers($token))->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount(MiniAppDraftService::RECEIPTS, 2);
    }

    public function test_scan_identity_cannot_create_a_replacement_document(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$id, , , $body] = $this->create($token, $warehouse);
        $this->putJson(self::BASE.'/'.Str::uuid(), $body, $this->headers($token, (string) Str::uuid(), 0))
            ->assertConflict()->assertJsonPath('error.code', 'SCAN_SESSION_CONFLICT');
        $this->assertDatabaseCount(MiniAppDraftService::TABLE, 1);
    }

    public function test_actor_isolation_includes_detail_update_cancel_list_and_receipts(): void
    {
        [, $first, , , $warehouse] = $this->actor();
        [$id, $scan, $key, $body] = $this->create($first, $warehouse);
        [, $other] = $this->actor($warehouse);
        $this->getJson(self::BASE.'?warehouse_id='.$warehouse->id, $this->headers($other))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->detailUrl($id, $warehouse), $this->headers($other))->assertNotFound();
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($other, (string) Str::uuid(), 1))->assertNotFound();
        $this->postJson(self::BASE.'/'.$id.'/cancel', $this->cancelBody($warehouse, $scan, $key), $this->headers($other, (string) Str::uuid(), 1))->assertNotFound();
        $this->getJson($this->receiptUrl($id, $key, $warehouse, $scan), $this->headers($other))->assertOk()
            ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.receipt', null)->assertJsonPath('data.document', null);
    }

    public function test_new_login_cannot_inherit_old_session_drafts(): void
    {
        [$user, $old, , $session, $warehouse] = $this->actor();
        [$id] = $this->create($old, $warehouse);
        [$new, , $newSession] = $this->login($user, $warehouse);
        $this->assertNotSame($session, $newSession);
        $this->getJson($this->detailUrl($id, $warehouse), $this->headers($new))->assertNotFound();
        $this->getJson(self::BASE.'?warehouse_id='.$warehouse->id, $this->headers($new))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->detailUrl($id, $warehouse), $this->headers($old))->assertOk();
    }

    public function test_refresh_preserves_draft_identity_and_idempotent_receipt(): void
    {
        [, $token, $cookie, $session, $warehouse] = $this->actor();
        [$id, , $key, $body] = $this->create($token, $warehouse);
        $this->resetAuthentication();
        $refresh = $this->browserRefresh($cookie)->assertOk();
        $next = $refresh->json('data.access_token');
        $this->getJson(self::CONTEXT, $this->headers($next))->assertOk()->assertJsonPath('data.auth_session_id', $session);
        $this->getJson($this->detailUrl($id, $warehouse), $this->headers($next))->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($next, $key, 0))->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertDatabaseCount(MiniAppDraftService::TABLE, 1);
    }

    public function test_out_of_scope_warehouse_is_denied_before_any_write(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        $other = Warehouse::create(['code' => 'P03-OTHER', 'name' => 'CI other warehouse', 'status' => 'INACTIVE']);
        $this->getJson(self::BASE.'?warehouse_id='.$other->id, $this->headers($token))->assertForbidden();
        $this->putJson(self::BASE.'/'.Str::uuid(), $this->body($other), $this->headers($token, (string) Str::uuid(), 0))->assertForbidden();
        $this->assertDatabaseCount(MiniAppDraftService::TABLE, 0);
    }

    public function test_context_clear_does_not_reassign_or_expose_drafts(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$id] = $this->create($token, $warehouse);
        $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'SYSTEM'], $this->headers($token))->assertOk();
        $this->getJson($this->detailUrl($id, $warehouse), $this->headers($token))->assertConflict()->assertJsonPath('error.code', 'WAREHOUSE_CONTEXT_REQUIRED');
        $this->assertDatabaseHas(MiniAppDraftService::TABLE, ['document_id' => $id, 'warehouse_id' => $warehouse->id, 'status' => 'DRAFT']);
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_role_permission_withdrawal_blocks_cached_replay(): void
    {
        [$user, $token, , , $warehouse] = $this->actor();
        [$id, , $key, $body] = $this->create($token, $warehouse);
        $role = $user->roles()->first();
        DB::table('role_permission')->where('role_id', $role->role_id)->whereIn('permission_id', Permission::whereIn('permission_key', ['inbound.create', 'inbound.scan'])->pluck('permission_id'))->delete();
        $user->forceFill(['version' => $user->version + 1])->save();
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, $key, 0))->assertForbidden()->assertJsonPath('error.code', 'P03_OPERATION_DENIED');
        $this->getJson(self::CONTEXT, $this->headers($token))->assertOk()->assertJsonPath('data.permissions.INBOUND.save', false);
        $this->assertDatabaseCount(MiniAppDraftService::RECEIPTS, 1);
    }

    public function test_warehouse_scope_withdrawal_blocks_receipt_and_draft_reads(): void
    {
        [$user, $token, , , $warehouse] = $this->actor();
        [$id, $scan, $key] = $this->create($token, $warehouse);
        $user->roles()->detach();
        $user->forceFill(['version' => $user->version + 1])->save();
        $this->getJson($this->detailUrl($id, $warehouse), $this->headers($token))->assertForbidden();
        $this->getJson($this->receiptUrl($id, $key, $warehouse, $scan), $this->headers($token))->assertForbidden();
    }

    public function test_logout_revokes_access_to_existing_draft_even_with_old_access_token(): void
    {
        [, $token, $cookie, , $warehouse] = $this->actor();
        [$id] = $this->create($token, $warehouse);
        $this->withCredentials()->withUnencryptedCookie((string) config('refresh_token.cookie.name'), $cookie)
            ->postJson('/api/v1/auth/logout', [], $this->headers($token))->assertOk();
        $this->getJson($this->detailUrl($id, $warehouse), $this->headers($token))->assertUnauthorized();
    }

    public function test_list_total_is_sql_scoped_and_order_is_stable(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$a] = $this->create($token, $warehouse);
        [$b] = $this->create($token, $warehouse);
        [, $other] = $this->actor($warehouse);
        $this->create($other, $warehouse);
        DB::table(MiniAppDraftService::TABLE)->update(['updated_at' => '2026-10-06T00:00:00.000000Z']);
        $ids = [$a, $b];
        rsort($ids);
        foreach ([1, 2] as $page) {
            $this->getJson(self::BASE.'?warehouse_id='.$warehouse->id.'&operation=INBOUND&per_page=1&page='.$page, $this->headers($token))
                ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data')->assertJsonPath('data.0.document_id', $ids[$page - 1]);
        }
        $this->getJson(self::BASE.'?warehouse_id='.$warehouse->id.'&operation=WARRANTY', $this->headers($token))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_cancel_requires_a_known_latest_receipt_and_is_soft_and_idempotent(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$id, $scan, $saved, $body] = $this->create($token, $warehouse);
        $key = (string) Str::uuid();
        $cancel = $this->cancelBody($warehouse, $scan, $saved);
        $this->postJson(self::BASE.'/'.$id.'/cancel', $this->cancelBody($warehouse, $scan, (string) Str::uuid()), $this->headers($token, (string) Str::uuid(), 1))
            ->assertConflict()->assertJsonPath('error.code', 'DRAFT_OUTCOME_UNKNOWN');
        $this->postJson(self::BASE.'/'.$id.'/cancel', $cancel, $this->headers($token, $key, 1))->assertOk()
            ->assertJsonPath('data.document.status', 'CANCELLED')->assertJsonPath('data.document.version', 2);
        $this->postJson(self::BASE.'/'.$id.'/cancel', $cancel, $this->headers($token, $key, 1))->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, (string) Str::uuid(), 2))->assertConflict()->assertJsonPath('error.code', 'DRAFT_STATE_CONFLICT');
        $this->assertDatabaseCount(MiniAppDraftService::TABLE, 1);
        $this->assertDatabaseCount(MiniAppDraftService::RECEIPTS, 2);
        $this->getJson(self::BASE.'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::BASE.'?warehouse_id='.$warehouse->id.'&status=CANCELLED', $this->headers($token))->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_cancel_cannot_use_an_old_receipt_even_with_latest_version(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$id, $scan, $key, $body] = $this->create($token, $warehouse);
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, (string) Str::uuid(), 1))->assertOk();
        $this->postJson(self::BASE.'/'.$id.'/cancel', $this->cancelBody($warehouse, $scan, $key), $this->headers($token, (string) Str::uuid(), 2))
            ->assertConflict()->assertJsonPath('error.code', 'DRAFT_OUTCOME_UNKNOWN');
        $this->getJson($this->receiptUrl($id, $key, $warehouse, $scan), $this->headers($token))->assertOk()->assertJsonPath('data.cancel_allowed', false);
    }

    public function test_reconcile_recovers_lost_response_without_creating_anything(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$id, $scan, $key] = $this->create($token, $warehouse); // Simulate client losing this successful HTTP response.
        $response = $this->getJson($this->receiptUrl($id, $key, $warehouse, $scan), $this->headers($token))->assertOk()
            ->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.receipt.request_id', $key)
            ->assertJsonPath('data.receipt.version', 1)->assertJsonPath('data.document.version', 1)
            ->assertJsonPath('data.response.data.document.document_id', $id)->assertJsonPath('data.new_document_allowed', false);
        $this->assertDatabaseCount(MiniAppDraftService::TABLE, 1);
        $this->assertDatabaseCount(MiniAppDraftService::RECEIPTS, 1);
        // Isolated synthetic account only; never emits an Authorization header or token.
        fwrite(STDOUT, "\nP03_RECONCILE_EVIDENCE=".json_encode($response->json('data'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    public function test_absent_receipt_is_unknown_not_failed_and_wrong_identity_is_conflict(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$id, $scan, $key] = $this->create($token, $warehouse);
        $this->getJson($this->receiptUrl($id, (string) Str::uuid(), $warehouse, $scan), $this->headers($token))->assertOk()
            ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.retry', 'SAME_REQUEST_ONLY')
            ->assertJsonPath('data.cancel_allowed', false)->assertJsonPath('data.new_document_allowed', false);
        $this->getJson($this->receiptUrl((string) Str::uuid(), $key, $warehouse, $scan), $this->headers($token))->assertConflict()->assertJsonPath('error.code', 'REQUEST_IDENTITY_MISMATCH');
        $this->getJson($this->receiptUrl($id, $key, $warehouse, (string) Str::uuid()), $this->headers($token))->assertConflict();
        $this->getJson($this->receiptUrl($id, $key, $warehouse, $scan, command: 'CANCEL'), $this->headers($token))->assertConflict();
    }

    public function test_headers_filters_forged_ownership_and_secrets_are_rejected(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        $id = (string) Str::uuid();
        $body = $this->body($warehouse);
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, version: 0))->assertStatus(428);
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, (string) Str::uuid()))->assertStatus(428);
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, 'not-a-uuid', 0))->assertStatus(422);
        foreach (['*', 'W/"0"', '0', '"-1"'] as $bad) {
            $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, (string) Str::uuid()) + ['If-Match' => $bad])->assertStatus(422);
        }
        foreach (['actor_id', 'auth_session_id', 'status', 'version', 'business_document_id'] as $key) {
            $this->putJson(self::BASE.'/'.$id, $body + [$key => 'forged'], $this->headers($token, (string) Str::uuid(), 0))->assertStatus(422)->assertJsonPath('error.code', 'UNEXPECTED_FIELD');
        }
        $this->getJson(self::BASE.'?warehouse_id='.$warehouse->id.'&operation=INVENTED', $this->headers($token))->assertStatus(422);
        $this->getJson(self::BASE.'?warehouse_id=not-a-uuid', $this->headers($token))->assertStatus(422);
        $this->getJson(self::BASE.'?warehouse_id='.$warehouse->id.'&actor_id='.Str::uuid(), $this->headers($token))->assertStatus(422);
        $body['form'] = ['access_token' => 'synthetic-secret-never-store'];
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, (string) Str::uuid(), 0))->assertStatus(422)->assertJsonPath('error.code', 'INVALID_DRAFT_FORM');
        $this->assertDatabaseCount(MiniAppDraftService::TABLE, 0);
    }

    public function test_operation_matrix_is_permission_based_not_role_name(): void
    {
        [, $token, , , $warehouse] = $this->actor(permissions: ['inbound.view']);
        $this->getJson(self::CONTEXT, $this->headers($token))->assertOk()->assertJsonPath('data.permissions.INBOUND.read', true)
            ->assertJsonPath('data.permissions.INBOUND.save', false)->assertJsonPath('data.permissions.WARRANTY.read', false)
            ->assertJsonPath('data.warehouse_profile.allowed', false);
        $this->putJson(self::BASE.'/'.Str::uuid(), $this->body($warehouse), $this->headers($token, (string) Str::uuid(), 0))->assertForbidden();
        $this->getJson('/api/v1/warehouses/'.$warehouse->id, $this->headers($token))->assertForbidden();
    }

    public function test_profile_contract_uses_existing_warehouse_endpoint(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        $this->getJson('/api/v1/warehouses/'.$warehouse->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.id', (string) $warehouse->id)->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonStructure(['data' => ['id', 'code', 'name', 'status', 'created_at', 'updated_at']]);
        // Match the production controller's repository update, including cache invalidation.
        app(WarehouseRepositoryInterface::class)->update((string) $warehouse->id, ['status' => 'INACTIVE']);
        $this->getJson('/api/v1/warehouses/'.$warehouse->id, $this->headers($token))->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->getJson(self::CONTEXT, $this->headers($token))->assertOk()->assertJsonPath('data.warehouse_context.status', 'UNAVAILABLE')->assertJsonPath('data.permissions.INBOUND.save', false);
    }

    public function test_record_handoff_on_real_warranty_route_is_atomic_and_cannot_be_cancelled_or_repeated(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$id, $scan] = $this->create($token, $warehouse, 'WARRANTY');
        $key = (string) Str::uuid();
        $body = $this->warrantyBody();
        $headers = $this->recordHeaders($token, $key, $id, $scan, 1);
        $response = $this->postJson('/api/v1/mini-app/warranty-cases', $body, $headers)->assertCreated()
            ->assertJsonPath('meta.p03_draft.document.status', 'RECORDED')->assertJsonPath('meta.p03_draft.document.version', 2);
        $businessId = $response->json('data.warranty_case_id');
        $this->assertNotSame($id, $businessId);
        $this->assertDatabaseHas(MiniAppDraftService::TABLE, ['document_id' => $id, 'business_document_id' => $businessId, 'status' => 'RECORDED']);
        $this->postJson('/api/v1/mini-app/warranty-cases', $body, $this->recordHeaders($token, $key, $id, $scan, 1))
            ->assertCreated()->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.warranty_case_id', $businessId);
        $changed = $body;
        $changed['description'] = 'Changed owner command';
        $this->postJson('/api/v1/mini-app/warranty-cases', $changed, $this->recordHeaders($token, $key, $id, $scan, 1))->assertConflict();
        $this->postJson(self::BASE.'/'.$id.'/cancel', $this->cancelBody($warehouse, $scan, $key, 'WARRANTY'), $this->headers($token, (string) Str::uuid(), 2))
            ->assertConflict()->assertJsonPath('error.code', 'DRAFT_STATE_CONFLICT');
        $this->getJson($this->receiptUrl($id, $key, $warehouse, $scan, 'WARRANTY', 'RECORD'), $this->headers($token))->assertOk()
            ->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.response.data.warranty_case_id', $businessId)
            ->assertJsonPath('data.document.status', 'RECORDED')->assertJsonPath('data.cancel_allowed', false);
        $this->assertSame(1, DB::table('warranty_case')->count());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_record_stale_version_or_wrong_scan_cannot_reach_owner(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$id, $scan] = $this->create($token, $warehouse, 'WARRANTY');
        $this->postJson('/api/v1/mini-app/warranty-cases', $this->warrantyBody(), $this->recordHeaders($token, (string) Str::uuid(), $id, $scan, 2))
            ->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');
        $this->postJson('/api/v1/mini-app/warranty-cases', $this->warrantyBody(), $this->recordHeaders($token, (string) Str::uuid(), $id, (string) Str::uuid(), 1))->assertNotFound();
        $this->assertSame(0, DB::table('warranty_case')->count());
    }

    public function test_owner_validation_failure_leaves_the_draft_and_inventory_unchanged(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$id, $scan] = $this->create($token, $warehouse, 'WARRANTY');
        $before = $this->stockCounts();
        $this->postJson('/api/v1/mini-app/warranty-cases', [], $this->recordHeaders($token, (string) Str::uuid(), $id, $scan, 1))->assertStatus(422);
        $this->assertDatabaseHas(MiniAppDraftService::TABLE, ['document_id' => $id, 'version' => 1, 'status' => 'DRAFT']);
        $this->assertDatabaseCount(MiniAppDraftService::RECEIPTS, 1);
        $this->assertSame($before, $this->stockCounts());
    }

    public function test_receipt_storage_failure_rolls_back_draft_creation(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'mini_app_draft_receipts')) {
                throw new \RuntimeException('Isolated receipt persistence fault');
            }
        });
        $this->putJson(self::BASE.'/'.Str::uuid(), $this->body($warehouse), $this->headers($token, (string) Str::uuid(), 0))->assertStatus(500);
        $this->assertDatabaseCount(MiniAppDraftService::TABLE, 0);
        $this->assertDatabaseCount(MiniAppDraftService::RECEIPTS, 0);
    }

    public function test_only_existing_record_routes_receive_the_opt_in_identity_bridge(): void
    {
        $seen = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array(MiniAppDraftHandoff::class, $route->gatherMiddleware(), true)) {
                $this->assertArrayHasKey($route->uri(), MiniAppDraftService::RECORD_ROUTES);
                $this->assertContains('POST', $route->methods());
                $seen[] = $route->uri();
            }
        }
        $this->assertEqualsCanonicalizing(array_keys(MiniAppDraftService::RECORD_ROUTES), $seen);
        [, $token, , , $warehouse] = $this->actor();
        [$id] = $this->create($token, $warehouse);
        $this->deleteJson(self::BASE.'/'.$id, [], $this->headers($token))->assertStatus(405);
    }

    public function test_receipt_failure_rolls_back_the_existing_owner_record_too(): void
    {
        [, $token, , , $warehouse] = $this->actor();
        [$id, $scan] = $this->create($token, $warehouse, 'WARRANTY');
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'mini_app_draft_receipts')) {
                throw new \RuntimeException('Isolated owner receipt persistence fault');
            }
        });
        $this->postJson('/api/v1/mini-app/warranty-cases', $this->warrantyBody(), $this->recordHeaders($token, (string) Str::uuid(), $id, $scan, 1))->assertStatus(500);
        $this->assertSame(0, DB::table('warranty_case')->count());
        $this->assertDatabaseCount('warranty_case_warehouse_owners', 0);
        $this->assertDatabaseCount(MiniAppDraftService::RECEIPTS, 1);
        $this->assertDatabaseHas(MiniAppDraftService::TABLE, ['document_id' => $id, 'status' => 'DRAFT', 'version' => 1]);
    }

    private function actor(?Warehouse $warehouse = null, array $permissions = self::PERMISSIONS, bool $confirm = true): array
    {
        $warehouse ??= $this->ensureActiveWarehouse('KHO-TONG-HN', 'Isolated P03 warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p03_'.$suffix, 'display_name' => 'P03 CI actor', 'email' => 'p03_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'ARBITRARY_P03_'.$suffix, 'role_name' => 'Not a permission inference', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => (string) $warehouse->id]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$token, $cookie, $session] = $this->login($user, $confirm ? $warehouse : null);

        return [$user, $token, $cookie, $session, $warehouse];
    }

    private function login(User $user, ?Warehouse $warehouse): array
    {
        $this->resetAuthentication();
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $token = $login->json('data.access_token');
        $cookie = $this->refreshTokenFromCookie($login);
        $session = $login->json('data.user.auth_session_id');
        if ($warehouse !== null) {
            $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id], $this->headers($token))->assertOk();
            $token = $selected->json('data.access_token');
            $session = $selected->json('data.user.auth_session_id');
        }

        return [$token, $cookie, $session];
    }

    private function body(Warehouse $warehouse, string $operation = 'INBOUND'): array
    {
        return ['warehouse_id' => (string) $warehouse->id, 'operation' => $operation, 'scan_session_id' => (string) Str::uuid(), 'title' => 'Isolated P03 snapshot',
            'codes' => [['raw_code' => 'CI-DRAFT-CODE-1', 'quantity' => 2], ['raw_code' => 'CI-DRAFT-CODE-2']], 'form' => ['note' => 'Unvalidated UI-only test snapshot']];
    }

    private function create(string $token, Warehouse $warehouse, string $operation = 'INBOUND'): array
    {
        $body = $this->body($warehouse, $operation);
        $id = (string) Str::uuid();
        $key = (string) Str::uuid();
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($token, $key, 0))->assertCreated()->assertJsonPath('data.receipt.outcome', 'SUCCEEDED');

        return [$id, $body['scan_session_id'], $key, $body];
    }

    private function cancelBody(Warehouse $warehouse, string $scan, string $key, string $operation = 'INBOUND'): array
    {
        return ['warehouse_id' => (string) $warehouse->id, 'operation' => $operation, 'scan_session_id' => $scan, 'confirmed_request_id' => $key, 'reason' => 'Cancel isolated unrecorded snapshot'];
    }

    private function detailUrl(string $id, Warehouse $warehouse, string $operation = 'INBOUND'): string
    {
        return self::BASE.'/'.$id.'?warehouse_id='.$warehouse->id.'&operation='.$operation;
    }

    private function receiptUrl(string $id, string $key, Warehouse $warehouse, string $scan, string $operation = 'INBOUND', string $command = 'SAVE'): string
    {
        return self::BASE.'/'.$id.'/requests/'.$key.'?'.http_build_query(['warehouse_id' => (string) $warehouse->id, 'operation' => $operation, 'scan_session_id' => $scan, 'command' => $command]);
    }

    private function warrantyBody(): array
    {
        return ['customer_name' => 'P03 isolated warranty', 'customer_phone' => '0900000000', 'description' => 'CI draft handoff', 'missing_code_reason' => 'CI unreadable code', 'manual_product_description' => 'CI unidentified product'];
    }

    private function stockCounts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(), ['stock_documents', 'stock_movements', 'inventory_balances', 'warranty_case', 'component_issue_documents']);
    }

    private function recordHeaders(string $token, string $key, string $id, string $scan, int $version): array
    {
        return $this->headers($token, $key) + ['X-Draft-ID' => $id, 'X-Draft-Scan-Session-ID' => $scan, 'X-Draft-Version' => (string) $version];
    }

    private function headers(string $token, ?string $key = null, ?int $version = null): array
    {
        $this->resetAuthentication();
        $headers = ['Authorization' => 'Bearer '.$token];
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }
        if ($version !== null) {
            $headers['If-Match'] = '"'.$version.'"';
        }

        return $headers;
    }

    private function resetAuthentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
