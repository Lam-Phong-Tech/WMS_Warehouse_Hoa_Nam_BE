<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use RuntimeException;
use Tests\TestCase;

/**
 * WWHN-266 · DEV266-P1-03 / DEV266-P1-04 — CORE_TRANSACTION hard gate cho
 * COMPONENT_ISSUE_POST.
 *
 * Vì sao bộ này tồn tại: SRS §13.3 "Stock-changing Action Test Design Matrix"
 * đánh số TC-P1-001..016 (POST_RECEIPT/POST_ISSUE), TC-P1-025..040
 * (POST_REVERSAL/MIGRATION_COMMIT) và **bỏ trống TC-P1-017..024**. Khoảng trống
 * đó rơi đúng vào `POST /component-issue-documents/{id}/post` — một action ĐỔI
 * TỒN thật (`CurrentBaselineStockService::postComponentIssue()` gọi
 * `mutateBalance(..., sign: -1)` và ghi movement `COMPONENT_ISSUE`). WWHN-234
 * F-07 đã ghi nhận khoảng trống này nhưng chưa có test nào lái action đó qua
 * HTTP; 4 file test có nhắc tới route chỉ kiểm route tồn tại/alias/tài liệu.
 *
 * Bộ này khoá 8 chiều của ma trận cho action đó, đánh số TC-P1-017..024 theo
 * đúng vị trí trống, và sinh số liệu thô cho hồ sơ ledger/balance
 * (`build/evidence/wwhn-266/component-issue-core-transaction.json`).
 *
 * Ranh giới sự thật: đây là lượt chạy in-process trên DB test của DEV, KHÔNG
 * thay thế lượt retest độc lập của QA1 (WWHN-264) trên build đã deploy.
 */
class Wwhn266ComponentIssueCoreTransactionTest extends TestCase
{
    use RefreshDatabase;

    private const EVIDENCE_PATH = 'build/evidence/wwhn-266/component-issue-core-transaction.json';

    /** @var array<string, mixed> */
    private static array $evidence = [];

    private function authHeadersWithRole(string $roleCode): array
    {
        if (DB::table('permission')->count() === 0) {
            $this->seed(AuthDatabaseSeeder::class);
            Cache::flush();
        }

        $role = Role::where('role_code', $roleCode)->firstOrFail();
        $user = User::create([
            'username' => 'w266-'.Str::lower(Str::random(8)),
            'display_name' => 'WWHN-266 fixture',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}", '__user_id' => (string) $user->user_id];
    }

    private function headers(array $auth): array
    {
        return ['Authorization' => $auth['Authorization']];
    }

    /**
     * Dựng tồn linh kiện bằng ĐÚNG ledger thật: inbound → box → scan →
     * post-receipt. Không INSERT thẳng `inventory_balances`; nếu seed tay thì
     * đối soát §7.5 sẽ lệch và số liệu đối soát mất giá trị (bài học WWHN-289).
     *
     * @return array{headers: array, warehouse_id: string, sku_id: string, container_id: string, case_id: string}
     */
    private function stockedComponent(int $qty, ?array $auth = null, ?string $warehouseId = null): array
    {
        $auth ??= $this->authHeadersWithRole('SUPER_ADMIN');
        $headers = $this->headers($auth);

        if ($warehouseId === null) {
            $warehouse = Warehouse::firstOrCreate(
                ['code' => 'KHO-TONG-HN'],
                ['name' => 'Kho tổng Hòa Nam', 'status' => 'ACTIVE']
            );
            $warehouseId = (string) $warehouse->id;
        }

        $sku = Sku::create([
            'sku_code' => 'LK-266-'.Str::upper(Str::random(6)),
            'sku_name' => 'Linh kiện bảo hành WWHN-266',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouseId,
            'source_name' => 'NCC WWHN-266',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => $qty]],
        ], $headers);
        $create->assertCreated();
        $inboundId = (string) $create->json('data.id');
        $inboundLineId = (string) $create->json('data.lines.0.id');

