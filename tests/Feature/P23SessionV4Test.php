<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\OpensWarrantyCaseForComponentIssue;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Fixtures exist only in disposable test databases; never live UAT evidence. */
class P23SessionV4Test extends TestCase
{
    use BuildsScanDrivenInbound, InteractsWithBrowserSession, OpensWarrantyCaseForComponentIssue, PreparesOutboundFixtures, RefreshDatabase;

    private const HEADER = 'X-Scan-Session-Contract';

    private const CONTRACT = 'mini-app-p23-read-v4';

    protected function setUp(): void
    {
        parent::setUp();
        // PG timestamp(0) rounds fractional seconds; pin the fixture instant so
        // an immediately queried fixture is not newer than the read upper bound.
        $this->travelTo(now()->startOfSecond());
    }

    public function test_v4_is_explicit_and_v3_wire_semantics_are_preserved(): void
    {
        $f = $this->reader();
        $id = $this->batch($f);
        $this->getJson($this->url($f), $this->headers($f, false))->assertOk()->assertJsonPath('meta.contract', 'mini-app-p23-read-v3');
        $this->getJson($this->url($f), $this->headers($f))->assertOk()->assertJsonPath('meta.contract', self::CONTRACT)
            ->assertJsonPath('data.0.contract', self::CONTRACT);
        $this->getJson($this->url($f, $id), $this->headers($f))->assertOk()->assertJsonPath('meta.contract', 'mini-app-p08-v1')
            ->assertJsonPath('meta.p23_contract', self::CONTRACT)->assertJsonPath('data.p23.contract', self::CONTRACT);
        $this->getJson($this->url($f), array_replace($this->headers($f), [self::HEADER => 'unknown']))->assertStatus(422);
        $this->getJson($this->url($f), array_replace($this->headers($f), [self::HEADER => 'mini-app-p23-read-v3']))->assertOk()
            ->assertJsonPath('meta.contract', 'mini-app-p23-read-v3');
        $this->getJson($this->url($f), $this->headers($f) + ['X-Read-Fields-Contract' => 'explicit-read-fields-v1'])->assertStatus(422);
    }

    public function test_legacy_gaps_and_batch_creator_are_never_invented_as_scanner_or_session_clock(): void
    {
        $f = $this->reader();
        $id = $this->batch($f, ['ACCEPTED', 'DUPLICATE', 'RESOLVED', 'REJECTED']);
        $r = $this->getJson($this->url($f, $id), $this->headers($f))->assertOk()
            ->assertJsonPath('data.p23.batch.batchNo', null)->assertJsonPath('data.p23.batch.startedAt', null)
            ->assertJsonPath('data.p23.batch.endedAt', null)->assertJsonPath('data.p23.batch.status', null)
            ->assertJsonPath('data.p23.batch.fieldSources.batchNo.status', 'NOT_RECORDED')
            ->assertJsonPath('data.p23.batch.actor.userId', $f['user']->user_id)
            ->assertJsonPath('data.p23.batch.scanners', null)->assertJsonPath('data.p23.batch.scannersStatus', 'NOT_RECORDED')
            ->assertJsonPath('data.p23.summary.verifiedValidCodes.value', 1)
            ->assertJsonPath('data.p23.summary.explicitRejectedAttempts.value', 1)
            ->assertJsonPath('data.p23.summary.duplicateAttempts.value', 1)
            ->assertJsonPath('data.p23.summary.issuedComponentQuantity.value', null);
        $this->assertSame(4, $r->json('data.p23.counts.total'));
        $this->assertSame('RESOLVED', collect($r->json('data.p23.events'))->firstWhere('status', 'RESOLVED')['status']);
        $this->assertSame('RESOLVED_NOT_ACCEPTED', collect($r->json('data.p23.events'))->firstWhere('status', 'RESOLVED')['acceptanceStatus']);
    }

