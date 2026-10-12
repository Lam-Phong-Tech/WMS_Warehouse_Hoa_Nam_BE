<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\MiniAppComponentIssueStagingController;
use Modules\Warehouse\Services\MiniAppDraftService as P03;
use Modules\Warehouse\Services\P19LinkedComponentIssue as P19;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\OpensWarrantyCaseForComponentIssue;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Only disposable fixtures; never split, truncate or silently replace a saved batch. */
class P19BatchLimitsTest extends TestCase
{
    use InteractsWithBrowserSession;
    use OpensWarrantyCaseForComponentIssue;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const DRAFT = '/api/v1/mini-app/drafts/';

    private const RECORD = '/api/v1/mini-app/warranty-component-issues/record';

    public static function saveBoundaries(): array
    {
        return ['empty' => [0, true], '500' => [500, true], '501' => [501, true], '1000' => [1000, true], '1001' => [1001, false]];
    }

    #[DataProvider('saveBoundaries')]
    public function test_save_capacity_preserves_exact_private_snapshot(int $count, bool $allowed): void
    {
        $f = $this->fixture();
        $rows = $this->rows($count);
        $draft = $this->draft($f, $rows);
        $before = $this->businessCounts();
        $response = $this->putJson(self::DRAFT.$draft['id'], $draft['body'], $this->saveHeaders($f, $draft));
        if (! $allowed) {
            $response->assertUnprocessable();
            $this->assertDatabaseCount(P03::TABLE, 0);
            $this->assertDatabaseCount(P03::RECEIPTS, 0);
        } else {
            $response->assertCreated();
            $read = $this->getJson($this->draftUrl($f, $draft), $this->headers($f['token']))->assertOk();
            $this->assertSame($rows, $read->json('data.codes'));
            $read->assertJsonPath('data.version', 1)->assertJsonPath('data.status', 'DRAFT')
                ->assertJsonPath('data.business_document_id', null)->assertJsonPath('data.stock_effect', 'NONE');
            $this->putJson(self::DRAFT.$draft['id'], $draft['body'], $this->saveHeaders($f, $draft))
                ->assertCreated()->assertHeader('Idempotent-Replay', 'true');
            $this->assertDatabaseCount(P03::TABLE, 1);
            $this->assertDatabaseCount(P03::RECEIPTS, 1);
        }
        $this->assertSame($before, $this->businessCounts());
    }

    public static function deniedRecordBoundaries(): array
    {
        return ['empty' => [0], '501' => [501], '1000' => [1000], '1001' => [1001]];
    }

    #[DataProvider('deniedRecordBoundaries')]
    public function test_record_rejects_out_of_range_without_losing_snapshot_or_creating_receipt(int $count): void
    {
        $f = $this->fixture();
        $rows = $this->rows($count);
        // The 1001 case sends an invalid command against a valid 1000-row draft.
        $saved = $count === 1001 ? $this->rows(1000) : $rows;
        $draft = $this->draft($f, $saved);
        $this->putJson(self::DRAFT.$draft['id'], $draft['body'], $this->saveHeaders($f, $draft))->assertCreated();
        $before = $this->businessCounts();
        $key = (string) Str::uuid();
        $this->postJson(self::RECORD, $this->recordBody($f, $rows), $this->recordHeaders($f, $draft, $key))->assertUnprocessable();
        $read = $this->getJson($this->draftUrl($f, $draft), $this->headers($f['token']))->assertOk();
        $this->assertSame($saved, $read->json('data.codes'));
        $read->assertJsonPath('data.version', 1)->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.business_document_id', null);
        $this->assertDatabaseCount(P03::TABLE, 1);
        $this->assertDatabaseCount(P03::RECEIPTS, 1);
        $this->assertSame($before, $this->businessCounts());
        $this->getJson(self::DRAFT.$draft['id'].'/requests/'.$key.'?'.http_build_query([
            'warehouse_id' => $f['warehouse_id'], 'operation' => 'COMPONENT_ISSUE',
            'scan_session_id' => $draft['scan_id'], 'command' => 'RECORD',
        ]), $this->headers($f['token']))->assertOk()->assertJsonPath('data.outcome', 'UNKNOWN')
            ->assertJsonPath('data.receipt', null)->assertJsonPath('data.new_document_allowed', false);
    }