        $box = $this->postJson("/api/v1/inbound-documents/{$inboundId}/boxes", [
            'line_id' => $inboundLineId,
            'box_code' => 'BOX-266-'.Str::upper(Str::random(6)),
        ], $headers);
        $containerId = (string) $box->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$inboundId}/scan", [
            'line_id' => $inboundLineId,
            'quantity' => $qty,
            'container_id' => $containerId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$inboundId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w266-rcv-'.(string) Str::uuid(),
        ])->assertOk();

        // Subledger nội dung BOX chỉ được ghi bởi route quản trị container;
        // luồng inbound không tự sinh `container_items` (xem NOTE trong hồ sơ
        // BE266-D02). Component Issue quantity-mode đọc chính bảng này.
        $containerVersion = (int) DB::table('containers')->where('container_id', $containerId)->value('version');
        $this->postJson("/api/v1/containers/{$containerId}/items", [
            'sku_id' => $sku->sku_id,
            'quantity' => $qty,
            'source_type' => 'RECEIPT',
            'source_id' => $inboundId,
        ], $headers + ['If-Match' => (string) $containerVersion])->assertSuccessful();

        $case = $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => 'Khách WWHN-266',
            'customer_phone' => '0933333333',
            'description' => 'Máy không lên nguồn',
            'missing_code_reason' => 'Mất tem mã',
            'manual_product_description' => 'Máy khoan',
        ], $headers + ['Idempotency-Key' => 'w266-case-'.(string) Str::uuid()]);
        $case->assertCreated();

        return [
            'headers' => $headers,
            'warehouse_id' => $warehouseId,
            'sku_id' => (string) $sku->sku_id,
            'container_id' => $containerId,
            'case_id' => (string) $case->json('data.id'),
        ];
    }

    /** @return array{0:string,1:string} documentId, lineId */
    private function scannedComponentIssue(array $fixture, int $qty): array
    {
        $create = $this->postJson('/api/v1/component-issue-documents', [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'purpose' => 'WARRANTY',
            'lines' => [['sku_id' => $fixture['sku_id'], 'required_qty' => $qty]],
        ], $fixture['headers']);
        $create->assertCreated();
        $documentId = (string) $create->json('data.document.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $this->postJson("/api/v1/component-issue-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'container_id' => $fixture['container_id'],
            'quantity' => $qty,
        ], $fixture['headers'] + ['If-Match' => '1'])->assertOk();

        return [$documentId, $lineId];
    }

    private function balanceQty(string $warehouseId, string $skuId): int
    {
        return (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where(function ($query) use ($skuId): void {
                $query->where('sku_id', $skuId)->orWhere('product_id', $skuId);
            })
            ->where('stock_status', 'AVAILABLE')
            ->value('quantity');
    }

    private function componentMovements(string $documentId): int
    {
        return (int) DB::table('stock_movements')
            ->where('source_id', $documentId)
            ->where('movement_type', 'COMPONENT_ISSUE')
            ->count();
    }

    /**
     * Ghi dồn số liệu thô ngay trong lúc app container còn sống. Viết ở
     * tearDownAfterClass() thì Laravel đã tháo container và `base_path()`/
     * `DB::getDriverName()` ném BindingResolutionException.
     */
    private function record(string $tc, array $data): void
    {
        self::$evidence[$tc] = $data;

        $path = base_path(self::EVIDENCE_PATH);
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode([
            'ticket' => 'WWHN-266',
            'register_items' => ['DEV266-P1-03', 'DEV266-P1-04'],
            'action' => 'COMPONENT_ISSUE_POST',
            'matrix_slot' => 'TC-P1-017..TC-P1-024 (khoảng trống đánh số của SRS §13.3)',
            'driver' => DB::getDriverName(),
            'producer' => static::class,
            'dimensions' => self::$evidence,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /** TC-P1-017 · POSITIVE — trừ tồn đúng một lần, movement COMPONENT_ISSUE, audit + posting_event. */
    public function test_tc_p1_017_positive_component_issue_post_decrements_stock_exactly_once(): void
    {
        $fixture = $this->stockedComponent(10);
        [$documentId] = $this->scannedComponentIssue($fixture, 3);

        $before = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);
        $this->assertSame(10, $before);

        $response = $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", [], $fixture['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w266-tc017',
        ]);

        $response->assertOk()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertHeader('ETag', '"3"')
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.stock_effect', 'DECREASE')
            ->assertJsonPath('data.replayed', false);

        $after = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);
        $this->assertSame(7, $after, 'Tồn phải giảm đúng required_qty.');
        $this->assertSame(1, $this->componentMovements($documentId), 'Đúng một movement COMPONENT_ISSUE.');
        $this->assertSame('POSTED', (string) DB::table('component_issue_documents')->where('id', $documentId)->value('status'));

        $movement = DB::table('stock_movements')->where('source_id', $documentId)->first();
        $this->assertSame(-3, (int) $movement->qty_delta);
        $this->assertSame(10, (int) $movement->qty_before);
        $this->assertSame(7, (int) $movement->qty_after);
        $this->assertNotNull($movement->request_id, 'Movement phải neo request_id để truy vết.');

        $this->assertSame(
            1,
            (int) DB::table('posting_event')->where('operation', 'post_component_issue_'.$documentId)->count(),
            'Đúng một Posting_Event trong operation scope.'
        );
        $this->assertSame(
            1,
            (int) DB::table('audit_log')
                ->where('object_type', 'component_issue_document')
                ->where('object_id', $documentId)
                ->where('action', 'POST_COMPONENT_ISSUE')
                ->count(),
            'Đúng một audit row POST_COMPONENT_ISSUE.'
        );

        $this->record('TC-P1-017', [
            'dimension' => 'POSITIVE',
            'http' => $response->getStatusCode(),
            'balance_before' => $before,
            'balance_after' => $after,
            'qty_delta' => (int) $movement->qty_delta,
            'movements' => $this->componentMovements($documentId),
        ]);
    }

    /** TC-P1-018 · NEGATIVE — scan chưa đủ thì không có business write nào. */
    public function test_tc_p1_018_negative_partial_scan_is_rejected_without_stock_effect(): void
    {
        $fixture = $this->stockedComponent(10);
        $create = $this->postJson('/api/v1/component-issue-documents', [
            'warehouse_id' => $fixture['warehouse_id'],
            'warranty_case_id' => $fixture['case_id'],
            'purpose' => 'WARRANTY',
            'lines' => [['sku_id' => $fixture['sku_id'], 'required_qty' => 4]],
        ], $fixture['headers']);
        $documentId = (string) $create->json('data.document.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $this->postJson("/api/v1/component-issue-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'container_id' => $fixture['container_id'],
            'quantity' => 2,
        ], $fixture['headers'] + ['If-Match' => '1'])->assertOk();

        $before = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);
        $response = $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", [], $fixture['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w266-tc018',
        ]);

        $response->assertStatus(422)->assertJsonPath('error_code', 'FULL_POST_REQUIRED');
        $this->assertNotNull($response->json('meta.request_id'), 'Lỗi nghiệp vụ vẫn phải có request_id.');
        $this->assertSame($before, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
        $this->assertSame(0, $this->componentMovements($documentId));
        $this->assertSame('SCANNING', (string) DB::table('component_issue_documents')->where('id', $documentId)->value('status'));
        $this->assertSame(0, (int) DB::table('posting_event')->where('operation', 'post_component_issue_'.$documentId)->count());

        $this->record('TC-P1-018', [
            'dimension' => 'NEGATIVE',
            'http' => 422,
            'error_code' => $response->json('error_code'),
            'balance_unchanged' => $before,
            'movements' => 0,
        ]);
    }

    /** TC-P1-019 · PERMISSION — thiếu quyền thì không có tác động tồn. */
    public function test_tc_p1_019_permission_role_without_component_issue_cannot_post(): void
    {
        $fixture = $this->stockedComponent(10);
        [$documentId] = $this->scannedComponentIssue($fixture, 2);

        $before = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);
        $stranger = $this->headers($this->authHeadersWithRole('LEADERSHIP'));

        $response = $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", [], $stranger + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w266-tc019',
        ]);

        $this->assertContains($response->getStatusCode(), [403, 404], 'Thiếu quyền phải là 403 hoặc 404 theo masking policy.');
        $this->assertSame($before, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
        $this->assertSame(0, $this->componentMovements($documentId));
        $this->assertStringNotContainsString('Exception', (string) $response->getContent());

        $this->record('TC-P1-019', [
            'dimension' => 'PERMISSION',
            'http' => $response->getStatusCode(),
            'error_code' => $response->json('error_code'),
            'balance_unchanged' => $before,
        ]);
    }

    /** TC-P1-020 · INVALID_STATE — đã POSTED thì post lại phải 409, không có tác động lần hai. */
    public function test_tc_p1_020_invalid_state_reposting_a_posted_document_is_rejected(): void
    {
        $fixture = $this->stockedComponent(10);
        [$documentId] = $this->scannedComponentIssue($fixture, 3);

        $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", [], $fixture['headers'] + [
            'If-Match' => '2', 'Idempotency-Key' => 'w266-tc020-a',
        ])->assertOk();

        $after = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        // Key MỚI + version hiện hành = hai người bấm Post trên cùng chứng từ.
        $response = $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", [], $fixture['headers'] + [
            'If-Match' => '3', 'Idempotency-Key' => 'w266-tc020-b',
        ]);

        $response->assertStatus(409)->assertJsonPath('error_code', 'STATE_CONFLICT');
        $this->assertSame($after, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']), 'Không được trừ tồn lần hai.');
        $this->assertSame(1, $this->componentMovements($documentId));

        // Version cũ = client chưa refresh: phải chặn ở tầng optimistic lock.
        $stale = $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", [], $fixture['headers'] + [
            'If-Match' => '2', 'Idempotency-Key' => 'w266-tc020-c',
        ]);
        $stale->assertStatus(412)->assertJsonPath('error_code', 'STALE_VERSION');
        $this->assertSame(1, $this->componentMovements($documentId));

        $this->record('TC-P1-020', [
            'dimension' => 'INVALID_STATE',
            'repost_http' => 409,
            'stale_http' => 412,
            'balance_after' => $after,
            'movements' => 1,
        ]);
    }

    /** TC-P1-021 · RETRY_SAME_KEY — 3 lần cùng key/payload = một tác động tồn. */
    public function test_tc_p1_021_retry_same_key_produces_exactly_one_stock_effect(): void
    {
        $fixture = $this->stockedComponent(10);
        [$documentId] = $this->scannedComponentIssue($fixture, 4);

        $replayFlags = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", [], $fixture['headers'] + [
                'If-Match' => '2',
                'Idempotency-Key' => 'w266-tc021',
            ]);
            $response->assertOk()->assertJsonPath('data.status', 'POSTED');
            $replayFlags[] = $response->headers->get('Idempotent-Replay');
        }

        $this->assertSame(['false', 'true', 'true'], $replayFlags, 'Lần đầu commit, hai lần sau là replay.');
        $this->assertSame(6, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']), 'Tồn chỉ giảm một lần.');
        $this->assertSame(1, $this->componentMovements($documentId));
        $this->assertSame(1, (int) DB::table('posting_event')->where('operation', 'post_component_issue_'.$documentId)->count());
        $this->assertSame(3, (int) DB::table('component_issue_documents')->where('id', $documentId)->value('version'), 'Replay không được bump version lần hai.');

        $this->record('TC-P1-021', [
            'dimension' => 'RETRY_SAME_KEY',
            'replay_flags' => $replayFlags,
            'balance_after' => 6,
            'movements' => 1,
            'posting_events' => 1,
        ]);
    }

    /** TC-P1-022 · SAME_KEY_DIFF_PAYLOAD — 409 IDEMPOTENCY_CONFLICT, không có write thứ hai. */
    public function test_tc_p1_022_same_key_with_different_payload_is_rejected(): void
    {
        $fixture = $this->stockedComponent(10);
        [$documentId] = $this->scannedComponentIssue($fixture, 2);

        $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", ['note' => 'payload-H1'], $fixture['headers'] + [
            'If-Match' => '2', 'Idempotency-Key' => 'w266-tc022',
        ])->assertOk();

        $balance = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        $conflict = $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", ['note' => 'payload-H2'], $fixture['headers'] + [
            'If-Match' => '2', 'Idempotency-Key' => 'w266-tc022',
        ]);

        $conflict->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame($balance, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
        $this->assertSame(1, $this->componentMovements($documentId));

        $this->record('TC-P1-022', [
            'dimension' => 'SAME_KEY_DIFF_PAYLOAD',
            'http' => 409,
            'error_code' => 'IDEMPOTENCY_CONFLICT',
            'balance_after' => $balance,
            'movements' => 1,
        ]);
    }

    /** TC-P1-023 · CONCURRENCY — hai phiếu tranh cùng tồn: tối đa một commit, không âm kho. */
    public function test_tc_p1_023_concurrency_two_documents_racing_the_same_balance(): void
    {
        $fixture = $this->stockedComponent(5);

        [$first] = $this->scannedComponentIssue($fixture, 3);
        [$second] = $this->scannedComponentIssue($fixture, 3);

        $a = $this->postJson("/api/v1/component-issue-documents/{$first}/post", [], $fixture['headers'] + [
            'If-Match' => '2', 'Idempotency-Key' => 'w266-tc023-a',
        ]);
        $b = $this->postJson("/api/v1/component-issue-documents/{$second}/post", [], $fixture['headers'] + [
            'If-Match' => '2', 'Idempotency-Key' => 'w266-tc023-b',
        ]);

        $codes = [$a->getStatusCode(), $b->getStatusCode()];
        $winners = count(array_filter($codes, fn (int $code): bool => $code === 200));
        $this->assertSame(1, $winners, 'Tối đa một request được commit trên cùng tồn.');

        $loser = $a->getStatusCode() === 200 ? $b : $a;
        $this->assertContains($loser->getStatusCode(), [409, 412, 422], 'Request thua phải trả 409/412/422 theo điểm phát hiện.');

        $balance = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);
        $this->assertSame(2, $balance, 'Chỉ một lần trừ 3 trên tồn 5.');
        $this->assertGreaterThanOrEqual(0, $balance, 'Không được âm kho.');
        $this->assertSame(
            1,
            $this->componentMovements($first) + $this->componentMovements($second),
            'Chỉ một movement COMPONENT_ISSUE được ghi.'
        );

        $this->record('TC-P1-023', [
            'dimension' => 'CONCURRENCY',
            'http_codes' => $codes,
            'loser_error' => $loser->json('error_code'),
            'balance_after' => $balance,
            'movements_total' => 1,
        ]);
    }

    /** TC-P1-024 · ROLLBACK — lỗi hạ tầng sau khi ghi movement phải rollback toàn bộ và retry được. */
    public function test_tc_p1_024_rollback_injected_failure_reverts_document_movement_and_balance(): void
    {
        $fixture = $this->stockedComponent(10);
        [$documentId] = $this->scannedComponentIssue($fixture, 3);

        $movementsBefore = (int) DB::table('stock_movements')->count();
        $balanceBefore = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);
        $containerBefore = (int) DB::table('container_items')
            ->where('container_id', $fixture['container_id'])
            ->where('is_active', true)
            ->sum('quantity');

        $injected = false;
        Event::listen(function (QueryExecuted $event) use (&$injected): void {
            if ($injected) {
                return;
            }
            $sql = strtolower($event->sql);
            if (str_contains($sql, 'insert into') && str_contains($sql, 'stock_movements')) {
                $injected = true;
                throw new RuntimeException('WWHN-266 injected infrastructure failure after movement insert');
            }
        });

        $failed = $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", [], $fixture['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w266-tc024',
        ]);

        Event::forget(QueryExecuted::class);

        $this->assertTrue($injected, 'Lỗi phải được tiêm đúng vào INSERT stock_movements.');
        $failed->assertStatus(500);
        foreach (['insert into', 'stock_movements', 'RuntimeException', '#0 ', 'vendor/laravel'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, (string) $failed->getContent(), "500 không được lộ chi tiết nội bộ: {$leak}");
        }

        $this->assertSame($movementsBefore, (int) DB::table('stock_movements')->count(), 'Movement phải rollback.');
        $this->assertSame($balanceBefore, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']), 'Balance phải rollback.');
        $this->assertSame(
            $containerBefore,
            (int) DB::table('container_items')->where('container_id', $fixture['container_id'])->where('is_active', true)->sum('quantity'),
            'Subledger BOX phải rollback cùng giao dịch.'
        );
        $document = DB::table('component_issue_documents')->where('id', $documentId)->first();
        $this->assertSame('SCANNING', (string) $document->status, 'Trạng thái chứng từ phải giữ nguyên.');
        $this->assertSame(2, (int) $document->version, 'Version không được bump khi giao dịch rollback.');

        // Retry sau sự cố phải Post được: lease idempotency đã nhả, không kẹt vĩnh viễn.
        $retry = $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", [], $fixture['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w266-tc024-retry',
        ]);
        $retry->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->assertSame($balanceBefore - 3, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        $this->record('TC-P1-024', [
            'dimension' => 'ROLLBACK',
            'failed_http' => 500,
            'balance_rolled_back_to' => $balanceBefore,
            'retry_http' => 200,
            'balance_after_retry' => $balanceBefore - 3,
        ]);
    }

    /**
     * DEV266-P1-04 — hồ sơ ledger/balance: mọi số dư đến từ movement thật,
     * đối soát §7.5 phải delta = 0 sau khi Component Issue đã trừ tồn.
     */
    public function test_ledger_balance_and_reconciliation_delta_is_zero_after_component_issue(): void
    {
        $fixture = $this->stockedComponent(9);
        [$documentId] = $this->scannedComponentIssue($fixture, 4);

        $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", [], $fixture['headers'] + [
            'If-Match' => '2', 'Idempotency-Key' => 'w266-reconcile',
        ])->assertOk();

        // Nguồn 1: chính báo cáo đối soát đang được kiểm chứng.
        $report = $this->getJson('/api/v1/reports/inventory-reconciliation?product_id='.$fixture['sku_id'], $fixture['headers']);
        $report->assertOk()
            ->assertJsonPath('data.summary.mismatch_count', 0)
            ->assertJsonPath('data.summary.status', 'PASS')
            ->assertJsonPath('data.summary.overall_status', 'PASS')
            ->assertJsonPath('data.rows.0.reconciliation_status', 'MATCH')
            ->assertJsonPath('data.rows.0.variance', 0);

        // Nguồn 2: SQL thô độc lập — reviewer không phải tin một nguồn duy nhất.
        $ledgerSum = (int) DB::table('stock_movements')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('stock_status', 'AVAILABLE')
            ->where(function ($query) use ($fixture): void {
                $query->where('sku_id', $fixture['sku_id'])->orWhere('product_id', $fixture['sku_id']);
            })
            ->sum('qty_delta');
        $balance = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        $this->assertSame(5, $ledgerSum, 'Ledger: +9 nhập, -4 xuất linh kiện.');
        $this->assertSame($ledgerSum, $balance, 'Balance phải bằng tổng qty_delta của ledger.');

        // Movement gốc là append-only: không có UPDATE/DELETE lên movement đã ghi.
        $types = DB::table('stock_movements')->pluck('movement_type')->unique()->sort()->values()->all();
        $this->assertSame(['COMPONENT_ISSUE', 'RECEIPT'], $types);

        $this->record('LEDGER_BALANCE_RECONCILE', [
            'register_item' => 'DEV266-P1-04',
            'ledger_sum_qty_delta' => $ledgerSum,
            'balance_on_hand' => $balance,
            'reconcile_mismatch_count' => $report->json('data.summary.mismatch_count'),
            'reconcile_status' => $report->json('data.summary.status'),
            'reconcile_overall_status' => $report->json('data.summary.overall_status'),
            'variance' => $report->json('data.rows.0.variance'),
            'movement_types' => $types,
        ]);
    }
}