    public function test_complete_persisted_optional_metadata_and_safe_codes_have_explicit_sources(): void
    {
        $this->optionalMetadata();
        $f = $this->reader(['inventory.trace', 'iam.user.view']);
        $id = $this->batch($f, ['ACCEPTED']);
        DB::table('scan_batches')->where('scan_batch_id', $id)->update(['batch_no' => 'TC41-TEST-0001',
            'started_at' => '2026-11-09 08:00:00', 'ended_at' => '2026-11-09 08:10:00', 'status' => 'ENDED']);
        DB::table('scan_record_events')->where('scan_batch_id', $id)->update(['actor_id' => $f['user']->user_id,
            'actor_display_name' => 'Historical TEST scanner', 'code_type' => 'QR', 'quantity' => 3]);
        $r = $this->getJson($this->url($f, $id), $this->headers($f))->assertOk()
            ->assertJsonPath('data.p23.batch.batchNo', 'TC41-TEST-0001')
            ->assertJsonPath('data.p23.batch.startedAt', '2026-11-09T01:00:00.000000Z')
            ->assertJsonPath('data.p23.batch.endedAt', '2026-11-09T01:10:00.000000Z')
            ->assertJsonPath('data.p23.batch.scanners.0.userId', $f['user']->user_id)
            ->assertJsonPath('data.p23.batch.scanners.0.displayName', 'Historical TEST scanner')
            ->assertJsonPath('data.p23.events.0.displayCode', 'TC41-CODE-001')
            ->assertJsonPath('data.p23.events.0.codeType', 'QR')->assertJsonPath('data.p23.events.0.quantity', 3)
            ->assertJsonPath('data.p23.events.0.actor.source', 'scan_record_events.actor_id');
        $this->writeExample('isolated-complete-not-live.json', $r->json());
    }

    public function test_raw_codes_require_extra_permission_and_secrets_remain_hidden_even_with_it(): void
    {
        $f = $this->reader();
        $id = $this->batch($f, ['ACCEPTED']);
        $this->getJson($this->url($f, $id), $this->headers($f))->assertOk()->assertJsonPath('data.p23.events.0.displayCode', null)
            ->assertJsonPath('data.p23.events.0.fieldSources.displayCode.status', 'REDACTED_PERMISSION');
        $f = $this->reader(['inventory.trace']);
        $id = $this->batch($f, ['ACCEPTED']);
        DB::table('scan_record_events')->where('scan_batch_id', $id)->update(['raw_code' => 'https://example.test/?token=private-TC41-needle']);
        foreach ([$this->url($f), $this->url($f, $id)] as $url) {
            $r = $this->getJson($url, $this->headers($f))->assertOk();
            $this->assertStringNotContainsString('private-TC41-needle', $r->getContent());
        }
    }

    public function test_valid_codes_are_distinct_not_attempt_count_and_unknown_identity_stays_null(): void
    {
        $f = $this->reader();
        $id = $this->batch($f, ['ACCEPTED', 'ACCEPTED', 'DUPLICATE', 'RESOLVED', 'ERROR']);
        $this->getJson($this->url($f, $id), $this->headers($f))->assertOk()
            ->assertJsonPath('data.p23.summary.verifiedValidCodes.value', 1)
            ->assertJsonPath('data.p23.summary.explicitRejectedAttempts.value', 0)
            ->assertJsonPath('data.p23.summary.duplicateAttempts.value', 1);
        DB::table('scan_record_events')->where('scan_batch_id', $id)->where('result_status', 'ACCEPTED')->update(['raw_code' => '']);
        $this->getJson($this->url($f, $id), $this->headers($f))->assertOk()
            ->assertJsonPath('data.p23.summary.verifiedValidCodes.value', null)
            ->assertJsonPath('data.p23.summary.verifiedValidCodes.status', 'IDENTITY_NOT_RECORDED');
    }