    public function test_500_real_items_post_atomically_and_replay_without_a_second_stock_effect(): void
    {
        $f = $this->fixture();
        $sku = $this->componentSkuFixture('HN-P19-LIMIT-'.Str::upper(Str::random(6)), serialTracked: true);
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $itemId = $this->seedItemInStock((string) $sku->sku_id);
            $code = 'P19-LIMIT-ITEM-'.$i;
            $this->seedScanCode($itemId, $code);
            $rows[] = ['raw_code' => $code, 'quantity' => 1, 'scan_source' => 'CAMERA'];
        }
        $this->seedLedgerBackedBalance($f['warehouse_id'], (string) $sku->sku_id, 500, $f['actor_id']);
        $draft = $this->draft($f, $rows);
        $this->putJson(self::DRAFT.$draft['id'], $draft['body'], $this->saveHeaders($f, $draft))->assertCreated();
        $key = (string) Str::uuid();
        $body = $this->recordBody($f, $rows);
        $first = $this->postJson(self::RECORD, $body, $this->recordHeaders($f, $draft, $key))->assertCreated()
            ->assertJsonPath('data.p19_result.quantity_total', 500)->assertJsonPath('data.p19_result.stock_effect', 'DECREASE')
            ->assertJsonPath('data.scan_evidence_count', 500)->assertJsonCount(500, 'data.items')
            ->assertJsonPath('meta.p03_draft.document.status', 'RECORDED');
        $id = $first->json('data.component_issue_document_id');
        $again = $this->postJson(self::RECORD, $body, $this->recordHeaders($f, $draft, $key))->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.component_issue_document_id', $id);
        $this->assertSame($first->json('data.p19_result'), $again->json('data.p19_result'));
        $this->assertDatabaseCount('component_issue_documents', 1);
        $this->assertDatabaseCount(P03::RECEIPTS, 2);
        $this->assertSame(500, DB::table('item_instances')->where('sku_id', $sku->sku_id)->where('item_status', 'ISSUED')->count());
        $this->assertSame(-500, (int) DB::table('stock_movements')->where('source_type', 'COMPONENT_ISSUE_DOCUMENT')->where('source_id', $id)->sum('qty_delta'));
        $this->assertSame(0, (int) DB::table('inventory_balances')->where('warehouse_id', $f['warehouse_id'])->where('sku_id', $sku->sku_id)->sum('available_qty'));
    }

    public function test_record_limit_matches_owner_and_published_save_record_capacities(): void
    {
        $owner = new \ReflectionClass(MiniAppComponentIssueStagingController::class);
        $this->assertSame(500, P19::MAX_RECORD_ITEMS);
        $this->assertSame(P19::MAX_RECORD_ITEMS, $owner->getConstant('MAX_BATCH_ITEMS'));
        $contract = json_decode(file_get_contents(base_path('contracts/p19/component-issue-linked-v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1000, $contract['limits']['save_max_codes']);
        $this->assertSame(P19::MAX_RECORD_ITEMS, $contract['limits']['record_max_items']);
        $this->assertFalse($contract['production_activation']);
    }

    private function fixture(): array
    {
        $auth = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $user = User::findOrFail($auth['__user_id']);
        $secret = bin2hex(random_bytes(16));
        $user->forceFill(['email' => 'p19-limit-'.Str::uuid().'@example.test', 'password_hash' => Hash::make($secret), 'must_change_password' => false])->save();
        $this->headers('');
        $login = $this->browserLogin($user->email, $secret)->assertOk();
        $selected = $this->postJson('/api/v1/auth/switch-context', [
            'context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id,
        ], $this->headers($login->json('data.access_token')))->assertOk();
        $token = $selected->json('data.access_token');
        $case = $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => 'Isolated limits fixture', 'customer_phone' => '0900000019',
            'description' => 'Disposable limits test', 'missing_code_reason' => 'Test without identifier',
            'manual_product_description' => 'Disposable product',
        ], $this->headers($token) + ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return ['token' => $token, 'warehouse_id' => (string) $warehouse->id, 'actor_id' => (string) $user->user_id,
            'case_id' => $this->moveCaseToChecking($this->headers($token), (string) $case->json('data.id'))];
    }

    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['raw_code' => 'CI-PRIVATE-CODE-'.$i, 'quantity' => ($i % 3) + 1, 'scan_source' => 'CAMERA'];
        }

        return $rows;
    }

    private function draft(array $f, array $rows): array
    {
        $draft = ['id' => (string) Str::uuid(), 'scan_id' => (string) Str::uuid(), 'key' => (string) Str::uuid()];
        $draft['body'] = ['warehouse_id' => $f['warehouse_id'], 'operation' => 'COMPONENT_ISSUE',
            'scan_session_id' => $draft['scan_id'], 'codes' => $rows,
            'form' => ['p19' => ['contract' => P19::VERSION, 'warranty_case_id' => $f['case_id']]]];

        return $draft;
    }

    private function recordBody(array $f, array $rows): array
    {
        return ['warehouse_id' => $f['warehouse_id'], 'warranty_case_id' => $f['case_id'], 'post_immediately' => true, 'items' => $rows];
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function saveHeaders(array $f, array $draft): array
    {
        return $this->headers($f['token']) + ['Idempotency-Key' => $draft['key'], 'If-Match' => '"0"'];
    }

    private function recordHeaders(array $f, array $draft, string $key): array
    {
        return $this->headers($f['token']) + [P19::HEADER => P19::VERSION, 'Idempotency-Key' => $key,
            'X-Draft-ID' => $draft['id'], 'X-Draft-Scan-Session-ID' => $draft['scan_id'], 'X-Draft-Version' => '1'];
    }

    private function draftUrl(array $f, array $draft): string
    {
        return self::DRAFT.$draft['id'].'?'.http_build_query(['warehouse_id' => $f['warehouse_id'],
            'operation' => 'COMPONENT_ISSUE', 'scan_session_id' => $draft['scan_id']]);
    }

    private function businessCounts(): array
    {
        return array_map(static fn (string $table): int => DB::table($table)->count(), [
            'component_issue_documents', 'component_issue_lines', 'component_issue_items', 'stock_movements', 'scan_event',
        ]);
    }
}
