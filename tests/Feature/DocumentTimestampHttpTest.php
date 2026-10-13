<?php

namespace Tests\Feature;

use App\Support\DocumentTimestamp as Time;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Middleware\DocumentTimestampResponse;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Support\DocumentTimestampSql;
use Modules\Warehouse\Support\P12MixedDocumentReadModel;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;
use Tests\TestCredentials;

/** Synthetic records in guarded disposable DBs only; never probes the live three UUIDs. */
final class DocumentTimestampHttpTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $app = $this->createApplication();
        $db = $app['config']->get('database.connections.'.$app['config']->get('database.default'));
        $safe = $app->environment('testing') && empty($db['url'])
            && (($db['driver'] === 'sqlite' && $db['database'] === ':memory:')
                || ($db['driver'] === 'pgsql' && $db['database'] === 'p12_contract_test' && $db['host'] === '127.0.0.1'));
        $app->flush();
        if (! $safe) {
            throw new \LogicException('Timestamp tests require isolated memory or localhost p12_contract_test.');
        }
        parent::setUp();
        config(['p12_mixed_documents.enabled' => true, 'document_timestamps.legacy_naive_timezone' => 'Asia/Ho_Chi_Minh']);
        $this->travelTo(CarbonImmutable::parse('2026-10-13T05:00:00Z'));
    }

    public function test_recent_all_source_and_canonical_detail_share_one_instant_in_both_app_and_pg_timezones(): void
    {
        [$user, $token, $warehouse] = $this->actor();
        $expected = [];
        foreach (['2026-10-11 21:40:07', '2026-10-11 11:02:58', '2026-10-09 15:11:50'] as $raw) {
            $expected[$this->stock($warehouse, $user, 'OUTBOUND', $raw)] = Time::utc($raw);
        }
        $inbound = $this->stock($warehouse, $user, 'INBOUND', '2026-10-12 00:00:00');
        $before = DB::table('stock_documents')->orderBy('id')->get()->toJson();
        $old = date_default_timezone_get();
        try {
            foreach (['UTC', 'Asia/Ho_Chi_Minh'] as $zone) {
                config(['app.timezone' => $zone]);
                date_default_timezone_set($zone);
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("SET LOCAL TIME ZONE '$zone'");
                }
                $recent = $this->read($token, $warehouse, ['source' => 'OUTBOUND', 'per_page' => 3])->assertOk()
                    ->assertJsonPath('meta.timestamp_contract', Time::CONTRACT)->assertJsonPath('meta.total', 3);
                $this->assertSame(array_keys($expected), array_column($recent->json('data'), 'id'));
                $all = $this->read($token, $warehouse)->assertOk()->assertJsonPath('meta.total', 4);
                $allById = array_column($all->json('data'), null, 'id');
                $sources = $this->getJson('/api/v1/mini-app/outbound/sources?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk();
                $sourceById = array_column($sources->json('data'), null, 'document_id');
                foreach ($expected as $id => $timestamp) {
                    $owner = $this->getJson('/api/v1/mini-app/outbound-documents/'.$id.'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk();
                    $typed = $this->getJson('/api/v1/mini-app/outbound/documents/'.$id.'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk();
                    $source = $this->getJson('/api/v1/mini-app/outbound/sources/'.$id.'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk();
                    foreach ([$allById[$id]['created_at'], $sourceById[$id]['created_at'], $owner->json('data.created_at'),
                        $typed->json('data.document.created_at'), $source->json('data.created_at')] as $actual) {
                        $this->assertSame($timestamp, $actual);
                        $this->assertSame(Time::instant($timestamp)->format('U.u'), Time::instant($actual)->format('U.u'));
                    }
                }
                $this->getJson('/api/v1/mini-app/inbound-documents/'.$inbound.'?warehouse_id='.$warehouse->id, $this->headers($token))
                    ->assertOk()->assertJsonPath('data.created_at', $allById[$inbound]['created_at']);
                $this->getJson('/api/v1/mini-app/inbound/documents/'.$inbound.'?warehouse_id='.$warehouse->id, $this->headers($token))
                    ->assertOk()->assertJsonPath('data.document.created_at', $allById[$inbound]['created_at']);
            }
        } finally {
            date_default_timezone_set($old);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL TIME ZONE 'UTC'");
        }
        $this->assertSame($before, DB::table('stock_documents')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_vietnam_midnight_filters_apply_before_total_and_cursor(): void
    {
        [$user, $token, $warehouse] = $this->actor();
        $before = $this->stock($warehouse, $user, 'INBOUND', '2026-10-11 23:59:59');
        $after = $this->stock($warehouse, $user, 'INBOUND', '2026-10-12 00:00:00');
        $day = ['date_from' => '2026-10-11', 'date_to' => '2026-10-11', 'timezone' => 'Asia/Ho_Chi_Minh'];
        $this->read($token, $warehouse, $day)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $before);
        // Public date selection remains Vietnam-only; UTC serialization does not
        // expand the existing query contract or move the business-day boundary.
        $this->read($token, $warehouse, array_replace($day, ['timezone' => 'UTC']))->assertStatus(422);
        $range = array_replace($day, ['date_to' => '2026-10-12', 'per_page' => 1]);
        foreach (['UTC', 'Asia/Ho_Chi_Minh'] as $appZone) {
            config(['app.timezone' => $appZone]);
            $first = $this->read($token, $warehouse, $range)->assertOk()
                ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $after)
                ->assertJsonPath('data.0.created_at', '2026-10-11T17:00:00.000000Z');
            $this->read($token, $warehouse, $range + ['cursor' => $first->json('meta.nextCursor')])
                ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $before)
                ->assertJsonPath('data.0.created_at', '2026-10-11T16:59:59.000000Z');
        }
    }

    public function test_ties_null_and_old_cursor_require_exact_keys(): void
    {
        [$user, $token, $warehouse] = $this->actor();
        $a = $this->stock($warehouse, $user, 'INBOUND', '2026-10-11 21:40:07');
        $b = $this->stock($warehouse, $user, 'OUTBOUND', '2026-10-11 21:40:07');
        // Preserve owner NOT NULL constraints. Null belongs to a nullable read
        // projection and the pure serializer tests, not a malformed stored row.
        foreach (['created_at_asc', 'created_at_desc'] as $sort) {
            $first = $this->read($token, $warehouse, ['per_page' => 1, 'sort' => $sort])->assertOk()->assertJsonPath('data.0.id', $a);
            $cursor = $first->json('meta.nextCursor');
            $state = json_decode(Crypt::decryptString($cursor), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(3, $state['v']);
            config(['document_timestamps.legacy_naive_timezone' => 'UTC']);
            $this->read($token, $warehouse, ['per_page' => 1, 'sort' => $sort, 'cursor' => $cursor])
                ->assertStatus(409)->assertJsonPath('error_code', 'DOCUMENTS_CURSOR_CONTEXT_CHANGED');
            config(['document_timestamps.legacy_naive_timezone' => 'Asia/Ho_Chi_Minh']);
            $old = $state;
            $old['v'] = 2;
            $this->read($token, $warehouse, ['per_page' => 1, 'sort' => $sort, 'cursor' => Crypt::encryptString(json_encode($old, JSON_THROW_ON_ERROR))])
                ->assertStatus(422)->assertJsonPath('error_code', 'DOCUMENTS_CURSOR_INVALID');
            $this->read($token, $warehouse, ['per_page' => 1, 'sort' => $sort, 'cursor' => $cursor])->assertOk()
                ->assertJsonPath('data.0.id', $b)->assertJsonPath('meta.hasMore', false);
        }
        $nullable = DB::table('stock_documents')->where('id', $a)
            ->selectRaw("CAST(id AS VARCHAR(36)) AS id, 'INBOUND' AS source, CAST(dst_warehouse_id AS VARCHAR(36)) AS warehouse_id, doc_no AS document_no, status, source_name AS partner_name, NULL AS created_at")->first();
        $projection = P12MixedDocumentReadModel::record($nullable);
        $this->assertNull($projection['created_at']);
        $this->assertSame('NOT_RECORDED', $projection['time_status']);
        $this->assertSame('2026-10-11 21:40:07', DB::table('stock_documents')->where('id', $a)->value('created_at'));
    }

    public function test_sql_projection_preserves_microseconds_without_timezone_session_inference(): void
    {
        DocumentTimestampSql::createdAt('INBOUND');
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::selectOne("SELECT hn_document_utc('2026-10-12 00:00:00.000001') a, hn_document_utc('2026-10-11T17:00:00.000002Z') b, hn_document_utc(NULL) n");
        } else {
            $row = DB::selectOne("SELECT (TIMESTAMP '2026-10-12 00:00:00.000001' AT TIME ZONE 'Asia/Ho_Chi_Minh') AT TIME ZONE 'UTC' a,
                TIMESTAMPTZ '2026-10-11T17:00:00.000002Z' AT TIME ZONE 'UTC' b, (NULL::timestamp AT TIME ZONE 'Asia/Ho_Chi_Minh') AT TIME ZONE 'UTC' n");
        }
        $this->assertSame('2026-10-11T17:00:00.000001Z', Time::utc($row->a, 'UTC'));
        $this->assertSame('2026-10-11T17:00:00.000002Z', Time::utc($row->b, 'UTC'));
        $this->assertLessThan(Time::instant($row->b, 'UTC'), Time::instant($row->a, 'UTC'));
        $this->assertNull($row->n);
    }

    public function test_warranty_and_aggregate_preserve_the_persisted_offset_not_stock_wall_clock(): void
    {
        [$user, $token, $warehouse] = $this->actor();
        $created = $this->postJson('/api/v1/mini-app/warranty-cases', [
            'warehouse_id' => $warehouse->id, 'customer_name' => 'Timestamp isolated customer', 'customer_phone' => '0000000000',
            'description' => 'Test only', 'missing_code_reason' => 'Isolated label', 'manual_product_description' => 'Test only',
        ], $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid(), 'X-Request-ID' => (string) Str::uuid()])->assertCreated();
        $id = $created->json('data.warranty_case_id');
        // PostgreSQL fixture sends an explicit instant, not an ambiguous naive value.
        $aware = in_array(strtolower(Schema::getColumnType('warranty_case', 'created_at')), ['timestamptz', 'timestamp with time zone'], true);
        $raw = $aware ? '2026-10-11 14:40:07+00:00' : '2026-10-11 21:40:07';
        DB::table('warranty_case')->where('warranty_case_id', $id)->update(['created_at' => $raw]);
        $before = DB::table('warranty_case')->where('warranty_case_id', $id)->first();
        $owner = $this->getJson('/api/v1/mini-app/warranty-cases/'.$id.'?warehouse_id='.$warehouse->id, $this->headers($token))->assertOk();
        $all = $this->read($token, $warehouse, ['source' => 'WARRANTY'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($owner->json('data.created_at'), $all->json('data.0.created_at'));
        $this->assertSame(Time::utc($raw), $all->json('data.0.created_at'));
        $this->assertEquals($before, DB::table('warranty_case')->where('warranty_case_id', $id)->first());
        $this->assertStringNotContainsString('Timestamp isolated customer', $all->getContent());
    }

    public function test_timestamp_middleware_is_not_attached_to_writers_or_receipts_and_does_not_grant_access(): void
    {
        $matched = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array(DocumentTimestampResponse::class, $route->middleware(), true)) {
                $this->assertContains('GET', $route->methods());
                $this->assertContains($route->uri(), DocumentTimestampResponse::READ_ROUTES);
                $matched[] = $route->uri();
            }
        }
        $this->assertEqualsCanonicalizing(DocumentTimestampResponse::READ_ROUTES, $matched);
        $this->get('/api/v1/mini-app/documents', ['Accept' => 'application/json'])->assertUnauthorized();
        [$user, $token, $warehouse] = $this->actor();
        $this->get('/api/v1/mini-app/documents?warehouse_id='.(string) Str::uuid(), $this->headers($token) + ['Accept' => 'application/json'])->assertForbidden();
    }

    private function actor(): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'Timestamp isolated warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'time_'.$suffix, 'display_name' => 'Timestamp CI', 'email' => 'time_'.$suffix.'@wms-hoanam.test',
            'password_hash' => Hash::make(TestCredentials::PASSWORD), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'TIME_'.$suffix, 'role_name' => 'Timestamp test', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (['inbound.view', 'outbound.view', 'warranty.view', 'warranty.manage'] as $key) {
            $p = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $p->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $login = $this->browserLogin($user->email, TestCredentials::PASSWORD)->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->headers($login->json('data.access_token')))->assertOk();

        return [$user, $selected->json('data.access_token'), $warehouse];
    }

    private function stock(object $warehouse, User $user, string $source, ?string $raw): string
    {
        if ($source === 'INBOUND') {
            $document = new StockDocument;
            $document->forceFill(['id' => (string) Str::uuid(), 'doc_type' => 'receipt', 'doc_no' => 'TS-IN-'.Str::upper(Str::random(12)),
                'doc_date' => '2026-10-11', 'status' => 'DRAFT', 'dst_warehouse_id' => $warehouse->id,
                'created_by' => $user->user_id, 'updated_by' => $user->user_id])->save();
            $id = (string) $document->id;
        } else {
            $sku = $this->productSkuFixture('TS-SKU-'.Str::upper(Str::random(10)));
            $request = $this->outboundRequestFixture($sku->sku_id, 1, 'TS-REQ-'.Str::upper(Str::random(10)));
            $id = $this->outboundDocumentFixture($warehouse->id, $request)['document_id'];
        }
        DB::table('stock_documents')->where('id', $id)->update(['created_at' => $raw]);

        return $id;
    }

    private function read(string $token, object $warehouse, array $query = [])
    {
        return $this->get('/api/v1/mini-app/documents?'.http_build_query(['warehouse_id' => $warehouse->id] + $query),
            $this->headers($token) + ['Accept' => 'application/json']);
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }
}
