<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/**
 * WWHN-239 · DEV239-P1-03/P1-04 — bằng chứng chạy thật cho ma trận QA1 (WWHN-241).
 *
 * Lỗ hổng gốc được đóng ở đây: guard reversible_qty của
 * CurrentBaselineReversalService tính theo TỔNG quantity của movement gốc, trong khi
 * CurrentBaselineStockService::postStockDocument ghi MỘT movement quantity=N cho mỗi
 * stock_document_line. Với line serial-tracked gồm N item, cùng một `item_id` có thể
 * được khai đảo tới N lần mà vẫn nằm trong hạn mức — sinh N movement REVERSAL cho một
 * serial vật lý duy nhất và trừ tồn N lần. Điều đó phá đúng ba bất biến mà WWHN-241
 * phải chứng minh: double-reverse bị chặn, duplicate movement = 0, ledger = balance.
 *
 * Preflight `GET /stock-movements/reversible` đã đúng (chỉ chào item IN_STOCK/NEW),
 * nhưng §8.1 quy định backend là nguồn chuẩn: enforcement không được lỏng hơn UI.
 *
 * Mỗi test dưới đây là một dòng của bảng DEV239-P1-04, và đều đo lại state sau khi
 * request bị từ chối để chứng minh "no write on denial" theo §8.2.
 */
class Wwhn239ReversalItemLevelClosureTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'Super Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $user = User::create([
            'username' => 'wwhn239-admin-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-239 Admin',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    /** Actor có đủ quyền nhập kho nhưng KHÔNG có inventory.reversal.post. */
    private function headersWithoutReversalPermission(): array
    {
        $roleCode = 'WWHN239_NOREV_'.Str::upper(Str::random(6));
        $role = Role::create(['role_code' => $roleCode, 'role_name' => $roleCode, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        foreach (['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post', 'inventory.view'] as $permissionKey) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $permissionKey],
                ['permission_name' => $permissionKey, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
            );
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->role_id, 'permission_id' => $permission->permission_id],
                ['role_permission_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
            );
        }
        $user = User::create([
            'username' => 'wwhn239-norev-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-239 No Reversal Permission',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function canonicalWarehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hòa Nam', 'status' => 'ACTIVE']
        );
    }

    /**
     * Phiếu nhập PRODUCT serial-tracked đã POSTED, dựng hoàn toàn qua HTTP thật.
     * Một line qty_planned=N sinh đúng MỘT movement RECEIPT quantity=N.
     *
     * @return array{document_id: string, line_id: string, movement_id: string, item_ids: list<string>, sku_id: string, warehouse_id: string, next_version: int}
     */
    private function postedSerialReceipt(array $headers, string $skuCode, int $itemCount): array
    {
        $warehouse = $this->canonicalWarehouse();
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => 'WWHN-239 '.$skuCode,
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);

        $itemIds = [];
        for ($index = 0; $index < $itemCount; $index++) {
            $itemId = (string) Str::uuid();
            DB::table('item_instances')->insert([
                'item_id' => $itemId,
                'sku_id' => $sku->sku_id,
                'serial_number' => 'SN239-'.Str::upper(Str::random(10)),
                'item_status' => 'NEW',
                'item_condition' => 'NORMAL',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $itemIds[] = $itemId;
        }

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'WWHN-239 serial fixture',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => $itemCount]],
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        foreach ($itemIds as $offset => $itemId) {
            $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
                'line_id' => $lineId,
                'quantity' => 1,
                'item_id' => $itemId,
            ], $headers + ['If-Match' => (string) ($offset + 1)])->assertOk();
        }

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => (string) ($itemCount + 1),
            'Idempotency-Key' => 'wwhn239-receipt-'.Str::random(12),
        ])->assertOk()->assertJsonPath('data.status', 'POSTED');

        return [
            'document_id' => $documentId,
            'line_id' => $lineId,
            'movement_id' => (string) DB::table('stock_movements')
                ->where('stock_document_id', $documentId)
                ->where('movement_type', 'RECEIPT')
                ->value('id'),
            'item_ids' => $itemIds,
            'sku_id' => (string) $sku->sku_id,
            'warehouse_id' => (string) $warehouse->id,
            'next_version' => $itemCount + 2,
        ];
    }

    private function balanceQty(string $warehouseId, string $skuId, string $stockStatus = 'AVAILABLE'): int
    {
        return (int) (DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->where('stock_status', $stockStatus)
            ->value('quantity') ?? 0);
    }

    private function reverseItem(array $headers, array $fixture, string $itemId, ?string $key = null, string $reason = 'WWHN-239 reversal'): TestResponse
    {
        return $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => $reason,
            'lines' => [[
                'movement_id' => $fixture['movement_id'],
                'quantity' => 1,
                'item_id' => $itemId,
            ]],
        ], $headers + [
            'If-Match' => (string) $fixture['next_version'],
            'Idempotency-Key' => $key ?? 'wwhn239-rev-'.Str::random(12),
        ]);
    }

    /** DEV239-P1-04 · double-reverse + duplicate movement = 0. */
    public function test_double_reverse_of_the_same_serial_item_is_blocked_and_creates_no_duplicate_movement(): void
    {
        $headers = $this->adminHeaders();
        $fixture = $this->postedSerialReceipt($headers, 'PRD-REV-DUP-01', 2);
        $itemId = $fixture['item_ids'][0];

        $this->assertSame(2, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        $this->reverseItem($headers, $fixture, $itemId)
            ->assertOk()
            ->assertJsonPath('data.reversal_status', 'POSTED');
        $this->assertSame(1, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        $movementsAfterFirst = DB::table('stock_movements')->count();

        // Lần đảo thứ hai cho ĐÚNG item đó: reversible_qty theo tổng vẫn còn 1, nhưng
        // serial vật lý đã ra khỏi kho nên phải bị chặn ở grain item.
        $this->reverseItem($headers, $fixture, $itemId)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REVERSAL_ITEM_ALREADY_REVERSED');

        $this->assertSame($movementsAfterFirst, DB::table('stock_movements')->count(), 'Lần đảo bị chặn không được ghi thêm movement.');
        $this->assertSame(1, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']), 'Lần đảo bị chặn không được trừ tồn.');

        $reversalMovementsForItem = DB::table('stock_movements')
            ->where('movement_type', 'REVERSAL')
            ->where('item_id', $itemId)
            ->count();
        $this->assertSame(1, $reversalMovementsForItem, 'Mỗi serial chỉ được có đúng một movement REVERSAL.');
    }

    /** DEV239-P1-04 · cùng một item khai hai dòng trong một request. */
    public function test_same_item_declared_twice_in_one_reversal_request_is_rejected(): void
    {
        $headers = $this->adminHeaders();
        $fixture = $this->postedSerialReceipt($headers, 'PRD-REV-DUP-02', 2);
        $itemId = $fixture['item_ids'][0];

        $movementsBefore = DB::table('stock_movements')->count();

        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Khai trùng item trong cùng một reversal',
            'lines' => [
                ['movement_id' => $fixture['movement_id'], 'quantity' => 1, 'item_id' => $itemId],
                ['movement_id' => $fixture['movement_id'], 'quantity' => 1, 'item_id' => $itemId],
            ],
        ], $headers + [
            'If-Match' => (string) $fixture['next_version'],
            'Idempotency-Key' => 'wwhn239-dup-'.Str::random(12),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REVERSAL_ITEM_DUPLICATED');

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(2, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
        $this->assertDatabaseMissing('reversal_line', ['item_id' => $itemId]);
    }

    /** DEV239-P1-04 · same-key replay không có second write. */
    public function test_same_key_replay_returns_the_first_result_without_second_write(): void
    {
        $headers = $this->adminHeaders();
        $fixture = $this->postedSerialReceipt($headers, 'PRD-REV-IDEMP-01', 2);
        $key = 'wwhn239-replay-'.Str::random(12);

        $first = $this->reverseItem($headers, $fixture, $fixture['item_ids'][0], $key);
        $first->assertOk()->assertHeader('Idempotent-Replay', 'false');
        $reversalId = (string) $first->json('data.reversal_document_id');

        $movementsAfterFirst = DB::table('stock_movements')->count();
        $balanceAfterFirst = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);
        $nodeCountAfterFirst = $this->getJson("/api/v1/stock-movements/{$fixture['movement_id']}/trace-source", $headers)
            ->assertOk()
            ->json('data.correction_chain.node_count');

        $replay = $this->reverseItem($headers, $fixture, $fixture['item_ids'][0], $key);
        $replay->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.reversal_document_id', $reversalId);

        $this->assertSame($movementsAfterFirst, DB::table('stock_movements')->count());
        $this->assertSame($balanceAfterFirst, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        // QA240-16: replay không được sinh thêm node trong correction chain — chain
        // đứng nguyên ở 2 node (ORIGINAL RECEIPT + REVERSAL POSTED).
        $this->getJson("/api/v1/stock-movements/{$fixture['movement_id']}/trace-source", $headers)
            ->assertOk()
            ->assertJsonPath('data.correction_chain.node_count', $nodeCountAfterFirst)
            ->assertJsonPath('data.correction_chain.node_count', 2);
    }

    /** DEV239-P1-04 · same key + payload khác → 409 IDEMPOTENCY_CONFLICT (§8.2). */
    public function test_same_key_with_different_payload_conflicts_without_write(): void
    {
        $headers = $this->adminHeaders();
        $fixture = $this->postedSerialReceipt($headers, 'PRD-REV-IDEMP-02', 2);
        $key = 'wwhn239-conflict-'.Str::random(12);

        $this->reverseItem($headers, $fixture, $fixture['item_ids'][0], $key)->assertOk();
        $movementsAfterFirst = DB::table('stock_movements')->count();
        $balanceAfterFirst = $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']);
        $nodeCountAfterFirst = $this->getJson("/api/v1/stock-movements/{$fixture['movement_id']}/trace-source", $headers)
            ->assertOk()
            ->json('data.correction_chain.node_count');

        $this->reverseItem($headers, $fixture, $fixture['item_ids'][1], $key, 'Payload khác cùng key')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame($movementsAfterFirst, DB::table('stock_movements')->count());
        $this->assertSame($balanceAfterFirst, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        // QA240-17: 409 idempotency conflict không được ghi thêm reversal — chain
        // quanh movement gốc phải đứng nguyên so với trước request xung đột.
        $this->getJson("/api/v1/stock-movements/{$fixture['movement_id']}/trace-source", $headers)
            ->assertOk()
            ->assertJsonPath('data.correction_chain.node_count', $nodeCountAfterFirst);
    }

    /** DEV239-P1-04 · RBAC 401/403; state/stock/movement không đổi. */
    public function test_reversal_is_denied_for_anonymous_and_for_actor_without_permission(): void
    {
        $headers = $this->adminHeaders();
        $fixture = $this->postedSerialReceipt($headers, 'PRD-REV-RBAC-01', 1);
        $movementsBefore = DB::table('stock_movements')->count();

        // Guard 'api' vẫn giữ user + token của fixture trong cùng process (tymon giữ
        // token ở JWT manager, không chỉ ở guard). Xoá cả hai để request không có
        // header Authorization thật sự là ẩn danh.
        app('tymon.jwt')->unsetToken();
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Không có token',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 1, 'item_id' => $fixture['item_ids'][0]]],
        ], ['If-Match' => (string) $fixture['next_version'], 'Idempotency-Key' => 'wwhn239-401-'.Str::random(12)])
            ->assertStatus(401);

        $this->reverseItem($this->headersWithoutReversalPermission(), $fixture, $fixture['item_ids'][0])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(1, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
        $this->assertDatabaseMissing('reversal_document', ['stock_document_id' => $fixture['document_id']]);
    }

    /** DEV239-P1-04 · dependency guard: item đã đi downstream không đảo trực tiếp được. */
    public function test_dependency_guard_blocks_receipt_reversal_when_item_moved_downstream(): void
    {
        $headers = $this->adminHeaders();
        $fixture = $this->postedSerialReceipt($headers, 'PRD-REV-DEP-01', 2);
        $itemId = $fixture['item_ids'][0];

        // Downstream thật của một item đã nhập là Issue/Warranty; ở đây chỉ cần đúng
        // trạng thái item mà guard đọc, nên set thẳng để test tập trung vào guard.
        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'ISSUED', 'updated_at' => now()]);

        $movementsBefore = DB::table('stock_movements')->count();
        $this->reverseItem($headers, $fixture, $itemId)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOWNSTREAM_DEPENDENCY_EXISTS');

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(2, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
    }

    /** DEV239-P1-04 · rollback: một dòng hỏng phải huỷ toàn bộ reversal. */
    public function test_failed_line_rolls_back_the_whole_reversal_document(): void
    {
        $headers = $this->adminHeaders();
        $fixture = $this->postedSerialReceipt($headers, 'PRD-REV-RB-01', 2);
        $movementsBefore = DB::table('stock_movements')->count();
        $reversalDocsBefore = DB::table('reversal_document')->count();

        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Dòng 2 trỏ item không thuộc giao dịch gốc',
            'lines' => [
                ['movement_id' => $fixture['movement_id'], 'quantity' => 1, 'item_id' => $fixture['item_ids'][0]],
                ['movement_id' => $fixture['movement_id'], 'quantity' => 1, 'item_id' => (string) Str::uuid()],
            ],
        ], $headers + [
            'If-Match' => (string) $fixture['next_version'],
            'Idempotency-Key' => 'wwhn239-rollback-'.Str::random(12),
        ])->assertStatus(422);

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count(), 'Rollback phải xoá mọi movement của reversal hỏng.');
        $this->assertSame($reversalDocsBefore, DB::table('reversal_document')->count(), 'Rollback phải xoá cả header reversal.');
        $this->assertSame(2, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
        $this->assertDatabaseMissing('reversal_line', ['item_id' => $fixture['item_ids'][0]]);
    }

    /** DEV239-P1-04 · no-negative + ledger = balance + orphan = 0. */
    public function test_ledger_reconciles_with_balance_and_leaves_no_orphan_after_reversals(): void
    {
        $headers = $this->adminHeaders();
        $fixture = $this->postedSerialReceipt($headers, 'PRD-REV-REC-01', 3);

        $this->reverseItem($headers, $fixture, $fixture['item_ids'][0])->assertOk();
        $this->reverseItem($headers, $fixture, $fixture['item_ids'][1])->assertOk();

        $this->assertSame(1, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        // Đảo tiếp quá phần còn lại phải bị chặn trước khi chạm tồn (no-negative).
        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Vượt reversible_qty còn lại',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 2]],
        ], $headers + [
            'If-Match' => (string) $fixture['next_version'],
            'Idempotency-Key' => 'wwhn239-neg-'.Str::random(12),
        ])->assertStatus(422);
        $this->assertSame(1, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        // Reconciliation: balance phải bằng tổng qty_delta có dấu của ledger.
        $ledger = (int) DB::table('stock_movements')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('sku_id', $fixture['sku_id'])
            ->where('stock_status', 'AVAILABLE')
            ->sum('qty_delta');
        $this->assertSame($ledger, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']), 'balance phải bằng SUM(qty_delta) của ledger.');

        // Orphan = 0: mọi movement REVERSAL phải trỏ về movement gốc còn tồn tại.
        $orphanMovements = DB::table('stock_movements as mv')
            ->leftJoin('stock_movements as origin', 'origin.id', '=', 'mv.original_movement_id')
            ->where('mv.movement_type', 'REVERSAL')
            ->whereNull('origin.id')
            ->count();
        $this->assertSame(0, $orphanMovements);

        $unpostedLines = DB::table('reversal_line as rl')
            ->join('reversal_document as rd', 'rd.reversal_document_id', '=', 'rl.reversal_document_id')
            ->where('rd.status', 'POSTED')
            ->whereNull('rl.posted_movement_id')
            ->count();
        $this->assertSame(0, $unpostedLines, 'Reversal POSTED không được có dòng thiếu movement đối ứng.');

        // Duplicate = 0 ở grain (movement gốc, item).
        $duplicates = DB::table('reversal_line')
            ->select('original_movement_id', 'item_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('item_id')
            ->whereNotNull('posted_movement_id')
            ->groupBy('original_movement_id', 'item_id')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();
        $this->assertCount(0, $duplicates);
    }
}
