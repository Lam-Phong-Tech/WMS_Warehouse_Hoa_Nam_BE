<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-202 — bộ evidence chuẩn (canonical) cho output "Balance hỗ trợ" của HN-OUT-003.
 *
 * Vì sao có file này bên cạnh `Wwhn207OutboundBalanceReconciliationTest`:
 *
 * - DEV202-P1-01R2 (branch/evidence mismatch): evidence gốc được ghi trên nhánh `m1-be-2`
 *   dưới tên `Wwhn207...`. `m1` nay là nhánh canonical. Không đổi tên file cũ vì 8 evidence
 *   pack đã đóng băng đang pin đúng đường dẫn đó; thay vào đó WWHN-202 có bộ evidence mang
 *   đúng tên của mình, và cả hai class cùng nằm trong `--group wwhn-202`.
 * - DEV202-P1-02R2 (QA1 retest tại exact candidate): file này phủ đúng ma trận QA1 —
 *   balance, subledger, trace-source, trace-by-code, reversible, reconciliation, reverse,
 *   401/403/404/422 — kèm **DB before/after** thật cho từng bước đổi tồn, thay vì chỉ
 *   assert response. Snapshot được ghi ra `storage/app/wwhn-202/db-before-after.json` để
 *   QA1 nộp kèm report WWHN-203 mà không phải dựng lại môi trường.
 * - DEV202-P1-03R2 (409 vs 422): `test_post_issue_error_taxonomy_matches_srs_8_2` khoá
 *   bảng phân lớp mã lỗi của Post Issue theo SRS §8.2 — xem
 *   `docs/decision-records/DEC-STK-202-post-issue-error-taxonomy-and-item-attribution.md`.
 * - DEV202-P1-04R2 (item_id attribution): `test_movement_item_attribution_follows_dec_stk_202`
 *   khoá quy tắc "line 1 item → movement.item_id; line gộp nhiều item → null + fallback
 *   theo line", đúng hành vi hiện tại của `CurrentBaselineStockService::resolveLineItemId()`.
 *
 * Baseline nghiệp vụ: SRS/FRD v1.12 REQ-SRS-011/012 (`AC-SRS-011`/`AC-SRS-012`),
 * AC-GWT-OUT-001, §7.5 (4 check đối soát), §8.1 (exact permission + data scope), §8.2
 * (HTTP/error contract), RPT-INV-01 (§13.10). ERD: `stock_movements` là ledger append-only,
 * `inventory_balances` là projection.
 */