    public function test_source_id_alone_never_creates_a_navigation_link(): void
    {
        $f = $this->reader(['outbound.view', 'documents.read']);
        $id = $this->batch($f);
        DB::table('scan_batches')->where('scan_batch_id', $id)->update(['source_type' => 'ISSUE_DOCUMENT', 'source_id' => (string) Str::uuid()]);
        $this->getJson($this->url($f, $id), $this->headers($f))->assertOk()
            ->assertJsonPath('data.p23.batch.primaryDocument', null)->assertJsonCount(0, 'data.p23.relatedDocuments')
            ->assertJsonPath('data.p23.batch.relatedDocumentsStatus', 'NOT_ESTABLISHED');
    }

    public function test_permissions_warehouse_and_tenant_are_still_enforced(): void
    {
        $f = $this->reader([], false);
        $id = $this->batch($f);
        foreach ([$this->url($f), $this->url($f, $id)] as $url) {
            $this->getJson($url, $this->headers($f))->assertForbidden();
        }
        $f = $this->reader();
        $other = Warehouse::create(['code' => 'TC41-OTHER', 'name' => 'Foreign TEST warehouse', 'status' => 'INACTIVE']);
        $id = $this->batch($f);
        DB::table('scan_batches')->where('scan_batch_id', $id)->update(['warehouse_id' => $other->id]);
        $this->getJson($this->url($f, $id), $this->headers($f))->assertNotFound();
        Schema::table('scan_batches', fn ($t) => $t->uuid('tenant_id')->nullable());
        $this->getJson($this->url($f), $this->headers($f))->assertStatus(503);
    }

    public function test_events_keep_stable_ids_cursor_scope_and_full_session_metrics(): void
    {
        $f = $this->reader();
        $id = $this->batch($f, array_fill(0, 7, 'ACCEPTED'));
        $first = $this->getJson($this->url($f, $id, ['limit' => 2]), $this->headers($f))->assertOk()
            ->assertJsonCount(2, 'data.p23.events')->assertJsonPath('data.p23.counts.total', 7);
        $cursor = $first->json('meta.nextCursor');
        $this->getJson($this->url($f, $id, ['limit' => 2, 'cursor' => $cursor]), $this->headers($f, false))->assertStatus(422);
        $ids = array_column($first->json('data.p23.events'), 'eventId');
        while ($cursor !== null) {
            $page = $this->getJson($this->url($f, $id, ['limit' => 2, 'cursor' => $cursor]), $this->headers($f))->assertOk()
                ->assertJsonPath('data.p23.summary.verifiedValidCodes.value', 1);
            $ids = [...$ids, ...array_column($page->json('data.p23.events'), 'eventId')];
            $cursor = $page->json('meta.nextCursor');
        }
        $this->assertCount(7, $ids);
        $this->assertCount(7, array_unique($ids));
    }

    public function test_partial_source_totals_and_latest_are_not_silently_complete(): void
    {
        $f = $this->reader();
        $id = $this->batch($f);
        Schema::rename('scan_record_events', 'tc41_unavailable');
        try {
            $this->getJson($this->url($f), $this->headers($f))->assertOk()->assertJsonPath('meta.total', null)
                ->assertJsonPath('data.0.summary.verifiedValidCodes.value', null)->assertJsonPath('data.0.latestEvent', null);
        } finally {
            Schema::rename('tc41_unavailable', 'scan_record_events');
        }
    }

