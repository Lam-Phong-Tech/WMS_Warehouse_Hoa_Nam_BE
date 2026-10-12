<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;
use Tests\TestCredentials;

/** Test-only fixtures in an isolated DB. No deployed service, full seed or feature activation. */
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

    public function test_all_is_one_interleaved_keyset_with_full_database_totals_and_facets(): void
    {
        [, $token, $warehouse] = $this->actor();
        $expected = [];
        for ($i = 0; $i < 9; $i++) {
            $source = ReadModel::SOURCES[$i % 3];
            $at = sprintf('2026-10-01 %02d:00:00', $i);
            $id = $source === 'WARRANTY' ? $this->warranty($token, $warehouse, $at) : $this->stock($warehouse, $source, $at);
            $expected[] = $source.':'.$id;
        }
        $before = $this->fingerprints();
        $found = [];
        $cursor = 'start';
        do {
            $page = $this->read($token, $warehouse, ['cursor' => $cursor, 'per_page' => 2])->assertOk()
                ->assertJsonPath('meta.total', 9)->assertJsonPath('meta.contract', ReadModel::CONTRACT)
                ->assertJsonPath('meta.source_facets.INBOUND.total', 3)
                ->assertJsonPath('meta.source_facets.OUTBOUND.total', 3)
                ->assertJsonPath('meta.source_facets.WARRANTY.total', 3)
                ->assertJsonPath('meta.cross_request_snapshot', false);
            $this->assertLessThanOrEqual(2, count($page->json('data')));
            $this->assertSame(count($page->json('data')), $page->json('meta.returned_count'));
            array_push($found, ...array_column($page->json('data'), 'row_key'));
            $cursor = $page->json('meta.next_cursor');
            $this->assertSame($cursor !== null, $page->json('meta.has_more'));
            $this->assertLessThanOrEqual(9, count($found));
        } while ($cursor !== null);
        $this->assertSame(array_reverse($expected), $found);
        $this->assertSame($before, $this->fingerprints());
        foreach (ReadModel::SOURCES as $source) {
            $tab = $this->read($token, $warehouse, ['source' => $source])->assertOk()->assertJsonPath('meta.total', 3);
            $this->assertSame([$source], array_values(array_unique(array_column($tab->json('data'), 'source'))));
            $tab->assertJsonPath('meta.source_facets.INBOUND.total', 3);
        }
    }

    public function test_ties_use_source_and_canonical_id_in_both_sort_directions(): void
    {
        [, $token, $warehouse] = $this->actor();
        $time = '2026-10-01 12:00:00';
        $case = $this->warranty($token, $warehouse, $time);
        $receipt = $this->stock($warehouse, 'INBOUND', $time, $case);
        $other = $this->stock($warehouse, 'OUTBOUND', $time);
        foreach (['created_at_asc', 'created_at_desc'] as $sort) {
            $keys = [];
            $cursor = 'start';
            do {
                $response = $this->read($token, $warehouse, ['sort' => $sort, 'per_page' => 1, 'cursor' => $cursor])->assertOk();
                array_push($keys, ...array_column($response->json('data'), 'row_key'));
                $cursor = $response->json('meta.next_cursor');
                $this->assertLessThanOrEqual(3, count($keys));
            } while ($cursor !== null);
            $expected = ['INBOUND:'.$receipt, 'OUTBOUND:'.$other, 'WARRANTY:'.$case];
            $this->assertSame($sort === 'created_at_asc' ? $expected : array_reverse($expected), $keys);
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
            ->assertJsonPath('data.0.p12.status.waiting_for_web', null)
            ->assertJsonPath('data.0.p12.status.mini_app', null);
        foreach (['WAITING_APPROVAL', 'SENT_WAITING_WEB', 'SCANNING'] as $status) {
            $this->read($token, $warehouse, ['status' => $status])->assertStatus(422);
        }
        $this->read($token, $warehouse, ['source' => 'OUTBOUND', 'status' => 'INBOUND:DRAFT'])->assertStatus(422);
    }

    public function test_created_date_uses_half_open_local_days_and_respects_native_storage_precision(): void
    {
        [, $token, $warehouse] = $this->actor();
        foreach (['2026-10-08 16:59:59', '2026-10-08 17:00:00', '2026-10-09 16:59:59', '2026-10-09 17:00:00'] as $at) {
            $this->stock($warehouse, 'INBOUND', $at);
        }
        $this->warranty($token, $warehouse, '2026-10-08 17:10:00');
        $this->read($token, $warehouse, ['date_from' => '2026-10-09', 'date_to' => '2026-10-09'])->assertOk()
            ->assertJsonPath('meta.source_facets.WARRANTY.total', 1)
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.date_field', 'created_at')
            ->assertJsonPath('meta.timezone', 'Asia/Ho_Chi_Minh');
        // A precision-zero PostgreSQL column rounds .999999 into the next day.
        // The API must filter the persisted timestamp, not the pre-write fixture string.
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
        // Owner mirrors enforce NOT NULL today. Exercise nullable read projection
        // in SQL without altering schema, disabling triggers or corrupting stored rows.
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
            $cursor = $meta['next_cursor'];
            $found = [];
            do {
                [$rows, $meta] = Page::read(clone $query, Page::state($cursor, 'isolated-null-projection'), 1, $sort);
                $record = ReadModel::record($rows->first());
                $this->assertNull($record['created_at']);
                $this->assertSame('NOT_RECORDED', $record['time_status']);
                $found[] = $record['id'];
                $cursor = $meta['next_cursor'];
                $this->assertLessThanOrEqual(2, count($found));
            } while ($cursor !== null);
            $this->assertSame($sort === 'created_at_asc' ? $nullIds : array_reverse($nullIds), $found);
        }
        $this->assertSame(0, DB::table('stock_documents')->whereIn('id', [$known, ...$nullIds])->whereNull('created_at')->count());
    }

    public function test_literal_keyword_search_and_warranty_pii_minimization_apply_before_counts(): void
    {
        [, $token, $warehouse] = $this->actor();
        $id = $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        DB::table('stock_documents')->where('id', $id)->update(['source_name' => 'Partner 50%_! literal']);
        $this->stock($warehouse, 'INBOUND', '2026-10-01 01:00:00');
        $case = $this->warranty($token, $warehouse, '2026-10-01 02:00:00');
        $this->read($token, $warehouse, ['keyword' => '%_!'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->read($token, $warehouse, ['keyword' => 'Hidden P12 customer'])->assertOk()->assertJsonPath('meta.total', 0);
        $response = $this->read($token, $warehouse, ['source' => 'WARRANTY'])->assertOk()
            ->assertJsonPath('data.0.id', $case)->assertJsonPath('data.0.partner.display_name', null)
            ->assertJsonPath('data.0.partner.availability', 'WITHHELD_IN_AGGREGATE');
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
        $this->read($token, $warehouse)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.source_facets.WARRANTY.total', null)
            ->assertJsonPath('meta.source_facets.OUTBOUND.total', null)
            ->assertJsonPath('meta.source_facets.WARRANTY.availability', 'DENIED')
            ->assertJsonPath('meta.coverage', 'AUTHORIZED_SOURCES_ONLY');
        $this->assertStringNotContainsString('warranty_case', implode("\n", $queries));
        $this->read($token, $warehouse, ['source' => 'WARRANTY'])->assertForbidden();
    }

    public function test_warehouse_and_persisted_warranty_owner_apply_before_paging(): void
    {
        [, $token, $warehouse] = $this->actor();
        $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $other = Warehouse::create(['code' => 'MIX-OTHER', 'name' => 'Other isolated warehouse', 'status' => 'INACTIVE']);
        $this->stock($other, 'INBOUND', '2026-10-01 01:00:00');
        $case = $this->warranty($token, $warehouse, '2026-10-01 02:00:00');
        DB::table(WarrantyWarehouseScope::TABLE)->where('warranty_case_id', $case)->delete();
        $this->read($token, $warehouse)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.source_facets.WARRANTY.total', 0);
        $this->read($token, $other)->assertStatus(409);
    }

    public function test_strict_query_body_and_per_page_validation_does_not_accept_owner_offset_params(): void
    {
        [, $token, $warehouse] = $this->actor();
        foreach ([['page' => 1], ['per_page' => 0], ['per_page' => 101], ['per_page' => 'abc'],
            ['tenant_id' => 'spoof'], ['sort_order' => 'desc'], ['source' => 'STOCK'],
            ['keyword' => str_repeat('x', 201)], ['cursor' => ['start']]] as $query) {
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
        $cursor = $first->json('meta.next_cursor');
        $next = $this->read($token, $warehouse, ['per_page' => 1, 'cursor' => $cursor])->assertOk();
        $this->assertSame($first->json('meta.cursor_expires_at'), $next->json('meta.cursor_expires_at'));
        foreach ([['per_page' => 2], ['source' => 'INBOUND'], ['sort' => 'created_at_asc'], ['keyword' => 'changed']] as $changed) {
            $this->read($token, $warehouse, array_replace(['per_page' => 1, 'cursor' => $cursor], $changed))->assertStatus(409);
        }
        $this->read($token, $warehouse, ['per_page' => 1, 'cursor' => 'tampered'])->assertStatus(422);
        $state = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        $state['expires'] = now()->timestamp - 1;
        $this->read($token, $warehouse, ['per_page' => 1, 'cursor' => Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR))])
            ->assertStatus(410)->assertJsonPath('error_code', 'DOCUMENTS_CURSOR_EXPIRED');
    }

    public function test_another_login_and_revoked_permissions_cannot_reuse_cursor(): void
    {
        [$user, $token, $warehouse, $role] = $this->actor();
        $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $this->stock($warehouse, 'INBOUND', '2026-10-01 01:00:00');
        $cursor = $this->read($token, $warehouse, ['per_page' => 1])->assertOk()->json('meta.next_cursor');
        $newToken = $this->loginContext($user, $warehouse);
        $this->read($newToken, $warehouse, ['per_page' => 1, 'cursor' => $cursor])->assertStatus(409);
        $cursor = $this->read($newToken, $warehouse, ['per_page' => 1])->assertOk()->json('meta.next_cursor');
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
        $cursor = $page->json('meta.next_cursor');
        $future = now()->addDay()->format('Y-m-d H:i:s');
        $this->stock($warehouse, 'INBOUND', $future);
        $this->read($token, $warehouse, ['per_page' => 1, 'cursor' => $cursor])->assertOk()->assertJsonPath('meta.total', 2);
        $late = $this->stock($warehouse, 'INBOUND', '2026-10-01 01:00:00');
        $this->read($token, $warehouse, ['per_page' => 1, 'cursor' => $cursor])->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.id', $late)->assertJsonPath('meta.cross_request_snapshot', false);
    }

    public function test_missing_authorized_source_fails_closed_instead_of_partial_success(): void
    {
        [, $token, $warehouse] = $this->actor();
        $this->stock($warehouse, 'INBOUND', '2026-10-01 00:00:00');
        $table = WarrantyWarehouseScope::TABLE;
        Schema::rename($table, $table.'_isolated_missing');
        try {
            $this->read($token, $warehouse)->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENTS_SOURCE_UNAVAILABLE')
                ->assertJsonMissingPath('data.0')->assertJsonMissingPath('meta.total');
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
        $this->assertSame('p12-mixed-documents-v1', $spec['info']['version']);
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
        // Existing owner is exercised only against the isolated test DB to prepare an honest canonical row.
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
        // getJson serializes an empty array as a raw GET body. Real bodyless GET is required.
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
            'warranty_case', 'p09_warranty_requests'] as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $result[$table] = hash('sha256', implode("\n", $rows));
        }

        return $result;
    }
}
