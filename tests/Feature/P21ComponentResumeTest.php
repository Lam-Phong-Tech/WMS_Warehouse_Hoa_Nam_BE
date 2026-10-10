<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\MiniAppDraftService as P03;
use Modules\Warehouse\Services\P19LinkedComponentIssue as P19;
use Modules\Warehouse\Services\P21ComponentResume as P21;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\OpensWarrantyCaseForComponentIssue;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Synthetic fixtures in disposable CI databases only; no DEV seed/Post. */
class P21ComponentResumeTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use InteractsWithBrowserSession;
    use OpensWarrantyCaseForComponentIssue;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const DRAFT = '/api/v1/mini-app/drafts';

    private const RECORD = '/api/v1/mini-app/warranty-component-issues/record';

    public function test_default_list_retains_recorded_tasks_and_separates_all_status_totals(): void
    {
        $f = $this->fixture();
        $first = $this->save($f);
        $second = $this->save($f, []);
        $cancelled = $this->save($f);
        $this->postJson(self::DRAFT.'/'.$cancelled['id'].'/cancel', [
            'warehouse_id' => $f['warehouse_id'], 'operation' => 'COMPONENT_ISSUE', 'scan_session_id' => $cancelled['scan_id'],
            'confirmed_request_id' => $cancelled['key'], 'reason' => 'Isolated owner cancellation',
        ], $this->headers($f['token'], false) + ['If-Match' => '"1"', 'Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $key = (string) Str::uuid();
        $result = $this->record($f, $first, $key)->assertCreated();
        $business = $result->json('data.component_issue_document_id');
        $before = $this->snapshot();
        $list = $this->read($this->listUrl($f), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('meta.resume_contract', P21::CONTRACT)->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.status_totals.DRAFT', 1)->assertJsonPath('meta.status_totals.RECORDED', 1)
            ->assertJsonPath('meta.status_totals.CANCELLED', 1)->assertJsonCount(2, 'data');
        $row = collect($list->json('data'))->firstWhere('document_id', $first['id']);
        $this->assertSame('RECORDED', $row['status']);
        $this->assertSame('OPEN_EXISTING_RESULT', $row['resume_policy']);
        $this->assertSame($business, $row['business_document_id']);
        $this->assertSame('POSTED', $row['business_status']);
        $this->assertSame(2, $row['version']);
        $this->assertNotNull($row['posted_at']);
        $this->assertFalse($row['codes_validated']);
        $this->assertSame('NONE', $row['stock_effect']);
        $this->assertSame('RECORD', $row['last_command']);
        $this->read($this->listUrl($f, ['status' => 'DRAFT']), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.document_id', $second['id']);
        $this->read($this->listUrl($f, ['status' => 'CANCELLED']), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.0.resume_policy', 'READ_ONLY_CANCELLED');
        $check = $this->read($this->receiptUrl($f, $first, $key, 'RECORD'), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.document.business_document_id', $business)
            ->assertJsonPath('data.response.data.p19_result.stock_effect', 'DECREASE')->assertJsonPath('data.p21_recovery_policy', 'CHECK_ONLY');
        $this->assertSame($result->json('data.p19_result'), $check->json('data.response.data.p19_result'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_snapshot_is_private_input_not_scan_evidence_or_business_lines(): void
    {
        $f = $this->fixture();
        $codes = [['raw_code' => 'Unknown|Original-MixedCase', 'quantity' => 5, 'scan_source' => 'SCANNER']];
        $d = $this->save($f, $codes);
        $before = $this->snapshot();
        $this->read($this->detailUrl($f, $d), $this->headers($f['token']))->assertOk()->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.codes', $codes)->assertJsonPath('data.codes_validated', false)
            ->assertJsonPath('data.scan_evidence_persisted', false)->assertJsonPath('data.summary.quantity_total', 5)
            ->assertJsonPath('data.summary.basis', 'UNVALIDATED_PERSONAL_SNAPSHOT')
            ->assertJsonPath('data.business_document_id', null)->assertJsonPath('data.business_version', null)
            ->assertJsonPath('data.document_no', null)->assertJsonPath('data.posted_at', null)
            ->assertJsonPath('data.mutation_exposed', false)->assertJsonPath('data.actor_id', $f['actor_id'])
            ->assertJsonPath('data.auth_session_id', $f['session_id'])->assertJsonPath('data.scan_session_id', $d['scan_id']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_empty_and_incomplete_snapshots_do_not_invent_quantity_or_eligibility(): void
    {
        $f = $this->fixture();
        $empty = $this->save($f, []);
        $this->read($this->detailUrl($f, $empty), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.summary.quantity_total', 0)->assertJsonPath('data.business_document_id', null);
        $incomplete = $this->save($f, [['raw_code' => 'legacy-input-without-explicit-quantity']]);
        $this->read($this->detailUrl($f, $incomplete), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.summary.quantity_total', null)->assertJsonPath('data.snapshot_shape_valid', false)
            ->assertJsonPath('data.resume_policy', 'READ_ONLY_INCOMPLETE_SNAPSHOT');
    }

    public function test_closed_case_keeps_checkpoint_readable_but_owner_record_stays_blocked(): void
    {
        $f = $this->fixture();
        $d = $this->save($f);
        DB::table('warranty_case')->where('warranty_case_id', $f['case_id'])->update(['status' => 'COMPLETED']);
        $before = $this->snapshot();
        $this->read($this->detailUrl($f, $d), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.case_status', 'COMPLETED')->assertJsonPath('data.case_allows_issue', false)
            ->assertJsonPath('data.resume_policy', 'READ_ONLY_CASE_STATE');
        $this->read($this->listUrl($f), $this->headers($f['token']))->assertOk()->assertJsonPath('meta.total', 1);
        $this->read($this->receiptUrl($f, $d, $d['key'], 'SAVE'), $this->headers($f['token']))->assertOk()->assertJsonPath('data.outcome', 'SUCCEEDED');
        $this->assertSame($before, $this->snapshot());
        $this->record($f, $d, (string) Str::uuid())->assertUnprocessable()->assertJsonPath('error.code', 'WARRANTY_CASE_STATUS_NOT_ALLOWED');
        $this->assertDatabaseCount('component_issue_documents', 0);
    }

    public function test_personal_actor_scope_applies_before_total_and_pagination(): void
    {
        $f = $this->fixture();
        $own = $this->save($f);
        $g = $this->fixture();
        $foreign = $this->save($g);
        $this->read($this->listUrl($f, ['per_page' => 1]), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.has_more', false)->assertJsonPath('data.0.document_id', $own['id']);
        $this->read($this->detailUrl($f, $foreign), $this->headers($f['token']))->assertNotFound();
        $this->read($this->receiptUrl($f, $foreign, $foreign['key'], 'SAVE'), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.receipt', null);
    }

    public function test_persisted_case_ownership_is_not_trusted_from_snapshot(): void
    {
        $f = $this->fixture();
        $d = $this->save($f);
        $other = Warehouse::create(['code' => 'P21-OTHER-'.Str::random(6), 'name' => 'Isolated other warehouse', 'status' => 'INACTIVE']);
        DB::table('warranty_case_warehouse_owners')->where('warranty_case_id', $f['case_id'])->update(['warehouse_id' => $other->id]);
        $this->read($this->listUrl($f), $this->headers($f['token']))->assertOk()->assertJsonPath('meta.total', 0);
        $this->read($this->detailUrl($f, $d), $this->headers($f['token']))->assertNotFound();
        $this->read($this->receiptUrl($f, $d, $d['key'], 'SAVE'), $this->headers($f['token']))->assertNotFound();
        $this->read($this->listUrl($f, ['warranty_case_id' => $f['case_id']]), $this->headers($f['token']))->assertNotFound();
    }

    public function test_new_login_cannot_reuse_old_tasks_or_receipts(): void
    {
        $f = $this->fixture();
        $d = $this->save($f);
        $user = User::findOrFail($f['actor_id']);
        $this->headers('');
        $login = $this->browserLogin($user->email, $f['secret'])->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $f['warehouse_id']],
            $this->headers($login->json('data.access_token'), false))->assertOk();
        $new = $selected->json('data.access_token');
        $this->read($this->listUrl($f), $this->headers($new))->assertOk()->assertJsonPath('meta.total', 0);
        $this->read($this->detailUrl($f, $d), $this->headers($new))->assertNotFound();
        $this->read($this->receiptUrl($f, $d, $d['key'], 'SAVE'), $this->headers($new))->assertOk()
            ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.may_create_new_request', false);
        $this->read($this->detailUrl($f, $d), $this->headers($f['token']))->assertOk();
    }

    public function test_unknown_never_recreates_or_increases_box_quantity(): void
    {
        $f = $this->fixture();
        $d = $this->save($f);
        $before = $this->snapshot();
        $key = (string) Str::uuid();
        for ($i = 0; $i < 2; $i++) {
            $this->read($this->receiptUrl($f, $d, $key, 'RECORD'), $this->headers($f['token']))->assertOk()
                ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.receipt', null)
                ->assertJsonPath('data.cancel_allowed', false)->assertJsonPath('data.new_document_allowed', false)
                ->assertJsonPath('data.may_create_new_request', false)->assertJsonPath('data.document.codes.0.quantity', 3);
        }
        $missing = ['id' => (string) Str::uuid(), 'scan_id' => (string) Str::uuid()];
        $this->read($this->receiptUrl($f, $missing, (string) Str::uuid(), 'SAVE'), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.document', null);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_historical_save_version_is_not_current_version_or_a_record_receipt(): void
    {
        $f = $this->fixture();
        $d = $this->save($f);
        $body = $d['body'];
        $body['codes'][0]['quantity'] = 2;
        $this->putJson(self::DRAFT.'/'.$d['id'], $body, $this->headers($f['token'], false) + [
            'If-Match' => '"1"', 'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();
        $this->read($this->receiptUrl($f, $d, $d['key'], 'SAVE'), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.receipt.version', 1)->assertJsonPath('data.document.version', 2)
            ->assertJsonPath('data.response.data.document.codes.0.quantity', 3)->assertJsonPath('data.document.codes.0.quantity', 2);
        $this->read($this->receiptUrl($f, $d, $d['key'], 'RECORD'), $this->headers($f['token']))->assertConflict()
            ->assertJsonPath('error.code', 'REQUEST_IDENTITY_MISMATCH');
    }

    public function test_scan_identity_and_context_mismatch_are_not_empty(): void
    {
        $f = $this->fixture();
        $d = $this->save($f);
        $bad = $d;
        $bad['scan_id'] = (string) Str::uuid();
        $this->read($this->detailUrl($f, $bad), $this->headers($f['token']))->assertNotFound();
        $this->read($this->listUrl($f, ['warehouse_id' => (string) Str::uuid()]), $this->headers($f['token']))->assertConflict();
        $this->read($this->detailUrl($f, $d).'&case_id='.$f['case_id'], $this->headers($f['token']))->assertUnprocessable();
    }

    public function test_legacy_source_is_not_aliased_and_native_get_contract_is_unchanged(): void
    {
        $f = $this->fixture();
        $d = $this->save($f);
        $legacy = $this->save($f);
        DB::table(P03::TABLE)->where('document_id', $legacy['id'])->update(['form' => '{}']);
        $this->read($this->listUrl($f), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.document_id', $d['id']);
        $this->read($this->detailUrl($f, $legacy), $this->headers($f['token']))->assertNotFound();
        $this->read($this->listUrl($f), $this->headers($f['token'], false))->assertOk()
            ->assertJsonPath('meta.contract', P03::CONTRACT)->assertJsonPath('meta.total', 2);
    }

    public function test_unsupported_headers_query_and_mutation_are_rejected(): void
    {
        $f = $this->fixture();
        $d = $this->save($f);
        $before = $this->snapshot();
        $this->read($this->listUrl($f, ['limit' => 20]), $this->headers($f['token']))->assertUnprocessable();
        $this->read($this->listUrl($f), $this->headers($f['token'], false) + [P21::HEADER => 'invented-v2'])->assertUnprocessable();
        $this->read($this->detailUrl($f, $d), $this->headers($f['token']) + ['If-Match' => '"1"'])->assertUnprocessable();
        $this->json('GET', $this->listUrl($f), ['actor_id' => $f['actor_id']], $this->headers($f['token']))->assertUnprocessable();
        $this->putJson(self::DRAFT.'/'.$d['id'], $d['body'], $this->headers($f['token']) + [
            'If-Match' => '"1"', 'Idempotency-Key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'P21_READ_ONLY');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_business_details_require_documents_permission_and_case_read_right(): void
    {
        $f = $this->fixture();
        $d = $this->save($f);
        $this->record($f, $d, (string) Str::uuid())->assertCreated();
        $this->grantOnly($f['actor_id'], ['warranty.view', 'warranty.component_issue']);
        $this->read($this->detailUrl($f, $d), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.business_link_present', true)->assertJsonPath('data.business_document_id', null)
            ->assertJsonPath('data.business_status', null)->assertJsonPath('data.can_open_business', false)
            ->assertJsonPath('data.resume_policy', 'RECORDED_RESULT_UNAVAILABLE');
        $this->grantOnly($f['actor_id'], ['warranty.component_issue']);
        $this->read($this->detailUrl($f, $d), $this->headers($f['token']))->assertForbidden();
        $this->read($this->listUrl($f), $this->headers($f['token']))->assertForbidden();
    }

    public function test_unresolved_tenant_dimension_fails_closed_before_totals(): void
    {
        $f = $this->fixture();
        $this->save($f);
        Schema::table(P03::TABLE, fn ($table) => $table->string('tenant_id')->nullable());
        try {
            $this->read($this->listUrl($f), $this->headers($f['token']))->assertStatus(503)
                ->assertJsonPath('error.code', 'WARRANTY_TENANCY_UNRESOLVED');
        } finally {
            Schema::table(P03::TABLE, fn ($table) => $table->dropColumn('tenant_id'));
        }
    }

    public function test_stable_sort_and_empty_page_do_not_change_authoritative_total(): void
    {
        $f = $this->fixture();
        $a = $this->save($f);
        $b = $this->save($f);
        DB::table(P03::TABLE)->whereIn('document_id', [$a['id'], $b['id']])->update(['updated_at' => '2026-10-10T00:00:00.000000Z']);
        $expected = [$a['id'], $b['id']];
        rsort($expected, SORT_STRING);
        $first = $this->read($this->listUrl($f, ['per_page' => 1, 'page' => 1]), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.has_more', true)->assertJsonPath('data.0.document_id', $expected[0]);
        $this->assertSame(1, $first->json('meta.page'));
        $this->read($this->listUrl($f, ['per_page' => 1, 'page' => 2]), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.0.document_id', $expected[1])->assertJsonPath('meta.has_more', false);
        $this->read($this->listUrl($f, ['per_page' => 1, 'page' => 3]), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonCount(0, 'data');
    }

    private function fixture(): array
    {
        $auth = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $user = User::findOrFail($auth['__user_id']);
        $secret = bin2hex(random_bytes(16));
        $user->forceFill(['email' => 'p21-'.Str::uuid().'@example.test', 'password_hash' => Hash::make($secret), 'must_change_password' => false])->save();
        $this->headers('', false);
        $login = $this->browserLogin($user->email, $secret)->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id],
            $this->headers($login->json('data.access_token'), false))->assertOk();
        $token = $selected->json('data.access_token');
        $me = $this->read('/api/v1/auth/me', $this->headers($token, false))->assertOk();
        $sku = $this->componentSkuFixture('HN-P21-'.Str::upper(Str::random(8)), serialTracked: false);
        $raw = 'BOX-'.$sku->sku_code.'-0001';
        $headers = $this->headers($token, false);
        $inbound = $this->createInboundHeader($headers, (string) $warehouse->id, ['source_name' => 'P21 isolated receipt']);
        $received = $this->receiveComponentQuantity($headers, $inbound['document_id'], (string) $sku->sku_id, 7, $inbound['version'], $raw);
        $this->postInboundReceipt($headers, $inbound['document_id'], $received['version'], (string) Str::uuid())->assertOk();
        $case = $this->postJson('/api/v1/warranty-cases', ['customer_name' => 'Isolated P21 customer', 'customer_phone' => '0900000021',
            'description' => 'Private resume test', 'missing_code_reason' => 'Isolated test input', 'manual_product_description' => 'Test product'],
            $this->headers($token, false) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $caseId = $this->moveCaseToChecking($this->headers($token, false), (string) $case->json('data.id'));

        return ['token' => $token, 'secret' => $secret, 'actor_id' => (string) $user->user_id,
            'session_id' => $me->json('data.auth_session_id'), 'warehouse_id' => (string) $warehouse->id,
            'case_id' => $caseId, 'raw_code' => $raw];
    }

    private function save(array $f, ?array $codes = null): array
    {
        $d = ['id' => (string) Str::uuid(), 'scan_id' => (string) Str::uuid(), 'key' => (string) Str::uuid()];
        $d['body'] = ['warehouse_id' => $f['warehouse_id'], 'operation' => 'COMPONENT_ISSUE', 'scan_session_id' => $d['scan_id'],
            'codes' => $codes ?? [['raw_code' => $f['raw_code'], 'quantity' => 3, 'scan_source' => 'CAMERA']],
            'form' => ['p19' => ['contract' => P19::VERSION, 'warranty_case_id' => $f['case_id']]]];
        $this->putJson(self::DRAFT.'/'.$d['id'], $d['body'], $this->headers($f['token'], false) + ['If-Match' => '"0"', 'Idempotency-Key' => $d['key']])->assertCreated();

        return $d;
    }

    private function record(array $f, array $d, string $key)
    {
        return $this->postJson(self::RECORD, ['warehouse_id' => $f['warehouse_id'], 'warranty_case_id' => $f['case_id'],
            'post_immediately' => true, 'items' => $d['body']['codes']], $this->headers($f['token'], false) + [
                P19::HEADER => P19::VERSION, 'Idempotency-Key' => $key, 'X-Draft-ID' => $d['id'],
                'X-Draft-Scan-Session-ID' => $d['scan_id'], 'X-Draft-Version' => '1',
            ]);
    }

    /** Laravel getJson serializes [] as a body; P21 deliberately forbids GET bodies. */
    private function read(string $url, array $headers)
    {
        return $this->get($url, ['Accept' => 'application/json', ...$headers]);
    }

    private function listUrl(array $f, array $extra = []): string
    {
        return self::DRAFT.'?'.http_build_query(array_replace(['warehouse_id' => $f['warehouse_id'], 'operation' => 'COMPONENT_ISSUE'], $extra));
    }

    private function detailUrl(array $f, array $d): string
    {
        return self::DRAFT.'/'.$d['id'].'?'.http_build_query(['warehouse_id' => $f['warehouse_id'], 'operation' => 'COMPONENT_ISSUE', 'scan_session_id' => $d['scan_id']]);
    }

    private function receiptUrl(array $f, array $d, string $key, string $command): string
    {
        return self::DRAFT.'/'.$d['id'].'/requests/'.$key.'?'.http_build_query(['warehouse_id' => $f['warehouse_id'],
            'operation' => 'COMPONENT_ISSUE', 'scan_session_id' => $d['scan_id'], 'command' => $command]);
    }

    private function headers(string $token, bool $p21 = true): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token] + ($p21 ? [P21::HEADER => P21::CONTRACT] : []);
    }

    private function grantOnly(string $id, array $keys): void
    {
        $user = User::findOrFail($id);
        $user->roles()->detach();
        $role = Role::create(['role_code' => 'P21-'.Str::uuid(), 'role_name' => 'Isolated P21 role', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        foreach ($keys as $key) {
            $p = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $p->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $user->forceFill(['version' => (int) $user->version + 1])->save();
    }

    private function snapshot(): array
    {
        $out = [];
        foreach ([P03::TABLE, P03::RECEIPTS, 'component_issue_documents', 'component_issue_lines', 'component_issue_items',
            'scan_event', 'stock_movements', 'inventory_balances', 'container_items'] as $table) {
            $rows = DB::table($table)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $out[$table] = $rows;
        }

        return $out;
    }
}
