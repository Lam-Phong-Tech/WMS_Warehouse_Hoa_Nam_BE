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
use Modules\Warehouse\Http\Middleware\P05OutboundSource;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class P05OutboundContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/mini-app/outbound';

    public function test_context_and_reads_require_authentication(): void
    {
        $this->getJson(self::BASE.'/context')->assertUnauthorized();
        $this->getJson(self::BASE.'/sources')->assertUnauthorized();
        $this->getJson(self::BASE.'/sources/'.Str::uuid())->assertUnauthorized();
        $this->getJson(self::BASE.'/documents/'.Str::uuid())->assertUnauthorized();
    }

    public function test_context_preserves_address_text_and_actual_permissions(): void
    {
        [, $token] = $this->session();
        $this->getJson(self::BASE.'/context', $this->headers($token))->assertOk()
            ->assertJsonPath('meta.contract', 'mini-app-p05-v1')
            ->assertJsonPath('data.selector.source_kind', 'WMS_OUTBOUND_DOCUMENT')
            ->assertJsonPath('data.permissions.record', true)
            ->assertJsonPath('data.record.reconcile_mode', 'P03_LINKED')
            ->assertJsonPath('data.recipients.permission', 'agency.view')
            ->assertJsonPath('data.recipients.allowed', false)
            ->assertJsonPath('data.address_contract.dataset_version', null)
            ->assertJsonPath('data.address_contract.dataset_decision', 'PENDING_DEV_BA');
        $this->assertDatabaseCount('mini_app_work_shifts', 0);
    }

    public function test_selector_filters_before_total_and_orders_by_creation_and_id(): void
    {
        [, $token, $warehouse] = $this->session();
        $a = $this->sourceFixture($warehouse);
        $b = $this->sourceFixture($warehouse);
        $excluded = $this->sourceFixture($warehouse);
        DB::table('stock_documents')->where('id', $excluded['document_id'])->update(['status' => 'SCANNING']);
        DB::table('stock_documents')->update(['created_at' => now()->subHour()]);
        $ids = [$a['document_id'], $b['document_id']];
        rsort($ids);
        foreach ([1, 2] as $page) {
            $this->getJson(self::BASE.'/sources?warehouse_id='.$warehouse->id.'&per_page=1&page='.$page, $this->headers($token))
                ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.source_document_id', $ids[$page - 1])->assertJsonPath('data.0.planned_total_qty', 1);
        }
        $this->getJson(self::BASE.'/sources?warehouse_id='.$warehouse->id.'&q=NO-SUCH-DOCUMENT', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::BASE.'/sources?warehouse_id='.$warehouse->id.'&status=POSTED', $this->headers($token))->assertStatus(422);
    }

    public function test_expired_partial_or_non_product_sources_are_not_selectable(): void
    {
        [, $token, $warehouse] = $this->session();
        $expired = $this->sourceFixture($warehouse);
        DB::table('stock_documents')->where('id', $expired['document_id'])->update(['created_at' => now()->subDays(8)]);
        $partial = $this->sourceFixture($warehouse);
        DB::table('stock_document_lines')->where('stock_document_id', $partial['document_id'])->update(['scanned_qty' => 1]);
        $component = $this->sourceFixture($warehouse);
        DB::table('sku')->where('sku_id', $component['sku_id'])->update(['sku_type' => 'COMPONENT']);
        $this->getJson(self::BASE.'/sources?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::BASE.'/sources/'.$expired['document_id'].'?warehouse_id='.$warehouse->id, $this->headers($token))
            ->assertConflict()->assertJsonPath('error.code', 'OUTBOUND_SOURCE_NOT_SELECTABLE');
    }

    public function test_linked_record_preserves_the_selected_source_recipient_and_plan(): void
    {
        [, $token, $warehouse] = $this->session();
        $source = $this->sourceFixture($warehouse, 2);
        [$draft, $scan, $body, $key] = $this->linked($token, $warehouse, $source);
        $counts = $this->sourceCounts();
        $before = DB::table('stock_documents')->where('id', $source['document_id'])->first();
        $this->getJson(self::BASE.'/sources/'.$source['document_id'].'?warehouse_id='.$warehouse->id, $this->headers($token))
            ->assertOk()->assertJsonPath('data.source_version', 1)->assertHeader('ETag', '"1"');
        $record = $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertOk()
            ->assertJsonPath('data.document_id', $source['document_id'])->assertJsonPath('data.source_created', false)
            ->assertJsonPath('data.status', 'SCANNING')->assertJsonPath('data.full_scan', true)
            ->assertJsonPath('data.posted', false)->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('meta.p03_draft.receipt.command', 'RECORD')
            ->assertJsonPath('meta.p03_draft.document.business_document_id', $source['document_id']);
        $this->assertSame($counts, $this->sourceCounts());
        $this->assertSame($before->recipient_name, DB::table('stock_documents')->where('id', $source['document_id'])->value('recipient_name'));
        $this->assertSame($before->outbound_request_id, DB::table('stock_documents')->where('id', $source['document_id'])->value('outbound_request_id'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(2, (int) DB::table('inventory_balances')->where('product_id', $source['sku_id'])->sum('available_qty'));
        $this->assertDatabaseCount('outbound_item_matches', 2);
        $this->getJson(self::BASE.'/documents/'.$source['document_id'].'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.status', 'SCANNING')->assertJsonPath('data.posted', false)->assertJsonStructure(['data' => ['history']]);
        fwrite(STDOUT, "\nP05_RECORD_EVIDENCE=".json_encode([
            'http_status' => 200, 'source_document_id' => $source['document_id'], 'business_document_id' => $record->json('data.document_id'),
            'status' => $record->json('data.status'), 'stock_effect' => $record->json('data.stock_effect'), 'source_created' => false,
            'receipt' => $record->json('meta.p03_draft.receipt'),
        ], JSON_UNESCAPED_SLASHES)."\n");
    }

    public function test_replay_and_lost_response_reconcile_do_not_create_a_replacement(): void
    {
        [, $token, $warehouse] = $this->session();
        $source = $this->sourceFixture($warehouse);
        [$draft, $scan, $body, $key] = $this->linked($token, $warehouse, $source);
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertOk();
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.document_id', $source['document_id']);
        $this->getJson($this->receiptUrl($warehouse, $draft, $scan, $key), $this->headers($token))->assertOk()
            ->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.document.business_document_id', $source['document_id'])
            ->assertJsonPath('data.cancel_allowed', false);
        $body['source_version'] = 2;
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('stock_documents', 1);
        $this->assertDatabaseCount('outbound_item_matches', 1);
    }

    public function test_standalone_source_mode_is_rejected_instead_of_inventing_reconcile(): void
    {
        [, $token, $warehouse] = $this->session();
        $source = $this->sourceFixture($warehouse);
        [, , $body, $key] = $this->linked($token, $warehouse, $source);
        $this->postJson(self::BASE.'/record', $body, $this->headers($token) + ['Idempotency-Key' => $key])->assertConflict()
            ->assertJsonPath('error.code', 'P05_LINKED_DRAFT_REQUIRED');
        $this->assertDatabaseCount('outbound_item_matches', 0);
    }

    public function test_stale_source_or_draft_versions_do_not_write(): void
    {
        [, $token, $warehouse] = $this->session();
        $source = $this->sourceFixture($warehouse);
        [$draft, $scan, $body, $key] = $this->linked($token, $warehouse, $source);
        $headers = $this->recordHeaders($token, $draft, $scan, $key);
        $headers['X-Draft-Version'] = '2';
        $this->postJson(self::BASE.'/record', $body, $headers)->assertStatus(412);
        DB::table('stock_documents')->where('id', $source['document_id'])->update(['version' => 2]);
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertStatus(412)
            ->assertJsonPath('error.code', 'SOURCE_STALE_VERSION');
        $this->assertDatabaseCount('outbound_item_matches', 0);
    }

    public function test_full_count_with_wrong_sku_is_not_a_valid_plan(): void
    {
        [, $token, $warehouse] = $this->session();
        $source = $this->sourceFixture($warehouse);
        $other = $this->sourceFixture($warehouse);
        $source['raw_codes'] = $other['raw_codes'];
        [$draft, $scan, $body, $key] = $this->linked($token, $warehouse, $source);
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertStatus(422)
            ->assertJsonPath('error.code', 'SOURCE_PLAN_SKU_MISMATCH');
        $this->assertDatabaseCount('outbound_item_matches', 0);
    }

    public function test_normalized_duplicate_item_is_rejected(): void
    {
        [, $token, $warehouse] = $this->session();
        $source = $this->sourceFixture($warehouse, 2);
        $source['raw_codes'][1] = $source['unique_codes'][0];
        [$draft, $scan, $body, $key] = $this->linked($token, $warehouse, $source);
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertConflict()
            ->assertJsonPath('error.code', 'DUPLICATE_ITEM_IN_BATCH');
        $this->assertDatabaseCount('outbound_item_matches', 0);
    }

    public function test_body_cannot_overwrite_recipient_or_use_an_unsaved_batch(): void
    {
        [, $token, $warehouse] = $this->session();
        $source = $this->sourceFixture($warehouse);
        [$draft, $scan, $body, $key] = $this->linked($token, $warehouse, $source);
        $this->postJson(self::BASE.'/record', $body + ['recipient_name' => 'Untrusted replacement'], $this->recordHeaders($token, $draft, $scan, $key))
            ->assertStatus(422)->assertJsonPath('error.code', 'UNEXPECTED_FIELD');
        $body['items'][0]['raw_code'] = 'Different unsaved code';
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertConflict()
            ->assertJsonPath('error.code', 'P05_DRAFT_ITEMS_MISMATCH');
        $this->assertDatabaseCount('outbound_item_matches', 0);
    }

    public function test_scope_and_context_are_required_before_source_reads(): void
    {
        [, $token, $warehouse] = $this->session();
        $this->getJson(self::BASE.'/sources?warehouse_id='.Str::uuid(), $this->headers($token))->assertForbidden();
        $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'SYSTEM'], $this->headers($token))->assertOk();
        $this->getJson(self::BASE.'/sources?warehouse_id='.$warehouse->id, $this->headers($token))->assertConflict();
    }

    public function test_read_permission_does_not_grant_record_and_withdrawal_blocks_replay(): void
    {
        [$user, $token, $warehouse] = $this->session();
        $source = $this->sourceFixture($warehouse);
        [$draft, $scan, $body, $key] = $this->linked($token, $warehouse, $source);
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertOk();
        $permission = Permission::where('permission_key', 'outbound.scan')->value('permission_id');
        DB::table('role_permission')->where('role_id', $user->roles()->first()->role_id)->where('permission_id', $permission)->delete();
        $user->increment('version');
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertForbidden();
        $this->getJson(self::BASE.'/context', $this->headers($token))->assertOk()
            ->assertJsonPath('data.permissions.read', true)->assertJsonPath('data.permissions.record', false);
    }

    public function test_unknown_receipt_stays_unknown_and_cannot_cancel_or_create(): void
    {
        [, $token, $warehouse] = $this->session();
        $source = $this->sourceFixture($warehouse);
        [$draft, $scan, , $key] = $this->linked($token, $warehouse, $source);
        $this->getJson($this->receiptUrl($warehouse, $draft, $scan, $key), $this->headers($token))->assertOk()
            ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.new_document_allowed', false)
            ->assertJsonPath('data.cancel_allowed', false)->assertJsonPath('data.retry', 'SAME_REQUEST_ONLY');
        $this->assertDatabaseCount('outbound_item_matches', 0);
    }

    public function test_receipt_failure_rolls_back_matches_label_and_source_version(): void
    {
        [, $token, $warehouse] = $this->session();
        $source = $this->sourceFixture($warehouse, 2);
        [$draft, $scan, $body, $key] = $this->linked($token, $warehouse, $source);
        DB::listen(function (QueryExecuted $event): void {
            if (str_starts_with(strtolower($event->sql), 'insert into') && str_contains($event->sql, 'mini_app_draft_receipts')) {
                throw new \RuntimeException('P05 isolated receipt write fault');
            }
        });
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertStatus(500);
        $this->assertDatabaseHas('stock_documents', ['id' => $source['document_id'], 'status' => 'DRAFT', 'version' => 1]);
        $this->assertDatabaseHas('mini_app_drafts', ['document_id' => $draft, 'status' => 'DRAFT', 'version' => 1]);
        $this->assertDatabaseCount('outbound_item_matches', 0);
        $this->assertDatabaseCount('label_snapshots', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_source_dispatch_is_inside_the_existing_handoff_and_not_on_post_routes(): void
    {
        $seen = 0;
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (in_array(P05OutboundSource::class, $middleware, true)) {
                $this->assertSame('api/v1/mini-app/outbound/record', $route->uri());
                $this->assertLessThan(array_search(P05OutboundSource::class, $middleware), array_search(MiniAppDraftHandoff::class, $middleware));
                $seen++;
            }
        }
        $this->assertSame(1, $seen);
    }

    public function test_existing_reservation_is_not_stolen_from_another_document(): void
    {
        [, $token, $warehouse] = $this->session();
        $source = $this->sourceFixture($warehouse);
        $requestId = $this->outboundRequestFixture($source['sku_id'], 1, 'P05-RESERVE-'.Str::upper(Str::random(10)));
        $other = $this->outboundDocumentFixture($warehouse->id, $requestId);
        $this->postJson('/api/v1/mini-app/outbound-documents/'.$other['document_id'].'/scan', [
            'code_value' => $source['raw_codes'][0], 'quantity' => 1, 'scan_source' => 'CAMERA',
        ], $this->headers($token) + ['If-Match' => '"1"'])->assertOk();
        [$draft, $scan, $body, $key] = $this->linked($token, $warehouse, $source);
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertConflict()
            ->assertJsonPath('error.code', 'ITEM_ALREADY_MATCHED');
        $this->assertDatabaseCount('outbound_item_matches', 1);
        $this->assertDatabaseHas('stock_documents', ['id' => $source['document_id'], 'status' => 'DRAFT', 'version' => 1]);
    }

    public function test_insufficient_aggregate_stock_rolls_back_the_whole_batch(): void
    {
        [, $token, $warehouse] = $this->session();
        $source = $this->sourceFixture($warehouse, 2);
        [$draft, $scan, $body, $key] = $this->linked($token, $warehouse, $source);
        DB::table('inventory_balances')->where('product_id', $source['sku_id'])->update(['available_qty' => 0, 'on_hand_qty' => 0, 'quantity' => 0]);
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($token, $draft, $scan, $key))->assertConflict()
            ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->assertDatabaseCount('outbound_item_matches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_refresh_preserves_linked_identity_and_detail_reads_authoritative_posted_state(): void
    {
        [, $token, $warehouse, $cookie] = $this->session();
        $source = $this->sourceFixture($warehouse);
        [$draft, $scan, $body, $key] = $this->linked($token, $warehouse, $source);
        $this->headers($token);
        $refresh = $this->browserRefresh($cookie)->assertOk();
        $next = $refresh->json('data.access_token');
        $this->postJson(self::BASE.'/record', $body, $this->recordHeaders($next, $draft, $scan, $key))->assertOk();
        // Test-only status fixture, not a P05 Post API or real stock posting.
        DB::table('stock_documents')->where('id', $source['document_id'])->update(['status' => 'POSTED']);
        $this->getJson(self::BASE.'/documents/'.$source['document_id'].'?warehouse_id='.$warehouse->id, $this->headers($next))
            ->assertOk()->assertJsonPath('data.status', 'POSTED')->assertJsonPath('data.posted', true);
        $this->getJson($this->receiptUrl($warehouse, $draft, $scan, $key), $this->headers($next))->assertOk()
            ->assertJsonPath('data.document.business_status', 'POSTED')->assertJsonPath('data.response.data.status', 'SCANNING');
    }

    private function session(): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P05 isolated test warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p05_'.$suffix, 'display_name' => 'P05 isolated actor', 'email' => 'p05_'.$suffix.'@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P05_'.$suffix, 'role_name' => 'Arbitrary test role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (['outbound.view', 'outbound.request.manual_create', 'outbound.scan', 'outbound.cancel'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->headers($login->json('data.access_token')))->assertOk();

        return [$user, $selected->json('data.access_token'), $warehouse, $this->refreshTokenFromCookie($login)];
    }

    private function sourceFixture(object $warehouse, int $quantity = 1): array
    {
        $sku = $this->productSkuFixture('P05-'.Str::upper(Str::random(8)));
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, $quantity);
        $raw = [];
        $unique = [];
        for ($i = 0; $i < $quantity; $i++) {
            $item = $this->seedItemInStock($sku->sku_id);
            $code = 'P05-ITEM-'.Str::upper(Str::random(10));
            $this->seedScanCode($item, $code);
            $raw[] = 'HN1|SKU='.$sku->sku_code.'|ITEM='.$code;
            $unique[] = $code;
        }
        $request = $this->outboundRequestFixture($sku->sku_id, $quantity, 'P05-REQ-'.Str::upper(Str::random(10)));
        $document = $this->outboundDocumentFixture($warehouse->id, $request);

        return ['document_id' => $document['document_id'], 'sku_id' => $sku->sku_id, 'quantity' => $quantity, 'raw_codes' => $raw, 'unique_codes' => $unique];
    }

    private function linked(string $token, object $warehouse, array $source): array
    {
        $draft = (string) Str::uuid();
        $scan = (string) Str::uuid();
        $items = array_map(fn (string $code): array => ['raw_code' => $code, 'scan_source' => 'CAMERA'], $source['raw_codes']);
        $this->putJson('/api/v1/mini-app/drafts/'.$draft, ['warehouse_id' => $warehouse->id, 'operation' => 'OUTBOUND',
            'scan_session_id' => $scan, 'codes' => $items,
            'form' => ['p05_source' => ['document_id' => $source['document_id'], 'version' => 1]]],
            $this->headers($token) + ['If-Match' => '"0"', 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return [$draft, $scan, ['warehouse_id' => $warehouse->id, 'source_document_id' => $source['document_id'],
            'source_version' => 1, 'expected_total_qty' => $source['quantity'], 'items' => $items], (string) Str::uuid()];
    }

    private function sourceCounts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(), ['stock_documents', 'stock_document_lines', 'outbound_requests', 'outbound_request_imports']);
    }

    private function receiptUrl(object $warehouse, string $draft, string $scan, string $key): string
    {
        return '/api/v1/mini-app/drafts/'.$draft.'/requests/'.$key.'?'.http_build_query([
            'warehouse_id' => $warehouse->id, 'operation' => 'OUTBOUND', 'scan_session_id' => $scan, 'command' => 'RECORD',
        ]);
    }

    private function recordHeaders(string $token, string $draft, string $scan, string $key): array
    {
        return $this->headers($token) + ['Idempotency-Key' => $key, 'X-Draft-ID' => $draft, 'X-Draft-Scan-Session-ID' => $scan, 'X-Draft-Version' => '1'];
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }
}
