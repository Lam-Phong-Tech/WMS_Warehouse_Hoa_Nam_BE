<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\AlignedWarrantyReportController;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class WarrantyWarehouseScopeTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const BASE = '/api/v1/warranty-cases';

    private const MINI = '/api/v1/mini-app/warranty-cases';

    private const PERMISSIONS = ['warranty.view', 'warranty.manage', 'warranty.temp_only', 'report.view', 'report.warranty.view', 'report.export', 'inbound.view', 'outbound.view', 'warehouse.view'];

    public function test_creation_requires_explicit_confirmation_and_never_selects_a_singleton(): void
    {
        $warehouse = $this->ensureActiveWarehouse('OWNER-A');
        [$actor, $token] = $this->actor();
        $this->postJson(self::BASE, $this->body(), $this->headers($token))
            ->assertStatus(409)->assertJsonPath('error.code', 'WAREHOUSE_CONTEXT_REQUIRED');
        // P09 requires a persistent login before it can inspect warehouse context.
        $this->postJson(self::MINI, $this->body(), $this->headers($token))
            ->assertStatus(409)->assertJsonPath('error.code', 'AUTH_SESSION_REQUIRED');
        $sessionToken = $this->sessionToken($actor);
        foreach ([$this->body(), $this->body() + ['warehouse_id' => (string) $warehouse->id]] as $body) {
            $this->postJson(self::MINI, $body, $this->headers($sessionToken))
                ->assertStatus(409)->assertJsonPath('error.code', 'WAREHOUSE_CONTEXT_REQUIRED');
        }
        $this->assertDatabaseCount(WarrantyWarehouseScope::TABLE, 0);
        $this->assertSame(0, DB::table('warranty_case')->count());
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_explicit_creation_captures_real_metadata_without_a_shift(): void
    {
        $warehouse = $this->ensureActiveWarehouse('OWNER-A', 'Owner warehouse A');
        [$actor] = $this->actor((string) $warehouse->id);
        $token = $this->sessionToken($actor, $warehouse);
        $requestId = (string) Str::uuid();
        // P09 commands return a PII-free durable receipt, not the case projection.
        $response = $this->postJson(self::MINI, $this->body() + ['warehouse_id' => (string) $warehouse->id],
            $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid(), 'X-Request-ID' => $requestId])
            ->assertCreated()->assertJsonPath('meta.contract', 'mini-app-p09-v1')
            ->assertJsonPath('meta.warehouse_id', (string) $warehouse->id)
            ->assertJsonPath('data.receipt.request_id', $requestId)->assertJsonPath('data.receipt.state', 'SUCCEEDED')
            ->assertJsonPath('data.receipt.stock_effect', 'NONE')->assertJsonPath('data.receipt.movement_created', false);
        $id = $response->json('data.warranty_case_id');
        $this->assertTrue(Str::isUuid((string) $id));
        $response->assertJsonPath('data.receipt.warranty_case_id', $id);
        $this->assertStringNotContainsString('0900000000', $response->getContent());
        $this->getJson(self::MINI.'/'.$id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.warranty_case_id', $id)->assertJsonPath('data.warehouse_id', (string) $warehouse->id)
            ->assertJsonPath('data.warehouse_code', 'OWNER-A')->assertJsonPath('data.warehouse_name', 'Owner warehouse A')
            ->assertJsonPath('data.warehouse_ownership', 'EXPLICIT_REQUEST')->assertJsonPath('data.identification_status', 'TEMP_ONLY')
            ->assertJsonPath('data.item_id', null)->assertJsonPath('data.product_id', null);
        $this->assertDatabaseHas(WarrantyWarehouseScope::TABLE, [
            'warranty_case_id' => $id, 'warehouse_id' => (string) $warehouse->id,
            'source' => 'EXPLICIT_REQUEST', 'actor_id' => (string) $actor->user_id,
        ]);
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_confirmed_session_context_is_captured_atomically_and_survives_refresh(): void
    {
        $warehouse = $this->ensureActiveWarehouse('OWNER-A');
        [$actor] = $this->actor((string) $warehouse->id);
        $this->resetAuthentication();
        $login = $this->browserLogin($actor->email, 'secret123')->assertOk();
        $token = $login->json('data.access_token');
        $cookie = $this->refreshTokenFromCookie($login);
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id], $this->headers($token))->assertOk();
        $session = $selected->json('data.user.auth_session_id');
        $token = $selected->json('data.access_token');
        $key = (string) Str::uuid();
        $body = $this->body();
        $created = $this->postJson(self::BASE, $body, $this->headers($token) + ['Idempotency-Key' => $key])
            ->assertCreated()->assertJsonPath('data.warehouse_ownership', 'CURRENT_CONTEXT');
        $id = $created->json('data.warranty_case_id');
        $this->assertDatabaseHas(WarrantyWarehouseScope::TABLE, ['warranty_case_id' => $id, 'auth_session_id' => $session]);
        $this->resetAuthentication();
        $refresh = $this->browserRefresh($cookie)->assertOk();
        $newToken = $refresh->json('data.access_token');
        $this->getJson(self::BASE.'/'.$id, $this->headers($newToken))->assertOk()
            ->assertJsonPath('data.warehouse_id', (string) $warehouse->id)->assertJsonPath('data.warehouse_ownership', 'CURRENT_CONTEXT');
        $this->postJson(self::BASE, $body, $this->headers($newToken) + ['Idempotency-Key' => $key])
            ->assertCreated()->assertJsonPath('data.warranty_case_id', $id)->assertHeader('Idempotent-Replay', 'true');
        $this->assertDatabaseCount(WarrantyWarehouseScope::TABLE, 1);
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_invalid_foreign_inactive_and_forged_ownership_are_rejected_without_creating_case(): void
    {
        $a = $this->ensureActiveWarehouse('OWNER-A');
        $b = Warehouse::create(['code' => 'OWNER-B', 'name' => 'Owner B', 'status' => 'INACTIVE']);
        [, $scoped] = $this->actor((string) $a->id);
        [, $global] = $this->actor();
        $this->postJson(self::BASE, $this->body() + ['warehouse_id' => 'not-uuid'], $this->headers($scoped))->assertStatus(422);
        $this->postJson(self::BASE, $this->body() + ['warehouse_id' => (string) $b->id], $this->headers($scoped))->assertForbidden();
        $this->postJson(self::BASE, $this->body() + ['warehouse_id' => (string) $b->id], $this->headers($global))->assertStatus(409)->assertJsonPath('error.code', 'WAREHOUSE_INACTIVE');
        $this->postJson(self::BASE, $this->body() + ['warehouse_id' => (string) Str::uuid()], $this->headers($global))->assertNotFound();
        $this->postJson(self::BASE, $this->body() + ['warehouse_id' => (string) $a->id, 'warehouse_ownership' => 'CURRENT_CONTEXT'], $this->headers($global))
            ->assertStatus(422)->assertJsonPath('error.code', 'WARRANTY_OWNER_READ_ONLY');
        $this->assertDatabaseCount(WarrantyWarehouseScope::TABLE, 0);
        $this->assertSame(0, DB::table('warranty_case')->count());
    }

    public function test_list_scope_total_and_default_sort_include_temp_only_without_item_join(): void
    {
        [$a, $b, $token, $idA, $idB] = $this->twoWarehouses();
        $idA2 = $this->createCase($token, $a);
        DB::table('warranty_case')->whereIn('warranty_case_id', [$idA, $idA2, $idB])->update(['created_at' => '2026-01-15 08:00:00', 'updated_at' => '2026-01-15 08:00:00']);
        $ordered = [$idA, $idA2];
        rsort($ordered, SORT_STRING);
        [$actor, $scoped] = $this->actor((string) $a->id);
        $miniToken = $this->sessionToken($actor, $a);
        foreach ([self::BASE, self::MINI] as $path) {
            $readToken = $path === self::MINI ? $miniToken : $scoped;
            // Preserve legacy coverage without sending its filters to P09.
            $filters = $path === self::BASE
                ? ['identification_status' => 'TEMP_ONLY', 'status' => 'RECEIVED,CHECKING,REPAIRING,COMPLETED']
                : ['status' => 'ALL'];
            foreach ($ordered as $page => $id) {
                $response = $this->getJson($path.'?'.http_build_query(['warehouse_id' => $a->id, 'per_page' => 1, 'page' => $page + 1] + $filters), $this->headers($readToken))
                    ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data')
                    ->assertJsonPath('data.0.warranty_case_id', $id)->assertJsonPath('data.0.warehouse_id', (string) $a->id)
                    ->assertJsonPath('data.0.pii_masked', true)->assertJsonPath('data.0.identification_status', 'TEMP_ONLY')
                    ->assertJsonPath('data.0.item_id', null)->assertJsonPath('data.0.product_id', null);
                if ($path === self::BASE) {
                    $response->assertJsonPath('meta.warehouse_scope.contract', 'warranty-owner-v1')
                        ->assertJsonPath('meta.warehouse_scope.ownership_complete', true);
                } else {
                    $response->assertJsonPath('meta.contract', 'mini-app-p09-v1')->assertJsonPath('meta.warehouse_id', (string) $a->id)
                        ->assertJsonPath('meta.scope_policy', 'PERSISTED_WARRANTY_OWNER_BEFORE_TOTAL');
                }
            }
            $this->getJson($path, $this->headers($readToken))->assertOk()->assertJsonPath('meta.total', 2);
            $foreign = $this->getJson($path.'?warehouse_id='.$b->id, $this->headers($readToken));
            if ($path === self::MINI) {
                $foreign->assertStatus(409)->assertJsonPath('error.code', 'WAREHOUSE_CONTEXT_MISMATCH');
            } else {
                $foreign->assertForbidden();
            }
            $this->assertStringNotContainsString($idB, $foreign->getContent());
            $this->getJson($path.'?warehouse_id=broken', $this->headers($readToken))->assertStatus(422);
        }
        $this->getJson(self::MINI.'?warehouse_id='.$a->id.'&identification_status=TEMP_ONLY', $this->headers($miniToken))
            ->assertStatus(422)->assertJsonPath('error.code', 'WARRANTY_UNKNOWN_FILTER');
        $this->getJson(self::MINI.'?warehouse_id='.$a->id.'&status=RECEIVED,CHECKING', $this->headers($miniToken))
            ->assertStatus(422);
    }

    public function test_scope_blocks_detail_update_events_and_temp_only_before_idempotency_replay(): void
    {
        [$a, $b, $token, $idA, $idB] = $this->twoWarehouses();
        [, $scoped] = $this->actor((string) $a->id);
        foreach ([self::BASE, self::MINI] as $path) {
            $this->getJson($path.'/'.$idB, $this->headers($scoped))->assertForbidden();
            $this->getJson($path.'/'.$idB.'/events', $this->headers($scoped))->assertForbidden();
            $this->getJson($path.'/'.$idB.'/attachments', $this->headers($scoped))->assertForbidden();
            $this->patchJson($path.'/'.$idB, ['description' => 'Must not be written'], $this->headers($scoped))->assertForbidden();
            $this->postJson($path.'/'.$idB.'/status', ['status' => 'CHECKING'], $this->headers($scoped))->assertForbidden();
        }
        $this->postJson(self::BASE.'/'.$idB.'/temp-only', $this->tempBody(), $this->headers($scoped) + ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseHas('warranty_case', ['warranty_case_id' => $idB, 'status' => 'RECEIVED']);
        $this->getJson(self::BASE.'/'.$idA, $this->headers($scoped))->assertOk();
    }

    public function test_temp_only_preserves_owner_is_idempotent_and_has_no_stock_effect(): void
    {
        $a = $this->ensureActiveWarehouse('OWNER-A');
        [$actor, $token] = $this->actor((string) $a->id);
        $id = $this->createCase($token, $a);
        $key = (string) Str::uuid();
        $body = $this->tempBody();
        $owners = DB::table(WarrantyWarehouseScope::TABLE)->where('warranty_case_id', $id)->first();
        DB::enableQueryLog();
        $this->postJson(self::BASE.'/'.$id.'/temp-only', $body, $this->headers($token) + ['Idempotency-Key' => $key])
            ->assertOk()->assertJsonPath('data.warehouse_id', (string) $a->id)
            ->assertJsonPath('data.warehouse_ownership', 'EXPLICIT_REQUEST')->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false)->assertJsonPath('data.status', 'RECEIVED');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/(?:insert\s+into|update|delete\s+from)\s+["`]?\b(?:stock_movements?|inventory_balances?|inventory_movements?)\b/i', $query['query']);
        }
        $version = DB::table('warranty_case')->where('warranty_case_id', $id)->value('version');
        $events = DB::table('warranty_event')->where('warranty_case_id', $id)->count();
        $this->postJson(self::BASE.'/'.$id.'/temp-only', $body, $this->headers($token) + ['Idempotency-Key' => $key])->assertOk();
        $this->assertEquals($owners, DB::table(WarrantyWarehouseScope::TABLE)->where('warranty_case_id', $id)->first());
        $this->assertSame($version, DB::table('warranty_case')->where('warranty_case_id', $id)->value('version'));
        $this->assertSame($events, DB::table('warranty_event')->where('warranty_case_id', $id)->count());
        $this->assertDatabaseHas('warranty_case', ['warranty_case_id' => $id, 'item_id' => null, 'product_id' => null]);
    }

    public function test_temp_only_keeps_required_fields_key_and_permission(): void
    {
        $a = $this->ensureActiveWarehouse('OWNER-A');
        [, $token] = $this->actor((string) $a->id);
        $id = $this->createCase($token, $a);
        $this->postJson(self::BASE.'/'.$id.'/temp-only', $this->tempBody(), $this->headers($token))->assertStatus(428);
        foreach (['temporary_item_code', 'missing_code_reason', 'manual_product_description', 'condition_status'] as $field) {
            $body = $this->tempBody();
            unset($body[$field]);
            $this->postJson(self::BASE.'/'.$id.'/temp-only', $body, $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
        }
        [, $readOnly] = $this->actor((string) $a->id, ['warranty.view']);
        $this->postJson(self::BASE.'/'.$id.'/temp-only', $this->tempBody(), $this->headers($readOnly) + ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->getJson(self::BASE.'/'.$id, $this->headers($readOnly))->assertOk();
    }

    public function test_case_ownership_cannot_be_changed_by_patch_or_temp_only(): void
    {
        [$a, $b, $token, $idA] = $this->twoWarehouses();
        $this->patchJson(self::BASE.'/'.$idA, ['warehouse_id' => (string) $b->id], $this->headers($token))->assertStatus(409)->assertJsonPath('error.code', 'WARRANTY_WAREHOUSE_IMMUTABLE');
        $this->postJson(self::BASE.'/'.$idA.'/temp-only', $this->tempBody() + ['warehouse_id' => (string) $b->id], $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);
        $this->assertDatabaseHas(WarrantyWarehouseScope::TABLE, ['warranty_case_id' => $idA, 'warehouse_id' => (string) $a->id]);
    }

    public function test_inactive_owner_is_readable_but_cannot_be_mutated(): void
    {
        [$a, $b, $token, $idA, $idB] = $this->twoWarehouses();
        $this->getJson(self::BASE.'/'.$idB, $this->headers($token))->assertOk()->assertJsonPath('data.warehouse_status', 'INACTIVE');
        $this->postJson(self::BASE.'/'.$idB.'/temp-only', $this->tempBody(), $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('error.code', 'WAREHOUSE_INACTIVE');
    }

    public function test_legacy_unassigned_is_labelled_quarantined_and_not_silently_claimed(): void
    {
        $a = $this->ensureActiveWarehouse('OWNER-A');
        [, $global] = $this->actor();
        $id = $this->createCase($global, $a);
        DB::table(WarrantyWarehouseScope::TABLE)->where('warranty_case_id', $id)->delete();
        [, $scoped] = $this->actor((string) $a->id);
        $this->getJson(self::BASE, $this->headers($global))->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.warehouse_ownership', 'UNASSIGNED')->assertJsonPath('data.0.warehouse_id', null)
            ->assertJsonPath('meta.warehouse_scope.ownership_complete', false);
        $this->getJson(self::BASE.'?warehouse_id='.$a->id, $this->headers($global))->assertOk()->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.warehouse_scope.unassigned_policy', 'EXCLUDED')->assertJsonPath('meta.warehouse_scope.ownership_complete', false);
        $this->getJson(self::BASE, $this->headers($scoped))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::BASE.'/'.$id, $this->headers($scoped))->assertForbidden();
        $this->postJson(self::BASE.'/'.$id.'/temp-only', $this->tempBody(), $this->headers($global) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('error.code', 'WARRANTY_WAREHOUSE_UNASSIGNED');
        $this->assertDatabaseCount(WarrantyWarehouseScope::TABLE, 0);
    }

    public function test_backfill_is_explicit_dry_run_atomic_and_audited(): void
    {
        $a = $this->ensureActiveWarehouse('OWNER-A');
        [$actor, $token] = $this->actor((string) $a->id);
        $id = $this->createCase($token, $a);
        DB::table(WarrantyWarehouseScope::TABLE)->where('warranty_case_id', $id)->delete();
        $path = tempnam(sys_get_temp_dir(), 'warranty-owner-test-');
        try {
            file_put_contents($path, json_encode([['warranty_case_id' => $id, 'warehouse_id' => (string) $a->id]], JSON_THROW_ON_ERROR));
            $args = ['mapping' => $path, '--actor' => (string) $actor->user_id, '--reason' => 'Verified isolated receiving evidence'];
            $this->artisan('warranty:assign-warehouse', $args)->assertSuccessful();
            $this->assertDatabaseCount(WarrantyWarehouseScope::TABLE, 0);
            $this->artisan('warranty:assign-warehouse', $args + ['--apply' => true])->assertSuccessful();
            $this->assertDatabaseHas(WarrantyWarehouseScope::TABLE, ['warranty_case_id' => $id, 'source' => 'VERIFIED_BACKFILL']);
            $this->assertDatabaseHas('audit_log', ['action' => 'WARRANTY_WAREHOUSE_ASSIGNED']);
            $this->getJson(self::BASE.'?warehouse_id='.$a->id, $this->headers($token))->assertOk()->assertJsonPath('meta.total', 1)
                ->assertJsonPath('meta.warehouse_scope.ownership_complete', true);
        } finally {
            unlink($path);
        }
    }

    public function test_mini_app_summary_matches_filtered_list_and_keeps_unread_stock_counts_separate(): void
    {
        [$a, $b, $global, $idA, $idB] = $this->twoWarehouses();
        $second = $this->createCase($global, $a);
        DB::table('warranty_case')->where('warranty_case_id', $second)->update(['status' => 'COMPLETED', 'completed_at' => now()]);
        [$actor, $scoped] = $this->actor((string) $a->id);
        $summary = $this->getJson('/api/v1/mini-app/dashboard/summary?warehouse_id='.$a->id, $this->headers($scoped))->assertOk()
            ->assertJsonPath('data.warranty.open_count', 2)->assertJsonPath('data.warranty.open_statuses', WarrantyWarehouseScope::OPEN_STATUSES)
            ->assertJsonPath('data.pending_approval_count', 0)->assertJsonPath('data.warranty.warehouse_scope.warehouse_id', (string) $a->id);
        $this->getJson(self::BASE.'?'.http_build_query(['warehouse_id' => $a->id, 'status' => implode(',', WarrantyWarehouseScope::OPEN_STATUSES), 'per_page' => 1]), $this->headers($scoped))
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $miniToken = $this->sessionToken($actor, $a);
        $this->getJson(self::MINI.'?'.http_build_query(['warehouse_id' => $a->id, 'status' => 'ALL', 'per_page' => 1]), $this->headers($miniToken))
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        // P09 supports one canonical status, not the legacy comma-separated set.
        // Compare server totals for every open status, never the length of one page.
        $openTotal = 0;
        foreach (WarrantyWarehouseScope::OPEN_STATUSES as $status) {
            $expected = in_array($status, ['RECEIVED', 'COMPLETED'], true) ? 1 : 0;
            $page = $this->getJson(self::MINI.'?'.http_build_query(['warehouse_id' => $a->id, 'status' => $status, 'per_page' => 1]), $this->headers($miniToken))
                ->assertOk()->assertJsonPath('meta.total', $expected)->assertJsonCount($expected, 'data');
            $openTotal += $page->json('meta.total');
        }
        $this->assertSame(2, $openTotal);
        $this->assertSame($summary->json('data.warranty.open_count'), $openTotal);
        // Each limited actor uses its own real session after the browser flow.
        [$warrantyActor] = $this->actor((string) $a->id, ['warranty.view']);
        $onlyWarranty = $this->sessionToken($warrantyActor, $a);
        $this->getJson('/api/v1/mini-app/dashboard/summary?warehouse_id='.$a->id, $this->headers($onlyWarranty))->assertOk()
            ->assertJsonPath('data.warranty.open_count', 2)->assertJsonPath('data.inbound', null)->assertJsonPath('data.outbound', null);
        [$stockActor] = $this->actor((string) $a->id, ['inbound.view']);
        $onlyStock = $this->sessionToken($stockActor, $a);
        $this->getJson('/api/v1/mini-app/dashboard/summary?warehouse_id='.$a->id, $this->headers($onlyStock))->assertOk()->assertJsonPath('data.warranty', null);
    }

    public function test_reports_and_dashboard_do_not_return_other_warehouse_cases_or_totals(): void
    {
        [$a, $b, $global, $idA, $idB] = $this->twoWarehouses();
        [, $scoped] = $this->actor((string) $a->id);
        foreach (['/api/v1/reports/warranty-defects', '/api/v1/reports/dashboard-quick', '/api/v1/reports/dashboard-overview'] as $path) {
            $response = $this->getJson($path.'?warehouse_id='.$a->id, $this->headers($scoped))->assertOk();
            $this->assertStringNotContainsString($idB, $response->getContent());
            if ($path === '/api/v1/reports/warranty-defects') {
                $response->assertJsonPath('data.summary.total', 1)->assertJsonPath('data.summary.processing', 1)
                    ->assertJsonPath('data.summary.temp_only', 1);
            } elseif ($path === '/api/v1/reports/dashboard-quick') {
                $response->assertJsonPath('data.warranty_cases_by_status.RECEIVED', 1);
            } else {
                $response->assertJsonPath('data.warranty.by_status.RECEIVED', 1)
                    ->assertJsonPath('data.warranty_received.today', 1);
            }
            $this->getJson($path.'?warehouse_id='.$b->id, $this->headers($scoped))->assertForbidden();
        }
    }

    public function test_export_payload_uses_request_warehouse_scope_including_temp_only(): void
    {
        [$a, $b, $token, $idA, $idB] = $this->twoWarehouses();
        [$user, $scoped] = $this->actor((string) $a->id);
        $this->getJson(self::BASE, $this->headers($scoped))->assertOk();
        $request = Request::create('/', 'GET', ['warehouse_id' => (string) $a->id]);
        $request->setUserResolver(fn () => $user);
        $payload = app(AlignedWarrantyReportController::class)->exportPayload($request, 'xlsx');
        $this->assertSame(1, $payload['rowCount']);
        $this->assertNotEmpty($payload['bytes']);
        $this->getJson('/api/v1/reports/warranty-defects/export-excel?warehouse_id='.$b->id, $this->headers($scoped))->assertForbidden();
    }

    public function test_unauthenticated_and_empty_role_scope_cannot_read_warranty(): void
    {
        $a = $this->ensureActiveWarehouse('OWNER-A');
        foreach ([self::BASE, self::MINI] as $path) {
            $this->resetAuthentication();
            $this->getJson($path)->assertUnauthorized();
        }
        [, $noPermission] = $this->actor((string) $a->id, ['inbound.view']);
        $this->getJson(self::BASE, $this->headers($noPermission))->assertForbidden();
    }

    public function test_secondary_report_routes_apply_owner_scope_before_cache(): void
    {
        [$a, $b, $global, $idA, $idB] = $this->twoWarehouses();
        [, $scoped] = $this->actor((string) $a->id);
        foreach (['/api/v1/reports/warranty-defect-sku-breakdown', '/api/v1/reports/warranty-component-usage'] as $path) {
            DB::enableQueryLog();
            $this->getJson($path.'?warehouse_id='.$a->id, $this->headers($scoped))->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $reportQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'warranty_case as') || str_contains($q['query'], '"warranty_case" as'));
            $this->assertNotEmpty($reportQueries);
            foreach ($reportQueries as $query) {
                $this->assertStringContainsString('warranty_case_warehouse_owners', $query['query']);
                $this->assertContains((string) $a->id, $query['bindings']);
            }
            $this->getJson($path.'?warehouse_id='.$b->id, $this->headers($scoped))->assertForbidden();
        }
    }

    public function test_same_create_key_cannot_replay_a_case_under_another_warehouse(): void
    {
        $a = $this->ensureActiveWarehouse('OWNER-A');
        $b = Warehouse::create(['code' => 'OWNER-B', 'name' => 'Owner B', 'status' => 'INACTIVE']);
        [, $token] = $this->actor();
        $key = (string) Str::uuid();
        $body = $this->body();
        $first = $this->postJson(self::BASE, $body + ['warehouse_id' => (string) $a->id], $this->headers($token) + ['Idempotency-Key' => $key])->assertCreated();
        $a->update(['status' => 'INACTIVE']);
        $b->update(['status' => 'ACTIVE']);
        $this->postJson(self::BASE, $body + ['warehouse_id' => (string) $b->id], $this->headers($token) + ['Idempotency-Key' => $key])->assertStatus(409);
        $this->assertDatabaseCount(WarrantyWarehouseScope::TABLE, 1);
        $this->assertDatabaseHas(WarrantyWarehouseScope::TABLE, ['warranty_case_id' => $first->json('data.warranty_case_id'), 'warehouse_id' => (string) $a->id]);
    }

    public function test_temp_only_cached_result_cannot_bypass_revoked_warehouse_scope(): void
    {
        $a = $this->ensureActiveWarehouse('OWNER-A');
        $b = Warehouse::create(['code' => 'OWNER-B', 'name' => 'Owner B', 'status' => 'INACTIVE']);
        [$actor, $token] = $this->actor((string) $a->id);
        $id = $this->createCase($token, $a);
        $key = (string) Str::uuid();
        $body = $this->tempBody();
        $this->postJson(self::BASE.'/'.$id.'/temp-only', $body, $this->headers($token) + ['Idempotency-Key' => $key])->assertOk();
        $roleId = $actor->roles()->first()->role_id;
        // Match UserController::assignRoles: membership and user.version change
        // together. Direct pivot SQL without this bump is not the IAM write contract.
        DB::transaction(function () use ($actor, $roleId, $b): void {
            $actor->roles()->updateExistingPivot($roleId, ['scope_ref' => (string) $b->id]);
            $actor->forceFill(['version' => $actor->version + 1])->save();
        });
        $this->postJson(self::BASE.'/'.$id.'/temp-only', $body, $this->headers($token) + ['Idempotency-Key' => $key])
            ->assertForbidden()->assertJsonPath('error.code', 'WARRANTY_WAREHOUSE_ACCESS_DENIED');
    }

    private function twoWarehouses(): array
    {
        $a = $this->ensureActiveWarehouse('OWNER-A');
        $b = Warehouse::create(['code' => 'OWNER-B', 'name' => 'Owner B', 'status' => 'INACTIVE']);
        [, $token] = $this->actor();
        $idA = $this->createCase($token, $a);
        $a->update(['status' => 'INACTIVE']);
        $b->update(['status' => 'ACTIVE']);
        $idB = $this->createCase($token, $b);
        $b->update(['status' => 'INACTIVE']);
        $a->update(['status' => 'ACTIVE']);

        return [$a, $b, $token, $idA, $idB];
    }

    private function createCase(string $token, Warehouse $warehouse): string
    {
        return (string) $this->postJson(self::BASE, $this->body() + ['warehouse_id' => (string) $warehouse->id], $this->headers($token))
            ->assertCreated()->json('data.warranty_case_id');
    }

    private function body(): array
    {
        return ['customer_name' => 'Isolated ownership test', 'customer_phone' => '0900000000', 'description' => 'Scope test only',
            'missing_code_reason' => 'No physical identifier in isolated fixture', 'manual_product_description' => 'Unidentified test product'];
    }

    private function tempBody(): array
    {
        return ['temporary_item_code' => 'TEMP-'.Str::uuid(), 'missing_code_reason' => 'Test unreadable code',
            'manual_product_description' => 'Unidentified test product', 'condition_status' => 'WAITING_CHECK'];
    }

    private function actor(?string $scope = null, array $permissions = self::PERMISSIONS): array
    {
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'owner_'.$suffix, 'display_name' => 'Owner test', 'email' => 'owner_'.$suffix.'@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'OWNER_'.$suffix, 'role_name' => 'Owner test', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $scope]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->resetAuthentication();

        return [$user, JWTAuth::fromUser($user)];
    }

    /** Exercise P09 through the existing login/context flow, without creating a shift. */
    private function sessionToken(User $actor, ?Warehouse $warehouse = null): string
    {
        $this->resetAuthentication();
        $login = $this->browserLogin($actor->email, 'secret123')->assertOk();
        $token = $login->json('data.access_token');
        if ($warehouse !== null) {
            $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id], $this->headers($token))
                ->assertOk()->json('data.access_token');
        }

        return $token;
    }

    private function headers(string $token): array
    {
        $this->resetAuthentication();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function resetAuthentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
