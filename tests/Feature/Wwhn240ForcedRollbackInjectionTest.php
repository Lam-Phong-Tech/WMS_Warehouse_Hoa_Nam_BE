<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use RuntimeException;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\TestCase;

/**
 * WWHN-240 / WWHN-241 — QA240-20 "forced rollback injection" (register R3
 * `DEV240-P1-QA-R3`, hard gate).
 *
 * Runbook yêu cầu: tiêm lỗi hạ tầng SAU khi movement REVERSAL đã INSERT nhưng
 * TRƯỚC khi transaction commit, rồi chứng minh "rollback sạch (chain/XNT trở
 * về nguyên trạng)". `Wwhn234PostIssueRollbackProofTest` (G-01) đã chứng minh
 * đúng cơ chế này cho Post Issue; đây là bản tương đương cho reversal — điểm
 * khác quan trọng: trong `CurrentBaselineReversalService::execute()`,
 * `reversal_document` + `reversal_line` được INSERT **trước** `stock_movements`
 * trong CÙNG một transaction, nên injection tại INSERT movement còn chứng minh
 * được cả hai bảng chứng từ đó cuốn theo rollback (bằng chứng mạnh hơn G-01).
 *
 * Oracle "nguyên trạng" lấy từ chính hai bề mặt WWHN-240 đã đóng:
 *   - GET /stock-movements/{id}/trace-source (net_effect + correction_chain)
 *   - GET /reports/inventory-xnt-enhanced (gross/reversal/net + closing)
 * và đối chiếu ledger SUM(qty_delta) — đúng câu chữ gate "XNT↔trace↔ledger
 * reconciliation, original/reversal/net".
 */
class Wwhn240ForcedRollbackInjectionTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'Super Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $user = User::create([
            'username' => 'wwhn240-admin-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-240 Admin',
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
            ['name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE']
        );
    }

    /**
     * Phiếu nhập PRODUCT serial-tracked đã POSTED, dựng hoàn toàn qua HTTP thật
     * (cùng fixture với Wwhn239ReversalItemLevelClosureTest — không viết movement
     * tay để khỏi tự bịa invariant).
     *
     * @return array{document_id: string, line_id: string, movement_id: string, item_ids: list<string>, sku_id: string, warehouse_id: string, next_version: int}
     */
    private function postedSerialReceipt(array $headers, string $skuCode, int $itemCount): array
    {
        $warehouse = $this->canonicalWarehouse();
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => 'WWHN-240 '.$skuCode,
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);

        // Contract GĐ1 scan-driven: header-only + composite QR HN1|SKU|ITEM,
        // item register-at-Post — item_instances chỉ tồn tại SAU Post Receipt.
        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'WWHN-240 rollback fixture',
        ]);
        $documentId = $document['document_id'];

        $scanned = $this->scanProductItems(
            $headers,
            $documentId,
            $skuCode,
            $itemCount,
            $document['version'],
            'SN240-'.Str::upper(Str::random(6))
        );

        $post = $this->postInboundReceipt($headers, $documentId, $scanned['version'], 'wwhn240-receipt-'.Str::random(12));
        $post->assertOk()->assertJsonPath('data.status', 'POSTED');
        $nextVersion = (int) ($post->json('data.version') ?? ($scanned['version'] + 1));

        $itemIds = [];
        foreach ($scanned['item_uniques'] as $itemUnique) {
            $itemId = (string) DB::table('item_instances')->where('serial_number', $itemUnique)->value('item_id');
            $this->assertNotSame('', $itemId, 'Post Receipt phải register item candidate thành item_instances.');
            $itemIds[] = $itemId;
        }

        return [
            'document_id' => $documentId,
            'line_id' => (string) $scanned['line_id'],
            'movement_id' => (string) DB::table('stock_movements')
                ->where('stock_document_id', $documentId)
                ->where('movement_type', 'RECEIPT')
                ->value('id'),
            'item_ids' => $itemIds,
            'sku_id' => (string) $sku->sku_id,
            'warehouse_id' => (string) $warehouse->id,
            'next_version' => $nextVersion,
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

    private function reverseItem(array $headers, array $fixture, string $itemId, string $key): TestResponse
    {
        return $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'WWHN-240 QA240-20 forced rollback probe',
            'lines' => [[
                'movement_id' => $fixture['movement_id'],
                'quantity' => 1,
                'item_id' => $itemId,
            ]],
        ], $headers + [
            'If-Match' => (string) $fixture['next_version'],
            'Idempotency-Key' => $key,
        ]);
    }

    /** @return array{gross: int, reversal: int, net: int, closing: int, reversal_net: int} */
    private function xntLine(array $headers, array $fixture): array
    {
        $response = $this->getJson(
            '/api/v1/reports/inventory-xnt-enhanced?from_date='.now()->subDay()->toDateString()
            .'&to_date='.now()->addDay()->toDateString(),
            $headers
        );
        $response->assertOk();
        foreach ((array) $response->json('data.lines') as $line) {
            if (($line['stock_status'] ?? null) === 'AVAILABLE'
                && ($line['warehouse_code'] ?? null) !== null
                && ($line['product_code'] ?? null) === $this->skuCode) {
                return [
                    'gross' => (int) $line['receipt_gross'],
                    'reversal' => (int) $line['receipt_reversal'],
                    'net' => (int) $line['receipt_net'],
                    'closing' => (int) $line['closing'],
                    'reversal_net' => (int) $line['reversal_net'],
                ];
            }
        }
        $this->fail('Báo cáo XNT không có dòng AVAILABLE cho SKU fixture '.$this->skuCode);
    }

    private function ledgerNet(array $fixture): int
    {
        return (int) DB::table('stock_movements')
            ->where('warehouse_id', $fixture['warehouse_id'])
            ->where('product_id', $fixture['sku_id'])
            ->where('stock_status', 'AVAILABLE')
            ->sum('qty_delta');
    }

    private string $skuCode = 'PRD-REV-INJ-01';

    public function test_qa240_20_injected_failure_after_reversal_movement_insert_rolls_back_chain_xnt_and_ledger(): void
    {
        $headers = $this->adminHeaders();
        $fixture = $this->postedSerialReceipt($headers, $this->skuCode, 2);
        $itemId = $fixture['item_ids'][0];
        $idempotencyKey = 'wwhn240-qa20-injected-key-001';

        // ---- Snapshot "nguyên trạng" trước khi tiêm lỗi ----
        $movementsBefore = DB::table('stock_movements')->count();
        $reversalDocsBefore = DB::table('reversal_document')->count();
        $reversalLinesBefore = DB::table('reversal_line')->count();
        $postingEventsBefore = DB::table('posting_event')->count();
        $itemStatusBefore = (string) DB::table('item_instances')->where('item_id', $itemId)->value('item_status');
        $this->assertSame(2, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        $traceBefore = $this->getJson("/api/v1/stock-movements/{$fixture['movement_id']}/trace-source", $headers);
        $traceBefore->assertOk();
        $this->assertSame(0, (int) $traceBefore->json('data.net_effect.reversal_count'));
        $this->assertSame(0, (int) $traceBefore->json('data.net_effect.reversed_qty'));

        $xntBefore = $this->xntLine($headers, $fixture);
        $this->assertSame(['gross' => 2, 'reversal' => 0, 'net' => 2, 'closing' => 2, 'reversal_net' => 0], $xntBefore);
        $this->assertSame(2, $this->ledgerNet($fixture));

        // ---- Tiêm lỗi hạ tầng đúng tại INSERT stock_movements (movement REVERSAL).
        // reversal_document + reversal_line đã được INSERT trước đó trong cùng
        // transaction, nên rollback phải cuốn theo cả hai. ----
        $injected = false;
        Event::listen(function (QueryExecuted $event) use (&$injected): void {
            if ($injected) {
                return;
            }
            $sql = strtolower($event->sql);
            if (str_contains($sql, 'insert into') && str_contains($sql, 'stock_movements')) {
                $injected = true;
                throw new RuntimeException('WWHN-240 QA240-20 injected infrastructure failure after reversal movement insert');
            }
        });

        $failed = $this->reverseItem($headers, $fixture, $itemId, $idempotencyKey);

        Event::forget(QueryExecuted::class);

        $this->assertTrue($injected, 'Lỗi phải được tiêm đúng vào lệnh INSERT stock_movements của movement REVERSAL.');
        $failed->assertStatus(500);
        $this->assertSame('INTERNAL_SERVER_ERROR', $failed->json('error.code'));
        $body = $failed->getContent();
        foreach (['insert into', 'stock_movements', 'RuntimeException', '#0 ', 'vendor/laravel'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $body, "Phản hồi 500 không được lộ chi tiết nội bộ: {$leak}");
        }

        // ---- Rollback sạch: chứng từ + movement + tồn + item + posting_event ----
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count(), 'Movement REVERSAL phải bị rollback.');
        $this->assertSame($reversalDocsBefore, DB::table('reversal_document')->count(), 'reversal_document (INSERT trước movement, cùng transaction) phải cuốn theo rollback.');
        $this->assertSame($reversalLinesBefore, DB::table('reversal_line')->count(), 'reversal_line phải cuốn theo rollback.');
        $this->assertSame($postingEventsBefore, DB::table('posting_event')->count(), 'posting_event phải cuốn theo rollback.');
        $this->assertSame(2, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']), 'Tồn không được thay đổi sau rollback.');
        $this->assertSame(
            $itemStatusBefore,
            (string) DB::table('item_instances')->where('item_id', $itemId)->value('item_status'),
            'item_status phải trở về nguyên trạng.'
        );

        // ---- Chain/XNT/ledger "trở về nguyên trạng" đúng câu chữ QA240-20 ----
        $traceAfter = $this->getJson("/api/v1/stock-movements/{$fixture['movement_id']}/trace-source", $headers);
        $traceAfter->assertOk();
        $this->assertSame(0, (int) $traceAfter->json('data.net_effect.reversal_count'));
        $this->assertSame(0, (int) $traceAfter->json('data.net_effect.reversed_qty'));
        $this->assertSame([], (array) $traceAfter->json('data.reversal_movements'));

        $this->assertSame($xntBefore, $this->xntLine($headers, $fixture), 'XNT gross/reversal/net/closing phải trở về nguyên trạng.');
        $this->assertSame(2, $this->ledgerNet($fixture), 'Ledger SUM(qty_delta) phải trở về nguyên trạng.');

        // ---- Sau sự cố, lease idempotency phải được nhả: CÙNG key phải reverse
        // lại được (không kẹt vĩnh viễn), và lần này đi tới cùng ----
        $recovered = $this->reverseItem($headers, $fixture, $itemId, $idempotencyKey);
        $recovered->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');

        $this->assertSame($movementsBefore + 1, DB::table('stock_movements')->count());
        $this->assertSame($reversalDocsBefore + 1, DB::table('reversal_document')->count());
        $this->assertSame($reversalLinesBefore + 1, DB::table('reversal_line')->count());
        $this->assertSame(1, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        $traceFinal = $this->getJson("/api/v1/stock-movements/{$fixture['movement_id']}/trace-source", $headers);
        $traceFinal->assertOk();
        $this->assertSame(1, (int) $traceFinal->json('data.net_effect.reversal_count'));
        $this->assertSame(1, (int) $traceFinal->json('data.net_effect.reversed_qty'));
        $this->assertFalse((bool) $traceFinal->json('data.correction_chain.truncated'));
        $this->assertSame(2, (int) $traceFinal->json('data.correction_chain.node_count'));
        $nodeIds = array_map(
            static fn (array $node): string => (string) $node['id'],
            (array) $traceFinal->json('data.correction_chain.nodes')
        );
        $this->assertSame(count($nodeIds), count(array_unique($nodeIds)), 'Chain không được trùng node sau recovery.');

        $xntFinal = $this->xntLine($headers, $fixture);
        $this->assertSame(['gross' => 2, 'reversal' => 1, 'net' => 1, 'closing' => 1, 'reversal_net' => -1], $xntFinal);
        $this->assertSame($xntFinal['closing'], $this->ledgerNet($fixture), 'closing (NET) phải khớp ledger SUM(qty_delta) — bất biến WWHN-240.');
    }
}