    public function test_posted_component_quantity_and_links_require_exact_owner_event_and_ledger(): void
    {
        $f = $this->componentFixture();
        $doc = $this->componentDocument($f, true, 3);
        $id = DB::table('scan_batch')->where('source_type', 'COMPONENT_ISSUE_DOCUMENT')->where('source_id', $doc)->value('scan_batch_id');
        $before = $this->fingerprint();
        $response = $this->getJson($this->url($f, $id), $this->headers($f))->assertOk();
        $this->writeExample('isolated-component-debug.json', $response->json());
        $response
            ->assertJsonPath('data.p23.batch.primaryDocument.id', $doc)
            ->assertJsonPath('data.p23.batch.primaryDocument.kind', 'COMPONENT_ISSUE')
            ->assertJsonCount(2, 'data.p23.relatedDocuments')
            ->assertJsonPath('data.p23.summary.issuedComponentQuantity.value', 3)
            ->assertJsonPath('data.p23.summary.issuedComponentQuantity.status', 'VERIFIED_POSTED_LEDGER')
            ->assertJsonPath('data.p23.summary.verifiedValidCodes.value', 1)
            ->assertJsonPath('data.p23.events.0.quantity', 3);
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_unposted_issue_and_foreign_case_never_prove_issued_quantity_or_links(): void
    {
        $f = $this->componentFixture();
        $doc = $this->componentDocument($f, false, 2);
        $id = DB::table('scan_batch')->where('source_type', 'COMPONENT_ISSUE_DOCUMENT')->where('source_id', $doc)->value('scan_batch_id');
        $response = $this->getJson($this->url($f, $id), $this->headers($f))->assertOk();
        $this->writeExample('isolated-unposted-debug.json', $response->json());
        $response
            ->assertJsonPath('data.p23.summary.issuedComponentQuantity.value', null)
            ->assertJsonPath('data.p23.summary.issuedComponentQuantity.status', 'NOT_POSTED');
        $foreign = Warehouse::create(['code' => 'TC41-CASE-OTHER', 'name' => 'Foreign TEST', 'status' => 'INACTIVE']);
        DB::table('warranty_case_warehouse_owners')->where('warranty_case_id', $f['case_id'])->update(['warehouse_id' => $foreign->id]);
        $this->getJson($this->url($f, $id), $this->headers($f))->assertOk()
            ->assertJsonCount(0, 'data.p23.relatedDocuments')->assertJsonPath('data.p23.summary.issuedComponentQuantity.value', null);
    }

    public function test_unknown_event_quantity_and_names_are_not_filled_from_current_catalog(): void
    {
        $f = $this->reader(['inventory.trace', 'iam.user.view']);
        $id = $this->batch($f);
        $before = $this->fingerprint();
        $r = $this->getJson($this->url($f, $id), $this->headers($f))->assertOk()
            ->assertJsonPath('data.p23.events.0.quantity', null)->assertJsonPath('data.p23.events.0.actor.userId', null)
            ->assertJsonPath('data.p23.events.0.actor.displayName', null);
        $this->assertStringNotContainsString('Current name must not fill history', $r->getContent());
        $this->assertSame($before, $this->fingerprint());
        $this->writeExample('isolated-legacy-not-live.json', $r->json());
    }

    public function test_valid_without_readable_owner_relation_is_unknown_not_a_false_zero(): void
    {
        $f = $this->reader();
        $id = $this->batch($f, ['VALID']);
        DB::table('scan_batches')->where('scan_batch_id', $id)->update(['source_type' => 'ISSUE_DOCUMENT', 'source_id' => (string) Str::uuid()]);
        $this->getJson($this->url($f, $id), $this->headers($f))->assertOk()
            ->assertJsonPath('data.p23.summary.verifiedValidCodes.value', null)
            ->assertJsonPath('data.p23.summary.verifiedValidCodes.status', 'REDACTED_PERMISSION')
            ->assertJsonPath('data.p23.batch.relatedDocumentsStatus', 'REDACTED_PERMISSION');
    }

    public function test_verified_event_actor_is_not_batch_creator_and_no_current_name_is_hydrated(): void
    {
        $f = $this->reader(['iam.user.view']);
        $id = $this->batch($f);
        $other = User::create(['username' => 'tc41_other', 'display_name' => 'Current scanner name is not a snapshot',
            'password_hash' => Hash::make(bin2hex(random_bytes(20))), 'status' => 'ACTIVE', 'version' => 1]);
        $event = DB::table('scan_record_events')->where('scan_batch_id', $id)->value('scan_event_id');
        AuditLogger::record('SCAN', 'scan_event', $event, $other->user_id, (string) Str::uuid(),
            afterData: ['scan_batch_id' => $id], result: 'SUCCESS');
        $r = $this->getJson($this->url($f, $id), $this->headers($f))->assertOk()
            ->assertJsonPath('data.p23.batch.actor.userId', $f['user']->user_id)
            ->assertJsonPath('data.p23.events.0.actor.userId', $other->user_id)
            ->assertJsonPath('data.p23.events.0.actor.displayName', null);
        $this->assertStringNotContainsString('Current scanner name is not a snapshot', $r->getContent());
    }

    private function componentFixture(): array
    {
        $auth = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $user = User::findOrFail($auth['__user_id']);
        $secret = bin2hex(random_bytes(20));
        $user->forceFill(['email' => 'tc41-component-'.Str::uuid().'@example.test', 'password_hash' => Hash::make($secret), 'must_change_password' => false])->save();
        $this->headers(['token' => '']);
        $token = $this->browserLogin($user->email, $secret)->assertOk()->json('data.access_token');
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            ['Authorization' => 'Bearer '.$token])->assertOk()->json('data.access_token');
        $sku = $this->componentSkuFixture('TC41-COMP-'.Str::upper(Str::random(6)), serialTracked: false);
        $f = compact('user', 'warehouse', 'token');
        $f['sku_id'] = $sku->sku_id;
        $f['raw_code'] = 'BOX-TC41-'.Str::upper(Str::random(8));
        $headers = $this->headers($f, false);
        $inbound = $this->createInboundHeader($headers, $warehouse->id, ['source_name' => 'Isolated TC41 component evidence']);
        $received = $this->receiveComponentQuantity($headers, $inbound['document_id'], $sku->sku_id, 7, $inbound['version'], $f['raw_code']);
        $this->postInboundReceipt($headers, $inbound['document_id'], $received['version'], (string) Str::uuid())->assertOk();
        $case = $this->postJson('/api/v1/warranty-cases', ['customer_name' => 'Isolated TC41 customer', 'customer_phone' => '0900000041',
            'description' => 'Isolated TC41 case', 'missing_code_reason' => 'TEST without identifier', 'manual_product_description' => 'TEST product'],
            $this->headers($f, false) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $f['case_id'] = $this->moveCaseToChecking($this->headers($f, false), (string) $case->json('data.id'));

        return $f;
    }

    private function componentDocument(array $f, bool $post, int $quantity): string
    {
        if (! $post) {
            $r = $this->postJson('/api/v1/mini-app/warranty-component-issues', ['warehouse_id' => $f['warehouse']->id,
                'warranty_case_id' => $f['case_id'], 'lines' => [['sku_id' => $f['sku_id'], 'required_qty' => $quantity]]], $this->headers($f, false))->assertCreated();
            $id = $r->json('data.document.id');
            $line = DB::table('component_issue_lines')->where('component_issue_document_id', $id)->first();
            $this->postJson('/api/v1/mini-app/warranty-component-issues/'.$id.'/scans', ['line_id' => $line->id, 'code_value' => $f['raw_code'], 'quantity' => $quantity],
                $this->headers($f, false) + ['If-Match' => '"1"'])->assertOk();

            return $id;
        }
        $draft = (string) Str::uuid();
        $scan = (string) Str::uuid();
        $rows = [['raw_code' => $f['raw_code'], 'quantity' => $quantity, 'scan_source' => 'CAMERA']];
        $this->putJson('/api/v1/mini-app/drafts/'.$draft, ['warehouse_id' => $f['warehouse']->id, 'operation' => 'COMPONENT_ISSUE',
            'scan_session_id' => $scan, 'codes' => $rows, 'form' => ['p19' => ['contract' => 'p19-component-issue-linked-v1', 'warranty_case_id' => $f['case_id']]]],
            $this->headers($f, false) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"0"'])->assertCreated();

        return $this->postJson('/api/v1/mini-app/warranty-component-issues/record', ['warehouse_id' => $f['warehouse']->id, 'warranty_case_id' => $f['case_id'],
            'post_immediately' => true, 'items' => $rows], $this->headers($f, false) + ['X-Component-Issue-Contract' => 'p19-component-issue-linked-v1',
                'Idempotency-Key' => (string) Str::uuid(), 'X-Draft-ID' => $draft, 'X-Draft-Scan-Session-ID' => $scan, 'X-Draft-Version' => '1'])
            ->assertCreated()->json('data.component_issue_document_id');
    }

    private function fingerprint(): array
    {
        $result = [];
        foreach (['scan_batch', 'scan_events', 'scan_batches', 'scan_record_events', 'stock_movements', 'inventory_balances', 'component_issue_documents', 'component_issue_items'] as $table) {
            $rows = DB::table($table)->get()->map(fn ($r) => json_encode((array) $r))->all();
            sort($rows);
            $result[$table] = hash('sha256', json_encode($rows));
        }

        return $result;
    }

    private function reader(array $extra = [], bool $inventory = true): array
    {
        $warehouse = $this->warehouseFixture();
        $suffix = Str::lower(Str::random(10));
        $secret = bin2hex(random_bytes(20));
        $user = User::create(['username' => 'tc41_'.$suffix, 'email' => 'tc41_'.$suffix.'@example.test',
            'display_name' => 'Current name must not fill history', 'password_hash' => Hash::make($secret), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'TC41_'.$suffix, 'role_name' => 'Isolated TC41', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (array_unique(['history.read', ...($inventory ? ['inventory.view'] : []), ...$extra]) as $key) {
            $p = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            $role->permissions()->attach($p->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        }
        $this->headers(['token' => '']);
        $token = $this->browserLogin($user->email, $secret)->assertOk()->json('data.access_token');
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id],
            ['Authorization' => 'Bearer '.$token])->assertOk()->json('data.access_token');

        return compact('user', 'token', 'warehouse');
    }

    private function batch(array $f, array $statuses = ['RESOLVED']): string
    {
        $id = (string) Str::uuid();
        DB::table('scan_batches')->insert(['scan_batch_id' => $id, 'source_type' => 'MANUAL', 'warehouse_id' => $f['warehouse']->id,
            'created_by' => $f['user']->user_id, 'created_at' => '2026-11-09 08:00:00']);
        foreach ($statuses as $i => $status) {
            DB::table('scan_record_events')->insert(['scan_event_id' => (string) Str::uuid(), 'scan_batch_id' => $id,
                'raw_code' => 'TC41-CODE-001', 'result_status' => $status, 'created_at' => sprintf('2026-11-09 08:00:%02d', $i)]);
        }

        return $id;
    }

    private function optionalMetadata(): void
    {
        Schema::table('scan_batches', function ($t): void {
            $t->string('batch_no')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->string('status')->nullable();
        });
        Schema::table('scan_record_events', function ($t): void {
            $t->uuid('actor_id')->nullable();
            $t->string('actor_display_name')->nullable();
            $t->string('code_type')->nullable();
            $t->integer('quantity')->nullable();
        });
    }

    private function url(array $f, ?string $id = null, array $q = []): string
    {
        return '/api/v1/mini-app/'.($id === null ? 'scan-sessions' : 'history/scan-sessions/'.$id).'?'.http_build_query(['warehouse_id' => $f['warehouse']->id, ...$q]);
    }

    private function headers(array $f, bool $v4 = true): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$f['token']] + ($v4 ? [self::HEADER => self::CONTRACT] : []);
    }

    private function writeExample(string $name, array $response): void
    {
        $dir = getenv('TC41_EVIDENCE_DIR');
        if (is_string($dir) && $dir !== '' && app()->environment('testing')) {
            file_put_contents($dir.'/'.$name, json_encode(['evidence_kind' => 'ISOLATED_PERSISTED_TEST_FIXTURE_NOT_DEV_HTTP', 'response' => $response], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}
