<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\MiniAppDraftService;
use Modules\Warehouse\Services\P19LinkedComponentIssue as P19;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\OpensWarrantyCaseForComponentIssue;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Disposable test DB only. No live case, chip, customer stock or credentials. */
class P19LinkedComponentIssueTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use InteractsWithBrowserSession;
    use OpensWarrantyCaseForComponentIssue;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const RECORD = '/api/v1/mini-app/warranty-component-issues/record';

    private const DRAFT = '/api/v1/mini-app/drafts/';

    public function test_save_replay_resume_and_resolve_do_not_post_or_change_stock(): void
    {
        $f = $this->fixture();
        $draft = $this->save($f);
        $this->putJson(self::DRAFT.$draft['id'], $draft['body'], $this->headers($f['token']) + ['Idempotency-Key' => $draft['save_key'], 'If-Match' => '"0"'])
            ->assertCreated()->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.document.version', 1);
        $this->getJson($this->draftUrl($f, $draft), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.business_document_id', null)
            ->assertJsonPath('data.stock_effect', 'NONE')->assertJsonPath('data.codes.0.quantity', 3);
        $this->postJson('/api/v1/mini-app/warranty-component-issues/resolve-code', [
            'warehouse_id' => $f['warehouse_id'], 'warranty_case_id' => $f['case_id'], 'raw_code' => $f['raw_code'],
        ], $this->headers($f['token']))->assertOk()->assertJsonPath('data.available_qty', 7)->assertJsonPath('data.stock_effect', 'NONE');
        $this->unchanged($f);
    }

    public function test_linked_post_replay_and_reconcile_preserve_one_business_id_and_full_receipt(): void
    {
        $f = $this->fixture();
        $draft = $this->save($f);
        $key = (string) Str::uuid();
        $first = $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft, $key))->assertCreated()
            ->assertJsonPath('data.p19_result.contract', P19::VERSION)->assertJsonPath('data.posted', true)
            ->assertJsonPath('data.p19_result.quantity_total', 3)->assertJsonPath('data.p19_result.stock_effect', 'DECREASE')
            ->assertJsonPath('data.p19_result.operation_key', $key)->assertJsonPath('data.p19_result.auth_session_id', $f['session_id'])
            ->assertJsonPath('data.p19_result.actor_id', $f['actor_id'])->assertJsonPath('data.p19_result.draft_id', $draft['id'])
            ->assertJsonPath('data.p19_result.lines.0.required_qty', 3)->assertJsonPath('data.p19_result.lines.0.scanned_qty', 3)
            ->assertJsonPath('data.p19_result.movements.0.qty_delta', -3)->assertJsonPath('meta.p03_draft.receipt.command', 'RECORD');
        $id = $first->json('data.component_issue_document_id');
        $this->assertSame($id, $first->json('data.p19_result.business_document_id'));
        $this->assertNotNull($first->json('data.p19_result.posted_at'));
        $retry = $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft, $key))->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('data.p19_result'), $retry->json('data.p19_result'));
        $check = $this->getJson($this->receiptUrl($f, $draft, $key), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.receipt.request_id', $key)
            ->assertJsonPath('data.receipt.document_id', $draft['id'])->assertJsonPath('data.document.business_document_id', $id)
            ->assertJsonPath('data.document.business_status', 'POSTED')->assertJsonPath('data.document.stock_effect', 'NONE');
        $this->assertSame($first->json('data.p19_result'), $check->json('data.response.data.p19_result'));
        $before = DB::table('scan_event')->count();
        $this->getJson('/api/v1/mini-app/warranty-component-issues/'.$id, $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.document.id', $id)->assertJsonPath('data.document.status', 'POSTED')
            ->assertJsonPath('data.lines.0.required_qty', 3)->assertJsonPath('data.lines.0.scanned_qty', 3);
        $this->assertSame($before, DB::table('scan_event')->count());
        $this->assertDatabaseCount('component_issue_documents', 1);
        $this->assertSame(4, $this->balance($f));
        $this->assertSame(1, DB::table('stock_movements')->where('source_type', 'COMPONENT_ISSUE_DOCUMENT')->count());
    }

    public function test_unknown_is_read_only_not_a_negative_receipt(): void
    {
        $f = $this->fixture();
        $draft = $this->save($f);
        $this->getJson($this->receiptUrl($f, $draft, (string) Str::uuid()), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.receipt', null)
            ->assertJsonPath('data.new_document_allowed', false)->assertJsonPath('data.cancel_allowed', false);
        $this->unchanged($f);
    }

    public function test_snapshot_quantity_metadata_and_order_must_match_before_owner_writes(): void
    {
        $f = $this->fixture();
        $draft = $this->save($f);
        $body = $this->body($f);
        $body['items'][0]['quantity'] = 2;
        $this->postJson(self::RECORD, $body, $this->recordHeaders($f, $draft))->assertConflict()->assertJsonPath('error.code', 'P19_DRAFT_ITEMS_MISMATCH');
        $body = $this->body($f);
        $body['note'] = 'Not in the saved snapshot';
        $this->postJson(self::RECORD, $body, $this->recordHeaders($f, $draft))->assertConflict()->assertJsonPath('error.code', 'P19_DRAFT_METADATA_MISMATCH');
        $body = $this->body($f);
        $body['items'][0]['scan_source'] = 'SCANNER';
        $this->postJson(self::RECORD, $body, $this->recordHeaders($f, $draft))->assertConflict()->assertJsonPath('error.code', 'P19_DRAFT_ITEMS_MISMATCH');
        $this->unchanged($f);
    }

    public function test_false_record_standalone_wrong_version_and_stripped_contract_are_blocked(): void
    {
        $f = $this->fixture();
        $draft = $this->save($f);
        $body = $this->body($f);
        $body['post_immediately'] = false;
        $this->postJson(self::RECORD, $body, $this->recordHeaders($f, $draft))->assertUnprocessable()->assertJsonPath('error.code', 'P19_POST_CONFIRMATION_REQUIRED');
        $this->postJson(self::RECORD, $this->body($f), $this->headers($f['token']) + [P19::HEADER => P19::VERSION, 'Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('error.code', 'P19_LINKED_DRAFT_REQUIRED');
        $headers = $this->recordHeaders($f, $draft);
        $headers['X-Draft-Version'] = '9';
        $this->postJson(self::RECORD, $this->body($f), $headers)->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');
        $headers = $this->recordHeaders($f, $draft);
        unset($headers[P19::HEADER]);
        $this->postJson(self::RECORD, $this->body($f), $headers)->assertConflict()->assertJsonPath('error.code', 'P19_CONTRACT_REQUIRED');
        $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft) + ['If-Match' => '"1"'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'P19_VERSION_HEADER_INVALID');
        $this->unchanged($f);
    }

    public function test_case_status_is_revalidated_and_successful_receipt_does_not_repost_later(): void
    {
        $f = $this->fixture();
        $draft = $this->save($f);
        DB::table('warranty_case')->where('warranty_case_id', $f['case_id'])->update(['status' => 'COMPLETED']);
        $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft))->assertUnprocessable()->assertJsonPath('error.code', 'WARRANTY_CASE_STATUS_NOT_ALLOWED');
        $this->unchanged($f);
        DB::table('warranty_case')->where('warranty_case_id', $f['case_id'])->update(['status' => 'REPAIRING']);
        $key = (string) Str::uuid();
        $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft, $key))->assertCreated();
        DB::table('warranty_case')->where('warranty_case_id', $f['case_id'])->update(['status' => 'COMPLETED']);
        $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft, $key))->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(4, $this->balance($f));
        $this->assertDatabaseCount('component_issue_documents', 1);
    }

    public function test_case_must_have_persisted_ownership_in_the_draft_warehouse(): void
    {
        $f = $this->fixture();
        $draft = $this->save($f);
        $other = Warehouse::create(['code' => 'P19-OTHER-'.Str::random(6), 'name' => 'Isolated other warehouse', 'status' => 'INACTIVE']);
        DB::table('warranty_case_warehouse_owners')->where('warranty_case_id', $f['case_id'])->update(['warehouse_id' => $other->id]);
        $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft))->assertNotFound()->assertJsonPath('error.code', 'WARRANTY_CASE_NOT_FOUND');
        $this->unchanged($f);
    }

    public function test_new_login_cannot_take_over_old_draft_even_for_same_actor_and_warehouse(): void
    {
        $f = $this->fixture();
        $draft = $this->save($f);
        $user = User::findOrFail($f['actor_id']);
        $this->headers($f['token']);
        $login = $this->browserLogin($user->email, $f['secret'])->assertOk();
        $token = $login->json('data.access_token');
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $f['warehouse_id']], $this->headers($token))->assertOk();
        $f['token'] = $selected->json('data.access_token');
        $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft))->assertNotFound()->assertJsonPath('error.code', 'DRAFT_NOT_FOUND');
        $this->unchanged($f);
    }

    public function test_revoked_permission_cannot_post_or_replay(): void
    {
        $f = $this->fixture();
        $draft = $this->save($f);
        // Match the IAM/P03 revocation contract: grants are cached by user.version.
        $user = User::findOrFail($f['actor_id']);
        $user->roles()->detach();
        $user->forceFill(['version' => (int) $user->version + 1])->save();
        $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft))->assertForbidden();
        $this->unchanged($f);
        // A successful historical receipt also cannot bypass later revocation.
        $g = $this->fixture();
        $posted = $this->save($g);
        $key = (string) Str::uuid();
        $this->postJson(self::RECORD, $this->body($g), $this->recordHeaders($g, $posted, $key))->assertCreated();
        $user = User::findOrFail($g['actor_id']);
        $user->roles()->detach();
        $user->forceFill(['version' => (int) $user->version + 1])->save();
        $this->postJson(self::RECORD, $this->body($g), $this->recordHeaders($g, $posted, $key))->assertForbidden();
        $this->assertSame(4, $this->balance($g));
        $this->assertDatabaseCount('component_issue_documents', 1);
    }

    public function test_box_aliases_cannot_withdraw_more_than_one_container_total(): void
    {
        $f = $this->fixture();
        $rows = $this->body($f)['items'];
        $rows[0]['quantity'] = 4;
        $rows[] = ['raw_code' => strtolower($f['raw_code']), 'quantity' => 4, 'scan_source' => 'CAMERA'];
        $draft = $this->save($f, $rows);
        $body = $this->body($f);
        $body['items'] = $rows;
        $this->postJson(self::RECORD, $body, $this->recordHeaders($f, $draft))->assertUnprocessable()->assertJsonPath('error.code', 'COMPONENT_BOX_INSUFFICIENT');
        $this->unchanged($f);
    }

    public function test_available_balance_failure_rolls_back_box_consumption_and_document(): void
    {
        $f = $this->fixture();
        $draft = $this->save($f);
        DB::table('inventory_balances')->where('warehouse_id', $f['warehouse_id'])->where('sku_id', $f['sku_id'])
            ->update(['available_qty' => 0, 'reserved_qty' => 7]);
        $before = $this->boxQty($f);
        $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft))->assertUnprocessable();
        $this->assertSame($before, $this->boxQty($f));
        $this->assertDatabaseCount('component_issue_documents', 0);
        $this->assertSame(0, DB::table('stock_movements')->where('source_type', 'COMPONENT_ISSUE_DOCUMENT')->count());
    }

    public function test_receipt_persistence_fault_rolls_back_all_owner_and_stock_writes(): void
    {
        $f = $this->fixture();
        $draft = $this->save($f);
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, MiniAppDraftService::RECEIPTS)) {
                throw new \RuntimeException('Isolated P19 receipt persistence failure');
            }
        });
        $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft))->assertStatus(500);
        $this->unchanged($f);
        $this->assertDatabaseHas(MiniAppDraftService::TABLE, ['document_id' => $draft['id'], 'status' => 'DRAFT', 'version' => 1]);
        $this->assertDatabaseCount(MiniAppDraftService::RECEIPTS, 1);
    }

    public function test_completed_draft_cannot_be_posted_again_with_new_key_or_changed_body(): void
    {
        $f = $this->fixture();
        $draft = $this->save($f);
        $key = (string) Str::uuid();
        $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft, $key))->assertCreated();
        $body = $this->body($f);
        $body['items'][0]['quantity'] = 2;
        $this->postJson(self::RECORD, $body, $this->recordHeaders($f, $draft, $key))->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft))->assertConflict()->assertJsonPath('error.code', 'DRAFT_STATE_CONFLICT');
        $this->assertSame(4, $this->balance($f));
        $this->assertDatabaseCount('component_issue_documents', 1);
    }

    public function test_competing_saved_batches_revalidate_after_first_batch_consumes_stock(): void
    {
        $f = $this->fixture();
        $body = $this->body($f);
        $body['items'][0]['quantity'] = 5;
        $first = $this->save($f, $body['items']);
        $second = $this->save($f, $body['items']);
        $this->postJson(self::RECORD, $body, $this->recordHeaders($f, $first))->assertCreated();
        $this->postJson(self::RECORD, $body, $this->recordHeaders($f, $second))->assertUnprocessable()->assertJsonPath('error.code', 'COMPONENT_BOX_INSUFFICIENT');
        $this->assertSame(2, $this->balance($f));
        $this->assertSame(2, $this->boxQty($f));
        $this->assertDatabaseCount('component_issue_documents', 1);
    }

    public function test_item_null_availability_and_quantity_one_are_not_box_semantics(): void
    {
        $f = $this->fixture(serial: true);
        $this->postJson('/api/v1/mini-app/warranty-component-issues/resolve-code', [
            'warehouse_id' => $f['warehouse_id'], 'raw_code' => $f['raw_code'], 'warranty_case_id' => $f['case_id'],
        ], $this->headers($f['token']))->assertOk()->assertJsonPath('data.item_type', 'ITEM')->assertJsonPath('data.available_qty', null);
        $draft = $this->save($f);
        $this->postJson(self::RECORD, $this->body($f), $this->recordHeaders($f, $draft))->assertCreated()->assertJsonPath('data.p19_result.quantity_total', 1);
        $this->assertSame(0, $this->balance($f));
        $this->assertDatabaseHas('item_instances', ['item_id' => $f['item_id'], 'item_status' => 'ISSUED']);
    }

    public function test_duplicate_item_aliases_do_not_create_any_business_document(): void
    {
        $f = $this->fixture(serial: true);
        $rows = $this->body($f)['items'];
        $rows[] = ['raw_code' => strtolower($f['raw_code']), 'quantity' => 1, 'scan_source' => 'CAMERA'];
        $draft = $this->save($f, $rows);
        $body = $this->body($f);
        $body['items'] = $rows;
        $this->postJson(self::RECORD, $body, $this->recordHeaders($f, $draft))->assertConflict()->assertJsonPath('error.code', 'DUPLICATE_COMPONENT_ITEM');
        $this->unchanged($f);
    }

    private function fixture(bool $serial = false): array
    {
        $auth = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $user = User::findOrFail($auth['__user_id']);
        $secret = bin2hex(random_bytes(16));
        $user->forceFill(['email' => 'p19-'.Str::uuid().'@example.test', 'password_hash' => Hash::make($secret), 'must_change_password' => false])->save();
        $this->headers('');
        $login = $this->browserLogin($user->email, $secret)->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id], $this->headers($login->json('data.access_token')))->assertOk();
        $token = $selected->json('data.access_token');
        $me = $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk();
        $headers = $this->headers($token);
        $sku = $this->componentSkuFixture('HN-P19-'.Str::upper(Str::random(6)), serialTracked: $serial);
        $f = ['token' => $token, 'secret' => $secret, 'actor_id' => (string) $user->user_id,
            'session_id' => $me->json('data.auth_session_id'), 'warehouse_id' => (string) $warehouse->id,
            'sku_id' => (string) $sku->sku_id, 'initial_qty' => $serial ? 1 : 7, 'quantity' => $serial ? 1 : 3];
        if ($serial) {
            $f['item_id'] = $this->seedItemInStock($f['sku_id']);
            $f['raw_code'] = 'P19-ITEM-'.Str::upper(Str::random(8));
            $this->seedScanCode($f['item_id'], $f['raw_code']);
            $this->seedLedgerBackedBalance($f['warehouse_id'], $f['sku_id'], 1, $f['actor_id']);
        } else {
            $f['raw_code'] = 'BOX-'.$sku->sku_code.'-0001';
            $inbound = $this->createInboundHeader($headers, $f['warehouse_id'], ['source_name' => 'P19 isolated receipt']);
            $received = $this->receiveComponentQuantity($headers, $inbound['document_id'], $f['sku_id'], 7, $inbound['version'], $f['raw_code']);
            $this->postInboundReceipt($headers, $inbound['document_id'], $received['version'], (string) Str::uuid())->assertOk();
            $f['container_id'] = $received['container_id'];
        }
        $case = $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => 'Isolated P19 customer', 'customer_phone' => '0900000019',
            'description' => 'Isolated component contract', 'missing_code_reason' => 'Test case without identifier',
            'manual_product_description' => 'Test product',
        ], $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $f['case_id'] = $this->moveCaseToChecking($this->headers($token), (string) $case->json('data.id'));
        $this->assertDatabaseHas('warranty_case_warehouse_owners', ['warranty_case_id' => $f['case_id'], 'warehouse_id' => $f['warehouse_id']]);

        return $f;
    }

    private function body(array $f): array
    {
        return ['warehouse_id' => $f['warehouse_id'], 'warranty_case_id' => $f['case_id'], 'post_immediately' => true,
            'items' => [['raw_code' => $f['raw_code'], 'quantity' => $f['quantity'], 'scan_source' => 'CAMERA']]];
    }

    private function save(array $f, ?array $rows = null): array
    {
        $draft = ['id' => (string) Str::uuid(), 'scan_id' => (string) Str::uuid(), 'save_key' => (string) Str::uuid()];
        $draft['body'] = ['warehouse_id' => $f['warehouse_id'], 'operation' => 'COMPONENT_ISSUE', 'scan_session_id' => $draft['scan_id'],
            'codes' => $rows ?? $this->body($f)['items'], 'form' => ['p19' => ['contract' => P19::VERSION, 'warranty_case_id' => $f['case_id']]]];
        $this->putJson(self::DRAFT.$draft['id'], $draft['body'], $this->headers($f['token']) + ['Idempotency-Key' => $draft['save_key'], 'If-Match' => '"0"'])
            ->assertCreated()->assertJsonPath('data.document.version', 1);

        return $draft;
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function recordHeaders(array $f, array $draft, ?string $key = null): array
    {
        return $this->headers($f['token']) + [P19::HEADER => P19::VERSION, 'Idempotency-Key' => $key ?? (string) Str::uuid(),
            'X-Draft-ID' => $draft['id'], 'X-Draft-Scan-Session-ID' => $draft['scan_id'], 'X-Draft-Version' => '1'];
    }

    private function draftUrl(array $f, array $draft): string
    {
        return self::DRAFT.$draft['id'].'?'.http_build_query(['warehouse_id' => $f['warehouse_id'], 'operation' => 'COMPONENT_ISSUE', 'scan_session_id' => $draft['scan_id']]);
    }

    private function receiptUrl(array $f, array $draft, string $key): string
    {
        return self::DRAFT.$draft['id'].'/requests/'.$key.'?'.http_build_query(['warehouse_id' => $f['warehouse_id'], 'operation' => 'COMPONENT_ISSUE', 'scan_session_id' => $draft['scan_id'], 'command' => 'RECORD']);
    }

    private function balance(array $f): int
    {
        return (int) DB::table('inventory_balances')->where('warehouse_id', $f['warehouse_id'])->where('sku_id', $f['sku_id'])->where('stock_status', 'AVAILABLE')->sum('available_qty');
    }

    private function boxQty(array $f): int
    {
        return (int) DB::table('container_items')->where('container_id', $f['container_id'])->where('is_active', true)->sum('quantity');
    }

    private function unchanged(array $f): void
    {
        $this->assertDatabaseCount('component_issue_documents', 0);
        $this->assertSame($f['initial_qty'], $this->balance($f));
        $this->assertSame(0, DB::table('stock_movements')->where('source_type', 'COMPONENT_ISSUE_DOCUMENT')->count());
        if (isset($f['container_id'])) {
            $this->assertSame($f['initial_qty'], $this->boxQty($f));
        }
    }
}
