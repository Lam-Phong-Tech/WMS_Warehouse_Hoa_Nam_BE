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
use Modules\Warehouse\Support\P12DocumentReadContract;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class P12DocumentsReadContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/mini-app/';

    public function test_existing_document_reads_require_authentication(): void
    {
        foreach (['inbound', 'outbound'] as $source) {
            foreach (['', '/'.Str::uuid(), '/'.Str::uuid().'/scan-entries', '/'.Str::uuid().'/attachments'] as $path) {
                $this->getJson(self::BASE.$source.'-documents'.$path)->assertUnauthorized();
            }
        }
    }

    public function test_created_date_filters_are_applied_before_total_with_local_day_boundaries(): void
    {
        config(['app.timezone' => 'UTC']);
        [, $token, $warehouse] = $this->actorSession();
        foreach (['outbound', 'inbound'] as $type) {
            $ids = [];
            foreach (['2026-10-07 16:59:59', '2026-10-07 17:00:00', '2026-10-08 16:59:59', '2026-10-08 17:00:00'] as $at) {
                $id = $this->documentFixture($warehouse, $type);
                DB::table('stock_documents')->where('id', $id)->update(['created_at' => $at]);
                $ids[] = $id;
            }
            $url = self::BASE.$type.'-documents?date_from=2026-10-08&date_to=2026-10-08&timezone=Asia%2FHo_Chi_Minh&per_page=1';
            $first = $this->getJson($url.'&page=1', $this->headers($token))->assertOk()
                ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $ids[2])
                ->assertJsonPath('meta.read_contract.date_field', 'created_at')
                ->assertJsonPath('meta.read_contract.all_sources_aggregate', false);
            $this->assertStringContainsString('date_from=2026-10-08', $first->json('links.next'));
            $this->getJson($url.'&page=2', $this->headers($token))->assertOk()
                ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $ids[1]);
            $this->getJson(self::BASE.$type.'-documents?date_to=2026-10-08', $this->headers($token))
                ->assertOk()->assertJsonPath('meta.total', 3);
        }
    }

    public function test_list_ties_have_a_deterministic_id_order_in_both_directions(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        foreach (['outbound', 'inbound'] as $type) {
            $ids = [$this->documentFixture($warehouse, $type), $this->documentFixture($warehouse, $type)];
            DB::table('stock_documents')->whereIn('id', $ids)->update(['created_at' => '2026-10-08 01:00:00']);
            foreach (['asc', 'desc'] as $order) {
                $order === 'asc' ? sort($ids) : rsort($ids);
                foreach ([1, 2] as $page) {
                    $this->getJson(self::BASE.$type.'-documents?per_page=1&page='.$page.'&sort_order='.$order, $this->headers($token))
                        ->assertOk()->assertJsonPath('data.0.id', $ids[$page - 1])->assertJsonPath('meta.total', 2);
                }
            }
        }
    }

    public function test_malformed_queries_are_rejected_even_after_a_cache_hit(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        foreach (['outbound', 'inbound'] as $type) {
            $id = $this->documentFixture($warehouse, $type);
            $url = self::BASE.$type.'-documents';
            $this->getJson($url, $this->headers($token))->assertOk();
            foreach (['page=0', 'per_page=201', 'page_size=bad', 'status[]=DRAFT', 'keyword[]=bad', 'sort_order=bad',
                'date_from=2026-02-30', 'date_from=2026-10-09&date_to=2026-10-08', 'timezone=Invalid%2FZone', 'cursor=not-supported'] as $query) {
                $this->getJson($url.'?'.$query, $this->headers($token))->assertStatus(422);
            }
            foreach (['page=0', 'per_page=201', 'status[]=ACTIVE', 'status=INVALID', 'cursor=unsupported'] as $query) {
                $this->getJson($url.'/'.$id.'/scan-entries?'.$query, $this->headers($token))->assertStatus(422);
            }
        }
        $this->getJson(self::BASE.'outbound-documents?status=WAITING_APPROVAL', $this->headers($token))->assertStatus(422);
        $this->getJson(self::BASE.'inbound/suppliers?warehouse_id='.$warehouse->id, $this->headers($token))->assertStatus(422);
    }

    public function test_outbound_scan_summary_covers_every_page_and_preserves_parent_identity(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $source = $this->sourceFixture($warehouse, 3);
        [$draft, $scan, $body, $key] = $this->linked($token, $warehouse, $source);
        $this->postJson(self::BASE.'outbound/record', $body, $this->headers($token) + [
            'Idempotency-Key' => $key, 'X-Draft-ID' => $draft, 'X-Draft-Scan-Session-ID' => $scan, 'X-Draft-Version' => '1',
        ])->assertOk();
        $id = $source['document_id'];
        DB::table('outbound_item_matches')->update(['created_at' => '2026-10-08 01:00:00']);
        $ids = DB::table('outbound_item_matches')->orderByDesc('id')->pluck('id')->all();
        $count = DB::table('outbound_item_matches')->count();
        $before = DB::table('stock_movements')->count();
        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson(self::BASE.'outbound-documents/'.$id.'/scan-entries?status=ALL&per_page=1&page='.$page.'&document_id='.Str::uuid(), $this->headers($token))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[$page - 1])
                ->assertJsonPath('data.0.document_id', $id)->assertJsonPath('data.0.sku_id', $source['sku_id'])
                ->assertJsonPath('data.0.unit', 'cai')->assertJsonPath('meta.total', 3)
                ->assertJsonPath('meta.document_id', $id)->assertJsonPath('meta.summary.entry_count', 3)
                ->assertJsonPath('meta.summary.sku_count', 1)->assertJsonPath('meta.summary.item_count', 3)
                ->assertJsonPath('meta.summary.quantity_total', 3)->assertJsonPath('meta.snapshot_consistent', false)
                ->assertJsonStructure(['data' => [['created_at', 'matched_at', 'line_id', 'raw_code', 'scan_source', 'serial_number']]]);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertSame($count, DB::table('outbound_item_matches')->count());
        $this->assertSame($before, DB::table('stock_movements')->count());
        fwrite(STDOUT, "\nP12_ISOLATED_SAMPLE=".json_encode(['live_dev' => false, 'summary' => $response->json('meta.summary'), 'http' => 200])."\n");
    }

    public function test_scan_status_filters_apply_to_summary_and_empty_evidence_is_not_an_error(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        foreach (['outbound', 'inbound'] as $type) {
            $id = $this->documentFixture($warehouse, $type);
            $this->getJson(self::BASE.$type.'-documents/'.$id.'/scan-entries?status=ALL&per_page=1', $this->headers($token))
                ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
                ->assertJsonPath('meta.summary.entry_count', 0)->assertJsonPath('meta.summary.quantity_total', 0);
        }
    }

    public function test_readonly_note_is_not_truncated_to_the_prototype_limit(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        foreach (['outbound', 'inbound'] as $type) {
            $id = $this->documentFixture($warehouse, $type);
            $note = str_repeat('N', 1000);
            DB::table('stock_documents')->where('id', $id)->update(['note' => $note]);
            $this->getJson(self::BASE.$type.'-documents/'.$id, $this->headers($token))->assertOk()
                ->assertJsonPath('data.note', $note)->assertJsonPath('data.status', 'DRAFT');
        }
    }

    public function test_warehouse_isolation_is_enforced_for_native_list_detail_and_scan_reads(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $other = Warehouse::create(['code' => 'P12-OTHER', 'name' => 'Isolated historical warehouse', 'status' => 'INACTIVE']);
        foreach (['outbound', 'inbound'] as $type) {
            $visible = $this->documentFixture($warehouse, $type);
            $hidden = $this->documentFixture($other, $type);
            $this->getJson(self::BASE.$type.'-documents', $this->headers($token))->assertOk()
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $visible);
            foreach (['', '/scan-entries', '/attachments'] as $suffix) {
                $this->getJson(self::BASE.$type.'-documents/'.$hidden.$suffix, $this->headers($token))->assertForbidden();
            }
        }
    }

    public function test_mixed_units_are_not_added_and_serial_counts_do_not_count_blank_values(): void
    {
        $a = DB::query()->selectRaw("'sku-a' AS sku_id, 'item-a' AS item_id, 'SERIAL-A' AS serial_number, 'cai' AS unit, 2 AS quantity");
        $b = DB::query()->selectRaw("'sku-b' AS sku_id, 'item-b' AS item_id, '' AS serial_number, 'hop' AS unit, 3 AS quantity");
        $summary = P12DocumentReadContract::evidenceSummary($a->unionAll($b));
        $this->assertSame(2, $summary['entry_count']);
        $this->assertSame(2, $summary['sku_count']);
        $this->assertSame(1, $summary['resolved_serial_count']);
        $this->assertNull($summary['quantity_total']);
        $this->assertCount(2, $summary['quantity_by_unit']);
    }

    private function actorSession(): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P12 isolated test warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p12_'.$suffix, 'display_name' => 'P12 isolated actor', 'email' => 'p12_'.$suffix.'@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P12_'.$suffix, 'role_name' => 'Scoped read test role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (['inbound.view', 'outbound.view', 'outbound.request.manual_create', 'outbound.scan', 'outbound.cancel'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->headers($login->json('data.access_token')))->assertOk();

        return [$user, $selected->json('data.access_token'), $warehouse];
    }

    private function documentFixture(object $warehouse, string $type): string
    {
        $sku = $this->productSkuFixture('P12-'.Str::upper(Str::random(10)));
        $request = $this->outboundRequestFixture($sku->sku_id, 1, 'P12-REQ-'.Str::upper(Str::random(10)));
        $doc = $this->outboundDocumentFixture($warehouse->id, $request);
        if ($type === 'inbound') {
            DB::table('stock_documents')->where('id', $doc['document_id'])->update([
                'doc_type' => 'receipt', 'dst_warehouse_id' => $warehouse->id, 'src_warehouse_id' => null,
            ]);
        }

        return $doc['document_id'];
    }

    private function sourceFixture(object $warehouse, int $quantity): array
    {
        $sku = $this->productSkuFixture('P12-SCAN-'.Str::upper(Str::random(8)));
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, $quantity);
        $raw = [];
        for ($i = 0; $i < $quantity; $i++) {
            $item = $this->seedItemInStock($sku->sku_id);
            $code = 'P12-ITEM-'.Str::upper(Str::random(10));
            $this->seedScanCode($item, $code);
            $raw[] = 'HN1|SKU='.$sku->sku_code.'|ITEM='.$code;
        }
        $request = $this->outboundRequestFixture($sku->sku_id, $quantity, 'P12-REQ-'.Str::upper(Str::random(10)));
        $document = $this->outboundDocumentFixture($warehouse->id, $request);

        return ['document_id' => $document['document_id'], 'sku_id' => $sku->sku_id, 'quantity' => $quantity, 'raw_codes' => $raw];
    }

    private function linked(string $token, object $warehouse, array $source): array
    {
        $draft = (string) Str::uuid();
        $scan = (string) Str::uuid();
        $items = array_map(fn (string $code): array => ['raw_code' => $code, 'scan_source' => 'CAMERA'], $source['raw_codes']);
        $this->putJson(self::BASE.'drafts/'.$draft, ['warehouse_id' => $warehouse->id, 'operation' => 'OUTBOUND', 'scan_session_id' => $scan,
            'codes' => $items, 'form' => ['p05_source' => ['document_id' => $source['document_id'], 'version' => 1]]],
            $this->headers($token) + ['If-Match' => '"0"', 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return [$draft, $scan, ['warehouse_id' => $warehouse->id, 'source_document_id' => $source['document_id'], 'source_version' => 1,
            'expected_total_qty' => $source['quantity'], 'items' => $items], (string) Str::uuid()];
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }
}
