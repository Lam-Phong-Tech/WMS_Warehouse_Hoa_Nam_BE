<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\P20ComponentHistoryController as P20;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\P19LinkedComponentIssue as P19;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\OpensWarrantyCaseForComponentIssue;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Disposable local test data only: no real server, account, stock or seeder. */
class P20ComponentHistoryTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use InteractsWithBrowserSession;
    use OpensWarrantyCaseForComponentIssue;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_closed_case_hydrates_posted_document_raw_evidence_and_stock_quantity_without_writes(): void
    {
        $f = $this->fixture();
        $id = $this->postBatch($f, 3);
        DB::table('warranty_case')->where('warranty_case_id', $f['case_id'])->update(['status' => 'RETURNED']);
        $before = $this->snapshot();
        $case = $this->getJson('/api/v1/mini-app/warranty-cases/'.$f['case_id'].'?warehouse_id='.$f['warehouse_id'], $this->headers($f['token']))
            ->assertOk()->assertJsonPath('data.capabilities.posted_component_issues_read', true);
        $this->assertNotEmpty($case->json('data'));
        $list = $this->getJson($this->listUrl($f), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.count_unit', 'POSTED_DOCUMENT')
            ->assertJsonPath('data.0.component_issue_document_id', $id)->assertJsonPath('data.0.status', 'POSTED');
        $this->assertArrayNotHasKey('lines', $list->json('data.0'));
        $this->assertNotSame($id, $list->json('data.0.document_no'));
        $detail = $this->getJson($this->detailUrl($f, $id), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.document.id', $id)->assertJsonPath('data.document.warranty_case_id', $f['case_id'])
            ->assertJsonPath('data.history.quantity_status', 'CONFIRMED')->assertJsonPath('data.history.quantity_total', 3)
            ->assertJsonPath('data.history.read_stock_effect', 'NONE')->assertJsonPath('data.history.is_operation_receipt', false)
            ->assertJsonPath('data.lines.0.issued_qty', 3)->assertJsonPath('data.lines.0.posted_item_count', 1)
            ->assertJsonPath('data.lines.0.items.0.target_type', 'BOX')->assertJsonPath('data.lines.0.items.0.raw_code', $f['raw_code'])
            ->assertJsonPath('data.lines.0.items.0.raw_code_status', 'RECORDED')->assertJsonPath('data.lines.0.movements.0.qty_delta', -3);
        $this->assertNotNull($detail->json('data.document.posted_at'));
        $this->assertStringContainsString('no-store', $detail->headers->get('Cache-Control'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_posted_sort_and_keyset_are_explicit_and_legacy_cursor_cannot_cross_contract(): void
    {
        $f = $this->fixture();
        $a = $this->postBatch($f, 1);
        $b = $this->postBatch($f, 1);
        DB::table('component_issue_documents')->where('id', $a)->update(['created_at' => now()->subDays(5), 'posted_at' => now()->subHour()]);
        DB::table('component_issue_documents')->where('id', $b)->update(['created_at' => now()->subDay(), 'posted_at' => now()->subHours(2)]);
        $first = $this->getJson($this->listUrl($f, ['per_page' => 1]), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.0.component_issue_document_id', $a)->assertJsonPath('meta.total', 2)->assertJsonPath('meta.hasMore', true);
        $cursor = $first->json('meta.nextCursor');
        $this->assertNotEmpty($cursor);
        $url = $this->listUrl($f, ['per_page' => 1, 'cursor' => $cursor]);
        $second = $this->getJson($url, $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.0.component_issue_document_id', $b)->assertJsonPath('meta.hasMore', false)->assertJsonPath('meta.nextCursor', null);
        $again = $this->getJson($url, $this->headers($f['token']))->assertOk();
        $this->assertSame($second->json('data'), $again->json('data'));
        $legacy = $this->getJson($this->listUrl($f, ['per_page' => 1]), $this->headers($f['token'], false))->assertOk()
            ->assertJsonPath('data.0.component_issue_document_id', $b);
        $this->getJson($this->listUrl($f, ['per_page' => 1, 'cursor' => $legacy->json('meta.nextCursor')]), $this->headers($f['token']))
            ->assertUnprocessable()->assertJsonPath('error.code', 'WARRANTY_CURSOR_SCOPE_MISMATCH');
        $this->getJson($url, $this->headers($f['token'], false))->assertUnprocessable();
    }

    public function test_query_and_cursor_validation_do_not_become_empty_history(): void
    {
        $f = $this->fixture();
        $this->postBatch($f, 1);
        $this->postBatch($f, 1);
        foreach ([['status' => 'POSTED'], ['limit' => 20], ['per_page' => 101], ['page' => 1, 'cursor' => 'not-valid'], ['cursor' => 'not-valid']] as $query) {
            $this->getJson($this->listUrl($f, $query), $this->headers($f['token']))->assertUnprocessable();
        }
        $read = $this->getJson($this->listUrl($f, ['per_page' => 1]), $this->headers($f['token']))->assertOk();
        $cursor = json_decode(Crypt::decryptString($read->json('meta.nextCursor')), true, 16, JSON_THROW_ON_ERROR);
        $cursor['expires'] = time() - 1;
        $expired = Crypt::encryptString(json_encode($cursor, JSON_THROW_ON_ERROR));
        $this->getJson($this->listUrl($f, ['per_page' => 1, 'cursor' => $expired]), $this->headers($f['token']))
            ->assertUnprocessable()->assertJsonPath('error.code', 'WARRANTY_CURSOR_SCOPE_MISMATCH');
        $this->getJson($this->listUrl($f, ['page' => 9, 'per_page' => 1]), $this->headers($f['token']))
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(0, 'data')->assertJsonPath('meta.hasMore', false);
    }

    public function test_unknown_post_time_stays_null_instead_of_created_at(): void
    {
        $f = $this->fixture();
        $id = $this->postBatch($f, 1);
        DB::table('component_issue_documents')->where('id', $id)->update(['posted_at' => null]);
        $this->getJson($this->listUrl($f), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.0.posted_at', null)->assertJsonPath('data.0.posted_time_status', 'NOT_RECORDED');
        $this->getJson($this->detailUrl($f, $id), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.document.posted_at', null)->assertJsonPath('data.document.posted_time_status', 'NOT_RECORDED');
    }

    public function test_draft_is_not_posted_history_and_read_does_not_create_receipt(): void
    {
        $f = $this->fixture();
        $create = $this->postJson('/api/v1/mini-app/warranty-component-issues', [
            'warehouse_id' => $f['warehouse_id'], 'warranty_case_id' => $f['case_id'],
            'lines' => [['sku_id' => $f['sku_id'], 'required_qty' => 1]],
        ], $this->headers($f['token'], false))->assertCreated();
        $id = $create->json('data.document.id');
        $before = $this->snapshot();
        $this->getJson($this->listUrl($f), $this->headers($f['token']))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        $this->getJson($this->detailUrl($f, $id), $this->headers($f['token']))->assertConflict()->assertJsonPath('error.code', 'COMPONENT_HISTORY_NOT_POSTED');
        $this->getJson('/api/v1/mini-app/warranty-component-issues/'.$id, $this->headers($f['token'], false))
            ->assertOk()->assertJsonPath('data.document.status', 'DRAFT');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_raw_code_is_exact_persisted_event_not_current_sku_or_mapping(): void
    {
        $f = $this->fixture();
        $id = $this->postBatch($f, 1);
        $item = DB::table('component_issue_items')->first();
        $raw = "  persisted|code|MiXeD\n ";
        DB::table('scan_event')->where('scan_event_id', $item->scan_event_id)->update(['raw_code' => $raw]);
        DB::table('sku')->where('sku_id', $f['sku_id'])->update(['status' => 'INACTIVE']);
        $this->getJson($this->detailUrl($f, $id), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.lines.0.items.0.raw_code', $raw)->assertJsonPath('data.lines.0.issued_qty', 1);
        DB::table('component_issue_items')->where('id', $item->id)->update(['scan_event_id' => null]);
        $this->getJson($this->detailUrl($f, $id), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.lines.0.items.0.raw_code', null)->assertJsonPath('data.lines.0.items.0.raw_code_status', 'NOT_RECORDED')
            ->assertJsonPath('data.lines.0.issued_qty', 1);
    }

    public function test_foreign_or_mismatched_scan_event_never_leaks_code(): void
    {
        $f = $this->fixture();
        $id = $this->postBatch($f, 1);
        $item = DB::table('component_issue_items')->first();
        $event = DB::table('scan_event')->where('scan_event_id', $item->scan_event_id)->first();
        DB::table('scan_event')->where('scan_event_id', $event->scan_event_id)->update(['resolved_object_id' => (string) Str::uuid()]);
        $this->getJson($this->detailUrl($f, $id), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.lines.0.items.0.raw_code', null)->assertJsonPath('data.lines.0.items.0.raw_code_status', 'IDENTITY_MISMATCH');
        DB::table('scan_batch')->where('scan_batch_id', $event->scan_batch_id)->update(['source_id' => (string) Str::uuid()]);
        $this->getJson($this->detailUrl($f, $id), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.lines.0.items.0.raw_code', null)->assertJsonPath('data.lines.0.items.0.scan_event_id', null);
    }

    public function test_removed_rows_and_missing_or_mismatched_movements_never_use_planned_quantity(): void
    {
        $f = $this->fixture();
        $id = $this->postBatch($f, 3);
        $item = DB::table('component_issue_items')->first();
        $ledgerBefore = $this->snapshot()['stock_movements'];
        DB::table('component_issue_items')->where('id', $item->id)->update(['status' => 'REMOVED']);
        $this->getJson($this->detailUrl($f, $id), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.lines.0.issued_qty', null)->assertJsonPath('data.lines.0.removed_item_count', 1)
            ->assertJsonCount(0, 'data.lines.0.items')->assertJsonPath('data.history.quantity_total', null);
        // Inconsistent persisted selection evidence, not an UPDATE of the
        // append-only ledger. Its original movement must remain exactly -3.
        DB::table('component_issue_items')->where('id', $item->id)->update(['status' => 'POSTED', 'quantity' => 1]);
        $this->getJson($this->detailUrl($f, $id), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.lines.0.quantity_status', 'MISMATCH')->assertJsonPath('data.lines.0.issued_qty', null);
        // Independent isolated legacy-document fixture lacking a movement.
        // No DELETE, disabled trigger or fabricated ledger rollback is used.
        $document = (array) DB::table('component_issue_documents')->where('id', $id)->first();
        $line = (array) DB::table('component_issue_lines')->where('id', $item->component_issue_line_id)->first();
        $missingId = (string) Str::uuid();
        $missingLine = (string) Str::uuid();
        DB::table('component_issue_documents')->insert(array_replace($document, ['id' => $missingId, 'component_issue_no' => 'P20-MISSING-'.$missingId]));
        DB::table('component_issue_lines')->insert(array_replace($line, ['id' => $missingLine, 'component_issue_document_id' => $missingId]));
        DB::table('component_issue_items')->insert(array_replace((array) $item, ['id' => (string) Str::uuid(), 'component_issue_line_id' => $missingLine, 'scan_event_id' => null]));
        $this->getJson($this->detailUrl($f, $missingId), $this->headers($f['token']))->assertOk()
            ->assertJsonPath('data.lines.0.quantity_status', 'INCOMPLETE')->assertJsonPath('data.history.quantity_total', null);
        $this->assertSame($ledgerBefore, $this->snapshot()['stock_movements']);
    }

    public function test_get_body_parameters_are_rejected_without_stock_or_document_changes(): void
    {
        $f = $this->fixture();
        $id = $this->postBatch($f, 1);
        $before = $this->snapshot();
        foreach ([$this->listUrl($f), $this->detailUrl($f, $id)] as $url) {
            foreach ([['warehouse_id' => $f['warehouse_id']], ['status' => 'POSTED'], ['command' => 'POST']] as $body) {
                $this->json('GET', $url, $body, $this->headers($f['token']))->assertUnprocessable()
                    ->assertJsonPath('error.code', 'COMPONENT_HISTORY_UNKNOWN_FILTER');
            }
            $this->getJson($url, $this->headers($f['token']))->assertOk();
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_detail_requires_every_link_permission_even_without_contract_header(): void
    {
        $f = $this->fixture();
        $id = $this->postBatch($f, 1);
        $all = ['warranty.view', 'documents.read', 'warranty.component_issue'];
        foreach ($all as $missing) {
            $token = $this->reader($f['warehouse_id'], array_values(array_diff($all, [$missing])));
            $this->getJson($this->listUrl($f), $this->headers($token))->assertForbidden();
            $this->getJson($this->detailUrl($f, $id), $this->headers($token))->assertForbidden();
            $this->getJson($this->detailUrl($f, $id), $this->headers($token, false))->assertForbidden();
        }
        $this->getJson($this->detailUrl($f, $id), $this->headers(''))->assertUnauthorized();
    }

    public function test_notes_are_redacted_and_previous_admin_response_is_not_reused(): void
    {
        $f = $this->fixture();
        $id = $this->postBatch($f, 1);
        DB::table('component_issue_documents')->where('id', $id)->update(['note' => 'Private customer note']);
        DB::table('component_issue_lines')->where('component_issue_document_id', $id)->update(['note' => 'Private line note']);
        $this->getJson($this->detailUrl($f, $id), $this->headers($f['token']))->assertOk()->assertJsonPath('data.document.note', 'Private customer note');
        $token = $this->reader($f['warehouse_id'], ['warranty.view', 'documents.read', 'warranty.component_issue']);
        $this->getJson($this->detailUrl($f, $id), $this->headers($token))->assertOk()
            ->assertJsonPath('data.document.note', null)->assertJsonPath('data.document.posted_by', null)
            ->assertJsonPath('data.lines.0.note', null)->assertJsonPath('data.lines.0.note_redacted', true);
    }

    public function test_persisted_case_ownership_and_context_are_checked_before_detail(): void
    {
        $f = $this->fixture();
        $id = $this->postBatch($f, 1);
        $foreign = Warehouse::create(['code' => 'P20-OTHER-'.Str::random(6), 'name' => 'Isolated foreign warehouse', 'status' => 'INACTIVE']);
        $this->getJson('/api/v1/mini-app/warranty-component-issues/'.$id.'?warehouse_id='.$foreign->id, $this->headers($f['token']))->assertConflict();
        DB::table('warranty_case_warehouse_owners')->where('warranty_case_id', $f['case_id'])->update(['warehouse_id' => $foreign->id]);
        $this->getJson($this->listUrl($f), $this->headers($f['token']))->assertNotFound();
        $this->getJson($this->detailUrl($f, $id), $this->headers($f['token']))->assertNotFound();
        $this->getJson($this->detailUrl($f, $id), $this->headers($f['token'], false))->assertNotFound();
    }

    public function test_new_login_cannot_reuse_old_cursor_but_may_read_authorized_history(): void
    {
        $f = $this->fixture();
        $id = $this->postBatch($f, 1);
        $this->postBatch($f, 1);
        $first = $this->getJson($this->listUrl($f, ['per_page' => 1]), $this->headers($f['token']))->assertOk();
        $user = User::findOrFail($f['actor_id']);
        $this->headers('');
        $login = $this->browserLogin($user->email, $f['secret'])->assertOk();
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $f['warehouse_id']], $this->headers($login->json('data.access_token'), false))
            ->assertOk()->json('data.access_token');
        $this->getJson($this->listUrl($f, ['per_page' => 1, 'cursor' => $first->json('meta.nextCursor')]), $this->headers($token))
            ->assertUnprocessable()->assertJsonPath('error.code', 'WARRANTY_CURSOR_SCOPE_MISMATCH');
        $this->getJson($this->detailUrl($f, $id), $this->headers($token))->assertOk();
    }

    private function fixture(): array
    {
        $auth = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $user = User::findOrFail($auth['__user_id']);
        $secret = bin2hex(random_bytes(16));
        $user->forceFill(['email' => 'p20-'.Str::uuid().'@example.test', 'password_hash' => Hash::make($secret), 'must_change_password' => false])->save();
        $this->headers('');
        $login = $this->browserLogin($user->email, $secret)->assertOk();
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id], $this->headers($login->json('data.access_token'), false))->assertOk()->json('data.access_token');
        $sku = $this->componentSkuFixture('HN-P20-'.Str::upper(Str::random(6)), serialTracked: false);
        $f = ['token' => $token, 'secret' => $secret, 'actor_id' => (string) $user->user_id,
            'warehouse_id' => (string) $warehouse->id, 'sku_id' => (string) $sku->sku_id, 'raw_code' => 'BOX-'.$sku->sku_code.'-0001'];
        $headers = $this->headers($token, false);
        $inbound = $this->createInboundHeader($headers, $f['warehouse_id'], ['source_name' => 'Isolated P20 evidence']);
        $received = $this->receiveComponentQuantity($headers, $inbound['document_id'], $f['sku_id'], 7, $inbound['version'], $f['raw_code']);
        $this->postInboundReceipt($headers, $inbound['document_id'], $received['version'], (string) Str::uuid())->assertOk();
        $case = $this->postJson('/api/v1/warranty-cases', ['customer_name' => 'Isolated P20 customer', 'customer_phone' => '0900000020',
            'description' => 'Isolated history test', 'missing_code_reason' => 'Test without identifier', 'manual_product_description' => 'Test product'],
            $this->headers($token, false) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $f['case_id'] = $this->moveCaseToChecking($this->headers($token, false), (string) $case->json('data.id'));

        return $f;
    }

    private function postBatch(array $f, int $quantity): string
    {
        $draft = (string) Str::uuid();
        $scan = (string) Str::uuid();
        $rows = [['raw_code' => $f['raw_code'], 'quantity' => $quantity, 'scan_source' => 'CAMERA']];
        $this->putJson('/api/v1/mini-app/drafts/'.$draft, ['warehouse_id' => $f['warehouse_id'], 'operation' => 'COMPONENT_ISSUE', 'scan_session_id' => $scan,
            'codes' => $rows, 'form' => ['p19' => ['contract' => P19::VERSION, 'warranty_case_id' => $f['case_id']]]],
            $this->headers($f['token'], false) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"0"'])->assertCreated();

        return $this->postJson('/api/v1/mini-app/warranty-component-issues/record', ['warehouse_id' => $f['warehouse_id'], 'warranty_case_id' => $f['case_id'], 'post_immediately' => true, 'items' => $rows],
            $this->headers($f['token'], false) + [P19::HEADER => P19::VERSION, 'Idempotency-Key' => (string) Str::uuid(), 'X-Draft-ID' => $draft, 'X-Draft-Scan-Session-ID' => $scan, 'X-Draft-Version' => '1'])
            ->assertCreated()->json('data.component_issue_document_id');
    }

    private function reader(string $warehouse, array $rights): string
    {
        $secret = bin2hex(random_bytes(16));
        $suffix = Str::lower(Str::random(12));
        $user = User::create(['username' => 'p20_'.$suffix, 'email' => 'p20_'.$suffix.'@example.test', 'display_name' => 'Isolated P20 reader',
            'password_hash' => Hash::make($secret), 'status' => 'ACTIVE', 'version' => 1, 'must_change_password' => false]);
        $role = Role::create(['role_code' => 'P20_'.$suffix, 'role_name' => 'Isolated role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse]);
        foreach ($rights as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->headers('');
        $login = $this->browserLogin($user->email, $secret)->assertOk();

        return $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse], $this->headers($login->json('data.access_token'), false))->assertOk()->json('data.access_token');
    }

    private function headers(string $token, bool $contract = true): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token] + ($contract ? [P20::HEADER => P20::CONTRACT] : []);
    }

    private function listUrl(array $f, array $query = []): string
    {
        return '/api/v1/mini-app/warranty-cases/'.$f['case_id'].'/component-issues?'.http_build_query(['warehouse_id' => $f['warehouse_id']] + $query);
    }

    private function detailUrl(array $f, string $id): string
    {
        return '/api/v1/mini-app/warranty-component-issues/'.$id.'?warehouse_id='.$f['warehouse_id'];
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['stock_movements', 'inventory_balances', 'component_issue_documents', 'component_issue_lines', 'component_issue_items', 'scan_event', 'scan_batch'] as $table) {
            $rows = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all();
            usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            $result[$table] = $rows;
        }

        return $result;
    }
}
