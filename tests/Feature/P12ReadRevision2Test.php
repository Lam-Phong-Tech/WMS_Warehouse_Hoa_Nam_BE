<?php

namespace Tests\Feature;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Middleware\P12DocumentReadMetadata;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\P12DocumentReadContract;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;
use Tests\TestCredentials;

/** All records/storage below are disposable test fixtures, never DEV/live evidence. */
class P12ReadRevision2Test extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/mini-app/';

    protected function setUp(): void
    {
        // Resolve and verify the destination BEFORE RefreshDatabase can run.
        $app = $this->createApplication();
        $name = $app['config']->get('database.default');
        $config = $app['config']->get('database.connections.'.$name);
        $safe = $app['config']->get('app.env') === 'testing'
            && empty($config['url'])
            && (($config['driver'] === 'sqlite' && $config['database'] === ':memory:')
                || ($config['driver'] === 'pgsql' && $config['database'] === 'p12_contract_test'
                    && $config['host'] === '127.0.0.1'));
        $app->flush();
        if (! $safe) {
            throw new \LogicException('P12 revision 2 tests require isolated memory or localhost p12_contract_test.');
        }
        parent::setUp();
    }

    public function test_null_quantity_is_not_zero_or_a_complete_known_subtotal(): void
    {
        $summary = P12DocumentReadContract::evidenceSummary($this->evidence([['cai', 2], ['cai', null]]));
        $this->assertNull($summary['quantity_total']);
        $this->assertNull($summary['quantity_by_unit'][0]['quantity']);
        $this->assertSame(2, $summary['quantity_by_unit'][0]['known_quantity']);
        $this->assertSame(1, $summary['missing_quantity_count']);
        $this->assertFalse($summary['quantity_complete']);
        $this->assertSame(2, $summary['entry_count']);
        $summary = P12DocumentReadContract::evidenceSummary($this->evidence([['cai', null]]));
        $this->assertNull($summary['quantity_by_unit'][0]['known_quantity']);
        $this->assertNull($summary['quantity_total']);
    }

    public function test_mixed_unknown_units_and_confirmed_zero_have_different_meanings(): void
    {
        $summary = P12DocumentReadContract::evidenceSummary($this->evidence([['cai', 0], ['hop', 3], [null, 4]]));
        $this->assertTrue($summary['mixed_units']);
        $this->assertFalse($summary['unit_complete']);
        $this->assertSame(1, $summary['unknown_unit_entry_count']);
        $this->assertNull($summary['quantity_total']);
        $zero = P12DocumentReadContract::evidenceSummary($this->evidence([['cai', 0]]));
        $this->assertSame(0, $zero['quantity_total']);
        $this->assertTrue($zero['quantity_complete']);
        $empty = P12DocumentReadContract::evidenceSummary($this->evidence([['cai', null]])->whereRaw('1 = 0'));
        $this->assertSame(0, $empty['entry_count']);
        $this->assertSame(0, $empty['quantity_total']);
    }

    public function test_old_cached_summary_cannot_publish_a_complete_quantity_after_deploy(): void
    {
        $response = response()->json(['success' => true, 'data' => [], 'meta' => ['total' => 2]]);
        $legacy = ['entry_count' => 2, 'quantity_total' => 2, 'quantity_by_unit' => [['unit' => 'cai', 'quantity' => 2]]];
        $body = P12DocumentReadContract::evidenceResponse($response, $legacy, (string) Str::uuid(), 'ALL')->getData(true);
        $this->assertNull($body['meta']['summary']['quantity_total']);
        $this->assertNull($body['meta']['summary']['quantity_by_unit'][0]['quantity']);
        $this->assertSame('LEGACY_CACHE_PENDING_REFRESH', $body['meta']['summary']['coverage']);
        $this->assertSame('p12-scan-evidence-v2', $body['meta']['contract']);
    }

    public function test_metadata_is_attached_only_to_the_eight_existing_get_routes(): void
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array(P12DocumentReadMetadata::class, $route->middleware(), true)) {
                $this->assertSame([], array_diff($route->methods(), ['GET', 'HEAD']));
                $this->assertContains('auth:api', $route->middleware());
                $found[] = $route->uri();
            }
        }
        $this->assertCount(8, $found);
        foreach ([...array_keys(P12DocumentReadMetadata::READ_ROUTES), P12DocumentReadMetadata::DOWNLOAD_ROUTE] as $path) {
            $this->assertContains($path, $found);
        }
    }

    public function test_native_lists_and_details_preserve_owner_fields_without_fake_timeline(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        foreach (['inbound', 'outbound'] as $type) {
            $id = $this->document($warehouse, $type);
            $note = str_repeat('N', 1000);
            DB::table('stock_documents')->where('id', $id)->update(['note' => $note, 'status' => 'SCANNING']);
            DB::table('stock_document_lines')->where('stock_document_id', $id)->update(['scanned_qty' => 1]);
            $before = $this->businessCounts();
            $url = self::BASE.$type.'-documents';
            $this->getJson($url, $this->headers($token))->assertOk()
                ->assertJsonPath('meta.p12.all_sources_aggregate', false)
                ->assertJsonPath('data.0.p12.identity.id', $id)
                ->assertJsonPath('data.0.p12.status.waiting_for_web', null);
            $response = $this->getJson($url.'/'.$id, $this->headers($token))->assertOk()
                ->assertJsonPath('data.note', $note)->assertJsonPath('data.status', 'SCANNING')
                ->assertJsonPath('data.p12.identity.source', strtoupper($type))
                ->assertJsonPath('data.p12.identity.warehouse_id', $warehouse->id)
                ->assertJsonPath('data.p12.timeline.milestones.created.availability', 'RECORDED')
                ->assertJsonPath('data.p12.timeline.milestones.checked.occurred_at', null)
                ->assertJsonPath('data.p12.timeline.milestones.sent_to_web.occurred_at', null)
                ->assertJsonPath('data.p12.timeline.milestones.posted.occurred_at', null);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame($before, $this->businessCounts());
        }
    }

    public function test_p09_successful_receipt_bridges_to_canonical_detail_without_pii_or_recreation(): void
    {
        [, $token, $warehouse] = $this->actorSession();
        $request = (string) Str::uuid();
        $created = $this->postJson(self::BASE.'warranty-cases', [
            'warehouse_id' => $warehouse->id, 'customer_name' => 'P12 isolated customer',
            'customer_phone' => '0000000000', 'description' => 'P12 isolated reported defect',
            'missing_code_reason' => 'Isolated unreadable label',
            'manual_product_description' => 'Isolated unidentifiable product',
        ], $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid(), 'X-Request-ID' => $request])
            ->assertCreated()->assertJsonPath('data.receipt.state', 'SUCCEEDED');
        $id = $created->json('data.warranty_case_id');
        $before = $this->businessCounts();
        $read = $this->getJson(self::BASE.'warranty-requests/'.$request, $this->headers($token))->assertOk()
            ->assertJsonPath('meta.p12.receipt_state', 'SUCCEEDED')
            ->assertJsonPath('meta.p12.receipt_is_detail', false)
            ->assertJsonPath('meta.p12.read_target.path', self::BASE.'warranty-cases/'.$id);
        $this->assertStringNotContainsString('0000000000', json_encode($read->json('meta.p12')));
        $this->getJson($read->json('meta.p12.read_target.path'), $this->headers($token))->assertOk()
            ->assertJsonPath('data.warranty_case_id', $id)->assertJsonPath('data.free_text_redacted', true)
            ->assertJsonPath('data.description', null)->assertJsonPath('data.p12.identity.id', $id)
            ->assertJsonPath('data.p12.timeline.milestones.posted.availability', 'NOT_APPLICABLE');
        $this->getJson(self::BASE.'warranty-cases/'.$id.'/events?per_page=1', $this->headers($token))->assertOk()
            ->assertJsonPath('data.0.warranty_case_id', $id)->assertJsonPath('data.0.message_redacted', true);
        $this->assertSame($before, $this->businessCounts());
        [, $otherToken] = $this->actorSession();
        $this->getJson(self::BASE.'warranty-requests/'.$request, $this->headers($otherToken))->assertOk()
            ->assertJsonPath('data.receipt.state', 'UNKNOWN')->assertJsonPath('meta.p12.read_target', null)
            ->assertJsonPath('data.receipt.may_create_new_request', false);
    }

    public function test_unknown_receipt_and_wrong_warehouse_reads_are_not_empty_successful_records(): void
    {
        [, $token] = $this->actorSession();
        $this->getJson(self::BASE.'warranty-requests/'.Str::uuid(), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.p12.read_target', null)->assertJsonPath('meta.p12.recovery_owner', 'P09');
        $other = Warehouse::create(['code' => 'P12R2-OTHER', 'name' => 'Isolated other warehouse', 'status' => 'INACTIVE']);
        foreach (['inbound', 'outbound'] as $type) {
            $id = $this->document($other, $type);
            $this->getJson(self::BASE.$type.'-documents/'.$id, $this->headers($token))->assertForbidden()
                ->assertJsonMissingPath('data.p12');
            $this->headers($token); // Clear cached auth before an anonymous request.
            $this->getJson(self::BASE.$type.'-documents/'.$id)->assertUnauthorized()->assertJsonMissingPath('data.p12');
        }
    }

    public function test_binary_download_uses_real_test_bytes_and_rejects_deleted_or_mistyped_parent(): void
    {
        Storage::fake('local');
        [, $token, $warehouse] = $this->actorSession();
        $id = $this->document($warehouse, 'inbound');
        [$attachment, $path, $bytes] = $this->attachment($id, 'INBOUND');
        $url = self::BASE.'document-attachments/'.$attachment.'/download';
        $before = $this->businessCounts();
        $response = $this->get($url, $this->headers($token))->assertOk()->assertDownload('p12-test.png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $response->streamedContent()));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->businessCounts());
        DB::table('stock_documents')->where('id', $id)->update(['deleted_at' => now()]);
        $this->getJson($url, $this->headers($token))->assertNotFound();
        $outbound = $this->document($warehouse, 'outbound');
        [$wrongType] = $this->attachment($outbound, 'INBOUND');
        $this->getJson(self::BASE.'document-attachments/'.$wrongType.'/download', $this->headers($token))->assertNotFound();
        $this->getJson(self::BASE.'document-attachments/not-a-uuid/download', $this->headers($token))->assertNotFound();
    }

    public function test_binary_wrong_warehouse_missing_file_and_missing_parent_fail_closed(): void
    {
        Storage::fake('local');
        [, $token, $warehouse] = $this->actorSession();
        $other = Warehouse::create(['code' => 'P12R2-BINARY', 'name' => 'Isolated other warehouse', 'status' => 'INACTIVE']);
        [$hidden] = $this->attachment($this->document($other, 'inbound'), 'INBOUND');
        $this->getJson(self::BASE.'document-attachments/'.$hidden.'/download', $this->headers($token))->assertForbidden();
        [$orphan] = $this->attachment((string) Str::uuid(), 'INBOUND');
        $this->getJson(self::BASE.'document-attachments/'.$orphan.'/download', $this->headers($token))->assertNotFound();
        $id = $this->document($warehouse, 'inbound');
        [$missing, $path] = $this->attachment($id, 'INBOUND');
        Storage::disk('local')->delete($path);
        $this->getJson(self::BASE.'document-attachments/'.$missing.'/download', $this->headers($token))->assertNotFound();
        $this->getJson(self::BASE.'inbound-documents/'.$id, $this->headers($token))->assertOk()->assertJsonPath('data.id', $id);
    }

    private function evidence(array $rows): Builder
    {
        $query = null;
        foreach ($rows as [$unit, $quantity]) {
            $row = DB::query()->selectRaw("'sku' AS sku_id, 'item' AS item_id, 'SERIAL' AS serial_number, CAST(? AS VARCHAR(50)) AS unit, CAST(? AS DECIMAL(18,3)) AS quantity", [$unit, $quantity]);
            $query = $query === null ? $row : $query->unionAll($row);
        }

        return $query;
    }

    private function actorSession(): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P12 revision 2 isolated warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p12r2_'.$suffix, 'display_name' => 'P12 isolated actor',
            'email' => 'p12r2_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make(TestCredentials::PASSWORD), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P12R2_'.$suffix, 'role_name' => 'P12 scoped role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (['inbound.view', 'outbound.view', 'warranty.view', 'warranty.manage'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $login = $this->browserLogin($user->email, TestCredentials::PASSWORD)->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            $this->headers($login->json('data.access_token')))->assertOk();

        return [$user, $selected->json('data.access_token'), $warehouse];
    }

    private function document(object $warehouse, string $type): string
    {
        $sku = $this->productSkuFixture('P12R2-'.Str::upper(Str::random(10)));
        if ($type === 'inbound') {
            $actor = User::query()->firstOrFail()->user_id;
            $doc = StockDocument::create(['doc_type' => 'receipt', 'doc_no' => 'P12R2-IN-'.Str::upper(Str::random(12)),
                'doc_date' => '2026-10-08', 'status' => 'DRAFT', 'dst_warehouse_id' => $warehouse->id, 'created_by' => $actor, 'updated_by' => $actor]);
            StockDocumentLine::create(['stock_document_id' => $doc->id, 'line_no' => 1, 'product_id' => $sku->sku_id,
                'qty_planned' => 1, 'qty_actual' => 0, 'scanned_qty' => 0]);

            return (string) $doc->id;
        }
        $request = $this->outboundRequestFixture($sku->sku_id, 1, 'P12R2-REQ-'.Str::upper(Str::random(10)));

        return $this->outboundDocumentFixture($warehouse->id, $request)['document_id'];
    }

    private function attachment(string $document, string $type): array
    {
        $id = (string) Str::uuid();
        $path = 'p12-isolated/'.$id.'.png';
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXz8AAAAASUVORK5CYII=');
        Storage::disk('local')->put($path, $bytes);
        $actor = User::query()->firstOrFail()->user_id;
        DB::table('document_attachments')->insert(['id' => $id, 'document_type' => $type, 'document_id' => $document,
            'attachment_type' => 'PHOTO', 'file_name' => 'p12-test.png', 'content_type' => 'image/png',
            'file_size_bytes' => strlen($bytes), 'storage_disk' => 'local', 'storage_key' => $path,
            'checksum' => hash('sha256', $bytes), 'status' => 'ACTIVE', 'uploaded_at' => now(), 'uploaded_by' => $actor,
            'created_at' => now(), 'created_by' => $actor, 'updated_at' => now(), 'updated_by' => $actor, 'version' => 1]);

        return [$id, $path, $bytes];
    }

    private function businessCounts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(),
            ['stock_documents', 'stock_movements', 'document_attachments', 'warranty_case', 'p09_warranty_requests']);
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }
}
