<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\P19ComponentCommand as Command;
use Modules\Warehouse\Support\WarrantyCasePii;
use RuntimeException;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\OpensWarrantyCaseForComponentIssue;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Disposable CI DB only. These writes are NOT a live smoke collection. */
class P24ScannerOwnerContractTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use InteractsWithBrowserSession;
    use OpensWarrantyCaseForComponentIssue;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/mini-app/warranty-component-issues';

    public function test_route_boundary_auth_and_default_disabled_commands(): void
    {
        $this->getJson('/api/v1/mini-app/component-issue-requests/'.Str::uuid().'?operation=CREATE')->assertUnauthorized();
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => $r->getActionName() === Command::class.'@check');
        $this->assertCount(1, $routes);
        $this->assertSame(['GET', 'HEAD'], $routes->first()->methods());
        $f = $this->fixture();
        config(['p19.commands_enabled' => false]);
        $this->postJson(self::BASE, $this->createBody($f), $this->headers($f) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(503)->assertJsonPath('error.code', 'P19_COMMANDS_NOT_ENABLED');
        $this->assertDatabaseCount(Command::TABLE, 0);
        $this->assertDatabaseCount('component_issue_documents', 0);
    }

    public function test_lost_create_id_recovers_from_exact_p19_receipt_without_p03_or_p09(): void
    {
        $f = $this->fixture();
        $key = (string) Str::uuid();
        $body = $this->createBody($f) + ['note' => 'P24 private text must not enter command receipts'];
        $first = $this->postJson(self::BASE, $body, $this->headers($f) + ['Idempotency-Key' => $key])->assertCreated();
        $receipt = $first->json('data.receipt');
        $id = $receipt['result']['business_document_id'];
        $this->assertTrue(Str::isUuid($id));
        $this->assertSame('CREATE', $receipt['operation']);
        $this->assertNull($receipt['target_document_id']);
        $this->assertNull($receipt['expected_version']);
        $this->assertSame($f['session_id'], $receipt['auth_session_id']);
        $this->assertNotNull($receipt['recorded_at']);
        $this->assertStringEndsWith('Z', $receipt['recorded_at']);
        $this->assertLessThan(60, abs(CarbonImmutable::parse($receipt['recorded_at'])->getTimestamp() - time()));
        $this->check($f, $key, 'CREATE')->assertOk()->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.receipt', $receipt);
        $this->postJson(self::BASE, $body, $this->headers($f) + ['Idempotency-Key' => $key])->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.receipt', $receipt);
        $this->postJson(self::BASE, $body, $this->plainHeaders($f['token']) + ['Idempotency-Key' => $key])->assertConflict()
            ->assertJsonPath('error.code', 'P19_COMMAND_CONTRACT_REQUIRED');
        $stored = DB::table(Command::TABLE)->where('request_id', $key)->first();
        $this->assertStringNotContainsString($body['note'], json_encode($stored));
        $this->assertDatabaseCount('component_issue_documents', 1);
        $this->assertDatabaseCount('mini_app_drafts', 0);
        $this->assertSame(7, $this->balance($f));
        $this->check($f, $key, 'POST')->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.receipt', null);
    }

    public function test_p19_native_receipt_replays_under_tracked_p14_shift(): void
    {
        config(['p14.shift_enabled' => true, 'p14.shift_drain_enabled' => false]);
        $f = $this->fixture();
        $shift = $this->postJson('/api/v1/mini-app/work-shifts/start', ['warehouse_id' => $f['warehouse_id']],
            $this->plainHeaders($f['token']) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('data');
        $key = (string) Str::uuid();
        $trace = (string) Str::uuid();
        $headers = $this->headers($f) + [
            'Idempotency-Key' => $key,
            'X-Work-Shift-ID' => $shift['shift_id'],
            'X-Request-ID' => $trace,
        ];
        $first = $this->postJson(self::BASE, $this->createBody($f), $headers)
            ->assertCreated()->assertJsonPath('data.receipt.outcome', 'SUCCEEDED');
        $this->postJson(self::BASE, $this->createBody($f), $headers)
            ->assertCreated()->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.receipt', $first->json('data.receipt'));
        $this->check($f, $key, 'CREATE')->assertOk()->assertJsonPath('data.outcome', 'SUCCEEDED');
        $this->assertSame(1, DB::table('p14_shift_operations')->where('shift_id', $shift['shift_id'])
            ->where('operation', 'COMPONENT_ISSUE')->count());
        $this->getJson('/api/v1/mini-app/work-shifts/'.$shift['shift_id'].'/close-check?warehouse_id='.$f['warehouse_id'],
            $this->plainHeaders($f['token']))->assertOk()->assertJsonPath('data.can_end', true)
            ->assertJsonPath('data.blockers', []);
    }

    public function test_box_scan_replay_does_not_add_quantity_or_attempts_twice(): void
    {
        $f = $this->fixture();
        $d = $this->createDocument($f);
        $key = (string) Str::uuid();
        $body = ['line_id' => $d['line_id'], 'quantity' => 3];
        $first = $this->scan($f, $d, $body, $key)->assertOk()->assertJsonPath('data.receipt.result.scan.quantity', 3);
        $this->scan($f, $d, $body, $key)->assertOk()->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.receipt', $first->json('data.receipt'));
        $this->check($f, $key, 'SCAN')->assertOk()->assertJsonPath('data.receipt.result.scan.quantity', 3);
        $this->assertDatabaseCount('component_issue_items', 1);
        $this->assertDatabaseHas('component_issue_lines', ['id' => $d['line_id'], 'scanned_qty' => 3]);
        $this->assertSame(7, $this->balance($f));
        $body['quantity'] = 2;
        $this->scan($f, $d, $body, $key)->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $body['quantity'] = 3;
        $d['version']++;
        $this->scan($f, $d, $body, $key)->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('component_issue_items', 1);
    }

    public function test_each_distinct_box_scan_receipt_has_its_own_entry_and_exact_quantity(): void
    {
        $f = $this->fixture();
        $d = $this->createDocument($f);
        // ID-only input cannot become fabricated raw scan evidence in this opt-in protocol.
        $this->postJson(self::BASE.'/'.$d['id'].'/scans', [
            'line_id' => $d['line_id'], 'container_id' => $f['container_id'], 'quantity' => 1,
        ], $this->headers($f) + ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"1"'])->assertUnprocessable();
        $this->assertDatabaseCount('component_issue_items', 0);
        $keyA = (string) Str::uuid();
        $a = $this->scan($f, $d, ['line_id' => $d['line_id'], 'quantity' => 1], $keyA)->assertOk();
        $d['version'] = $a->json('data.receipt.result.document_version');
        $keyB = (string) Str::uuid();
        $b = $this->scan($f, $d, ['line_id' => $d['line_id'], 'quantity' => 2], $keyB)->assertOk();
        $this->assertTrue(Str::isUuid($a->json('data.receipt.result.scan.id')));
        $this->assertTrue(Str::isUuid($b->json('data.receipt.result.scan.id')));
        foreach ([$a, $b] as $response) {
            $scan = $response->json('data.receipt.result.scan');
            $stored = DB::table('component_issue_items')->where('id', $scan['id'])->first();
            $this->assertSame($stored->scan_event_id, $scan['scan_event_id']);
            $this->assertSame($scan['scan_event_id'] === null ? 'NOT_RECORDED' : 'SOURCE_EVENT', $scan['event_status']);
            $this->assertSame('component_issue_items.id', $scan['id_source']);
        }
        $this->assertNotSame($a->json('data.receipt.result.scan.id'), $b->json('data.receipt.result.scan.id'));
        $this->assertSame(1, (int) DB::table('component_issue_items')->where('id', $a->json('data.receipt.result.scan.id'))->value('quantity'));
        $this->assertSame(2, (int) DB::table('component_issue_items')->where('id', $b->json('data.receipt.result.scan.id'))->value('quantity'));
        $this->check($f, $keyA, 'SCAN')->assertOk()->assertJsonPath('data.receipt.result.scan.quantity', 1);
        $this->check($f, $keyB, 'SCAN')->assertOk()->assertJsonPath('data.receipt.result.scan.quantity', 2);
        $this->assertDatabaseCount('component_issue_items', 2);
        $this->assertDatabaseHas('component_issue_lines', ['id' => $d['line_id'], 'scanned_qty' => 3]);
        $this->assertSame(7, $this->balance($f));
    }

    public function test_rejected_quantity_and_stale_version_do_not_clamp_or_modify_batch(): void
    {
        $f = $this->fixture();
        $d = $this->createDocument($f);
        $key = (string) Str::uuid();
        $this->scan($f, $d, ['line_id' => $d['line_id'], 'quantity' => 4], $key)
            ->assertUnprocessable()->assertJsonPath('error.code', 'SCAN_EXCEEDS_REQUIRED');
        $this->assertDatabaseHas('component_issue_documents', ['id' => $d['id'], 'version' => 1, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('component_issue_lines', ['id' => $d['line_id'], 'scanned_qty' => 0]);
        $this->assertDatabaseCount('component_issue_items', 0);
        $this->check($f, $key, 'SCAN')->assertOk()->assertJsonPath('data.outcome', 'FAILED')
            ->assertJsonPath('data.receipt.error_code', 'SCAN_EXCEEDS_REQUIRED');
        $d['version'] = 9;
        $this->scan($f, $d, ['line_id' => $d['line_id'], 'quantity' => 1], (string) Str::uuid())
            ->assertStatus(412)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->assertDatabaseCount('component_issue_items', 0);
    }

    public function test_post_receipt_is_historical_and_current_read_failure_never_reposts(): void
    {
        $f = $this->fixture();
        $d = $this->createDocument($f);
        $scanned = $this->scan($f, $d, ['line_id' => $d['line_id'], 'quantity' => 3], (string) Str::uuid())->assertOk();
        $d['version'] = $scanned->json('data.receipt.result.document_version');
        $key = (string) Str::uuid();
        $headers = $this->headers($f) + ['Idempotency-Key' => $key, 'If-Match' => '"'.$d['version'].'"'];
        $posted = $this->postJson(self::BASE.'/'.$d['id'].'/post', [], $headers)->assertOk()
            ->assertJsonPath('data.outcome', 'SUCCEEDED')->assertJsonPath('data.receipt.result.stock_effect', 'DECREASE');
        $receipt = $posted->json('data.receipt');
        $replacement = (string) Str::uuid();
        $this->postJson(self::BASE.'/'.$d['id'].'/post', [], $this->headers($f) + [
            'Idempotency-Key' => $replacement, 'If-Match' => '"'.$receipt['result']['document_version'].'"',
        ])->assertConflict()->assertJsonPath('error.code', 'P19_DOCUMENT_NOT_WRITABLE');
        $this->check($f, $replacement, 'POST')->assertOk()->assertJsonPath('data.outcome', 'FAILED');
        DB::table('warranty_case')->where('warranty_case_id', $f['case_id'])->update(WarrantyCasePii::encode(['status' => 'RETURNED']));
        $this->postJson(self::BASE.'/'.$d['id'].'/post', [], $this->headers($f) + ['Idempotency-Key' => $key, 'If-Match' => '"'.$d['version'].'"'])
            ->assertOk()->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.receipt', $receipt);
        $this->check($f, $key, 'POST')->assertOk()->assertJsonPath('data.receipt', $receipt);
        $this->getJson(self::BASE.'/'.Str::uuid(), $this->headers($f))->assertNotFound();
        $this->check($f, (string) Str::uuid(), 'POST')->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN');
        $this->assertSame(4, $this->balance($f));
        $this->assertSame(1, DB::table('stock_movements')->where('source_type', 'COMPONENT_ISSUE_DOCUMENT')->count());
        config(['p19.commands_enabled' => false]);
        $this->check($f, $key, 'POST')->assertOk()->assertJsonPath('data.receipt', $receipt);
    }

    public function test_receipt_storage_failure_rolls_back_owner_and_unknown_is_never_taken_over(): void
    {
        $f = $this->fixture();
        $key = (string) Str::uuid();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, Command::TABLE)
                && str_contains($query->sql, 'result_json')) {
                $armed = false;
                throw new RuntimeException('Isolated P24 receipt persistence fault');
            }
        });
        $this->postJson(self::BASE, $this->createBody($f), $this->headers($f) + ['Idempotency-Key' => $key])->assertStatus(500);
        $this->assertDatabaseCount('component_issue_documents', 0);
        $this->check($f, $key, 'CREATE')->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN')
            ->assertJsonPath('data.receipt', null)->assertJsonPath('data.may_create_new_request', false);
        DB::table(Command::TABLE)->where('request_id', $key)->update(['created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)]);
        $this->postJson(self::BASE, $this->createBody($f), $this->headers($f) + ['Idempotency-Key' => $key])->assertStatus(202)
            ->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.retry_policy', 'CHECK_ONLY');
        $this->assertDatabaseCount('component_issue_documents', 0);
        $this->assertSame(7, $this->balance($f));
    }

    public function test_new_session_cannot_reuse_old_request_id_or_read_receipt(): void
    {
        $f = $this->fixture();
        $key = (string) Str::uuid();
        $this->postJson(self::BASE, $this->createBody($f), $this->headers($f) + ['Idempotency-Key' => $key])->assertCreated();
        $user = User::findOrFail($f['actor_id']);
        $this->plainHeaders('');
        $login = $this->browserLogin($user->email, $f['secret'])->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $f['warehouse_id']], $this->plainHeaders($login->json('data.access_token')))->assertOk();
        $f['token'] = $selected->json('data.access_token');
        $this->check($f, $key, 'CREATE')->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN')->assertJsonPath('data.receipt', null);
        $this->postJson(self::BASE, $this->createBody($f), $this->headers($f) + ['Idempotency-Key' => $key])->assertConflict();
        $this->assertDatabaseCount('component_issue_documents', 1);
    }

    public function test_revoked_rights_wrong_parent_and_forged_context_fail_closed(): void
    {
        $f = $this->fixture();
        $key = (string) Str::uuid();
        $this->postJson(self::BASE, $this->createBody($f), $this->headers($f) + ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson(self::BASE, $this->createBody($f) + ['auth_session_id' => (string) Str::uuid()], $this->headers($f) + ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $other = Warehouse::create(['code' => 'P24-OTHER-'.Str::random(6), 'name' => 'CI only', 'status' => 'INACTIVE']);
        DB::table('warranty_case_warehouse_owners')->where('warranty_case_id', $f['case_id'])->update(['warehouse_id' => $other->id]);
        $this->check($f, $key, 'CREATE')->assertNotFound();
        $user = User::findOrFail($f['actor_id']);
        $user->roles()->detach();
        $user->forceFill(['version' => (int) $user->version + 1])->save();
        $this->check($f, $key, 'CREATE')->assertForbidden();
        $this->assertDatabaseCount('component_issue_documents', 1);
    }

    public function test_resolver_rechecks_cached_case_and_box_quantity_without_writes(): void
    {
        $f = $this->fixture();
        $body = ['warehouse_id' => $f['warehouse_id'], 'warranty_case_id' => $f['case_id'], 'raw_code' => $f['raw_code']];
        $this->postJson(self::BASE.'/resolve-code', $body, $this->headers($f))->assertOk()
            ->assertJsonPath('data.available_qty', 7)->assertJsonPath('meta.p19_scanner.can_confirm_local', true)
            ->assertJsonPath('meta.p19_scanner.confirmation_is_scan', false);
        DB::table('warranty_case')->where('warranty_case_id', $f['case_id'])->update(WarrantyCasePii::encode(['status' => 'RETURNED']));
        DB::table('container_items')->where('container_id', $f['container_id'])->update(['is_active' => false]);
        $this->postJson(self::BASE.'/resolve-code', $body, $this->headers($f))->assertOk()
            ->assertJsonPath('data.available_qty', 0)->assertJsonPath('data.case_allows_issue', false)
            ->assertJsonPath('data.eligible_for_issue', false)->assertJsonPath('meta.p19_scanner.can_confirm_local', false);
        $this->assertDatabaseCount('component_issue_documents', 0);
        $this->assertDatabaseCount(Command::TABLE, 0);
    }

    public function test_item_null_quantity_unknown_reason_and_closed_stale_handler(): void
    {
        $f = $this->fixture(true);
        $body = ['warehouse_id' => $f['warehouse_id'], 'warranty_case_id' => $f['case_id'], 'raw_code' => $f['raw_code']];
        $this->postJson(self::BASE.'/resolve-code', $body, $this->headers($f))->assertOk()
            ->assertJsonPath('data.available_qty', null)->assertJsonPath('meta.p19_scanner.reason_kind', 'UNIT');
        $body['raw_code'] = 'P24-UNRESOLVED-'.Str::uuid();
        $this->postJson(self::BASE.'/resolve-code', $body, $this->headers($f))->assertOk()
            ->assertJsonPath('data.resolved', false)->assertJsonPath('data.available_qty', null)
            ->assertJsonPath('meta.p19_scanner.reason_kind', 'UNKNOWN')->assertJsonPath('meta.p19_scanner.can_confirm_local', false);
        $d = $this->createDocument($f);
        DB::table('warranty_case')->where('warranty_case_id', $f['case_id'])->update(WarrantyCasePii::encode(['status' => 'RETURNED']));
        $this->scan($f, $d, ['line_id' => $d['line_id'], 'quantity' => 1], (string) Str::uuid())
            ->assertUnprocessable()->assertJsonPath('error.code', 'WARRANTY_CASE_NOT_ACTIVE');
        $this->assertDatabaseCount('component_issue_items', 0);
    }

    private function fixture(bool $serial = false): array
    {
        config(['p19.commands_enabled' => true]);
        $auth = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $user = User::findOrFail($auth['__user_id']);
        $secret = bin2hex(random_bytes(16));
        $user->forceFill(['email' => 'p24-'.Str::uuid().'@example.test', 'password_hash' => Hash::make($secret), 'must_change_password' => false])->save();
        $this->plainHeaders('');
        $login = $this->browserLogin($user->email, $secret)->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id], $this->plainHeaders($login->json('data.access_token')))->assertOk();
        $token = $selected->json('data.access_token');
        $me = $this->getJson('/api/v1/auth/me', $this->plainHeaders($token))->assertOk();
        $sku = $this->componentSkuFixture('HN-P24-'.Str::upper(Str::random(6)), serialTracked: $serial);
        $f = ['token' => $token, 'secret' => $secret, 'actor_id' => (string) $user->user_id,
            'session_id' => $me->json('data.auth_session_id'), 'warehouse_id' => (string) $warehouse->id,
            'sku_id' => (string) $sku->sku_id, 'quantity' => $serial ? 1 : 3];
        if ($serial) {
            $f['item_id'] = $this->seedItemInStock($f['sku_id']);
            $f['raw_code'] = 'P24-ITEM-'.Str::upper(Str::random(8));
            $this->seedScanCode($f['item_id'], $f['raw_code']);
            $this->seedLedgerBackedBalance($f['warehouse_id'], $f['sku_id'], 1, $f['actor_id']);
        } else {
            $f['raw_code'] = 'BOX-'.$sku->sku_code.'-0001';
            $headers = $this->plainHeaders($token);
            $inbound = $this->createInboundHeader($headers, $f['warehouse_id'], ['source_name' => 'P24 isolated CI receipt']);
            $received = $this->receiveComponentQuantity($headers, $inbound['document_id'], $f['sku_id'], 7, $inbound['version'], $f['raw_code']);
            $this->postInboundReceipt($headers, $inbound['document_id'], $received['version'], (string) Str::uuid())->assertOk();
            $f['container_id'] = $received['container_id'];
        }
        $case = $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => 'P24 isolated customer', 'customer_phone' => '0900000024',
            'description' => 'Isolated CI test', 'missing_code_reason' => 'No serial in CI case', 'manual_product_description' => 'CI product',
        ], $this->plainHeaders($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $f['case_id'] = $this->moveCaseToChecking($this->plainHeaders($token), (string) $case->json('data.id'));

        return $f;
    }

    private function createBody(array $f): array
    {
        return ['warehouse_id' => $f['warehouse_id'], 'warranty_case_id' => $f['case_id'],
            'lines' => [['sku_id' => $f['sku_id'], 'required_qty' => $f['quantity']]]];
    }

    private function createDocument(array $f): array
    {
        $response = $this->postJson(self::BASE, $this->createBody($f), $this->headers($f) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $id = $response->json('data.receipt.result.business_document_id');
        $detail = $this->getJson(self::BASE.'/'.$id, $this->plainHeaders($f['token']))->assertOk();

        return ['id' => $id, 'version' => $response->json('data.receipt.result.document_version'), 'line_id' => $detail->json('data.lines.0.id')];
    }

    private function scan(array $f, array $d, array $body, string $key)
    {
        return $this->postJson(self::BASE.'/'.$d['id'].'/scans', $body + ['code_value' => $f['raw_code']],
            $this->headers($f) + ['Idempotency-Key' => $key, 'If-Match' => '"'.$d['version'].'"']);
    }

    private function check(array $f, string $key, string $operation)
    {
        return $this->get('/api/v1/mini-app/component-issue-requests/'.$key.'?operation='.$operation, $this->headers($f) + ['Accept' => 'application/json']);
    }

    private function headers(array $f): array
    {
        return $this->plainHeaders($f['token']) + [Command::HEADER => Command::CONTRACT];
    }

    private function plainHeaders(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function balance(array $f): int
    {
        return (int) DB::table('inventory_balances')->where('warehouse_id', $f['warehouse_id'])->where('sku_id', $f['sku_id'])
            ->where('stock_status', 'AVAILABLE')->sum('available_qty');
    }
}
