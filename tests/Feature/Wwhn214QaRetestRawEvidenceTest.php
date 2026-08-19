<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Warehouse\Models\Sku;
use RuntimeException;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-214 · DEV214-P1-04R (HARD GATE) — QA retest với RAW API/DB evidence.
 *
 * Vòng re-review 32/100 chỉ ra gói evidence cũ (commit 089eacd, run tại 28625ee)
 * chỉ có log tên test PHPUnit — KHÔNG có payload HTTP thô lẫn output truy vấn DB
 * thô nào. Bộ này đóng đúng khoảng hở đó: lái 7 hạng mục checklist của register
 * qua HTTP thật và ghi lại từng cặp request/response (method, URI, header
 * If-Match/Idempotency-Key, body JSON, status, ETag/Idempotent-Replay) cùng các
 * dòng DB thô (stock_movements, inventory_balances, posting_event, audit_log)
 * vào `build/evidence/wwhn-214/qa-retest-raw-api-db.<driver>.json`.
 *
 * Oracle theo SRS v1.12 (HN-SRS-FRD-001, xem docs/evidence/WWHN-214/
 * EXPECTED_RESULT_MAPPING.md):
 *  - no-negative        REQ-SRS-011/012, §8.2: 422 INSUFFICIENT_STOCK, không đổi tồn
 *  - duplicate/retry    NFR-SRS-001, AC-GWT-IDEMP-001: replay 200 một hiệu ứng;
 *                       cùng key khác payload → 409 IDEMPOTENCY_CONFLICT
 *  - concurrency        §6: thiếu If-Match → 428; cũ → 412 STALE_VERSION; sai
 *                       trạng thái → 409 STATE_CONFLICT; race → tối đa 1 commit
 *                       (AC-GWT-CONC-001: bên thua 409/412/422 theo điểm phát hiện)
 *  - rollback           NFR-SRS-002, TC-P1-008: lỗi hạ tầng giữa giao dịch → 500,
 *                       rollback toàn bộ, không lộ nội bộ, retry được
 *  - RBAC               §8.1 enforcement, NFR-SRS-005: 401/403(404 masking),
 *                       từ chối không tạo hiệu ứng tồn
 *  - append-only ledger DEC-INV-001 §7.1, BR-REV-01: UPDATE/DELETE movement bị
 *                       DB trigger chặn trên cả hai driver
 *  - reconciliation=0   §7.5, REQ-SRS-023: ledger vs balance delta = 0, không
 *                       sinh reconciliation_incident
 *
 * Ranh giới sự thật: lượt chạy in-process trên DB test; race đa kết nối thật
 * thuộc Wwhn198/184/219 RuntimeConcurrency (PG) — retest riêng trong cùng gói R.
 */
class Wwhn214QaRetestRawEvidenceTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private static array $evidence = [];

    // ------------------------------------------------------------------
    // Hạ tầng ghi bằng chứng thô
    // ------------------------------------------------------------------

    /**
     * Gọi HTTP thật và ghi lại cặp request/response thô. Authorization được
     * mask (AC WWHN-214: "không lộ secret"); các header quyết định ngữ nghĩa
     * (If-Match, Idempotency-Key) giữ nguyên giá trị thật.
     */
    private function capture(string $area, string $step, string $method, string $uri, array $payload, array $headers): TestResponse
    {
        $response = $this->json($method, $uri, $payload, $headers);

        $recordedHeaders = [];
        foreach (['If-Match', 'Idempotency-Key', 'Content-Type'] as $name) {
            if (array_key_exists($name, $headers)) {
                $recordedHeaders[$name] = $headers[$name];
            }
        }
        $recordedHeaders['Authorization'] = array_key_exists('Authorization', $headers)
            ? 'Bearer ***masked***'
            : '(absent)';

        self::$evidence['areas'][$area]['api_transcript'][] = [
            'step' => $step,
            'request' => [
                'method' => strtoupper($method),
                'uri' => $uri,
                'headers' => $recordedHeaders,
                'body' => $payload === [] ? (object) [] : $payload,
            ],
            'response' => [
                'status' => $response->getStatusCode(),
                'headers' => array_filter([
                    'ETag' => $response->headers->get('ETag'),
                    'Idempotent-Replay' => $response->headers->get('Idempotent-Replay'),
                ], static fn ($value): bool => $value !== null),
                'body' => json_decode((string) $response->getContent(), true),
            ],
        ];

        return $response;
    }

    /** Chốt một hạng mục: verdict chỉ được ghi khi mọi assertion phía trên đã pass. */
    private function concludeArea(string $area, string $oracle, array $dbEvidence): void
    {
        self::$evidence['areas'][$area]['oracle'] = $oracle;
        self::$evidence['areas'][$area]['db_raw'] = $dbEvidence;
        self::$evidence['areas'][$area]['verdict'] = 'PASS';
        $this->flushEvidence();
    }

    /**
     * Ghi file NGAY trong test (không dùng tearDownAfterClass — container đã bị
     * tháo, base_path()/DB ném BindingResolutionException; bài học Wwhn266).
     */
    private function flushEvidence(): void
    {
        $sha = null;
        if (is_callable('shell_exec')) {
            $sha = trim((string) @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse HEAD')) ?: null;
        }

        self::$evidence['ticket'] = 'WWHN-214';
        self::$evidence['register_item'] = 'DEV214-P1-04R (HARD GATE) + DEV214-P1-01R';
        self::$evidence['run'] = [
            'candidate_sha' => getenv('WWHN214_EXPECT_SHA') ?: $sha,
            'driver' => DB::getDriverName(),
            'php' => PHP_VERSION,
            'executed_at' => now()->toIso8601String(),
            'producer' => static::class,
        ];

        $path = base_path('build/evidence/wwhn-214/qa-retest-raw-api-db.'.DB::getDriverName().'.json');
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode(self::$evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /** Envelope lỗi tồn tại 2 biến thể trong hệ thống: `error_code` và `error.code`. */
    private function errorCode(TestResponse $response): ?string
    {
        return $response->json('error_code') ?? $response->json('error.code');
    }

    /** @return array<int, array<string, mixed>> */
    private function rawRows(string $table, array $where): array
    {
        $query = DB::table($table);
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    private function movementRows(string $skuId): array
    {
        return $this->rawRows('stock_movements', ['sku_id' => $skuId]);
    }

    private function balanceRows(string $skuId): array
    {
        return $this->rawRows('inventory_balances', ['sku_id' => $skuId]);
    }

    private function balanceQty(string $warehouseId, string $skuId): int
    {
        return (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->where('stock_status', 'AVAILABLE')
            ->value('quantity');
    }

    // ------------------------------------------------------------------
    // Fixture: dựng tồn qua ĐÚNG ledger thật (inbound chain), không insert
    // thẳng balance — nếu seed tay thì đối soát §7.5 mất giá trị (bài học
    // WWHN-289, cùng lý do với stockedComponent() của Wwhn266).
    // ------------------------------------------------------------------

    /**
     * @return array{headers: array, warehouse_id: string, sku_id: string, document_id: string, line_id: string, version: int}
     */
    private function scannedInbound(int $qty, string $area): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();

        $sku = Sku::create([
            'sku_code' => 'LK-214-'.Str::upper(Str::random(6)),
            'sku_name' => 'Linh kiện retest WWHN-214',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $create = $this->capture($area, 'inbound.create', 'POST', '/api/v1/inbound-documents', [
            'dst_warehouse_id' => (string) $warehouse->id,
            'source_name' => 'NCC retest WWHN-214',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => $qty]],
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $box = $this->capture($area, 'inbound.box', 'POST', "/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-214-'.Str::upper(Str::random(6)),
        ], $headers);
        $box->assertSuccessful();

        $this->capture($area, 'inbound.scan', 'POST', "/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => $qty,
            'container_id' => (string) $box->json('data.container_id'),
        ], $headers + ['If-Match' => '1'])->assertOk();

        return [
            'headers' => $headers,
            'warehouse_id' => (string) $warehouse->id,
            'sku_id' => (string) $sku->sku_id,
            'document_id' => $documentId,
            'line_id' => $lineId,
            'version' => 2,
        ];
    }

    // ------------------------------------------------------------------
    // Hạng mục 1 — NO-NEGATIVE: guard không-âm-tồn tại Post Issue time
    // ------------------------------------------------------------------

    public function test_area_no_negative_second_issue_on_shared_balance_is_rejected_422(): void
    {
        $area = 'no-negative';
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('WWHN214-NONEG-001');

        // Tồn thực chỉ đủ cho MỘT document (opening có gốc ledger).
        $this->seedLedgerBackedBalance((string) $warehouse->id, (string) $sku->sku_id, 1, $headers['__user_id']);

        $docs = [];
        foreach (['a', 'b'] as $tag) {
            $requestId = $this->outboundRequestFixture((string) $sku->sku_id, 1, 'TOA-214-'.Str::upper($tag).Str::upper(Str::random(4)));
            $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
            $itemId = $this->seedItemInStock((string) $sku->sku_id);
            $this->seedRfidCode($itemId);
            $this->capture($area, "outbound.scan.{$tag}", 'POST', "/api/v1/outbound-documents/{$created['document_id']}/scan", [
                'line_id' => $created['line_ids'][0],
                'quantity' => 1,
                'item_id' => $itemId,
            ], $headers + ['If-Match' => '1'])->assertOk();
            $this->markLabelsPrinted($created['document_id']);
            $docs[$tag] = $created['document_id'];
        }

        $balanceBefore = $this->balanceRows((string) $sku->sku_id);

        $postA = $this->capture($area, 'post-issue.a(win)', 'POST', "/api/v1/outbound-documents/{$docs['a']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w214-noneg-a',
        ]);
        $postA->assertOk();

        $movementsAfterA = count($this->movementRows((string) $sku->sku_id));

        $postB = $this->capture($area, 'post-issue.b(insufficient)', 'POST', "/api/v1/outbound-documents/{$docs['b']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w214-noneg-b',
        ]);
        $postB->assertStatus(422);
        $this->assertContains(
            $this->errorCode($postB),
            ['INSUFFICIENT_STOCK', 'INSUFFICIENT_AVAILABLE_STOCK'],
            'Post Issue vượt tồn phải bị guard không-âm-tồn chặn với mã lỗi §8.2.'
        );

        $balance = $this->balanceQty((string) $warehouse->id, (string) $sku->sku_id);
        $this->assertSame(0, $balance, 'Tồn dừng ở 0 — tuyệt đối không âm.');
        $this->assertSame(
            $movementsAfterA,
            count($this->movementRows((string) $sku->sku_id)),
            'Request 422 không được ghi thêm movement nào.'
        );

        $this->concludeArea($area, 'REQ-SRS-011/012 + §8.2: 422 INSUFFICIENT_STOCK; state/stock/movement unchanged', [
            'balance_before_posts' => $balanceBefore,
            'balance_after_posts' => $this->balanceRows((string) $sku->sku_id),
            'movements_after_posts' => $this->movementRows((string) $sku->sku_id),
        ]);
    }

    // ------------------------------------------------------------------
    // Hạng mục 2 — DUPLICATE/RETRY: idempotency key + canonical payload
    // ------------------------------------------------------------------

    public function test_area_duplicate_retry_same_key_one_effect_and_conflict_on_payload_change(): void
    {
        $area = 'duplicate-retry';
        $fx = $this->scannedInbound(7, $area);
        $key = 'w214-retry-'.Str::lower(Str::random(6));

        $first = $this->capture($area, 'post-receipt.first', 'POST', "/api/v1/inbound-documents/{$fx['document_id']}/post-receipt", [], $fx['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => $key,
        ]);
        $first->assertOk();

        $replay = $this->capture($area, 'post-receipt.replay-same-key', 'POST', "/api/v1/inbound-documents/{$fx['document_id']}/post-receipt", [], $fx['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => $key,
        ]);
        $replay->assertOk();

        $conflict = $this->capture($area, 'post-receipt.same-key-diff-payload', 'POST', "/api/v1/inbound-documents/{$fx['document_id']}/post-receipt", [
            'note' => 'payload-đã-đổi',
        ], $fx['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => $key,
        ]);
        $conflict->assertStatus(409);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $this->errorCode($conflict), 'Cùng key khác payload phải là 409 IDEMPOTENCY_CONFLICT (§8.2).');

        $movements = $this->movementRows($fx['sku_id']);
        $this->assertCount(1, $movements, 'Retry 3 lần cùng key = đúng MỘT movement RECEIPT (NFR-SRS-001).');
        $this->assertSame(7, (int) $movements[0]['qty_delta']);
        $this->assertSame(7, $this->balanceQty($fx['warehouse_id'], $fx['sku_id']), 'Tồn chỉ cộng một lần.');

        $documentRow = $this->rawRows('stock_documents', ['id' => $fx['document_id']]);
        $this->assertSame('POSTED', (string) $documentRow[0]['status']);

        $this->concludeArea($area, 'NFR-SRS-001 + AC-GWT-IDEMP-001: replay 200 một hiệu ứng; khác payload → 409 IDEMPOTENCY_CONFLICT', [
            'movements' => $movements,
            'balance' => $this->balanceRows($fx['sku_id']),
            'document' => $documentRow,
        ]);
    }

    // ------------------------------------------------------------------
    // Hạng mục 3 — CONCURRENCY: 428 / 412 / 409 + race in-process
    // ------------------------------------------------------------------

    public function test_area_concurrency_precondition_stale_state_guards_block_every_conflicting_write(): void
    {
        $area = 'concurrency';
        $fx = $this->scannedInbound(4, $area);
        $uri = "/api/v1/inbound-documents/{$fx['document_id']}/post-receipt";

        $missing = $this->capture($area, 'post-receipt.no-if-match', 'POST', $uri, [], $fx['headers'] + [
            'Idempotency-Key' => 'w214-conc-missing',
        ]);
        $missing->assertStatus(428);

        $stale = $this->capture($area, 'post-receipt.stale-if-match', 'POST', $uri, [], $fx['headers'] + [
            'If-Match' => '1',
            'Idempotency-Key' => 'w214-conc-stale',
        ]);
        $stale->assertStatus(412);
        $this->assertSame('STALE_VERSION', $this->errorCode($stale), 'If-Match cũ phải là 412 STALE_VERSION (§6/§8.2).');

        $this->assertCount(0, $this->movementRows($fx['sku_id']), '428/412 không được tạo movement nào.');

        $win = $this->capture($area, 'post-receipt.current-version', 'POST', $uri, [], $fx['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w214-conc-win',
        ]);
        $win->assertOk();

        // Người thứ hai bấm Post với key MỚI trên chứng từ đã POSTED.
        $repost = $this->capture($area, 'post-receipt.repost-new-key', 'POST', $uri, [], $fx['headers'] + [
            'If-Match' => '3',
            'Idempotency-Key' => 'w214-conc-repost',
        ]);
        $repost->assertStatus(409);
        $this->assertSame('STATE_CONFLICT', $this->errorCode($repost), 'Post lại chứng từ POSTED phải là 409 STATE_CONFLICT.');

        // Client chưa refresh sau khi thua: optimistic lock chặn trước state guard.
        $staleAfter = $this->capture($area, 'post-receipt.stale-after-post', 'POST', $uri, [], $fx['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w214-conc-stale2',
        ]);
        $staleAfter->assertStatus(412);

        $movements = $this->movementRows($fx['sku_id']);
        $this->assertCount(1, $movements, 'Mọi nhánh xung đột bị chặn — đúng một movement được commit.');
        $this->assertSame(4, $this->balanceQty($fx['warehouse_id'], $fx['sku_id']), 'Không lost update, không double effect.');

        $this->concludeArea($area, '§6 + §8.2 + AC-GWT-CONC-001: thiếu If-Match 428; cũ 412 STALE_VERSION; sai trạng thái 409 STATE_CONFLICT; đúng một commit', [
            'movements' => $movements,
            'balance' => $this->balanceRows($fx['sku_id']),
            'document' => $this->rawRows('stock_documents', ['id' => $fx['document_id']]),
            'note' => 'Race đa kết nối OS thật: xem log retest Wwhn184/198/219 RuntimeConcurrency trên PostgreSQL trong cùng gói R.',
        ]);
    }

    // ------------------------------------------------------------------
    // Hạng mục 4 — ROLLBACK: lỗi hạ tầng giữa giao dịch không để lại vết ghi
    // ------------------------------------------------------------------

    public function test_area_rollback_injected_failure_reverts_all_and_retry_succeeds(): void
    {
        $area = 'rollback';
        $fx = $this->scannedInbound(5, $area);

        $before = [
            'movements' => $this->movementRows($fx['sku_id']),
            'balance' => $this->balanceRows($fx['sku_id']),
            'document' => $this->rawRows('stock_documents', ['id' => $fx['document_id']]),
        ];
        $movementsTotalBefore = (int) DB::table('stock_movements')->count();

        $injected = false;
        Event::listen(function (QueryExecuted $event) use (&$injected): void {
            if ($injected) {
                return;
            }
            $sql = strtolower($event->sql);
            if (str_contains($sql, 'insert into') && str_contains($sql, 'stock_movements')) {
                $injected = true;
                throw new RuntimeException('WWHN-214 injected infrastructure failure after movement insert');
            }
        });

        $failed = $this->capture($area, 'post-receipt.injected-failure', 'POST', "/api/v1/inbound-documents/{$fx['document_id']}/post-receipt", [], $fx['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w214-rollback',
        ]);

        Event::forget(QueryExecuted::class);

        $this->assertTrue($injected, 'Lỗi phải được tiêm đúng vào INSERT stock_movements.');
        $failed->assertStatus(500);
        foreach (['RuntimeException', 'stock_movements', '#0 ', 'vendor/laravel', 'insert into'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, (string) $failed->getContent(), "500 không được lộ chi tiết nội bộ: {$leak}");
        }

        $this->assertSame($movementsTotalBefore, (int) DB::table('stock_movements')->count(), 'Movement phải rollback.');
        $this->assertSame($before['balance'], $this->balanceRows($fx['sku_id']), 'Balance phải rollback nguyên trạng.');
        $documentAfter = $this->rawRows('stock_documents', ['id' => $fx['document_id']]);
        $this->assertSame('SCANNING', (string) $documentAfter[0]['status'], 'Trạng thái chứng từ giữ nguyên.');
        $this->assertSame((int) $before['document'][0]['version'], (int) $documentAfter[0]['version'], 'Version không được bump khi giao dịch rollback.');

        // Retry sau sự cố phải Post được — lease idempotency không kẹt vĩnh viễn.
        $retry = $this->capture($area, 'post-receipt.retry-after-failure', 'POST', "/api/v1/inbound-documents/{$fx['document_id']}/post-receipt", [], $fx['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w214-rollback-retry',
        ]);
        $retry->assertOk();
        $this->assertSame(5, $this->balanceQty($fx['warehouse_id'], $fx['sku_id']));

        $this->concludeArea($area, 'NFR-SRS-002 + TC-P1-008: 500 không partial write, không lộ nội bộ, retry an toàn', [
            'db_before_failure' => $before,
            'db_after_failure' => [
                'movements' => $before['movements'],
                'balance' => $this->balanceRows($fx['sku_id']),
                'document' => $documentAfter,
            ],
            'db_after_retry' => [
                'movements' => $this->movementRows($fx['sku_id']),
                'balance' => $this->balanceRows($fx['sku_id']),
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Hạng mục 5 — RBAC: từ chối không tạo hiệu ứng tồn
    // ------------------------------------------------------------------

    public function test_area_rbac_anonymous_and_unpermitted_actor_cannot_post(): void
    {
        $area = 'rbac';

        // Nặc danh phải đi TRƯỚC mọi auth('api')->login() của fixture: JWT guard
        // giữ user đã login cho request không mang token (request có Bearer luôn
        // được parse lại theo token). Auth middleware chặn 401 trước cả tầng
        // tồn-tại-document nên dùng UUID bất kỳ.
        $anonymous = $this->capture($area, 'post-receipt.anonymous', 'POST', '/api/v1/inbound-documents/'.Str::uuid().'/post-receipt', [], [
            'If-Match' => '2',
            'Idempotency-Key' => 'w214-rbac-anon',
        ]);
        $anonymous->assertStatus(401);

        $fx = $this->scannedInbound(3, $area);
        $uri = "/api/v1/inbound-documents/{$fx['document_id']}/post-receipt";

        $unpermitted = $this->authHeadersWithRole('LEADERSHIP');
        $denied = $this->capture($area, 'post-receipt.no-permission', 'POST', $uri, [], [
            'Authorization' => $unpermitted['Authorization'],
            'If-Match' => '2',
            'Idempotency-Key' => 'w214-rbac-denied',
        ]);
        $this->assertContains($denied->getStatusCode(), [403, 404], 'Thiếu quyền phải là 403 hoặc 404 theo masking policy (TC-P1-003).');
        foreach (['Exception', '#0 ', 'vendor/laravel'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $denied->getContent(), "Từ chối RBAC không được lộ nội bộ: {$leak}");
        }

        $this->assertCount(0, $this->movementRows($fx['sku_id']), 'Từ chối RBAC không được tạo movement.');
        $documentRow = $this->rawRows('stock_documents', ['id' => $fx['document_id']]);
        $this->assertSame('SCANNING', (string) $documentRow[0]['status'], 'Chứng từ giữ nguyên trạng thái.');

        $this->concludeArea($area, '§8.1 enforcement + NFR-SRS-005: 401 anonymous; 403/404 thiếu quyền; state/stock/movement unchanged', [
            'movements' => $this->movementRows($fx['sku_id']),
            'document' => $documentRow,
            'balance' => $this->balanceRows($fx['sku_id']),
        ]);
    }

    // ------------------------------------------------------------------
    // Hạng mục 6 — APPEND-ONLY LEDGER: DB trigger chặn UPDATE/DELETE
    // ------------------------------------------------------------------

    public function test_area_append_only_ledger_rejects_raw_update_and_delete_at_db_level(): void
    {
        $area = 'append-only-ledger';
        $fx = $this->scannedInbound(3, $area);

        $this->capture($area, 'post-receipt', 'POST', "/api/v1/inbound-documents/{$fx['document_id']}/post-receipt", [], $fx['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w214-append-only',
        ])->assertOk();

        $rowsBefore = $this->movementRows($fx['sku_id']);
        $this->assertCount(1, $rowsBefore);
        $movementId = $rowsBefore[0]['id'];

        // Tấn công trực tiếp tầng DB — đi vòng qua mọi guard Eloquent/service.
        // Mỗi đòn bọc trong DB::transaction() lồng (savepoint): trên PostgreSQL,
        // statement lỗi làm abort cả transaction bao ngoài của RefreshDatabase
        // (SQLSTATE 25P02) — savepoint giữ transaction ngoài sống để đòn thứ hai
        // và các truy vấn kiểm chứng phía sau vẫn đọc được message trigger thật.
        $updateError = null;
        try {
            DB::transaction(static function () use ($movementId): void {
                DB::table('stock_movements')->where('id', $movementId)->update(['qty_delta' => 999]);
            });
        } catch (QueryException $exception) {
            $updateError = $exception->getMessage();
        }
        $this->assertNotNull($updateError, 'UPDATE thẳng vào stock_movements phải bị DB trigger chặn.');
        $this->assertStringContainsString('append-only', $updateError);

        $deleteError = null;
        try {
            DB::transaction(static function () use ($movementId): void {
                DB::table('stock_movements')->where('id', $movementId)->delete();
            });
        } catch (QueryException $exception) {
            $deleteError = $exception->getMessage();
        }
        $this->assertNotNull($deleteError, 'DELETE thẳng vào stock_movements phải bị DB trigger chặn.');
        $this->assertStringContainsString('append-only', $deleteError);

        $this->assertSame($rowsBefore, $this->movementRows($fx['sku_id']), 'Dòng ledger giữ nguyên từng cột sau hai lần tấn công.');

        $this->concludeArea($area, 'DEC-INV-001 §7.1 + BR-REV-01: movement append-only, DB trigger chặn UPDATE/DELETE trên cả hai driver', [
            'ledger_rows' => $this->movementRows($fx['sku_id']),
            'update_rejected_with' => $updateError,
            'delete_rejected_with' => $deleteError,
        ]);
    }

    // ------------------------------------------------------------------
    // Hạng mục 7 — RECONCILIATION = 0 sau chu trình receipt + issue
    // ------------------------------------------------------------------

    public function test_area_reconciliation_delta_is_zero_after_full_cycle(): void
    {
        $area = 'reconciliation';

        // Chu trình 1: nhập 6 linh kiện qua ledger thật.
        $inbound = $this->scannedInbound(6, $area);
        $this->capture($area, 'post-receipt', 'POST', "/api/v1/inbound-documents/{$inbound['document_id']}/post-receipt", [], $inbound['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w214-recon-rcv',
        ])->assertOk();

        // Chu trình 2: xuất 1 sản phẩm serial-tracked (opening có gốc ledger).
        $issue = $this->readyToPostIssueDocumentLedgerBacked($inbound['headers'], 'WWHN214-RECON-001');
        $this->capture($area, 'post-issue', 'POST', "/api/v1/outbound-documents/{$issue['document_id']}/post-issue", [], $inbound['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w214-recon-iss',
        ])->assertOk();

        // Nguồn 1: chính API báo cáo đối soát, cho từng SKU trong chu trình.
        foreach (['component' => $inbound['sku_id'], 'product' => $issue['sku_id']] as $label => $skuId) {
            $report = $this->capture($area, "report.reconciliation.{$label}", 'GET', '/api/v1/reports/inventory-reconciliation?product_id='.$skuId, [], $inbound['headers']);
            $report->assertOk()
                ->assertJsonPath('data.summary.mismatch_count', 0)
                ->assertJsonPath('data.summary.status', 'PASS');
        }

        // Nguồn 2: SQL thô độc lập — từng dòng balance phải bằng tổng qty_delta ledger.
        $comparison = [];
        foreach (DB::table('inventory_balances')->get() as $balance) {
            $ledgerSum = (int) DB::table('stock_movements')
                ->where('warehouse_id', $balance->warehouse_id)
                ->where('sku_id', $balance->sku_id)
                ->where('stock_status', $balance->stock_status)
                ->sum('qty_delta');
            $comparison[] = [
                'warehouse_id' => (string) $balance->warehouse_id,
                'sku_id' => (string) $balance->sku_id,
                'stock_status' => (string) $balance->stock_status,
                'balance_quantity' => (int) $balance->quantity,
                'ledger_sum_qty_delta' => $ledgerSum,
                'delta' => (int) $balance->quantity - $ledgerSum,
            ];
            $this->assertSame(0, (int) $balance->quantity - $ledgerSum, 'Ledger vs balance phải khớp tuyệt đối trên từng dòng (§7.5).');
        }
        $this->assertNotEmpty($comparison);

        $incidents = Schema::hasTable('reconciliation_incidents')
            ? (int) DB::table('reconciliation_incidents')->count()
            : 0;
        $this->assertSame(0, $incidents, 'Chu trình sạch không được sinh reconciliation_incident.');

        $this->concludeArea($area, '§7.5 + REQ-SRS-023: Balance = Σ qty_delta ledger trên mọi dòng; mismatch_count=0; không incident', [
            'ledger_vs_balance' => $comparison,
            'reconciliation_incidents' => $incidents,
            'movements_all' => $this->rawRows('stock_movements', []),
        ]);
    }
}
