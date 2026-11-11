<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Services\StockDocumentService;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class P05SaveNumberingTest extends TestCase
{
    use InteractsWithBrowserSession, PreparesOutboundFixtures, RefreshDatabase;

    private const BASE = '/api/v1/mini-app/drafts';

    private const HEADER = 'X-Outbound-Numbering-Contract';

    private const CONTRACT = 'p05-save-numbering-v1';

    public function test_context_advertises_manual_save_numbering_without_creating_a_number(): void
    {
        $f = $this->actor();
        $this->getJson('/api/v1/mini-app/outbound/context', $this->headers($f))->assertOk()
            ->assertJsonPath('data.document_number_assignment', 'WITH_DRAFT_SAVE')
            ->assertJsonPath('data.numbering.contract', self::CONTRACT)
            ->assertJsonPath('data.numbering.mode', 'MANUAL_CREATE_ONLY')
            ->assertJsonPath('data.numbering.format', 'PX-YYYYMMDDHHmmss')
            ->assertJsonPath('data.numbering.timezone', 'Asia/Ho_Chi_Minh')
            ->assertJsonPath('data.numbering.allowed', true)
            ->assertJsonPath('data.numbering.source_linked_policy', 'KEEP_SOURCE_DOCUMENT_NO');
        $this->assertDatabaseCount('outbound_draft_numbers', 0);
        $this->assertNeutral();
    }

    public function test_partial_save_commits_one_number_and_original_receipt_with_no_business_or_stock_writes(): void
    {
        $f = $this->actor();
        $id = (string) Str::uuid();
        $body = $this->body($f);
        $key = (string) Str::uuid();
        $saved = $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 0, $key))->assertCreated()
            ->assertJsonPath('data.document.numbering_status', 'ASSIGNED')->assertJsonPath('data.document.business_document_id', null);
        $number = $saved->json('data.document.document_no');
        $this->assertMatchesRegularExpression('/^PX-[0-9]{14}$/D', (string) $number);
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 0, $key))->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.document.document_no', $number);
        $receipt = DB::table('mini_app_draft_receipts')->where('request_id', $key)->first();
        $this->assertSame($number, data_get(json_decode($receipt->response_body, true), 'data.document.document_no'));
        $this->getJson($this->receiptUrl($f, $id, $body, $key), $this->headers($f))->assertOk()
            ->assertJsonPath('data.response.data.document.document_no', $number)
            ->assertJsonPath('data.document.document_no', $number);
        $this->assertDatabaseCount('outbound_draft_numbers', 1);
        $this->assertNeutral();
    }

    public function test_metadata_edit_detail_refresh_and_old_replay_keep_one_number_and_original_version(): void
    {
        $f = $this->actor();
        [$id, $body, $key, $number] = $this->save($f);
        $changed = $body;
        $changed['form']['p05_create'] = ['recipient' => 'TEST recipient', 'address' => 'TEST address changed', 'name' => ''];
        $this->putJson(self::BASE.'/'.$id, $changed, $this->headers($f, 1, (string) Str::uuid()))->assertOk()
            ->assertJsonPath('data.document.document_no', $number)->assertJsonPath('data.document.version', 2);
        $this->getJson($this->detailUrl($f, $id, $body), $this->headers($f))->assertOk()
            ->assertJsonPath('data.document_no', $number)->assertJsonPath('data.form.p05_create.address', 'TEST address changed');
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 0, $key))->assertCreated()
            ->assertJsonPath('data.document.document_no', $number)->assertJsonPath('data.document.version', 1);
        $this->resetAuth();
        $refresh = $this->browserRefresh($f['cookie'])->assertOk();
        $f['token'] = $refresh->json('data.access_token');
        $this->getJson($this->detailUrl($f, $id, $body), $this->headers($f))->assertOk()->assertJsonPath('data.document_no', $number);
        $this->assertDatabaseCount('outbound_draft_numbers', 1);
        $this->assertNeutral();
    }

    public function test_record_consumes_exact_reserved_number_once_without_increasing_reservations_or_decreasing_stock(): void
    {
        $f = $this->actor();
        $sku = $this->productSkuFixture('P05-NUM-'.Str::upper(Str::random(8)));
        $this->seedAvailableBalance($f['warehouse']->id, $sku->sku_id, 1);
        $item = $this->seedItemInStock($sku->sku_id);
        $code = 'P05-NUM-ITEM-'.Str::upper(Str::random(8));
        $this->seedScanCode($item, $code);
        $raw = 'HN1|SKU='.$sku->sku_code.'|ITEM='.$code;
        [$id, $body, , $number] = $this->save($f, [['raw_code' => $raw, 'scan_source' => 'MANUAL']]);
        $stockBefore = DB::table('inventory_balances')->get()->toJson();
        $key = (string) Str::uuid();
        $record = ['warehouse_id' => $f['warehouse']->id, 'recipient_name' => 'Approved isolated TEST recipient',
            'expected_total_qty' => 1, 'items' => $body['codes']];
        // The reservation cannot be bypassed by dropping the opt-in RECORD header.
        $headers = $this->headers($f, null, $key, false) + ['X-Draft-ID' => $id, 'X-Draft-Scan-Session-ID' => $body['scan_session_id'], 'X-Draft-Version' => '1'];
        $r = $this->postJson('/api/v1/mini-app/outbound/record', $record, $headers)->assertCreated()
            ->assertJsonPath('data.doc_no', $number)->assertJsonPath('data.name', null)
            ->assertJsonPath('data.stock_effect', 'NONE');
        $business = $r->json('data.document_id');
        $this->assertNotSame($id, $business);
        $this->postJson('/api/v1/mini-app/outbound/record', $record, $this->headers($f, null, $key, false) + array_diff_key($headers, ['Authorization' => true]))
            ->assertCreated()->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.document_id', $business);
        $this->assertDatabaseCount('stock_documents', 1);
        $this->assertDatabaseCount('outbound_draft_numbers', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame($stockBefore, DB::table('inventory_balances')->get()->toJson());
        $this->assertDatabaseHas('stock_documents', ['id' => $business, 'doc_no' => $number]);
    }

    public function test_legacy_receipt_and_get_do_not_allocate_until_an_explicit_new_save(): void
    {
        $f = $this->actor();
        $id = (string) Str::uuid();
        $body = $this->body($f);
        $key = (string) Str::uuid();
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 0, $key, false))->assertCreated()->assertJsonMissingPath('data.document.document_no');
        $this->getJson($this->detailUrl($f, $id, $body), $this->headers($f))->assertOk()->assertJsonPath('data.document_no', null);
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 0, $key))->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.document.document_no', null);
        $this->assertDatabaseCount('outbound_draft_numbers', 0);
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 1, (string) Str::uuid()))->assertOk()->assertJsonPath('data.document.numbering_status', 'ASSIGNED');
        $this->assertDatabaseCount('outbound_draft_numbers', 1);
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 0, $key))->assertCreated()->assertJsonPath('data.document.document_no', null);
        $this->assertNeutral();
    }

    public function test_cancel_keeps_number_and_unknown_get_is_check_only_without_allocating(): void
    {
        $f = $this->actor();
        [$id, $body, $key, $number] = $this->save($f);
        $this->getJson($this->receiptUrl($f, $id, $body, (string) Str::uuid()), $this->headers($f))->assertOk()
            ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.retry', 'CHECK_ONLY')->assertJsonPath('data.new_document_allowed', false);
        $this->postJson(self::BASE.'/'.$id.'/cancel', ['warehouse_id' => $f['warehouse']->id, 'operation' => 'OUTBOUND',
            'scan_session_id' => $body['scan_session_id'], 'confirmed_request_id' => $key, 'reason' => 'Cancel isolated numbered draft'],
            $this->headers($f, 1, (string) Str::uuid()))->assertOk()->assertJsonPath('data.document.document_no', $number);
        [, , , $next] = $this->save($f);
        $this->assertNotSame($number, $next);
        $this->assertDatabaseHas('outbound_draft_numbers', ['draft_id' => $id, 'doc_no' => $number]);
        $this->assertNeutral();
    }

    public function test_forged_source_fields_wrong_operation_and_key_or_version_conflicts_do_not_allocate(): void
    {
        $f = $this->actor();
        $body = $this->body($f);
        $this->putJson(self::BASE.'/'.Str::uuid(), $body + ['doc_no' => 'PX-FORGED'], $this->headers($f, 0, (string) Str::uuid()))->assertStatus(422);
        $source = $body;
        $source['form']['p05_source'] = ['document_id' => (string) Str::uuid(), 'version' => 1];
        $this->putJson(self::BASE.'/'.Str::uuid(), $source, $this->headers($f, 0, (string) Str::uuid()))->assertConflict();
        $body['operation'] = 'INBOUND';
        $this->putJson(self::BASE.'/'.Str::uuid(), $body, $this->headers($f, 0, (string) Str::uuid()))->assertStatus(422);
        [$id, $saved, $key] = $this->save($f);
        $saved['title'] = 'Changed';
        $this->putJson(self::BASE.'/'.$id, $saved, $this->headers($f, 0, $key))->assertConflict();
        $this->putJson(self::BASE.'/'.$id, $saved, $this->headers($f, 2, (string) Str::uuid()))->assertStatus(412);
        $this->assertDatabaseCount('outbound_draft_numbers', 1);
        $this->assertNeutral();
    }

    public function test_role_name_alone_never_grants_numbering_and_wrong_owner_is_not_resumed(): void
    {
        $f = $this->actor();
        [$id, $body] = $this->save($f);
        $other = $this->actor($f['warehouse']);
        $this->getJson($this->detailUrl($f, $id, $body), $this->headers($other))->assertNotFound();
        $other['role']->update(['role_code' => 'SUPER_ADMIN']);
        DB::table('role_permission')->where('role_id', $other['role']->role_id)->delete();
        $other['user']->increment('version');
        $this->putJson(self::BASE.'/'.Str::uuid(), $this->body($other), $this->headers($other, 0, (string) Str::uuid()))->assertForbidden();
        $this->assertDatabaseCount('outbound_draft_numbers', 1);
        $this->assertNeutral();
    }

    public function test_number_storage_failure_rolls_back_draft_receipt_and_counter(): void
    {
        $f = $this->actor();
        DB::listen(function (QueryExecuted $q): void {
            if (str_starts_with(strtolower($q->sql), 'insert into') && str_contains($q->sql, 'outbound_draft_numbers')) {
                throw new \RuntimeException('Isolated numbering storage failure');
            }
        });
        $this->putJson(self::BASE.'/'.Str::uuid(), $this->body($f), $this->headers($f, 0, (string) Str::uuid()))->assertStatus(500);
        foreach (['mini_app_drafts', 'mini_app_draft_receipts', 'outbound_daily_numbers', 'outbound_draft_numbers'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertNeutral();
    }

    public function test_opt_in_preserves_empty_object_and_nested_array_snapshot_shapes(): void
    {
        $f = $this->actor();
        $id = (string) Str::uuid();
        $body = $this->body($f);
        $body['form'] = (object) ['p05_create' => (object) [], 'groups' => [], 'nested' => (object) ['options' => (object) []]];
        $key = (string) Str::uuid();
        $r = $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 0, $key))->assertCreated()->getData();
        $this->assertInstanceOf(\stdClass::class, $r->data->document->form->p05_create);
        $this->assertIsArray($r->data->document->form->groups);
        $this->assertInstanceOf(\stdClass::class, $r->data->document->form->nested->options);
        $detail = $this->getJson($this->detailUrl($f, $id, $body), $this->headers($f))->assertOk()->getData();
        $this->assertInstanceOf(\stdClass::class, $detail->data->form->p05_create);
        $list = $this->getJson(self::BASE.'?'.http_build_query(['warehouse_id' => $f['warehouse']->id, 'operation' => 'OUTBOUND']), $this->headers($f))->assertOk()->getData();
        $this->assertInstanceOf(\stdClass::class, $list->data[0]->form->p05_create);
        $this->assertSame($detail->data->document_no, $list->data[0]->document_no);
        $reconcile = $this->getJson($this->receiptUrl($f, $id, $body, $key), $this->headers($f))->assertOk()->getData();
        $this->assertInstanceOf(\stdClass::class, $reconcile->data->response->data->document->form->p05_create);
    }

    public function test_vietnam_date_boundary_does_not_renumber_a_saved_draft(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03T16:59:50Z'));
        $f = $this->actor();
        [$id, $body, , $first] = $this->save($f);
        $this->assertSame('PX-20261103235950', $first);
        $this->travelTo(Carbon::parse('2026-11-03T17:00:01Z'));
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 1, (string) Str::uuid()))->assertOk()->assertJsonPath('data.document.document_no', $first);
        [, , , $next] = $this->save($f);
        $this->assertSame('PX-20261104000001', $next);
        $this->assertNeutral();
    }

    public function test_actual_grant_withdrawal_blocks_save_and_numbered_record(): void
    {
        $f = $this->actor();
        [$id, $body] = $this->save($f);
        DB::table('role_permission')->where('role_id', $f['role']->role_id)
            ->where('permission_id', Permission::where('permission_key', 'outbound.scan')->value('permission_id'))->delete();
        $f['user']->increment('version');
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 1, (string) Str::uuid()))->assertForbidden();
        $this->postJson('/api/v1/mini-app/outbound/record', [], $this->headers($f, null, (string) Str::uuid(), false)
            + ['X-Draft-ID' => $id, 'X-Draft-Scan-Session-ID' => $body['scan_session_id'], 'X-Draft-Version' => '1'])->assertForbidden();
        $this->assertDatabaseCount('outbound_draft_numbers', 1);
        $this->assertNeutral();
    }

    public function test_new_auth_family_or_foreign_warehouse_does_not_take_over_numbered_draft(): void
    {
        $f = $this->actor();
        [$id, $body] = $this->save($f);
        $foreign = $body;
        $foreign['warehouse_id'] = (string) Str::uuid();
        $this->putJson(self::BASE.'/'.$id, $foreign, $this->headers($f, 1, (string) Str::uuid()))->assertForbidden();
        $this->resetAuth();
        $new = $this->browserLogin($f['user']->email, 'fixture-only')->assertOk()->json('data.access_token');
        $new = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $f['warehouse']->id], ['Authorization' => 'Bearer '.$new])->assertOk()->json('data.access_token');
        $f['token'] = $new;
        $this->getJson($this->detailUrl($f, $id, $body), $this->headers($f))->assertNotFound();
        $this->assertDatabaseCount('outbound_draft_numbers', 1);
        $this->assertNeutral();
    }

    public function test_web_creation_and_header_patch_cannot_take_a_reserved_px_number(): void
    {
        $f = $this->actor();
        [, , , $number] = $this->save($f);
        try {
            app(StockDocumentService::class)->create('issue', ['doc_no' => $number, 'src_warehouse_id' => $f['warehouse']->id, 'lines' => []], $f['user']->user_id);
            $this->fail('A reserved PX must not be reused by Web.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
        }
        $p = Permission::firstOrCreate(['permission_key' => 'inbound.create'], ['permission_name' => 'inbound.create', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        $f['role']->permissions()->attach($p->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        $f['user']->increment('version');
        $this->postJson('/api/v1/inbound-documents', ['dst_warehouse_id' => $f['warehouse']->id, 'doc_no' => $number], $this->headers($f, null, (string) Str::uuid(), false))
            ->assertConflict()->assertJsonPath('error.code', 'DOCUMENT_NUMBER_RESERVED');
        $this->assertNeutral();
    }

    public function test_numbering_record_header_requires_the_original_draft_identity(): void
    {
        $f = $this->actor();
        $this->postJson('/api/v1/mini-app/outbound/record', [], $this->headers($f, null, (string) Str::uuid()))
            ->assertConflict()->assertJsonPath('error.code', 'P05_DRAFT_IDENTITY_REQUIRED');
        $this->assertNeutral();
    }

    public function test_numbered_draft_cannot_record_against_a_web_source_even_if_the_header_is_dropped(): void
    {
        $f = $this->actor();
        [$id, $body] = $this->save($f);
        $this->postJson('/api/v1/mini-app/outbound/record', ['warehouse_id' => $f['warehouse']->id,
            'source_document_id' => (string) Str::uuid(), 'source_version' => 1, 'expected_total_qty' => 1, 'items' => []],
            $this->headers($f, null, (string) Str::uuid(), false) + ['X-Draft-ID' => $id, 'X-Draft-Scan-Session-ID' => $body['scan_session_id'], 'X-Draft-Version' => '1'])
            ->assertConflict()->assertJsonPath('error.code', 'P05_NUMBER_SOURCE_LINKED_UNSUPPORTED');
        $this->assertNeutral();
    }

    public function test_tracked_shift_preserves_identity_fence_and_original_key_reconcile_for_numbered_save(): void
    {
        config(['p14.shift_enabled' => true]);
        $f = $this->actor();
        $shift = $this->postJson('/api/v1/mini-app/work-shifts/start', ['warehouse_id' => $f['warehouse']->id],
            $this->headers($f, null, (string) Str::uuid(), false))->assertCreated()->json('data.shift_id');
        $id = (string) Str::uuid();
        $key = (string) Str::uuid();
        $trace = (string) Str::uuid();
        $body = $this->body($f);
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 0, $key))->assertStatus(428);
        $bind = ['X-Work-Shift-ID' => $shift, 'X-Request-ID' => $trace];
        $r = $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 0, $key) + $bind)->assertCreated();
        $number = $r->json('data.document.document_no');
        $this->assertNotEmpty($number);
        $this->getJson($this->receiptUrl($f, $id, $body, $key), $this->headers($f))->assertOk()
            ->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.response.data.document.document_no', $number);
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 0, $key) + $bind)->assertConflict()
            ->assertJsonPath('error.code', 'OWNER_RECONCILE_REQUIRED');
        $this->assertDatabaseCount('p14_shift_operations', 1);
        $this->assertDatabaseCount('outbound_draft_numbers', 1);
        $this->assertNeutral();
    }

    public function test_dropping_numbering_header_does_not_bypass_grant_revocation_on_existing_numbered_draft(): void
    {
        $f = $this->actor();
        [$id, $body, $key] = $this->save($f);
        $f['role']->update(['role_code' => 'SUPER_ADMIN']);
        DB::table('role_permission')->where('role_id', $f['role']->role_id)->delete();
        $f['user']->increment('version');
        $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 0, $key, false))->assertForbidden();
        $this->getJson($this->detailUrl($f, $id, $body), $this->headers($f, null, null, false))->assertForbidden();
        $this->assertDatabaseCount('outbound_draft_numbers', 1);
        $this->assertNeutral();
    }

    public function test_allocator_skips_existing_canonical_sequence_and_does_not_truncate_after_9999(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03T03:00:00Z'));
        $f = $this->actor();
        $sku = $this->productSkuFixture('P05-SEQ-'.Str::upper(Str::random(6)));
        $request = $this->outboundRequestFixture($sku->sku_id, 1, 'P05-SEQ-REQ-'.Str::upper(Str::random(8)));
        $doc = $this->outboundDocumentFixture($f['warehouse']->id, $request);
        DB::table('stock_documents')->where('id', $doc['document_id'])->update(['doc_no' => 'PX-20261103100005']);
        [, , , $number] = $this->save($f);
        $this->assertSame('PX-20261103100006', $number);
        $this->assertDatabaseCount('stock_documents', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function actor(?object $warehouse = null): array
    {
        $warehouse ??= $this->ensureActiveWarehouse('KHO-TONG-HN');
        $suffix = Str::lower(Str::random(8));
        $user = User::create(['username' => 'p05num_'.$suffix, 'email' => 'p05num_'.$suffix.'@wms-hoanam.test', 'display_name' => 'Isolated numbering TEST', 'password_hash' => Hash::make('fixture-only'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P05NUM_'.$suffix, 'role_name' => 'TEST scoped role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (['outbound.view', 'outbound.request.manual_create', 'outbound.scan', 'outbound.cancel'] as $key) {
            $p = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            $role->permissions()->attach($p->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        }
        $this->resetAuth();
        $login = $this->browserLogin($user->email, 'fixture-only')->assertOk();
        $cookie = $this->refreshTokenFromCookie($login);
        $token = $login->json('data.access_token');
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], ['Authorization' => 'Bearer '.$token])->assertOk()->json('data.access_token');

        return compact('warehouse', 'user', 'role', 'token', 'cookie');
    }

    private function body(array $f, array $codes = []): array
    {
        return ['warehouse_id' => $f['warehouse']->id, 'operation' => 'OUTBOUND', 'scan_session_id' => (string) Str::uuid(), 'title' => null, 'codes' => $codes,
            'form' => ['p05_create' => ['name' => '', 'recipient' => '', 'address' => '', 'plannedInput' => '1']]];
    }

    private function save(array $f, array $codes = []): array
    {
        $id = (string) Str::uuid();
        $body = $this->body($f, $codes);
        $key = (string) Str::uuid();
        $r = $this->putJson(self::BASE.'/'.$id, $body, $this->headers($f, 0, $key))->assertCreated();

        return [$id, $body, $key, $r->json('data.document.document_no')];
    }

    private function headers(array $f, ?int $version = null, ?string $key = null, bool $numbered = true): array
    {
        $this->resetAuth();
        $h = ['Authorization' => 'Bearer '.$f['token']];
        if ($version !== null) {
            $h['If-Match'] = '"'.$version.'"';
        }
        if ($key !== null) {
            $h['Idempotency-Key'] = $key;
        }
        if ($numbered) {
            $h[self::HEADER] = self::CONTRACT;
        }

        return $h;
    }

    private function detailUrl(array $f, string $id, array $body): string
    {
        return self::BASE.'/'.$id.'?'.http_build_query(['warehouse_id' => $f['warehouse']->id, 'operation' => 'OUTBOUND', 'scan_session_id' => $body['scan_session_id']]);
    }

    private function receiptUrl(array $f, string $id, array $body, string $key): string
    {
        return self::BASE.'/'.$id.'/requests/'.$key.'?'.http_build_query(['warehouse_id' => $f['warehouse']->id, 'operation' => 'OUTBOUND', 'scan_session_id' => $body['scan_session_id'], 'command' => 'SAVE']);
    }

    private function assertNeutral(): void
    {
        foreach (['stock_documents', 'outbound_requests', 'outbound_request_imports', 'stock_movements', 'outbound_item_matches', 'inventory_balances'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
