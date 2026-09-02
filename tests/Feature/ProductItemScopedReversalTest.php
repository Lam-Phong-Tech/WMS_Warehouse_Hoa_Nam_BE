<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\TestCase;

/**
 * Rule chuẩn item-scope cho Reversal:
 *   $itemScoped = sku_type === 'PRODUCT' || is_serial_tracked
 *
 * PRODUCT LUÔN đảo theo từng Item/máy vật lý cụ thể — kể cả is_serial_tracked=false
 * — và phải hỗ trợ partial reversal (nhập 10 chọn đúng 3 máy để đảo). KHÔNG được
 * chỉ nhập quantity=3 rồi backend tự chọn. COMPONENT non-serial vẫn đảo theo
 * quantity như cũ.
 *
 * Bug gốc: endpoint /stock-movements/reversible tính item-scope theo mỗi
 * is_serial_tracked (trả items=[] cho PRODUCT non-serial) trong khi validation
 * create lại đòi item_id cho PRODUCT ⇒ combo PRODUCT + non-serial không thể tạo
 * phiếu. File này khoá lại hành vi đã thống nhất hai phía.
 */
class ProductItemScopedReversalTest extends TestCase
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
            'username' => 'itemscope-admin-'.Str::lower(Str::random(6)),
            'display_name' => 'Item Scope Admin',
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
     * Phiếu nhập PRODUCT (serial tuỳ chọn) đã POSTED, dựng qua HTTP thật. Một line
     * qty_planned=N sinh đúng MỘT movement RECEIPT quantity=N, với N item_instances.
     *
     * @return array{document_id: string, movement_id: string, item_ids: list<string>, sku_id: string, warehouse_id: string, next_version: int}
     */
    private function postedProductReceipt(array $headers, string $skuCode, int $itemCount, bool $serialTracked): array
    {
        $warehouse = $this->canonicalWarehouse();
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => 'Item-scoped '.$skuCode,
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => $serialTracked,
            'status' => 'ACTIVE',
        ]);

        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'Item-scoped fixture',
        ]);
        $documentId = $document['document_id'];

        $scanned = $this->scanProductItems(
            $headers,
            $documentId,
            $skuCode,
            $itemCount,
            $document['version'],
            'IS-'.Str::upper(Str::random(6))
        );

        $post = $this->postInboundReceipt($headers, $documentId, $scanned['version'], 'itemscope-receipt-'.Str::random(12));
        $post->assertOk()->assertJsonPath('data.status', 'POSTED');
        $nextVersion = (int) ($post->json('data.version') ?? ($scanned['version'] + 1));

        $itemIds = [];
        foreach ($scanned['item_uniques'] as $itemUnique) {
            $itemId = (string) DB::table('item_instances')->where('serial_number', $itemUnique)->value('item_id');
            $this->assertNotSame('', $itemId, 'Post Receipt phải register item_instances cho PRODUCT (kể cả non-serial).');
            $itemIds[] = $itemId;
        }

        return [
            'document_id' => $documentId,
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

    /** Đảo nhiều Item trong một request (mỗi máy một line quantity=1 + item_id). */
    private function reverseItems(array $fixture, array $headers, array $itemIds, ?string $key = null): TestResponse
    {
        $lines = array_map(fn (string $itemId): array => [
            'movement_id' => $fixture['movement_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $itemIds);

        return $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Item-scoped partial reversal',
            'lines' => $lines,
        ], $headers + [
            'If-Match' => (string) $fixture['next_version'],
            'Idempotency-Key' => $key ?? 'itemscope-rev-'.Str::random(12),
        ]);
    }

    private function attachEvidenceThenPost(array $headers, string $reversalId, int $version, string $idempotencyKey): TestResponse
    {
        $this->post("/api/v1/reversal-documents/{$reversalId}/attachments", [
            'file' => UploadedFile::fake()->image('is-bien-ban-'.Str::lower(Str::random(6)).'.jpg'),
        ], $headers)->assertCreated();

        return $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $headers + [
            'If-Match' => (string) $version,
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    /** #1 — PRODUCT serial: nhập 10, đảo đúng 3 Item. */
    public function test_product_serial_receive_ten_reverse_exactly_three_items(): void
    {
        Storage::fake('local');
        $headers = $this->adminHeaders();
        $fixture = $this->postedProductReceipt($headers, 'PRD-SER-10', 10, true);
        $this->assertSame(10, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        $created = $this->reverseItems($fixture, $headers, array_slice($fixture['item_ids'], 0, 3));
        $created->assertOk()->assertJsonPath('data.reversal_status', 'DRAFT');
        $this->attachEvidenceThenPost($headers, (string) $created->json('data.reversal_document_id'), 1, 'is-ser-post-'.Str::random(8))
            ->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');

        $this->assertSame(7, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']), 'Đảo 3/10 phải còn 7.');
        $this->assertSame(3, DB::table('stock_movements')->where('movement_type', 'REVERSAL')->where('sku_id', $fixture['sku_id'])->count());
    }

    /** #2 — PRODUCT non-serial: nhập 10, VẪN chọn được đúng 3 Item để đảo. */
    public function test_product_non_serial_receive_ten_reverse_exactly_three_items(): void
    {
        Storage::fake('local');
        $headers = $this->adminHeaders();
        $fixture = $this->postedProductReceipt($headers, 'PRD-NOSER-10', 10, false);
        $this->assertSame(10, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        // Reversible lookup phải chào từng Item cho PRODUCT non-serial (item_scoped=true, items != []).
        $lookup = $this->getJson('/api/v1/stock-movements/reversible?reversible_only=1&search=PRD-NOSER-10', $headers)->assertOk();
        $row = collect($lookup->json('data'))->firstWhere('id', $fixture['movement_id']);
        $this->assertNotNull($row, 'Movement PRODUCT non-serial phải xuất hiện trong danh sách reversible.');
        $this->assertTrue((bool) $row['item_scoped'], 'PRODUCT non-serial phải item_scoped=true.');
        $this->assertCount(10, $row['lines'][0]['items'], 'Phải trả đủ 10 Item ứng viên để chọn.');

        $created = $this->reverseItems($fixture, $headers, array_slice($fixture['item_ids'], 0, 3));
        $created->assertOk()->assertJsonPath('data.reversal_status', 'DRAFT');
        $this->attachEvidenceThenPost($headers, (string) $created->json('data.reversal_document_id'), 1, 'is-noser-post-'.Str::random(8))
            ->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');

        $this->assertSame(7, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']), 'Đảo 3/10 PRODUCT non-serial phải còn 7.');
        $reversalMovements = DB::table('stock_movements')->where('movement_type', 'REVERSAL')->where('sku_id', $fixture['sku_id'])->count();
        $this->assertSame(3, $reversalMovements, 'Đúng 3 movement REVERSAL, mỗi máy một.');
    }

    /** #3 — PRODUCT thiếu item selection (chỉ nhập quantity) → reject, không ghi. */
    public function test_product_non_serial_reverse_without_item_selection_is_rejected(): void
    {
        $headers = $this->adminHeaders();
        $fixture = $this->postedProductReceipt($headers, 'PRD-NOSER-NOITEM', 5, false);
        $movementsBefore = DB::table('stock_movements')->count();

        $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/reverse", [
            'reason' => 'Chỉ nhập quantity, không chọn máy',
            'lines' => [['movement_id' => $fixture['movement_id'], 'quantity' => 3]],
        ], $headers + [
            'If-Match' => (string) $fixture['next_version'],
            'Idempotency-Key' => 'is-noitem-'.Str::random(12),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REVERSAL_ITEM_REQUIRED');

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count(), 'Bị chặn thì không ghi movement.');
        $this->assertSame(5, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
    }

    /** #4a — Item không thuộc phiếu gốc → reject (rollback toàn bộ). */
    public function test_product_non_serial_reverse_item_not_in_original_is_rejected(): void
    {
        $headers = $this->adminHeaders();
        $fixture = $this->postedProductReceipt($headers, 'PRD-NOSER-FOREIGN', 3, false);
        $movementsBefore = DB::table('stock_movements')->count();

        $this->reverseItems($fixture, $headers, [(string) Str::uuid()])
            ->assertStatus(422);

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(3, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
    }

    /** #4b — Item đã đảo rồi → reject lần hai, không double-trừ tồn. */
    public function test_product_non_serial_double_reverse_same_item_is_blocked(): void
    {
        Storage::fake('local');
        $headers = $this->adminHeaders();
        $fixture = $this->postedProductReceipt($headers, 'PRD-NOSER-DUP', 3, false);
        $itemId = $fixture['item_ids'][0];

        $created = $this->reverseItems($fixture, $headers, [$itemId]);
        $created->assertOk();
        $this->attachEvidenceThenPost($headers, (string) $created->json('data.reversal_document_id'), 1, 'is-dup-post-'.Str::random(8))
            ->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');
        $this->assertSame(2, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));

        $movementsAfterFirst = DB::table('stock_movements')->count();
        $this->reverseItems($fixture, $headers, [$itemId])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REVERSAL_ITEM_ALREADY_REVERSED');

        $this->assertSame($movementsAfterFirst, DB::table('stock_movements')->count(), 'Lần đảo trùng không ghi thêm movement.');
        $this->assertSame(2, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
    }

    /** #4c — Item đã đi downstream → dependency guard chặn. */
    public function test_product_non_serial_downstream_dependency_blocks_reversal(): void
    {
        $headers = $this->adminHeaders();
        $fixture = $this->postedProductReceipt($headers, 'PRD-NOSER-DEP', 3, false);
        $itemId = $fixture['item_ids'][0];

        DB::table('item_instances')->where('item_id', $itemId)->update(['item_status' => 'ISSUED', 'updated_at' => now()]);

        $movementsBefore = DB::table('stock_movements')->count();
        $this->reverseItems($fixture, $headers, [$itemId])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DOWNSTREAM_DEPENDENCY_EXISTS');

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame(3, $this->balanceQty($fixture['warehouse_id'], $fixture['sku_id']));
    }

    /** #5 — COMPONENT non-serial: vẫn đảo theo quantity, không đòi item_id. */
    public function test_component_non_serial_still_reverses_by_quantity(): void
    {
        Storage::fake('local');
        $headers = $this->adminHeaders();
        $warehouse = $this->canonicalWarehouse();
        $sku = $this->componentSkuFixture('CMP-NOSER-REV-'.Str::upper(Str::random(4)), false);

        $document = $this->createInboundHeader($headers, (string) $warehouse->id, ['source_name' => 'Component qty fixture']);
        $received = $this->receiveComponentQuantity($headers, $document['document_id'], (string) $sku->sku_id, 10, $document['version']);
        $post = $this->postInboundReceipt($headers, $document['document_id'], $received['version'], 'is-cmp-receipt-'.Str::random(10));
        $post->assertOk()->assertJsonPath('data.status', 'POSTED');
        $nextVersion = (int) ($post->json('data.version') ?? ($received['version'] + 1));

        $movementId = (string) DB::table('stock_movements')
            ->where('stock_document_id', $document['document_id'])
            ->where('movement_type', 'RECEIPT')
            ->value('id');
        $this->assertSame(10, $this->balanceQty((string) $warehouse->id, (string) $sku->sku_id));

        // Đảo 4 theo quantity, KHÔNG item_id — phải chấp nhận (quantity-mode).
        $created = $this->postJson("/api/v1/inbound-documents/{$document['document_id']}/reverse", [
            'reason' => 'Component quantity reversal',
            'lines' => [['movement_id' => $movementId, 'quantity' => 4]],
        ], $headers + [
            'If-Match' => (string) $nextVersion,
            'Idempotency-Key' => 'is-cmp-rev-'.Str::random(12),
        ]);
        $created->assertOk()->assertJsonPath('data.reversal_status', 'DRAFT');
        $this->attachEvidenceThenPost($headers, (string) $created->json('data.reversal_document_id'), 1, 'is-cmp-post-'.Str::random(8))
            ->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');

        $this->assertSame(6, $this->balanceQty((string) $warehouse->id, (string) $sku->sku_id), 'Đảo quantity 4/10 component phải còn 6.');
    }
}
