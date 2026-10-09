<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\SourceCountEvidence;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Owner fixture writes run only in a disposable test DB, never through P16. */
class P16DocumentsReadStateContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const RIGHTS = ['inbound.view', 'outbound.view', 'warranty.view', 'warranty.manage', 'warranty.temp_only'];

    private const BASE = '/api/v1/mini-app/';

    public function test_handoff_and_collection_reuse_four_existing_gets_not_an_aggregate(): void
    {
        $contract = json_decode(file_get_contents(base_path('contracts/p16/owner-read-v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $collection = json_decode(file_get_contents(base_path('contracts/p16/P16-documents-read.postman_collection.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PENDING_OWNER_CONTRACT_AND_LIVE_TEST', $contract['status']);
        $this->assertSame(['GET'], $contract['methods']);
        foreach (['mutations', 'new_api_routes', 'fallbacks'] as $key) {
            $this->assertSame([], $contract[$key]);
        }
        foreach (['production_activation', 'live_test_verified', 'ui_wiring_verified'] as $key) {
            $this->assertFalse($contract[$key]);
        }
        $this->assertFalse($contract['aggregate']['enabled']);
        $this->assertNull($contract['aggregate']['path']);
        $this->assertNull($contract['aggregate']['total']);
        $this->assertCount(4, $collection['item']);
        $this->assertFalse($collection['protocolProfileBehavior']['followRedirects']);
        foreach ($collection['item'] as $item) {
            $spec = $contract['reads'][$item['name']];
            $this->assertSame('GET', $item['request']['method']);
            $this->assertArrayNotHasKey('body', $item['request']);
            $this->assertSame('{{api_origin}}'.$spec['path'].'{{p16_query}}', $item['request']['url']);
            $route = Route::getRoutes()->match(Request::create($spec['path'], 'GET'));
            $this->assertContains('auth:api', $route->gatherMiddleware());
        }
        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('~/p16(?:/|$)~', $route->uri());
        }
        $scripts = json_encode($collection['event'], JSON_THROW_ON_ERROR);
        foreach (['pm.sendRequest', 'pm.execution.runRequest', 'pm.environment.set', 'pm.collectionVariables.set', 'pm.execution.setNextRequest'] as $writer) {
            $this->assertStringNotContainsString($writer, $scripts);
        }
    }

    public function test_count_evidence_never_confuses_page_length_or_false_filters_with_scope_total(): void
    {
        $transport = ['warehouse_id', 'page', 'per_page', 'cursor', 'sort'];
        $request = Request::create('/', 'GET', ['warehouse_id' => (string) Str::uuid(), 'page' => 9, 'per_page' => 1]);
        $evidence = SourceCountEvidence::make($request, 3, $transport);
        $this->assertSame(3, $evidence['unfiltered_authorized_total']);
        $this->assertFalse($evidence['source_empty']);
        foreach (['keyword' => 'missing', 'ready_for_post' => false, 'ready_for_issue' => '0', 'unknown_filter' => 'x'] as $key => $value) {
            $evidence = SourceCountEvidence::make(Request::create('/', 'GET', [$key => $value]), 0, $transport);
            $this->assertNull($evidence['unfiltered_authorized_total']);
            $this->assertNull($evidence['source_empty']);
            $this->assertSame(0, $evidence['filtered_total']);
        }
        $evidence = SourceCountEvidence::make(Request::create('/', 'GET', ['status' => 'ALL']), 0, $transport, ['status' => ['ALL']]);
        $this->assertTrue($evidence['source_empty']);
        $this->assertFalse($evidence['all_sources_aggregate']);
        foreach (['[]', '{}', '{"keyword":"missing"}'] as $body) {
            $withBody = Request::create('/', 'GET', [], [], [], [], $body);
            $this->assertNull(SourceCountEvidence::make($withBody, 0, $transport)['source_empty']);
        }
    }

    public function test_four_read_entries_require_authentication_and_do_not_create_business_data(): void
    {
        $before = $this->domainSnapshot();
        foreach (['/api/v1/auth/me', self::BASE.'inbound-documents', self::BASE.'outbound-documents', self::BASE.'warranty-cases'] as $path) {
            $this->resetAuthentication();
            $this->get($path, ['Accept' => 'application/json'])->assertUnauthorized();
        }
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_unfiltered_empty_evidence_is_source_local_on_all_three_owners(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $before = $this->domainSnapshot();
        foreach (['inbound-documents', 'outbound-documents', 'warranty-cases'] as $source) {
            $this->get(self::BASE.$source.'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk()
                ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
                ->assertJsonPath('meta.count_evidence.unfiltered_authorized_total', 0)
                ->assertJsonPath('meta.count_evidence.source_empty', true)
                ->assertJsonPath('meta.count_evidence.all_sources_aggregate', false);
        }
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_p12_filtered_empty_and_out_of_range_page_do_not_mean_empty_warehouse(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        foreach (['inbound', 'outbound'] as $source) {
            $id = $this->documentFixture($warehouse, $source);
            $url = self::BASE.$source.'-documents?warehouse_id='.$warehouse->id;
            $before = $this->domainSnapshot();
            $this->get($url.'&keyword=P16-NO-MATCH', $this->headers($token))->assertOk()
                ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
                ->assertJsonPath('meta.count_evidence.unfiltered_request', false)
                ->assertJsonPath('meta.count_evidence.unfiltered_authorized_total', null)
                ->assertJsonPath('meta.count_evidence.source_empty', null);
            $this->get($url.'&page=1&per_page=1', $this->headers($token))->assertOk()
                ->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 1)
                ->assertJsonPath('meta.count_evidence.unfiltered_authorized_total', 1)
                ->assertJsonPath('meta.count_evidence.source_empty', false);
            $this->get($url.'&page=9&per_page=1', $this->headers($token))->assertOk()
                ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 1)
                ->assertJsonPath('meta.count_evidence.source_empty', false);
            $this->assertSame($before, $this->domainSnapshot());
        }
    }

    public function test_warranty_counts_keep_persisted_identity_pii_masking_and_filtered_null_evidence(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $id = $this->warrantyFixture($token, $warehouse);
        $url = self::BASE.'warranty-cases?warehouse_id='.$warehouse->id;
        $before = $this->domainSnapshot();
        $this->get($url.'&status=ALL', $this->headers($token))->assertOk()
            ->assertJsonPath('data.0.warranty_case_id', $id)->assertJsonPath('data.0.warehouse_id', (string) $warehouse->id)
            ->assertJsonPath('data.0.pii_masked', true)->assertJsonPath('data.0.free_text_redacted', true)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.count_evidence.source_empty', false);
        $this->get($url.'&search=P16-NO-MATCH', $this->headers($token))->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.count_evidence.unfiltered_authorized_total', null)
            ->assertJsonPath('meta.count_evidence.source_empty', null);
        $this->get($url.'&page=9&per_page=1', $this->headers($token))->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.count_evidence.source_empty', false);
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_warranty_unsupported_filters_and_paging_conflicts_are_errors_not_no_results(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $url = self::BASE.'warranty-cases?warehouse_id='.$warehouse->id;
        foreach (['date_from=2026-10-01', 'date_to=2026-10-09', 'customer_name=x', 'keyword=x', 'status=WAITING_APPROVAL'] as $query) {
            $this->get($url.'&'.$query, $this->headers($token))->assertStatus(422);
        }
        $this->get($url.'&page=1&cursor=x', $this->headers($token))->assertStatus(422)
            ->assertJsonPath('error.code', 'WARRANTY_PAGING_CONFLICT');
        $this->get($url.'&cursor=invalid', $this->headers($token))->assertStatus(422)
            ->assertJsonPath('error.code', 'WARRANTY_CURSOR_INVALID');
    }

    public function test_warranty_cursor_preserves_full_total_and_cannot_cross_filter_or_session(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $this->warrantyFixture($token, $warehouse);
        $this->warrantyFixture($token, $warehouse);
        $url = self::BASE.'warranty-cases?warehouse_id='.$warehouse->id.'&per_page=1';
        $first = $this->get($url, $this->headers($token))->assertOk()->assertJsonPath('meta.total', 2);
        $cursor = $first->json('meta.nextCursor');
        $this->assertIsString($cursor);
        $this->assertNotSame('', $cursor);
        $second = $this->get($url.'&cursor='.urlencode($cursor), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.count_evidence.unfiltered_authorized_total', 2);
        $this->assertNotSame($first->json('data.0.warranty_case_id'), $second->json('data.0.warranty_case_id'));
        $this->get($url.'&cursor='.urlencode($cursor).'&search=x', $this->headers($token))->assertStatus(422)
            ->assertJsonPath('error.code', 'WARRANTY_CURSOR_SCOPE_MISMATCH');
        [, $other] = $this->actorSession(['warranty.view']);
        $this->get($url.'&cursor='.urlencode($cursor), $this->headers($other))->assertStatus(422)
            ->assertJsonPath('error.code', 'WARRANTY_CURSOR_SCOPE_MISMATCH');
    }

    public function test_a_warm_authorized_list_never_grants_a_different_actor_missing_permission(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        foreach (['inbound-documents', 'outbound-documents', 'warranty-cases'] as $source) {
            $this->get(self::BASE.$source.'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk();
        }
        [, $limited] = $this->actorSession(['warehouse.view']);
        foreach (['inbound-documents', 'outbound-documents', 'warranty-cases'] as $source) {
            $this->get(self::BASE.$source.'?warehouse_id='.$warehouse->id, $this->headers($limited))->assertForbidden();
        }
    }

    private function actorSession(array $rights = self::RIGHTS): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P16 isolated warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p16_'.$suffix, 'display_name' => 'P16 isolated actor', 'email' => 'p16_'.$suffix.'@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P16_'.$suffix, 'role_name' => 'P16 isolated rights', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($rights as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->resetAuthentication();
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->headers($login->json('data.access_token')))->assertOk();

        return [$user, $selected->json('data.access_token'), $warehouse];
    }

    private function documentFixture(object $warehouse, string $type): string
    {
        $sku = $this->productSkuFixture('P16-'.Str::upper(Str::random(10)));
        if ($type === 'inbound') {
            $actorId = User::query()->firstOrFail()->user_id;
            $doc = StockDocument::create(['doc_type' => 'receipt', 'doc_no' => 'P16-IN-'.Str::upper(Str::random(12)),
                'doc_date' => '2026-10-08', 'status' => 'DRAFT', 'dst_warehouse_id' => $warehouse->id,
                'created_by' => $actorId, 'updated_by' => $actorId]);
            StockDocumentLine::create(['stock_document_id' => $doc->id, 'line_no' => 1, 'product_id' => $sku->sku_id,
                'qty_planned' => 1, 'qty_actual' => 0, 'scanned_qty' => 0]);

            return (string) $doc->id;
        }
        $request = $this->outboundRequestFixture($sku->sku_id, 1, 'P16-REQ-'.Str::upper(Str::random(10)));

        return $this->outboundDocumentFixture($warehouse->id, $request)['document_id'];
    }

    private function warrantyFixture(string $token, Warehouse $warehouse): string
    {
        return (string) $this->postJson('/api/v1/warranty-cases', ['warehouse_id' => (string) $warehouse->id,
            'customer_name' => 'P16 isolated customer', 'customer_phone' => '0900000000', 'description' => 'P16 isolated test',
            'missing_code_reason' => 'No identifier in disposable fixture', 'manual_product_description' => 'Unidentified test product'],
            $this->headers($token))->assertCreated()->json('data.warranty_case_id');
    }

    private function headers(string $token): array
    {
        $this->resetAuthentication();

        // Bodyless GET plus Accept mirrors the read-only collection. getJson adds [] as a body.
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function resetAuthentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }

    private function domainSnapshot(): array
    {
        $snapshot = [];
        foreach (['stock_documents', 'stock_document_lines', 'stock_movements', 'inventory_balances', 'warranty_case',
            'warranty_case_warehouse_owners', 'mini_app_drafts', 'mini_app_work_shifts'] as $table) {
            $rows = DB::table($table)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows);
            $snapshot[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $snapshot;
    }
}