#[Group('wwhn-202')]
class Wwhn202OutboundBalanceEvidenceTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /**
     * §8.1 — exact permission cho từng bề mặt đọc của output "Balance hỗ trợ".
     * Đây là bảng nguồn cho: test 401, test 403, FE contract pin
     * (`src/shared/api/wwhn-202-balance-contract-pin.ts`) và QA1 runbook.
     *
     * @var array<string, string>
     */
    private const READ_SURFACE_PERMISSIONS = [
        'GET /api/v1/inventory/balances' => 'inventory.view',
        'GET /api/v1/inventory/balances/{id}/subledger' => 'inventory.view',
        'GET /api/v1/stock-movements' => 'inventory.view',
        'GET /api/v1/stock-movements/{id}' => 'inventory.view',
        'GET /api/v1/stock-movements/{id}/trace-source' => 'inventory.trace',
        'GET /api/v1/stock-movements/trace-by-code' => 'inventory.trace',
        'GET /api/v1/stock-movements/reversible' => 'inventory.reversal.post',
        'GET /api/v1/reports/inventory-reconciliation' => 'report.view',
    ];

    /** @var array<int, array<string, mixed>> */
    private array $evidenceLog = [];

    /**
     * Ảnh chụp DB tại một thời điểm cho đúng một chiều (warehouse, sku): balance projection,
     * tổng ledger và trạng thái item. Đây là "DB before/after" mà DEV202-P1-02R2 yêu cầu —
     * đo trực tiếp trên bảng, không đi qua API, nên không thể tự chứng minh vòng tròn.
     *
     * @return array<string, mixed>
     */
    private function snapshotStock(string $warehouseId, string $skuId): array
    {
        $balance = DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->where('stock_status', 'AVAILABLE')
            ->first();

        $ledgerSum = (float) DB::table('stock_movements')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->where('stock_status', 'AVAILABLE')
            ->sum('qty_delta');

        $itemStatuses = DB::table('item_instances')
            ->where('sku_id', $skuId)
            ->orderBy('item_id')
            ->pluck('item_status', 'item_id')
            ->all();

        return [
            'balance_on_hand_qty' => $balance === null ? null : (float) $balance->on_hand_qty,
            'balance_available_qty' => $balance === null ? null : (float) $balance->available_qty,
            'balance_quantity' => $balance === null ? null : (float) $balance->quantity,
            'ledger_sum_qty_delta' => $ledgerSum,
            'ledger_row_count' => DB::table('stock_movements')
                ->where('warehouse_id', $warehouseId)
                ->where('sku_id', $skuId)
                ->count(),
            'variance_balance_minus_ledger' => $balance === null ? null : (float) $balance->on_hand_qty - $ledgerSum,
            'item_statuses' => $itemStatuses,
        ];
    }

    /** Ghi lại một cặp before/after để nộp kèm QA1 report WWHN-203. */
    private function recordEvidence(string $step, array $before, array $after): void
    {
        $this->evidenceLog[] = ['step' => $step, 'before' => $before, 'after' => $after];
    }

    protected function tearDown(): void
    {
        if ($this->evidenceLog !== []) {
            $dir = storage_path('app/wwhn-202');
            File::ensureDirectoryExists($dir);
            $path = $dir.'/db-before-after.json';
            $existing = File::exists($path)
                ? (json_decode((string) File::get($path), true) ?: [])
                : [];
            $existing[$this->name()] = $this->evidenceLog;
            File::put($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->evidenceLog = [];
        }

        parent::tearDown();
    }

    // ── QA1 §1: Post Issue — balance/ledger/subledger, DB before/after ────────────

    public function test_post_issue_moves_balance_ledger_and_subledger_together_with_db_before_after(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'WWHN202-BAL-001');

        $before = $this->snapshotStock($doc['warehouse_id'], $doc['sku_id']);
        $this->assertSame(1.0, $before['balance_on_hand_qty'], 'Trước Post Issue tồn phải là 1.');
        $this->assertSame(1.0, $before['ledger_sum_qty_delta'], 'Opening balance phải có gốc ledger (MATCH được).');
        $this->assertSame(0.0, $before['variance_balance_minus_ledger']);
        $this->assertSame('IN_STOCK', $before['item_statuses'][$doc['item_id']]);

        $this->postJson("/api/v1/outbound-documents/{$doc['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn202-balance-001',
        ])->assertOk()->assertJsonPath('data.status', 'POSTED');

        $after = $this->snapshotStock($doc['warehouse_id'], $doc['sku_id']);
        $this->recordEvidence('post-issue', $before, $after);

        // AC-GWT-OUT-001: stock giảm đúng số lượng, không âm, ledger và projection đi cùng nhau.
        $this->assertSame(0.0, $after['balance_on_hand_qty']);
        $this->assertSame(0.0, $after['balance_available_qty']);
        $this->assertSame(0.0, $after['ledger_sum_qty_delta']);
        $this->assertSame(0.0, $after['variance_balance_minus_ledger'], 'Ledger ≡ Balance là bất biến của DEC-INV-001.');
        $this->assertSame($before['ledger_row_count'] + 1, $after['ledger_row_count'], 'Đúng 1 movement ISSUE được append.');
        $this->assertSame('ISSUED', $after['item_statuses'][$doc['item_id']], 'IN_STOCK → ISSUED tại Post Issue (§6 state model).');

        // Bề mặt đọc 1: GET /inventory/balances phải phản chiếu đúng ledger.
        $row = collect($this->getJson('/api/v1/inventory/balances?product_id='.$doc['sku_id'], $headers)->assertOk()->json('data'))
            ->firstWhere('product_id', $doc['sku_id']);
        $this->assertNotNull($row);
        $this->assertSame(0.0, (float) $row['on_hand_qty']);
        $this->assertSame('MATCH', $row['reconciliation_status']);
        $this->assertSame(0.0, (float) $row['variance']);
        $this->assertNull($row['incident_id']);

        // Bề mặt đọc 2: subledger loại đúng item vừa ISSUED.
        $balanceId = (string) DB::table('inventory_balances')
            ->where('warehouse_id', $doc['warehouse_id'])
            ->where('sku_id', $doc['sku_id'])
            ->where('stock_status', 'AVAILABLE')
            ->value('id');
        $subledgerItems = collect(
            $this->getJson("/api/v1/inventory/balances/{$balanceId}/subledger", $headers)->assertOk()->json('data.items')
        )->pluck('id')->all();
        $this->assertNotContains($doc['item_id'], $subledgerItems, 'Item đã ISSUED không được còn trong subledger khả dụng.');
    }

    // ── QA1 §2: movement list/show/trace-source/trace-by-code/reversible ──────────

    public function test_all_movement_read_surfaces_agree_with_the_ledger_after_post_issue(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'WWHN202-MV-001');

        $this->postJson("/api/v1/outbound-documents/{$doc['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn202-movement-001',
        ])->assertOk();

        $movement = DB::table('stock_movements')
            ->where('stock_document_id', $doc['document_id'])
            ->where('movement_type', 'ISSUE')
            ->first();
        $this->assertNotNull($movement, 'Post Issue phải append đúng 1 movement ISSUE.');

        // list
        $listIds = collect(
            $this->getJson(
                "/api/v1/stock-movements?warehouse_id={$doc['warehouse_id']}&product_id={$doc['sku_id']}&movement_type=ISSUE",
                $headers
            )->assertOk()->json('data')
        )->pluck('id')->all();
        $this->assertContains($movement->id, $listIds);

        // show
        $this->getJson("/api/v1/stock-movements/{$movement->id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $movement->id)
            ->assertJsonPath('data.movement_type', 'ISSUE')
            ->assertJsonPath('data.qty_delta', -1);

        // trace-source
        $this->getJson("/api/v1/stock-movements/{$movement->id}/trace-source", $headers)
            ->assertOk()
            ->assertJsonPath('data.movement.id', $movement->id)
            ->assertJsonPath('data.document.id', $doc['document_id'])
            ->assertJsonPath('data.original_movement', null)
            ->assertJsonPath('data.reversal_movement', null);

        // trace-by-code
        $codeValue = DB::table('physical_codes')->where('assigned_object_id', $doc['item_id'])->value('code_value');
        $this->assertNotNull($codeValue);
        $trace = $this->getJson('/api/v1/stock-movements/trace-by-code?code='.$codeValue, $headers)->assertOk();
        $trace->assertJsonPath('data.resolved_type', 'ITEM')->assertJsonPath('data.resolved_id', $doc['item_id']);
        $this->assertContains(
            $movement->id,
            collect($trace->json('data.movements'))->pluck('id')->all(),
            'trace-by-code phải trả về movement ISSUE của chính item mang mã vật lý đó.'
        );

        // reversible
        $reversibleRow = collect($this->getJson('/api/v1/stock-movements/reversible?reversible_only=1', $headers)->assertOk()->json('data'))
            ->firstWhere('id', $movement->id);
        $this->assertNotNull($reversibleRow, 'Movement ISSUE vừa post phải xuất hiện trong danh sách reversible.');
        $this->assertTrue($reversibleRow['can_reverse']);
        $this->assertSame(1, $reversibleRow['lines'][0]['reversible_qty']);
    }

    // ── QA1 §3: reconciliation (report + command) ────────────────────────────────

    public function test_reconciliation_report_passes_all_four_checks_and_command_creates_no_incident(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'WWHN202-RECON-001');

        $this->postJson("/api/v1/outbound-documents/{$doc['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn202-recon-001',
        ])->assertOk();

        // §7.5: đúng 4 check — ledger_to_balance, serial_subledger, quantity_subledger,
        // container_integrity. Khoá luôn tập check để report không âm thầm mất một chiều.
        $report = $this->getJson('/api/v1/reports/inventory-reconciliation?product_id='.$doc['sku_id'], $headers)->assertOk();
        $this->assertSame(
            ['ledger_to_balance', 'serial_subledger', 'quantity_subledger', 'container_integrity'],
            $report->json('data.checks')
        );
        $this->assertSame(
            'variance = COALESCE(balance.on_hand_qty, 0) - SUM(stock_movements.qty_delta)',
            $report->json('data.formula')
        );

        $reportRow = collect($report->json('data.rows'))->firstWhere('product_id', $doc['sku_id']);
        $this->assertNotNull($reportRow, 'Chiều vừa Post Issue phải có mặt trong report đối soát.');
        $this->assertSame('MATCH', $reportRow['reconciliation_status']);
        $this->assertSame('MATCH', $reportRow['reconciliation_overall']);
        $this->assertSame(0, collect($report->json('data.mismatches'))
            ->where('product_id', $doc['sku_id'])->count(), 'mismatch của chiều này phải = 0.');

        $this->artisan('inventory:reconcile', ['--dry-run' => false])->assertExitCode(0);
        $this->assertDatabaseMissing('reconciliation_incidents', [
            'sku_id' => $doc['sku_id'],
            'warehouse_id' => $doc['warehouse_id'],
            'status' => 'OPEN',
        ]);
    }

    // ── QA1 §4: reverse — DB before/after và net effect trên mọi bề mặt ───────────

    public function test_reverse_restores_stock_and_every_read_surface_reflects_the_net_effect(): void
    {
        Storage::fake('local');
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'WWHN202-REV-001');

        $this->postJson("/api/v1/outbound-documents/{$doc['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn202-reverse-post-001',
        ])->assertOk();

        $issueMovementId = (string) DB::table('stock_movements')
            ->where('stock_document_id', $doc['document_id'])
            ->where('movement_type', 'ISSUE')
            ->value('id');

        $before = $this->snapshotStock($doc['warehouse_id'], $doc['sku_id']);
        $this->assertSame(0.0, $before['balance_on_hand_qty']);
        $this->assertSame('ISSUED', $before['item_statuses'][$doc['item_id']]);

        // "Chứng từ nhập/xuất/hoàn trả": Create luôn dừng ở DRAFT (stock_effect=NONE);
        // phải đính kèm chứng từ rồi Post riêng mới thật sự ghi movement/tồn.
        $reverse = $this->postJson("/api/v1/outbound-documents/{$doc['document_id']}/reverse", [
            'reason' => 'WWHN-202 QA retest — khách trả hàng',
            'condition_status' => 'SELLABLE',
            'physical_return_confirmed' => true,
            'lines' => [['movement_id' => $issueMovementId, 'quantity' => 1, 'item_id' => $doc['item_id']]],
        ], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'wwhn202-reverse-doc-001',
        ]);
        $reverse->assertOk()
            ->assertJsonPath('data.original_document_status', 'POSTED')
            ->assertJsonPath('data.reversal_status', 'DRAFT');
        $reversalId = (string) $reverse->json('data.reversal_document_id');

        $this->post("/api/v1/reversal-documents/{$reversalId}/attachments", [
            'file' => UploadedFile::fake()->image('wwhn202-bien-ban-hoan-tra.jpg'),
        ], $headers)->assertCreated();
        $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $headers + [
            'If-Match' => '1',
            'Idempotency-Key' => 'wwhn202-reverse-post-002',
        ])->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');

        $after = $this->snapshotStock($doc['warehouse_id'], $doc['sku_id']);
        $this->recordEvidence('reverse', $before, $after);

        // BR-REV-*: reversal là append-only — movement gốc không bị sửa/xoá, tồn quay về đúng.
        $this->assertSame(1.0, $after['balance_on_hand_qty']);
        $this->assertSame(1.0, $after['ledger_sum_qty_delta']);
        $this->assertSame(0.0, $after['variance_balance_minus_ledger']);
        $this->assertSame($before['ledger_row_count'] + 1, $after['ledger_row_count'], 'Reverse append thêm đúng 1 movement.');

        $originalStillIntact = DB::table('stock_movements')->where('id', $issueMovementId)->first();
        $this->assertSame(-1, (int) $originalStillIntact->qty_delta, 'Movement ISSUE gốc phải giữ nguyên (append-only).');

        $reversalMovementId = DB::table('stock_movements')->where('original_movement_id', $issueMovementId)->value('id');
        $this->assertNotNull($reversalMovementId);

        $this->getJson("/api/v1/stock-movements/{$issueMovementId}/trace-source", $headers)
            ->assertOk()
            ->assertJsonPath('data.reversal_movement.id', $reversalMovementId);

        $row = collect($this->getJson('/api/v1/inventory/balances?product_id='.$doc['sku_id'], $headers)->assertOk()->json('data'))
            ->firstWhere('product_id', $doc['sku_id']);
        $this->assertSame(1.0, (float) $row['on_hand_qty']);
        $this->assertSame('MATCH', $row['reconciliation_status']);
    }

    // ── QA1 §5: 401 / 403 / 404 / 422 trên toàn bộ bề mặt đọc ────────────────────

    public function test_every_read_surface_rejects_anonymous_access_with_401(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'WWHN202-401-001');
        $this->postJson("/api/v1/outbound-documents/{$doc['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn202-401-001',
        ])->assertOk();

        // Fixture đã gọi auth('api')->login() nên trong cùng một container test, guard JWT
        // giữ user đã resolve và singleton `tymon.jwt` giữ luôn token vừa phát (production
        // mỗi request là một container mới nên không có state này). Xoá cả hai để đo đúng
        // nhánh ẩn danh, thay vì đo lại state của chính test harness.
        $this->app['tymon.jwt']->unsetToken();
        $this->app['auth']->forgetGuards();

        foreach ($this->concreteReadUrls($doc) as $label => $url) {
            $response = $this->getJson($url);
            $this->assertSame(401, $response->status(), "{$label} phải trả 401 khi chưa xác thực.");
            $this->assertSame('UNAUTHENTICATED', $response->json('error_code'), "{$label} sai error_code 401.");
            $this->assertSame($response->json('error_code'), $response->json('error.code'), "{$label}: error_code và error.code phải bằng nhau.");
        }
    }

    public function test_every_read_surface_rejects_missing_permission_with_403_and_changes_nothing(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'WWHN202-403-001');
        $this->postJson("/api/v1/outbound-documents/{$doc['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn202-403-001',
        ])->assertOk();

        $before = $this->snapshotStock($doc['warehouse_id'], $doc['sku_id']);
        $restricted = $this->headersWithoutAnyPermission();

        foreach ($this->concreteReadUrls($doc) as $label => $url) {
            $response = $this->getJson($url, $restricted);
            $this->assertSame(403, $response->status(), "{$label} phải trả 403 khi thiếu permission.");
            $this->assertSame('ACCESS_DENIED', $response->json('error_code'), "{$label} sai error_code 403.");
            $this->assertNotNull($response->json('meta.request_id'), "{$label}: §8.2 bắt buộc trả request_id khi từ chối.");
        }

        // §8.2: "State, stock and movement unchanged" — kiểm bằng DB, không tin response.
        $this->assertSame($before, $this->snapshotStock($doc['warehouse_id'], $doc['sku_id']));
    }

    public function test_read_surfaces_return_404_and_422_without_leaking_internals(): void
    {
        $headers = $this->superAdminHeaders();
        $unknownId = (string) Str::uuid();

        $notFound = [
            'stock-movements/{id}' => "/api/v1/stock-movements/{$unknownId}",
            'stock-movements/{id}/trace-source' => "/api/v1/stock-movements/{$unknownId}/trace-source",
            'inventory/balances/{id}/subledger' => "/api/v1/inventory/balances/{$unknownId}/subledger",
            'stock-movements/trace-by-code (mã không tồn tại)' => '/api/v1/stock-movements/trace-by-code?code=WWHN202-KHONG-TON-TAI',
        ];

        foreach ($notFound as $label => $url) {
            $response = $this->getJson($url, $headers);
            $this->assertSame(404, $response->status(), "{$label} phải trả 404.");
            $this->assertSame('RESOURCE_NOT_FOUND', $response->json('error_code'), "{$label} sai error_code 404.");
            $this->assertNoInternalLeak($response->json('message'), $label);
        }

        // Thiếu tham số bắt buộc → 422 validation, không phải 500.
        $missingCode = $this->getJson('/api/v1/stock-movements/trace-by-code', $headers);
        $this->assertSame(422, $missingCode->status());
        $this->assertNoInternalLeak($missingCode->json('message'), 'trace-by-code thiếu code');
    }

    // ── DEV202-P1-03R2: phân lớp 409 vs 422 của Post Issue theo SRS §8.2 ─────────

    public function test_post_issue_error_taxonomy_matches_srs_8_2(): void
    {
        $headers = $this->superAdminHeaders();

        // (a) 412 STALE_VERSION — If-Match lệch version hiện tại.
        $staleDoc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'WWHN202-TAX-STALE');
        $staleBefore = $this->snapshotStock($staleDoc['warehouse_id'], $staleDoc['sku_id']);
        $stale = $this->postJson("/api/v1/outbound-documents/{$staleDoc['document_id']}/post-issue", [], $headers + [
            'If-Match' => '99',
            'Idempotency-Key' => 'wwhn202-tax-stale-001',
        ]);
        $this->assertSame(412, $stale->status(), 'If-Match lệch phải là 412, không phải 409.');
        $this->assertSame('STALE_VERSION', $stale->json('error_code'));
        $this->assertSame($staleBefore, $this->snapshotStock($staleDoc['warehouse_id'], $staleDoc['sku_id']));

        // (b) 200 replay — cùng Idempotency-Key, cùng payload: không tạo movement thứ hai.
        $postHeaders = $headers + ['If-Match' => '2', 'Idempotency-Key' => 'wwhn202-tax-replay-001'];
        $this->postJson("/api/v1/outbound-documents/{$staleDoc['document_id']}/post-issue", [], $postHeaders)->assertOk();
        $afterFirst = $this->snapshotStock($staleDoc['warehouse_id'], $staleDoc['sku_id']);
        $this->postJson("/api/v1/outbound-documents/{$staleDoc['document_id']}/post-issue", [], $postHeaders)->assertOk();
        $this->assertSame($afterFirst, $this->snapshotStock($staleDoc['warehouse_id'], $staleDoc['sku_id']), 'Replay không được trừ tồn lần hai.');

        // (c) 409 — cùng Idempotency-Key nhưng payload khác.
        $conflictDoc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'WWHN202-TAX-CONFLICT');
        $key = 'wwhn202-tax-conflict-001';
        $this->postJson("/api/v1/outbound-documents/{$conflictDoc['document_id']}/post-issue", ['note' => 'A'], $headers + [
            'If-Match' => '2', 'Idempotency-Key' => $key,
        ])->assertOk();
        $conflictBefore = $this->snapshotStock($conflictDoc['warehouse_id'], $conflictDoc['sku_id']);
        $conflict = $this->postJson("/api/v1/outbound-documents/{$conflictDoc['document_id']}/post-issue", ['note' => 'B'], $headers + [
            'If-Match' => '2', 'Idempotency-Key' => $key,
        ]);
        $this->assertSame(409, $conflict->status(), 'Cùng key khác payload phải là 409 (họ idempotency/state), không phải 422.');
        $this->assertContains($conflict->json('error_code'), ['IDEMPOTENCY_CONFLICT', 'STATE_CONFLICT']);
        $this->assertSame($conflictBefore, $this->snapshotStock($conflictDoc['warehouse_id'], $conflictDoc['sku_id']));

        // (d) 422 INSUFFICIENT_STOCK — hết tồn thật. Guard cuối nằm ở mutateBalance(),
        //     là lỗi *nghiệp vụ/validation* nên thuộc 422, không phải 409.
        $starvedDoc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'WWHN202-TAX-STOCK');
        DB::table('inventory_balances')
            ->where('warehouse_id', $starvedDoc['warehouse_id'])
            ->where('sku_id', $starvedDoc['sku_id'])
            ->update(['quantity' => 0, 'on_hand_qty' => 0, 'available_qty' => 0]);
        $starvedBefore = $this->snapshotStock($starvedDoc['warehouse_id'], $starvedDoc['sku_id']);
        $starved = $this->postJson("/api/v1/outbound-documents/{$starvedDoc['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2', 'Idempotency-Key' => 'wwhn202-tax-stock-001',
        ]);
        $this->assertSame(422, $starved->status(), 'Không đủ tồn phải là 422 INSUFFICIENT_STOCK theo §8.2, không phải 409.');
        $this->assertContains($starved->json('error_code'), ['INSUFFICIENT_STOCK', 'INSUFFICIENT_AVAILABLE_STOCK']);
        $this->assertSame(
            $starvedBefore['ledger_row_count'],
            $this->snapshotStock($starvedDoc['warehouse_id'], $starvedDoc['sku_id'])['ledger_row_count'],
            'Post Issue bị chặn không được ghi movement nào — bất biến atomic của REQ-SRS-012.'
        );
    }

    // ── DEV202-P1-04R2: quy tắc gán item_id cho movement ─────────────────────────

    /**
     * Khoá quy tắc DEC-STK-202 ở tầng bề mặt đọc: khi line chỉ có đúng 1 item thì
     * `stock_movements.item_id` phải trỏ đúng item đó (write-side WWHN-205), và bề mặt
     * `trace-by-code` phải resolve được cả khi item_id null (fallback theo line, WWHN-202).
     * `Wwhn205StockMovementItemIdInvariantTest` khoá phía write cho cả 1-item lẫn >=2-item;
     * test này chống hồi quy cho phía đọc, là nơi evidence cũ từng mô tả sai.
     */
    public function test_movement_item_attribution_follows_dec_stk_202(): void
    {
        $headers = $this->superAdminHeaders();
        $doc = $this->readyToPostIssueDocumentLedgerBacked($headers, 'WWHN202-ITEMID-001');

        $this->postJson("/api/v1/outbound-documents/{$doc['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn202-itemid-001',
        ])->assertOk();

        $movement = DB::table('stock_movements')
            ->where('stock_document_id', $doc['document_id'])
            ->where('movement_type', 'ISSUE')
            ->first();

        // Line 1 item → item_id được gán. Đây chính là điểm mà evidence ATT-11845 (ghi ở
        // thời điểm trước WWHN-205) mô tả ngược lại; xem WWHN-202.md §7 Amendment.
        $this->assertSame($doc['item_id'], $movement->item_id, 'Line có đúng 1 item thì movement.item_id phải trỏ item đó.');
        $this->assertSame((string) $doc['line_id'], (string) $movement->stock_document_line_id);

        // Nhánh trực tiếp (item_id = ?) phải là nhánh được dùng, không phải fallback.
        $direct = DB::table('stock_movements')->where('item_id', $doc['item_id'])->pluck('id')->all();
        $this->assertContains($movement->id, $direct);
    }

    /**
     * Vế còn lại của DEC-STK-202: line gộp nhiều item (qty_planned=2, serial-tracked) thì
     * một dòng movement không thể quy về một item duy nhất — `item_id` phải là null, và
     * `trace-by-code` phải fallback theo line để vẫn trả đúng lịch sử biến động của item.
     * Dựng bằng luồng HTTP thật, không UPDATE thẳng `stock_movements` (bảng có trigger
     * append-only HN-WMS-ERD-DD-001 chặn mọi UPDATE — chính trigger đó chứng minh rằng
     * evidence nào mô tả "sửa item_id sau khi post" là không thể thực hiện được).
     */
    public function test_aggregate_line_leaves_item_id_null_and_trace_by_code_falls_back_to_the_line(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('WWHN202-ITEMID-MULTI');
        $this->seedLedgerBackedBalance($warehouse->id, $sku->sku_id, 2, $headers['__user_id']);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 2, 'TOA-WWHN202-MULTI');

        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $documentId = $created['document_id'];
        $lineId = $created['line_ids'][0];

        $itemIds = [];
        foreach ([1, 2] as $i) {
            $itemId = $this->seedItemInStock($sku->sku_id);
            $this->seedRfidCode($itemId);
            $itemIds[] = $itemId;

            $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
                'line_id' => $lineId,
                'quantity' => 1,
                'item_id' => $itemId,
            ], $headers + ['If-Match' => (string) $i])->assertOk();
        }

        $this->markLabelsPrinted($documentId);

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '3',
            'Idempotency-Key' => 'wwhn202-itemid-multi-001',
        ])->assertOk();

        $movement = DB::table('stock_movements')
            ->where('stock_document_id', $documentId)
            ->where('movement_type', 'ISSUE')
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame(2, (int) $movement->quantity, 'Ledger ghi 1 dòng gộp cho cả line (qty=2).');
        $this->assertNull($movement->item_id, 'Line gộp 2 item thì item_id phải null — không được gán nhầm 1 trong 2.');

        foreach ($itemIds as $index => $itemId) {
            $codeValue = DB::table('physical_codes')->where('assigned_object_id', $itemId)->value('code_value');
            $trace = $this->getJson('/api/v1/stock-movements/trace-by-code?code='.$codeValue, $headers)->assertOk();
            $trace->assertJsonPath('data.resolved_type', 'ITEM')->assertJsonPath('data.resolved_id', $itemId);
            $this->assertContains(
                $movement->id,
                collect($trace->json('data.movements'))->pluck('id')->all(),
                "Item #{$index}: item_id null nên trace-by-code phải fallback theo line — không được trả rỗng."
            );
        }
    }

    // ── DEV202-P1-05R2: pin bảng permission để FE/QA dùng cùng một hợp đồng ──────

    public function test_read_surface_permission_matrix_matches_the_registered_routes(): void
    {
        foreach (self::READ_SURFACE_PERMISSIONS as $surface => $expectedPermission) {
            [$method, $path] = explode(' ', $surface, 2);
            $route = collect(app('router')->getRoutes()->getRoutes())
                ->first(fn ($r) => in_array($method, $r->methods(), true) && '/'.ltrim($r->uri(), '/') === $path);

            $this->assertNotNull($route, "Route {$surface} phải tồn tại đúng như hợp đồng đã pin cho FE/QA.");
            $this->assertContains(
                'permission:'.$expectedPermission,
                $route->gatherMiddleware(),
                "Route {$surface} phải yêu cầu đúng permission {$expectedPermission} (§8.1 exact permission)."
            );
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    /**
     * URL cụ thể cho từng bề mặt đọc, dùng dữ liệu outbound thật vừa post.
     *
     * @return array<string, string>
     */
    private function concreteReadUrls(array $doc): array
    {
        $movementId = (string) DB::table('stock_movements')
            ->where('stock_document_id', $doc['document_id'])
            ->where('movement_type', 'ISSUE')
            ->value('id');
        $balanceId = (string) DB::table('inventory_balances')
            ->where('warehouse_id', $doc['warehouse_id'])
            ->where('sku_id', $doc['sku_id'])
            ->where('stock_status', 'AVAILABLE')
            ->value('id');
        $codeValue = (string) DB::table('physical_codes')->where('assigned_object_id', $doc['item_id'])->value('code_value');

        return [
            'GET /inventory/balances' => '/api/v1/inventory/balances',
            'GET /inventory/balances/{id}/subledger' => "/api/v1/inventory/balances/{$balanceId}/subledger",
            'GET /stock-movements' => '/api/v1/stock-movements',
            'GET /stock-movements/{id}' => "/api/v1/stock-movements/{$movementId}",
            'GET /stock-movements/{id}/trace-source' => "/api/v1/stock-movements/{$movementId}/trace-source",
            'GET /stock-movements/trace-by-code' => '/api/v1/stock-movements/trace-by-code?code='.$codeValue,
            'GET /stock-movements/reversible' => '/api/v1/stock-movements/reversible?reversible_only=1',
            'GET /reports/inventory-reconciliation' => '/api/v1/reports/inventory-reconciliation',
        ];
    }

    /** Actor hợp lệ nhưng role rỗng permission — dùng để đo đúng tầng permission, không phải tầng auth. */
    private function headersWithoutAnyPermission(): array
    {
        $role = Role::create([
            'role_code' => 'WWHN202_NO_PERMISSION',
            'role_name' => 'Không có quyền nào (WWHN-202)',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'wwhn202-noperm-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-202 No Permission',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    /** §8.2: message không bao giờ chứa stack trace, câu SQL hay secret. */
    private function assertNoInternalLeak(?string $message, string $label): void
    {
        $this->assertNotNull($message, "{$label} phải có message hiển thị được.");
        foreach (['SQLSTATE', 'select * from', 'Stack trace', '#0 ', 'vendor/laravel', '.php:'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $message, "{$label} lộ chi tiết nội bộ: {$needle}");
        }
    }
}
