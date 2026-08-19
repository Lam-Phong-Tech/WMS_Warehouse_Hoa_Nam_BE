<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/**
 * WWHN-266 · DEV266-P1-04R — Stock Integrity gate cho correction policy của
 * COMPONENT_ISSUE đã POSTED (BE266-D03, DEC-REV-COMPONENT-001).
 *
 * Chính sách được BA + Tech Lead chốt: REVERSAL theo đúng BR-REV-01 — original
 * movement COMPONENT_ISSUE + movement đối ứng append-only (movement_type
 * REVERSAL, original_movement_id + correction_chain_id), KHÔNG UPDATE/DELETE
 * movement gốc, KHÔNG direct-edit balance. ERD bảng 33 đã khai
 * original_document_type ∈ INBOUND/OUTBOUND/COMPONENT_ISSUE từ trước — schema
 * sẵn sàng, chỉ thiếu hành vi. Component Issue cùng chiều stock effect với
 * Issue nên chịu BR-REV-06/07 (physical return + return_condition).
 *
 * Phạm vi bộ này: quantity-mode (BOX + quantity — chế độ chính của §5.4.1).
 * Nhánh serial component dùng chung guard với Reverse Issue serial
 * (assertItemDependency / assertItemNotAlreadyReversed / BR-REV-05 grain ITEM)
 * đã được khoá bởi bộ test WWHN-239/242.
 *
 * Ranh giới sự thật: lượt chạy in-process trên DB test của DEV; retest độc lập
 * thuộc QA1 (WWHN-264).
 */
class Wwhn266ComponentIssueReversalTest extends TestCase
{
    use RefreshDatabase;

    private const EVIDENCE_PATH = 'build/evidence/wwhn-266/component-issue-reversal.json';

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
            'username' => 'w266r-'.Str::lower(Str::random(8)),
            'display_name' => 'WWHN-266 reversal fixture',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    /**
     * Dựng tồn linh kiện bằng ledger thật (inbound → box → scan → post-receipt →
     * khai nội dung BOX) rồi Post một Component Issue — y hệt fixture của
     * Wwhn266ComponentIssueCoreTransactionTest để hai bộ đo cùng một thế giới.
     *
     * @return array{headers: array, warehouse_id: string, sku_id: string, sku_code: string, container_id: string, case_id: string, document_id: string, movement_id: string, doc_version: int}
     */
    private function postedComponentIssue(int $stockQty, int $issueQty): array
    {
        $headers = $this->authHeadersWithRole('SUPER_ADMIN');

        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hòa Nam', 'status' => 'ACTIVE']
        );
        $warehouseId = (string) $warehouse->id;

