<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\P12MixedDocumentPage as Page;
use Modules\Warehouse\Support\P12MixedDocumentReadModel as ReadModel;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use Normalizer;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;
use Tests\TestCredentials;

/** Disposable fixtures only. Never contacts a deployed service or enables its feature flag. */
final class P12MixedDocumentsTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/mini-app/documents';

    protected function setUp(): void
    {
        $app = $this->createApplication();
        $db = $app['config']->get('database.connections.'.$app['config']->get('database.default'));
        $safe = $app->environment('testing') && empty($db['url'])
            && (($db['driver'] === 'sqlite' && $db['database'] === ':memory:')
                || ($db['driver'] === 'pgsql' && $db['database'] === 'p12_contract_test' && $db['host'] === '127.0.0.1'));
        $app->flush();
        if (! $safe) {
            throw new \LogicException('Mixed document tests require isolated memory or localhost p12_contract_test.');
        }
        parent::setUp();
        config(['p12_mixed_documents.enabled' => true, 'app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-12T05:00:00Z'));
    }

    public function test_exact_route_is_authenticated_read_only_and_default_gate_is_off(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => $route->uri() === ltrim(self::URL, '/'));
        $this->assertCount(1, $routes);
        $route = $routes->first();
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth:api', $route->middleware());
        $this->getJson(self::URL)->assertUnauthorized();
        foreach (['post', 'patch', 'put', 'delete'] as $verb) {
            $this->{$verb.'Json'}(self::URL, [])->assertStatus(405);
        }
        [, $token, $warehouse] = $this->actor();
        config(['p12_mixed_documents.enabled' => false]);
        $this->read($token, $warehouse)->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENTS_AGGREGATE_DISABLED');
        $this->assertStringContainsString("env('P12_MIXED_DOCUMENTS_ENABLED', false)", file_get_contents(config_path('p12_mixed_documents.php')));
    }

    public function test_authenticated_v3_read_exposes_required_cache_dimensions(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p12-header-test-key', 2))]);
        [, $token, $warehouse] = $this->actor();
        $response = $this->read($token, $warehouse)->assertOk()
            ->assertJsonPath('meta.contract', 'p12-mixed-documents-v3')
            ->assertJsonPath('meta.cursor_version', 3)
            ->assertJsonPath('meta.timestamp_contract', 'document-timestamps-v1');

        $vary = array_map('strtolower', array_map('trim', explode(',', (string) $response->headers->get('Vary'))));
        $this->assertContains('authorization', $vary);
        $this->assertContains('origin', $vary);
        $cacheControl = array_map('strtolower', array_map('trim', explode(',', (string) $response->headers->get('Cache-Control'))));
        $this->assertContains('private', $cacheControl);
        $this->assertContains('no-store', $cacheControl);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
    }

    public function test_all_and_every_source_traverse_more_than_two_pages_without_missing_or_duplicate_keys(): void
    {
        [, $token, $warehouse] = $this->actor();
        $expected = [];
        for ($i = 0; $i < 21; $i++) {
            $source = ReadModel::SOURCES[$i % 3];
            $at = sprintf('2026-10-01 %02d:00:00', $i);
            $id = $source === 'WARRANTY' ? $this->warranty($token, $warehouse, $at) : $this->stock($warehouse, $source, $at);
            $expected[] = $source.':'.$id;
        }
        $before = $this->fingerprints();
        foreach (['created_at_asc', 'created_at_desc'] as $sort) {
            foreach (['ALL', ...ReadModel::SOURCES] as $source) {
                $found = [];
                $cursor = 'start';
                $pageCount = 0;
                do {
                    $page = $this->read($token, $warehouse, ['source' => $source, 'sort' => $sort, 'cursor' => $cursor, 'per_page' => 2])->assertOk()
                        ->assertJsonPath('meta.total', $source === 'ALL' ? 21 : 7)
                        ->assertJsonPath('meta.contract', ReadModel::CONTRACT)
                        ->assertJsonPath('meta.count_unit', 'DOCUMENT_LIST_RECORD')
                        ->assertJsonPath('meta.source_facets.ALL.total', 21)
                        ->assertJsonPath('meta.source_facets.INBOUND.total', 7)
                        ->assertJsonPath('meta.source_facets.OUTBOUND.total', 7)
                        ->assertJsonPath('meta.source_facets.WARRANTY.total', 7)
                        ->assertJsonPath('meta.snapshot_consistent', false);
                    $this->assertLessThanOrEqual(2, count($page->json('data')));
                    $this->assertSame(count($page->json('data')), $page->json('meta.returned_count'));
                    array_push($found, ...array_column($page->json('data'), 'row_key'));
                    $cursor = $page->json('meta.nextCursor');
                    $this->assertSame($cursor, $page->json('meta.next_cursor'));
                    $this->assertSame($cursor !== null, $page->json('meta.hasMore'));
                    $this->assertSame($page->json('meta.hasMore'), $page->json('meta.has_more'));
                    $this->assertLessThanOrEqual(21, count($found));
                    $pageCount++;
                } while ($cursor !== null);
                $selected = array_values(array_filter($expected, fn ($key) => $source === 'ALL' || str_starts_with($key, $source.':')));
                $this->assertSame($sort === 'created_at_asc' ? $selected : array_reverse($selected), $found);
                $this->assertSame($found, array_values(array_unique($found)));
                $this->assertGreaterThan(2, $pageCount);
            }
        }
        $this->assertSame($before, $this->fingerprints());
    }

    public function test_ties_keep_source_rank_and_id_ascending_in_both_time_directions(): void
    {
        [, $token, $warehouse] = $this->actor();
        $time = '2026-10-01 12:00:00';
        $case = $this->warranty($token, $warehouse, $time);
        $receipt = $this->stock($warehouse, 'INBOUND', $time, $case);
        $second = $this->stock($warehouse, 'INBOUND', $time);
        $other = $this->stock($warehouse, 'OUTBOUND', $time);
        $receipts = [$receipt, $second];
        sort($receipts, SORT_STRING);
        $expected = ['INBOUND:'.$receipts[0], 'INBOUND:'.$receipts[1], 'OUTBOUND:'.$other, 'WARRANTY:'.$case];
        foreach (['created_at_asc', 'created_at_desc'] as $sort) {
            $keys = [];
            $cursor = 'start';
            do {
                $response = $this->read($token, $warehouse, ['sort' => $sort, 'per_page' => 1, 'cursor' => $cursor])->assertOk();
                array_push($keys, ...array_column($response->json('data'), 'row_key'));
                $cursor = $response->json('meta.nextCursor');
                $this->assertLessThanOrEqual(4, count($keys));
            } while ($cursor !== null);
            $this->assertSame($expected, $keys);
        }
    }

    public function test_qualified_status_filters_never_infer_sent_or_approval_from_scanning(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->stock($warehouse, 'INBOUND', '2026-10-01 12:00:00');
        DB::table('stock_documents')->where('id', $id)->update(['status' => 'SCANNING']);
        $this->stock($warehouse, 'OUTBOUND', '2026-10-01 13:00:00');
        $this->read($token, $warehouse, ['status' => 'INBOUND:SCANNING'])->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'SCANNING')
            ->assertJsonPath('data.0.canonical_status', 'SCANNING')
            ->assertJsonPath('data.0.source_status', 'SCANNING')
            ->assertJsonPath('data.0.mini_app_status', 'SCANNING')
            ->assertJsonPath('data.0.readiness.state', 'IN_PROGRESS')
            ->assertJsonPath('data.0.display_status.availability', 'MAPPED')
            ->assertJsonPath('data.0.p12.status.waiting_for_web', null)
            ->assertJsonPath('data.0.p12.status.mini_app', 'SCANNING')
            ->assertJsonPath('meta.source_facets.OUTBOUND.total', 0);
        foreach (['WAITING_APPROVAL', 'SENT_WAITING_WEB', 'SCANNING'] as $status) {
            $this->read($token, $warehouse, ['status' => $status])->assertStatus(422);
        }
        $this->read($token, $warehouse, ['source' => 'OUTBOUND', 'status' => 'INBOUND:DRAFT'])->assertStatus(422);
    }

    public function test_all_total_source_totals_and_owner_status_mapping_cover_the_full_set(): void
    {
        [, $token, $warehouse] = $this->actor();
        $inbound = $this->stock($warehouse, 'INBOUND', '2026-10-01 10:00:00');
        $outbound = $this->stock($warehouse, 'OUTBOUND', '2026-10-01 11:00:00');
        $warranty = $this->warranty($token, $warehouse, '2026-10-01 12:00:00');

        $sku = $this->productSkuFixture('MIX-READY-'.Str::upper(Str::random(8)));
        DB::table('sku')->where('sku_id', $sku->sku_id)->update([
            'sku_name' => 'Ready inbound product', 'unit' => 'cai',
        ]);
        DB::table('stock_document_lines')->insert([
            'id' => (string) Str::uuid(), 'stock_document_id' => $inbound, 'line_no' => 1,
            'product_id' => $sku->sku_id, 'qty_planned' => 1, 'qty_actual' => 0,
            'scanned_qty' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_documents')->where('id', $inbound)->update(['status' => 'SCANNING']);
        DB::table('stock_document_lines')->where('stock_document_id', $outbound)
            ->update(['qty_planned' => 1, 'scanned_qty' => 1]);
        DB::table('stock_documents')->where('id', $outbound)->update(['status' => 'SCANNING']);

        $response = $this->read($token, $warehouse, ['source' => 'ALL', 'per_page' => 10])->assertOk()
            ->assertJsonPath('meta.contract', 'p12-mixed-documents-v3')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.source_facets.ALL.total', 3)
            ->assertJsonPath('meta.source_facets.INBOUND.total', 1)
            ->assertJsonPath('meta.source_facets.OUTBOUND.total', 1)
            ->assertJsonPath('meta.source_facets.WARRANTY.total', 1)
            ->assertJsonPath('meta.source_failure_policy', 'FAIL_CLOSED_NO_PARTIAL_DATA_OR_TOTAL')
            ->assertJsonCount(3, 'data');

        $rows = collect($response->json('data'))->keyBy('source');
        $this->assertSame($inbound, $rows['INBOUND']['id']);
        $this->assertSame('SCANNING', $rows['INBOUND']['canonical_status']);
        $this->assertSame('WAITING_APPROVAL', $rows['INBOUND']['mini_app_status']);
        $this->assertSame('READY', $rows['INBOUND']['readiness']['state']);
        $this->assertTrue($rows['INBOUND']['readiness']['ready_for_post']);
        $this->assertTrue($rows['INBOUND']['readiness']['can_post']);

        $this->assertSame($outbound, $rows['OUTBOUND']['id']);
        $this->assertSame('SCANNING', $rows['OUTBOUND']['canonical_status']);
        $this->assertSame('READY_TO_ISSUE', $rows['OUTBOUND']['mini_app_status']);
        $this->assertSame('READY', $rows['OUTBOUND']['readiness']['state']);
        $this->assertTrue($rows['OUTBOUND']['readiness']['ready_for_issue']);

        $this->assertSame($warranty, $rows['WARRANTY']['id']);
        $this->assertSame('RECEIVED', $rows['WARRANTY']['canonical_status']);
        $this->assertSame('RECEIVED', $rows['WARRANTY']['mini_app_status']);
        $this->assertSame('OWNER_STATUS_ONLY', $rows['WARRANTY']['readiness']['state']);

        foreach (['INBOUND', 'OUTBOUND', 'WARRANTY'] as $source) {
            $this->read($token, $warehouse, ['source' => $source, 'per_page' => 10])->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.source', $source);
        }
    }

    public function test_created_date_uses_half_open_local_days_and_respects_native_storage_precision(): void
    {
        [, $token, $warehouse] = $this->actor();
        foreach (['2026-10-08 16:59:59', '2026-10-08 17:00:00', '2026-10-09 16:59:59', '2026-10-09 17:00:00'] as $at) {
            $this->stock($warehouse, 'INBOUND', $at);
        }
        $this->warranty($token, $warehouse, '2026-10-08 17:10:00');
        $this->read($token, $warehouse, ['date_from' => '2026-10-09', 'date_to' => '2026-10-09'])->assertOk()
            ->assertJsonPath('meta.source_facets.WARRANTY.total', 1)->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.date_field', 'created_at')->assertJsonPath('meta.timezone', 'Asia/Ho_Chi_Minh');
        $fractional = $this->stock($warehouse, 'INBOUND', '2026-10-09 16:59:59.999999');
        $stored = DB::table('stock_documents')->where('id', $fractional)->value('created_at');
        $localDate = (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d');
        $included = $localDate === '2026-10-09';
        $page = $this->read($token, $warehouse, ['date_from' => '2026-10-09', 'date_to' => '2026-10-09'])->assertOk()
            ->assertJsonPath('meta.total', 3 + (int) $included);
        $this->assertSame($included, in_array($fractional, array_column($page->json('data'), 'id'), true));
        foreach ([['date_from' => '2026-10-10'], ['date_to' => '2026-10-10'],
            ['date_from' => '2026-10-10', 'date_to' => '2026-10-09'], ['timezone' => 'NOT_A_TIMEZONE']] as $invalid) {
            $this->read($token, $warehouse, $invalid)->assertStatus(422);
        }
    }

    public function test_null_time_keyset_and_projection_without_relaxing_owner_schema(): void
    {
        [, , $warehouse] = $this->actor();
        $known = $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $nullIds = [$this->stock($warehouse, 'INBOUND', '2026-10-01 01:00:00'),
            $this->stock($warehouse, 'INBOUND', '2026-10-01 02:00:00')];
        sort($nullIds, SORT_STRING);
        // Exercise nullable projection without weakening owner NOT NULL constraints or mirrors.
        $source = DB::table('stock_documents')->whereIn('id', [$known, ...$nullIds])
            ->selectRaw("CAST(id AS VARCHAR(36)) AS id, 'INBOUND' AS source, 1 AS source_rank,
                doc_no AS document_no, CAST(dst_warehouse_id AS VARCHAR(36)) AS warehouse_id,
                status, source_name AS partner_name")
            ->selectRaw('CASE WHEN id = ? THEN created_at ELSE NULL END AS created_at,
                CASE WHEN id = ? THEN 0 ELSE 1 END AS time_missing', [$known, $known]);
        $query = DB::query()->fromSub($source, 'nullable_projection');
        foreach (['created_at_asc', 'created_at_desc'] as $sort) {
            $state = Page::state('start', 'isolated-null-projection');
            [$first, $meta] = Page::read(clone $query, $state, 1, $sort);
            $this->assertSame($known, $first->first()->id);
            $cursor = $meta['nextCursor'];
            $found = [];
            do {
                [$rows, $meta] = Page::read(clone $query, Page::state($cursor, 'isolated-null-projection'), 1, $sort);
                $record = ReadModel::record($rows->first());
                $this->assertNull($record['created_at']);
                $this->assertSame('NOT_RECORDED', $record['time_status']);
                $found[] = $record['id'];
                $cursor = $meta['nextCursor'];
                $this->assertLessThanOrEqual(2, count($found));
            } while ($cursor !== null);
            $this->assertSame($nullIds, $found);
        }
        $this->assertSame(0, DB::table('stock_documents')->whereIn('id', [$known, ...$nullIds])->whereNull('created_at')->count());
        $this->assertSame(1, (clone $query)->where('created_at', '>=', '2026-10-01 00:00:00')->count());
    }

    public function test_literal_keyword_search_and_warranty_pii_minimization_apply_before_counts(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        DB::table('stock_documents')->where('id', $id)->update(['source_name' => 'Partner 50%_! literal']);
        $this->stock($warehouse, 'INBOUND', '2026-10-01 01:00:00');
        $case = $this->warranty($token, $warehouse, '2026-10-01 02:00:00');
        foreach (['q', 'keyword'] as $key) {
            $this->read($token, $warehouse, [$key => '%_!'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
            $this->read($token, $warehouse, [$key => 'Hidden P12 customer'])->assertOk()->assertJsonPath('meta.total', 0);
        }
        $response = $this->read($token, $warehouse, ['source' => 'WARRANTY'])->assertOk()
            ->assertJsonPath('data.0.id', $case)->assertJsonPath('data.0.partner.display_name', null)
            ->assertJsonPath('data.0.partner.availability', 'WITHHELD_IN_AGGREGATE')->assertJsonPath('data.0.partner.redacted', true);
        foreach (['Hidden P12 customer', '0000000000', 'encrypted', 'customer_phone'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->read($token, $warehouse, ['keyword' => ''])->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_denied_sources_are_not_queried_or_counted_and_explicit_denied_tab_is_forbidden(): void
    {
        [, $allToken, $warehouse] = $this->actor();
        $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $this->warranty($allToken, $warehouse, '2026-10-01 01:00:00');
        [, $token] = $this->actor(['inbound.view']);
        $queries = [];
        DB::listen(static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = strtolower($event->sql);
        });
        $response = $this->read($token, $warehouse)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.source_facets.WARRANTY.total', null)->assertJsonPath('meta.source_facets.OUTBOUND.total', null)
            ->assertJsonPath('meta.source_facets.WARRANTY.availability', 'DENIED')->assertJsonPath('meta.coverage', 'AUTHORIZED_SOURCES_ONLY')
            ->assertJsonPath('meta.allowed_sources', ['INBOUND']);
        $this->assertStringNotContainsString('warranty_case', implode("\n", $queries));
        $this->assertNotContains('WARRANTY:RECEIVED', $response->json('meta.status_filter_keys'));
        $this->read($token, $warehouse, ['source' => 'WARRANTY'])->assertForbidden();
        $this->read($token, $warehouse, ['status' => 'WARRANTY:RECEIVED'])->assertForbidden();
        [, $none] = $this->actor([]);
        $this->read($none, $warehouse)->assertForbidden();
    }

    public function test_warehouse_and_persisted_warranty_owner_apply_before_paging(): void
    {
        [, $token, $warehouse] = $this->actor();
        $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $other = Warehouse::create(['code' => 'MIX-OTHER', 'name' => 'Other isolated warehouse', 'status' => 'INACTIVE']);
        $this->stock($other, 'INBOUND', '2026-10-01 01:00:00');
        $case = $this->warranty($token, $warehouse, '2026-10-01 02:00:00');
        DB::table(WarrantyWarehouseScope::TABLE)->where('warranty_case_id', $case)->delete();
        $this->read($token, $warehouse)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.source_facets.WARRANTY.total', 0);
        $this->read($token, $other)->assertStatus(409);
    }

    public function test_strict_query_body_and_per_page_validation_does_not_accept_owner_offset_params(): void
    {
        [, $token, $warehouse] = $this->actor();
        foreach ([['page' => 1], ['per_page' => 0], ['per_page' => 101], ['per_page' => 'abc'],
            ['tenant_id' => 'spoof'], ['sort_order' => 'desc'], ['source' => 'STOCK'], ['timezone' => 'UTC'],
            ['keyword' => str_repeat('x', 201)], ['q' => str_repeat('x', 201)], ['cursor' => ['start']],
            ['q' => 'one', 'keyword' => 'two'], ['warehouse_id' => '']] as $query) {
            $this->read($token, $warehouse, $query)->assertStatus(422);
        }
        $url = self::URL.'?warehouse_id='.$warehouse->id;
        foreach (['{}', '[]', ' ', '{"warehouse_id":"spoof"}'] as $body) {
            $headers = $this->headers($token);
            $response = $this->call('GET', $url, [], [], [], [
                'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => $headers['Authorization'],
            ], $body);
            $response->assertStatus(422)->assertJsonPath('error_code', 'DOCUMENTS_BODY_NOT_ALLOWED');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }

    public function test_cursor_is_bound_to_filters_size_scope_and_fixed_expiry(): void
    {
        [, $token, $warehouse] = $this->actor();
        for ($i = 0; $i < 3; $i++) {
            $this->stock($warehouse, 'INBOUND', '2026-10-01 0'.$i.':00:00');
        }
        $first = $this->read($token, $warehouse, ['per_page' => 1])->assertOk();
        $cursor = $first->json('meta.nextCursor');
        $next = $this->read($token, $warehouse, ['per_page' => 1, 'cursor' => $cursor])->assertOk();
        $this->assertSame($first->json('meta.cursor_expires_at'), $next->json('meta.cursor_expires_at'));
        foreach ([['per_page' => 2], ['source' => 'INBOUND'], ['sort' => 'created_at_asc'], ['q' => 'changed']] as $changed) {
            $this->read($token, $warehouse, array_replace(['per_page' => 1, 'cursor' => $cursor], $changed))->assertStatus(409);
        }
        $this->read($token, $warehouse, ['per_page' => 1, 'cursor' => 'tampered'])->assertStatus(422);
        $state = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        $old = $state;
        $old['v'] = 1;
        $this->read($token, $warehouse, ['per_page' => 1, 'cursor' => Crypt::encryptString(json_encode($old, JSON_THROW_ON_ERROR))])->assertStatus(422);
        $state['expires'] = now()->timestamp - 1;
        $this->read($token, $warehouse, ['per_page' => 1, 'cursor' => Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR))])
            ->assertStatus(410)->assertJsonPath('error_code', 'DOCUMENTS_CURSOR_EXPIRED');
    }

    public function test_another_login_and_revoked_permissions_cannot_reuse_cursor(): void
    {
        [$user, $token, $warehouse, $role] = $this->actor();
        $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $this->stock($warehouse, 'INBOUND', '2026-10-01 01:00:00');
        $cursor = $this->read($token, $warehouse, ['per_page' => 1])->assertOk()->json('meta.nextCursor');
        $newToken = $this->loginContext($user, $warehouse);
        $this->read($newToken, $warehouse, ['per_page' => 1, 'cursor' => $cursor])->assertStatus(409);
        $cursor = $this->read($newToken, $warehouse, ['per_page' => 1])->assertOk()->json('meta.nextCursor');
        $permission = Permission::where('permission_key', 'outbound.view')->firstOrFail();
        DB::table('role_permission')->where('role_id', $role->role_id)->where('permission_id', $permission->permission_id)->delete();
        Cache::flush();
        $this->read($newToken, $warehouse, ['per_page' => 1, 'cursor' => $cursor])->assertStatus(409);
    }

    public function test_live_keyset_excludes_newer_inserts_but_does_not_promise_frozen_backdated_counts(): void
    {
        [, $token, $warehouse] = $this->actor();
        $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $this->stock($warehouse, 'INBOUND', '2026-10-01 02:00:00');
        $page = $this->read($token, $warehouse, ['per_page' => 1])->assertOk();
        $cursor = $page->json('meta.nextCursor');
        $this->stock($warehouse, 'INBOUND', now()->addDay()->format('Y-m-d H:i:s'));
        $this->read($token, $warehouse, ['per_page' => 1, 'cursor' => $cursor])->assertOk()->assertJsonPath('meta.total', 2);
        $late = $this->stock($warehouse, 'INBOUND', '2026-10-01 01:00:00');
        $this->read($token, $warehouse, ['per_page' => 1, 'cursor' => $cursor])->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.id', $late)->assertJsonPath('meta.cross_request_snapshot', false);
        DB::table('stock_documents')->where('id', $late)->update(['deleted_at' => now()]);
        $this->read($token, $warehouse, ['per_page' => 1, 'cursor' => $cursor])->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_missing_authorized_source_fails_closed_instead_of_partial_success(): void
    {
        [, $token, $warehouse] = $this->actor();
        $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $table = WarrantyWarehouseScope::TABLE;
        Schema::rename($table, $table.'_isolated_missing');
        try {
            foreach (['ALL', 'INBOUND'] as $source) {
                $this->read($token, $warehouse, ['source' => $source])->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENTS_SOURCE_UNAVAILABLE')
                    ->assertJsonMissingPath('data.0')->assertJsonMissingPath('meta.total');
            }
        } finally {
            Schema::rename($table.'_isolated_missing', $table);
        }
    }

    public function test_soft_deleted_documents_are_excluded_and_read_targets_use_existing_owner_routes(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $deleted = $this->stock($warehouse, 'INBOUND', '2026-10-01 01:00:00');
        DB::table('stock_documents')->where('id', $deleted)->update(['deleted_at' => now()]);
        $response = $this->read($token, $warehouse)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.read_target.method', 'GET')
            ->assertJsonPath('data.0.read_target.path', '/api/v1/mini-app/inbound-documents/'.$id)
            ->assertJsonPath('data.0.read_target.query.warehouse_id', $warehouse->id);
        $target = $response->json('data.0.read_target');
        $this->getJson($target['path'].'?'.http_build_query($target['query']), $this->headers($token))->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_sql_union_and_read_only_explain_use_database_counts_without_owner_http_fanout(): void
    {
        [, $token, $warehouse] = $this->actor();
        $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $queries = [];
        DB::listen(static function (QueryExecuted $event) use (&$queries): void {
            if (str_contains(strtolower($event->sql), 'union all')) {
                $queries[] = [$event->sql, $event->bindings];
            }
        });
        $this->read($token, $warehouse, ['per_page' => 1])->assertOk();
        $this->assertGreaterThanOrEqual(2, count($queries));
        [$sql, $bindings] = $queries[0];
        $this->assertStringContainsString('count(*)', strtolower($sql));
        $prefix = DB::getDriverName() === 'pgsql' ? 'EXPLAIN (FORMAT JSON) ' : 'EXPLAIN QUERY PLAN ';
        $plan = DB::select($prefix.$sql, $bindings);
        $this->assertNotEmpty($plan);
        fwrite(STDOUT, 'P12_MIXED_EXPLAIN '.DB::getDriverName().' '.hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)).PHP_EOL);
    }

    public function test_missing_tenant_evidence_is_fail_closed_not_an_unscoped_query(): void
    {
        [, $token, $warehouse] = $this->actor(['inbound.view']);
        $added = ! Schema::hasColumn('stock_documents', 'tenant_id');
        if ($added) {
            Schema::table('stock_documents', fn ($table) => $table->string('tenant_id')->nullable());
        }
        try {
            $this->read($token, $warehouse)->assertStatus(503)->assertJsonMissingPath('meta.total');
        } finally {
            if ($added) {
                Schema::table('stock_documents', fn ($table) => $table->dropColumn('tenant_id'));
            }
        }
    }

    public function test_unconfirmed_session_must_confirm_context_and_openapi_declares_only_get(): void
    {
        [$user, , $warehouse] = $this->actor(['inbound.view']);
        $this->app['auth']->forgetGuards();
        $login = $this->browserLogin($user->email, TestCredentials::PASSWORD)->assertOk();
        $this->read($login->json('data.access_token'), $warehouse)->assertStatus(409);
        $spec = json_decode(file_get_contents(base_path('docs/contracts/p12-mixed-documents-openapi.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['get'], array_keys($spec['paths'][self::URL]));
        $this->assertSame(ReadModel::CONTRACT, $spec['info']['version']);
    }

    public function test_q_optional_context_and_keyword_alias_share_one_normalized_cursor_binding(): void
    {
        [, $token, $warehouse] = $this->actor(['inbound.view']);
        $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $this->stock($warehouse, 'INBOUND', '2026-10-01 01:00:00');
        $response = $this->get(self::URL.'?q=pn&per_page=1', $this->headers($token) + ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('meta.warehouse_id', $warehouse->id)->assertJsonPath('meta.total', 2);
        $this->read($token, $warehouse, ['keyword' => ' PN ', 'cursor' => $response->json('meta.nextCursor'), 'per_page' => 1])->assertOk();
        $this->read($token, $warehouse, ['q' => 'PN', 'keyword' => 'pn'])->assertOk();
    }

    public function test_vietnamese_unicode_normalization_case_and_accents_are_explicit(): void
    {
        [, $token, $warehouse] = $this->actor();
        $inbound = $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $outbound = $this->stock($warehouse, 'OUTBOUND', '2026-10-01 01:00:00');
        DB::table('stock_documents')->where('id', $inbound)->update(['source_name' => 'ĐẠI LÝ HỒNG HÀ']);
        DB::table('stock_documents')->where('id', $outbound)->update(['recipient_name' => Normalizer::normalize('Đại lý Hồng Hà', Normalizer::FORM_D)]);
        foreach (['đại lý hồng hà', 'ĐẠI LÝ HỒNG HÀ', Normalizer::normalize('đại lý hồng hà', Normalizer::FORM_D)] as $q) {
            $this->read($token, $warehouse, ['q' => $q])->assertOk()->assertJsonPath('meta.total', 2)
                ->assertJsonPath('meta.source_facets.INBOUND.total', 1)->assertJsonPath('meta.source_facets.OUTBOUND.total', 1);
        }
        $this->read($token, $warehouse, ['q' => 'dai ly hong ha'])->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_type_search_matches_explicit_source_terms_without_title_or_id_inference(): void
    {
        [, $token, $warehouse] = $this->actor();
        $receipt = $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $issue = $this->stock($warehouse, 'OUTBOUND', '2026-10-01 01:00:00');
        $case = $this->warranty($token, $warehouse, '2026-10-01 02:00:00');
        // Random fixture codes can contain PN/PX/BH and legitimately match a
        // second source. Separate source-term matching from literal-code matching.
        DB::table('stock_documents')->where('id', $receipt)->update(['doc_no' => 'TYPE-IN-001', 'source_name' => 'Alpha supplier']);
        DB::table('stock_documents')->where('id', $issue)->update(['doc_no' => 'TYPE-OUT-001', 'recipient_name' => 'Beta receiver']);
        DB::table('warranty_case')->where('warranty_case_id', $case)->update([
            'warranty_case_code' => 'TYPE-WC-001', 'temporary_item_code' => 'TYPE-TMP-001',
        ]);
        foreach (['PN' => 'INBOUND', 'Nhập kho' => 'INBOUND', 'PX' => 'OUTBOUND', 'XUẤT' => 'OUTBOUND', 'BH' => 'WARRANTY', 'BẢO HÀNH' => 'WARRANTY'] as $q => $source) {
            $this->read($token, $warehouse, ['q' => $q])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.source', $source);
        }
        // q is a code/partner/type search, not an implicit source selector.
        DB::table('stock_documents')->where('id', $issue)->update(['doc_no' => 'TYPE-OUT-PN-001']);
        $this->read($token, $warehouse, ['q' => 'PN'])->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.source_facets.INBOUND.total', 1)->assertJsonPath('meta.source_facets.OUTBOUND.total', 1);
        $this->read($token, $warehouse, ['q' => 'PN', 'source' => 'OUTBOUND'])->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $issue);
    }

    public function test_ninety_day_selection_and_all_dates_are_different_filters(): void
    {
        [, $token, $warehouse] = $this->actor(['inbound.view']);
        $old = $this->stock($warehouse, 'INBOUND', '2025-01-01 00:00:00');
        $day = now()->setTimezone('Asia/Ho_Chi_Minh')->startOfDay();
        $min = $day->copy()->subDays(90)->toDateString();
        $today = $day->toDateString();
        $this->stock($warehouse, 'INBOUND', $day->copy()->subDays(90)->utc()->format('Y-m-d H:i:s'));
        $this->read($token, $warehouse, ['date_from' => $min, 'date_to' => $today])->assertOk()->assertJsonPath('meta.total', 1);
        $all = $this->read($token, $warehouse)->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertContains($old, array_column($all->json('data'), 'id'));
        foreach ([[$day->copy()->subDays(91)->toDateString(), $today], [$today, $day->copy()->addDay()->toDateString()]] as [$from, $to]) {
            $this->read($token, $warehouse, ['date_from' => $from, 'date_to' => $to])->assertStatus(422)
                ->assertJsonPath('error_code', 'DOCUMENTS_DATE_OUT_OF_WINDOW');
        }
    }

    public function test_cursor_date_window_does_not_shift_at_vietnam_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12T16:59:00Z'));
        [, $token, $warehouse] = $this->actor(['inbound.view']);
        $from = now()->setTimezone('Asia/Ho_Chi_Minh')->subDays(90)->toDateString();
        $filter = ['date_from' => $from, 'date_to' => '2026-10-12', 'per_page' => 1];
        $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $this->stock($warehouse, 'INBOUND', '2026-10-01 01:00:00');
        $first = $this->read($token, $warehouse, $filter)->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-10-12T17:01:00Z'));
        $this->read($token, $warehouse, $filter + ['cursor' => $first->json('meta.nextCursor')])->assertOk()
            ->assertJsonPath('meta.date_window.max', '2026-10-12');
        $this->read($token, $warehouse, $filter)->assertStatus(422);
    }

    public function test_read_targets_resource_types_and_filter_options_are_owner_derived(): void
    {
        [, $token, $warehouse] = $this->actor();
        $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $this->stock($warehouse, 'OUTBOUND', '2026-10-01 01:00:00');
        $this->warranty($token, $warehouse, '2026-10-01 02:00:00');
        $response = $this->read($token, $warehouse)->assertOk();
        foreach ($response->json('data') as $row) {
            $this->assertSame($row['resource_type'], $row['p12']['identity']['resource_type']);
            $this->assertSame($row['id'], $row['p12']['identity']['id']);
            $this->assertSame($row['source'], $row['p12']['identity']['source']);
            $this->assertSame($row['read_target'], $row['p12']['read_target']);
            $this->assertSame($row['source'] === 'WARRANTY' ? 'WARRANTY_CASE' : 'STOCK_DOCUMENT', $row['resource_type']);
            $target = $row['read_target'];
            $this->getJson($target['path'].'?'.http_build_query($target['query']), $this->headers($token))->assertOk();
        }
        foreach ($response->json('meta.filter_options.status') as $option) {
            if ($option['value'] !== 'ALL') {
                $this->assertSame($option['source'].':'.$option['source_status'], $option['value']);
                $this->assertSame('APPROVED', $option['label_availability']);
                $this->assertIsString($option['display_label']);
                $this->assertNotSame('', $option['display_label']);
                $this->assertContains($option['value'], ReadModel::statusKeys());
            }
        }
        $this->assertArrayNotHasKey('auth_session_id', $response->json('meta'));
        $this->assertArrayNotHasKey('actor_id', $response->json('meta'));
    }

    public function test_warranty_pii_is_not_returned_or_searched_even_with_pii_permission(): void
    {
        [, $token, $warehouse] = $this->actor(['warranty.view', 'warranty.manage', 'warranty.pii.view']);
        $this->warranty($token, $warehouse, '2026-10-01 00:00:00');
        foreach (['Hidden P12 customer', '0000000000', 'Isolated reported defect'] as $q) {
            $this->read($token, $warehouse, ['q' => $q])->assertOk()->assertJsonPath('meta.total', 0);
        }
        $response = $this->read($token, $warehouse)->assertOk()->assertJsonPath('data.0.partner.display_name', null);
        $this->assertStringNotContainsString('Hidden P12 customer', $response->getContent());
    }

    public function test_bad_serialization_fails_closed_instead_of_fabricating_identity_or_time(): void
    {
        $row = (object) ['id' => (string) Str::uuid(), 'warehouse_id' => (string) Str::uuid(), 'source' => 'INBOUND',
            'document_no' => null, 'created_at' => 'tomorrow', 'status' => 'DRAFT', 'partner_name' => null];
        try {
            ReadModel::record($row);
            $this->fail('Relative time must not become a historical source timestamp.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(503, $exception->getResponse()->getStatusCode());
        }
        $row->created_at = null;
        $row->status = null;
        $record = ReadModel::record($row);
        $this->assertNull($record['created_at']);
        $this->assertNull($record['source_status']);
        $this->assertNull($record['status_key']);
    }

    private function actor(array $permissions = ['inbound.view', 'outbound.view', 'warranty.view', 'warranty.manage']): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P12 mixed isolated warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'mix_'.$suffix, 'display_name' => 'Isolated mixed actor',
            'email' => 'mix_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make(TestCredentials::PASSWORD), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'MIX_'.$suffix, 'role_name' => 'Mixed scoped test role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$user, $this->loginContext($user, $warehouse), $warehouse, $role];
    }

    private function loginContext(User $user, object $warehouse): string
    {
        $this->app['auth']->forgetGuards();
        $login = $this->browserLogin($user->email, TestCredentials::PASSWORD)->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->headers($login->json('data.access_token')))->assertOk();

        return $selected->json('data.access_token');
    }

    private function stock(object $warehouse, string $source, ?string $at, ?string $id = null): string
    {
        $actor = User::query()->firstOrFail()->user_id;
        if ($source === 'INBOUND') {
            $document = new StockDocument;
            $document->forceFill(['id' => $id ?? (string) Str::uuid(), 'doc_type' => 'receipt',
                'doc_no' => 'MIX-IN-'.Str::upper(Str::random(12)), 'doc_date' => '2026-10-01',
                'status' => 'DRAFT', 'dst_warehouse_id' => $warehouse->id, 'created_by' => $actor, 'updated_by' => $actor])->save();
            $id = (string) $document->id;
        } else {
            $sku = $this->productSkuFixture('MIX-SKU-'.Str::upper(Str::random(10)));
            $request = $this->outboundRequestFixture($sku->sku_id, 1, 'MIX-REQ-'.Str::upper(Str::random(10)));
            $id = $this->outboundDocumentFixture($warehouse->id, $request)['document_id'];
        }
        DB::table('stock_documents')->where('id', $id)->update(['created_at' => $at]);

        return $id;
    }

    private function warranty(string $token, object $warehouse, string $at): string
    {
        $response = $this->postJson('/api/v1/mini-app/warranty-cases', [
            'warehouse_id' => $warehouse->id, 'customer_name' => 'Hidden P12 customer', 'customer_phone' => '0000000000',
            'description' => 'Isolated reported defect', 'missing_code_reason' => 'Isolated unreadable label',
            'manual_product_description' => 'Isolated unidentifiable product',
        ], $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid(), 'X-Request-ID' => (string) Str::uuid()])->assertCreated();
        $id = $response->json('data.warranty_case_id');
        DB::table('warranty_case')->where('warranty_case_id', $id)->update(['created_at' => $at]);

        return $id;
    }

    private function read(string $token, object $warehouse, array $query = [])
    {
        return $this->get(self::URL.'?'.http_build_query(array_replace(['warehouse_id' => $warehouse->id], $query)),
            $this->headers($token) + ['Accept' => 'application/json']);
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function fingerprints(): array
    {
        $result = [];
        foreach (['stock_documents', 'stock_document_lines', 'stock_movements', 'inventory_balances',
            'warranty_case', 'p09_warranty_requests', 'mini_app_drafts', 'mini_app_draft_requests',
            'p19_component_command_receipts'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $result[$table] = hash('sha256', implode("\n", $rows));
        }

        return $result;
    }
}