        $sku = Sku::create([
            'sku_code' => 'LKR-266-'.Str::upper(Str::random(6)),
            'sku_name' => 'Linh kiện reversal WWHN-266',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouseId,
            'source_name' => 'NCC WWHN-266R',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => $stockQty]],
        ], $headers);
        $create->assertCreated();
        $inboundId = (string) $create->json('data.id');
        $inboundLineId = (string) $create->json('data.lines.0.id');

        $box = $this->postJson("/api/v1/inbound-documents/{$inboundId}/boxes", [
            'line_id' => $inboundLineId,
            'box_code' => 'BOXR-266-'.Str::upper(Str::random(6)),
        ], $headers);
        $containerId = (string) $box->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$inboundId}/scan", [
            'line_id' => $inboundLineId,
            'quantity' => $stockQty,
            'container_id' => $containerId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->postJson("/api/v1/inbound-documents/{$inboundId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w266r-rcv-'.(string) Str::uuid(),
        ])->assertOk();

        $containerVersion = (int) DB::table('containers')->where('container_id', $containerId)->value('version');
        $this->postJson("/api/v1/containers/{$containerId}/items", [
            'sku_id' => $sku->sku_id,
            'quantity' => $stockQty,
            'source_type' => 'RECEIPT',
            'source_id' => $inboundId,
        ], $headers + ['If-Match' => (string) $containerVersion])->assertSuccessful();

        $case = $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => 'Khách WWHN-266R',
            'customer_phone' => '0944444444',
            'description' => 'Máy kêu to bất thường',
            'missing_code_reason' => 'Mất tem mã',
            'manual_product_description' => 'Máy cắt',
        ], $headers + ['Idempotency-Key' => 'w266r-case-'.(string) Str::uuid()]);
        $case->assertCreated();
        $caseId = (string) $case->json('data.id');

        $doc = $this->postJson('/api/v1/component-issue-documents', [
            'warehouse_id' => $warehouseId,
            'warranty_case_id' => $caseId,
            'purpose' => 'WARRANTY',
            'lines' => [['sku_id' => $sku->sku_id, 'required_qty' => $issueQty]],
        ], $headers);
        $doc->assertCreated();
        $documentId = (string) $doc->json('data.document.id');
        $lineId = (string) $doc->json('data.lines.0.id');

        $this->postJson("/api/v1/component-issue-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'container_id' => $containerId,
            'quantity' => $issueQty,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $this->postJson("/api/v1/component-issue-documents/{$documentId}/post", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w266r-post-'.(string) Str::uuid(),
        ])->assertOk();

        $movementId = (string) DB::table('stock_movements')
            ->where('source_id', $documentId)
            ->where('movement_type', 'COMPONENT_ISSUE')
            ->value('id');
        $this->assertNotSame('', $movementId, 'Fixture phải có movement COMPONENT_ISSUE.');

        return [
            'headers' => $headers,
            'warehouse_id' => $warehouseId,
            'sku_id' => (string) $sku->sku_id,
            'sku_code' => (string) $sku->sku_code,
            'container_id' => $containerId,
            'case_id' => $caseId,
            'document_id' => $documentId,
            'movement_id' => $movementId,
            'doc_version' => (int) DB::table('component_issue_documents')->where('id', $documentId)->value('version'),
        ];
    }

    private function balanceQty(string $warehouseId, string $skuId, string $stockStatus = 'AVAILABLE'): int
    {
        return (int) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where(function ($query) use ($skuId): void {
                $query->where('sku_id', $skuId)->orWhere('product_id', $skuId);
            })
            ->where('stock_status', $stockStatus)
            ->value('quantity');
    }

    private function boxQty(string $containerId, string $skuId): int
    {
        return (int) DB::table('container_items')
            ->where('container_id', $containerId)
            ->where('sku_id', $skuId)
            ->where('is_active', true)
            ->sum('quantity');
    }

    /** @return array<string, mixed> */
    private function movementRow(string $movementId): array
    {
        return (array) DB::table('stock_movements')->where('id', $movementId)->first();
    }

    /** @return array<string, mixed> body tạo reversal quantity-mode chuẩn */
    private function reversalPayload(array $fixture, int $qty, string $condition = 'SELLABLE', bool $physicalConfirmed = true): array
    {
        return [
            'reference_document_id' => $fixture['document_id'],
            'reference_document_type' => 'COMPONENT_ISSUE',
            'reason' => 'Post nhầm số lượng linh kiện, khách trả lại',
            'return_condition' => $condition,
            'physical_return_confirmed' => $physicalConfirmed,
            'lines' => [['sku' => $fixture['sku_code'], 'reversal_qty' => $qty]],
        ];
    }

    private function record(string $tc, array $data): void
    {
        self::$evidence[$tc] = $data;

        $path = base_path(self::EVIDENCE_PATH);
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode([
            'ticket' => 'WWHN-266',
            'register_items' => ['DEV266-P1-04R'],
            'decision' => 'DEC-REV-COMPONENT-001 — correction policy = append-only reversal cho COMPONENT_ISSUE',
            'action' => 'REVERSAL of COMPONENT_ISSUE (quantity-mode)',
            'driver' => DB::getDriverName(),
            'producer' => static::class,
            'dimensions' => self::$evidence,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * TC-REV-CI-01 · POSITIVE — reversal cộng lại tồn đúng một lần; movement gốc
     * bất biến từng cột; movement đối ứng append-only đủ chain; subledger BOX
     * không bị tự ý sửa; posting_event + audit đầy đủ.
     */
    public function test_tc_rev_ci_01_positive_reversal_restores_stock_and_keeps_original_immutable(): void
    {
        $fixture = $this->postedComponentIssue(10, 3);
        $before = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);
        $this->assertSame(7, $before);
        $boxBefore = $this->boxQty($fixture['container_id'], $fixture['sku_id']);
        $originalBefore = $this->movementRow($fixture['movement_id']);

        $response = $this->postJson('/api/v1/reversal-documents', $this->reversalPayload($fixture, 2), $fixture['headers'] + [
            'If-Match' => (string) $fixture['doc_version'],
            'Idempotency-Key' => 'w266r-tc01',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'POSTED');
        $reversalId = (string) $response->json('data.id');

        $after = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);
        $this->assertSame(9, $after, 'Reversal SELLABLE phải cộng lại đúng reversal_qty.');

        // BR-REV-01: movement gốc không bị UPDATE/DELETE — so từng cột.
        $this->assertSame($originalBefore, $this->movementRow($fixture['movement_id']), 'Movement gốc phải bất biến từng cột sau reversal.');

        $reversalMovement = DB::table('stock_movements')
            ->where('source_type', 'REVERSAL_DOCUMENT')
            ->where('source_id', $reversalId)
            ->first();
        $this->assertNotNull($reversalMovement, 'Phải có movement đối ứng append-only.');
        $this->assertSame('REVERSAL', (string) $reversalMovement->movement_type, 'ERD chỉ cho movement_type REVERSAL (không có COMPONENT_ISSUE_REVERSAL).');
        $this->assertSame(2, (int) $reversalMovement->qty_delta);
        $this->assertSame(7, (int) $reversalMovement->qty_before);
        $this->assertSame(9, (int) $reversalMovement->qty_after);
        $this->assertSame($fixture['movement_id'], (string) $reversalMovement->original_movement_id, 'Movement đối ứng phải tham chiếu original_movement_id.');
        $this->assertNotNull($reversalMovement->correction_chain_id, 'Movement đối ứng phải nằm trong correction chain.');

        $reversalDoc = DB::table('reversal_document')->where('reversal_document_id', $reversalId)->first();
        $this->assertSame('POSTED', (string) $reversalDoc->status);
        $this->assertSame('COMPONENT_ISSUE', (string) $reversalDoc->original_document_type, 'ERD bảng 33: original_document_type = COMPONENT_ISSUE.');
        $this->assertSame($fixture['document_id'], (string) $reversalDoc->original_document_id);
        $this->assertSame('CONFIRMED', (string) $reversalDoc->physical_return_status);
        $this->assertNull($reversalDoc->stock_document_id, 'Chứng từ linh kiện không thuộc stock_documents.');

        // DEC-REV-COMPONENT-001 §BOX: reversal KHÔNG tự nạp lại subledger BOX;
        // linh kiện quay về được khai qua POST /containers/{id}/items (đường ghi
        // duy nhất của container_items — disposition BE266-D04).
        $this->assertSame($boxBefore, $this->boxQty($fixture['container_id'], $fixture['sku_id']), 'container_items không được tự thay đổi bởi reversal.');

        $this->assertSame(1, (int) DB::table('posting_event')
            ->where('operation', 'reverse_'.$reversalId)
            ->where('posting_action', 'REVERSE_COMPONENT_ISSUE')
            ->count(), 'Đúng một posting_event REVERSE_COMPONENT_ISSUE.');
        $this->assertSame(1, (int) DB::table('audit_log')
            ->where('object_type', 'reversal_document')
            ->where('object_id', $reversalId)
            ->where('action', 'REVERSAL_POST')
            ->count());
        $this->assertSame('POSTED', (string) DB::table('component_issue_documents')->where('id', $fixture['document_id'])->value('status'), 'Chứng từ gốc giữ nguyên POSTED (sửa sai bằng chain, không đổi state gốc).');

        $this->record('TC-REV-CI-01', [
            'dimension' => 'POSITIVE',
            'http' => 201,
            'balance_before' => $before,
            'balance_after' => $after,
            'reversal_qty_delta' => (int) $reversalMovement->qty_delta,
            'original_movement_immutable' => true,
            'box_subledger_untouched' => $boxBefore,
            'posting_action' => 'REVERSE_COMPONENT_ISSUE',
        ]);
    }

    /**
     * TC-REV-CI-02 · BR-REV-06 pending-return — chưa xác nhận hàng vật lý thì
     * DRAFT/PENDING_RETURN, stock effect = 0; sau confirm + post mới cộng tồn.
     */
    public function test_tc_rev_ci_02_pending_return_holds_stock_until_confirm_then_post(): void
    {
        $fixture = $this->postedComponentIssue(10, 3);
        $before = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        $create = $this->postJson('/api/v1/reversal-documents', $this->reversalPayload($fixture, 3, 'SELLABLE', false), $fixture['headers'] + [
            'If-Match' => (string) $fixture['doc_version'],
            'Idempotency-Key' => 'w266r-tc02',
        ]);
        $create->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $reversalId = (string) $create->json('data.id');

        $this->assertSame($before, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']), 'PENDING_RETURN phải stock-neutral.');
        $this->assertSame(0, (int) DB::table('stock_movements')->where('source_id', $reversalId)->count());

        // Post khi chưa confirm phải bị chặn 409 (BR-REV-06).
        $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $fixture['headers'] + [
            'If-Match' => '1',
            'Idempotency-Key' => 'w266r-tc02-early',
        ])->assertStatus(409)->assertJsonPath('error_code', 'PHYSICAL_RETURN_REQUIRED');

        $this->postJson("/api/v1/reversal-documents/{$reversalId}/confirm", [], $fixture['headers'] + [
            'If-Match' => '1',
        ])->assertOk();

        $post = $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $fixture['headers'] + [
            'If-Match' => '2',
            'Idempotency-Key' => 'w266r-tc02-post',
        ]);
        $post->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');

        $this->assertSame($before + 3, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        $this->record('TC-REV-CI-02', [
            'dimension' => 'PENDING_RETURN → CONFIRMED → POSTED (BR-REV-06)',
            'stock_neutral_until_confirm' => true,
            'early_post_http' => 409,
            'balance_after_post' => $before + 3,
        ]);
    }

    /**
     * TC-REV-CI-03 · BR-REV-07 — hàng về DEFECTIVE không cộng available;
     * movement đối ứng ghi vào bucket DEFECTIVE.
     */
    public function test_tc_rev_ci_03_defective_return_does_not_restore_available_bucket(): void
    {
        $fixture = $this->postedComponentIssue(10, 3);
        $availableBefore = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        $response = $this->postJson('/api/v1/reversal-documents', $this->reversalPayload($fixture, 1, 'DEFECTIVE'), $fixture['headers'] + [
            'If-Match' => (string) $fixture['doc_version'],
            'Idempotency-Key' => 'w266r-tc03',
        ]);
        $response->assertCreated()->assertJsonPath('data.status', 'POSTED');

        $this->assertSame($availableBefore, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']), 'DEFECTIVE không được cộng bucket AVAILABLE.');
        $this->assertSame(1, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id'], 'DEFECTIVE'), 'Hàng lỗi phải nằm ở bucket DEFECTIVE.');

        $reversalId = (string) $response->json('data.id');
        $movement = DB::table('stock_movements')->where('source_id', $reversalId)->first();
        $this->assertSame('DEFECTIVE', (string) $movement->stock_status);

        $this->record('TC-REV-CI-03', [
            'dimension' => 'RETURN_CONDITION (BR-REV-07)',
            'available_unchanged' => $availableBefore,
            'defective_bucket' => 1,
        ]);
    }

    /**
     * TC-REV-CI-04 · BR-REV-05 — tổng lượng đảo không vượt movement gốc; vượt
     * hạn mức trả 422 REVERSAL_QTY_EXCEEDED và không có write thứ hai.
     */
    public function test_tc_rev_ci_04_over_reversal_is_rejected(): void
    {
        $fixture = $this->postedComponentIssue(10, 3);

        $this->postJson('/api/v1/reversal-documents', $this->reversalPayload($fixture, 3), $fixture['headers'] + [
            'If-Match' => (string) $fixture['doc_version'],
            'Idempotency-Key' => 'w266r-tc04-a',
        ])->assertCreated();

        $balance = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);
        $movementCount = (int) DB::table('stock_movements')->count();

        $second = $this->postJson('/api/v1/reversal-documents', $this->reversalPayload($fixture, 1), $fixture['headers'] + [
            'If-Match' => (string) $fixture['doc_version'],
            'Idempotency-Key' => 'w266r-tc04-b',
        ]);

        $second->assertStatus(422)->assertJsonPath('error_code', 'REVERSAL_QTY_EXCEEDED');
        $this->assertSame($balance, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
        $this->assertSame($movementCount, (int) DB::table('stock_movements')->count(), 'Không được sinh movement nào khi bị chặn hạn mức.');

        $this->record('TC-REV-CI-04', [
            'dimension' => 'NEGATIVE (BR-REV-05)',
            'http' => 422,
            'error_code' => 'REVERSAL_QTY_EXCEEDED',
            'no_second_write' => true,
        ]);
    }

    /**
     * TC-REV-CI-05 · BR-REV-12 — retry cùng Idempotency-Key/payload replay kết
     * quả cũ, tồn chỉ đổi một lần; cùng key khác payload trả 409.
     */
    public function test_tc_rev_ci_05_idempotency_replay_and_conflict(): void
    {
        $fixture = $this->postedComponentIssue(10, 3);
        $payload = $this->reversalPayload($fixture, 2);
        $headers = $fixture['headers'] + [
            'If-Match' => (string) $fixture['doc_version'],
            'Idempotency-Key' => 'w266r-tc05',
        ];

        $first = $this->postJson('/api/v1/reversal-documents', $payload, $headers);
        $first->assertCreated()->assertHeader('Idempotent-Replay', 'false');
        $reversalId = (string) $first->json('data.id');
        $balanceAfterFirst = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        $replay = $this->postJson('/api/v1/reversal-documents', $payload, $headers);
        $replay->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($reversalId, (string) $replay->json('data.id'), 'Replay phải trả đúng reversal cũ.');
        $this->assertSame($balanceAfterFirst, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']), 'Replay không được cộng tồn lần hai.');
        $this->assertSame(1, (int) DB::table('stock_movements')->where('source_id', $reversalId)->count());

        $conflict = $this->postJson('/api/v1/reversal-documents', $this->reversalPayload($fixture, 1), $headers);
        $conflict->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');

        $this->record('TC-REV-CI-05', [
            'dimension' => 'RETRY_SAME_KEY + SAME_KEY_DIFF_PAYLOAD (BR-REV-12)',
            'replay_flags' => [false, true],
            'conflict_http' => 409,
            'single_stock_effect' => $balanceAfterFirst,
        ]);
    }

    /**
     * TC-REV-CI-06 · PERMISSION + If-Match contract — thiếu quyền không có tác
     * động; thiếu If-Match 428; lệch version 412 (BR-REV-02, §8.2).
     */
    public function test_tc_rev_ci_06_permission_and_precondition_contract(): void
    {
        $fixture = $this->postedComponentIssue(10, 3);
        $before = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        $stranger = $this->authHeadersWithRole('LEADERSHIP');
        $denied = $this->postJson('/api/v1/reversal-documents', $this->reversalPayload($fixture, 1), $stranger + [
            'If-Match' => (string) $fixture['doc_version'],
            'Idempotency-Key' => 'w266r-tc06-perm',
        ]);
        $this->assertContains($denied->getStatusCode(), [403, 404], 'Thiếu inventory.reversal.post phải 403/404 theo masking policy.');

        $missingIfMatch = $this->postJson('/api/v1/reversal-documents', $this->reversalPayload($fixture, 1), $fixture['headers'] + [
            'Idempotency-Key' => 'w266r-tc06-428',
        ]);
        $missingIfMatch->assertStatus(428)->assertJsonPath('error_code', 'PRECONDITION_REQUIRED');

        $stale = $this->postJson('/api/v1/reversal-documents', $this->reversalPayload($fixture, 1), $fixture['headers'] + [
            'If-Match' => '99',
            'Idempotency-Key' => 'w266r-tc06-412',
        ]);
        $stale->assertStatus(412)->assertJsonPath('error_code', 'STALE_VERSION');

        $this->assertSame($before, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']), 'Không nhánh lỗi nào được đổi tồn.');
        $this->assertSame(0, (int) DB::table('reversal_document')->where('original_document_id', $fixture['document_id'])->where('status', 'POSTED')->count());

        $this->record('TC-REV-CI-06', [
            'dimension' => 'PERMISSION + PRECONDITION (BR-REV-02 / §8.2)',
            'permission_http' => $denied->getStatusCode(),
            'missing_if_match_http' => 428,
            'stale_version_http' => 412,
            'balance_unchanged' => $before,
        ]);
    }

    /**
     * TC-REV-CI-07 · BR-REV-08 — movement REVERSAL không phải nguồn reversal
     * hợp lệ: tham chiếu nó không tạo được chứng từ đảo mới kiểu trực tiếp.
     */
    public function test_tc_rev_ci_07_reversal_movement_is_not_a_reversible_source(): void
    {
        $fixture = $this->postedComponentIssue(10, 3);

        $created = $this->postJson('/api/v1/reversal-documents', $this->reversalPayload($fixture, 2), $fixture['headers'] + [
            'If-Match' => (string) $fixture['doc_version'],
            'Idempotency-Key' => 'w266r-tc07-a',
        ]);
        $created->assertCreated();
        $reversalMovementId = (string) DB::table('stock_movements')
            ->where('source_id', (string) $created->json('data.id'))
            ->value('id');

        $attempt = $this->postJson('/api/v1/reversal-documents', [
            'reference_document_id' => $reversalMovementId,
            'reference_document_type' => 'REVERSAL',
            'reason' => 'Thử đảo movement reversal',
            'lines' => [['sku' => $fixture['sku_code'], 'reversal_qty' => 1]],
        ], $fixture['headers'] + [
            'If-Match' => '1',
            'Idempotency-Key' => 'w266r-tc07-b',
        ]);

        $attempt->assertStatus(404)->assertJsonPath('error_code', 'MOVEMENT_NOT_FOUND');

        $this->record('TC-REV-CI-07', [
            'dimension' => 'INVALID_SOURCE (BR-REV-08)',
            'http' => 404,
            'error_code' => 'MOVEMENT_NOT_FOUND',
        ]);
    }

    /**
     * TC-REV-CI-08 · Đối soát §7.5 — sau chuỗi post + reversal, SUM(qty_delta)
     * của ledger đúng bằng balance; không mismatch.
     */
    public function test_tc_rev_ci_08_ledger_reconciles_with_balance_after_reversal(): void
    {
        $fixture = $this->postedComponentIssue(10, 3);

        $this->postJson('/api/v1/reversal-documents', $this->reversalPayload($fixture, 2), $fixture['headers'] + [
            'If-Match' => (string) $fixture['doc_version'],
            'Idempotency-Key' => 'w266r-tc08',
        ])->assertCreated();

        $ledgerSum = (int) DB::table('stock_movements')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where(function ($query) use ($fixture): void {
                $query->where('sku_id', $fixture['sku_id'])->orWhere('product_id', $fixture['sku_id']);
            })
            ->where('stock_status', 'AVAILABLE')
            ->sum('qty_delta');
        $balance = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);

        $this->assertSame(9, $balance, '10 nhập − 3 xuất linh kiện + 2 đảo = 9.');
        $this->assertSame($balance, $ledgerSum, 'Ledger phải đối soát khớp balance (mismatch = 0).');

        $this->record('TC-REV-CI-08', [
            'dimension' => 'RECONCILE (§7.5)',
            'ledger_sum_qty_delta' => $ledgerSum,
            'balance_quantity' => $balance,
            'mismatch_count' => $ledgerSum === $balance ? 0 : 1,
        ]);
    }
}
