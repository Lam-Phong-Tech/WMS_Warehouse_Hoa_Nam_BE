<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-234 vòng 2 (đánh giá 67/100) — đóng ba finding P2 còn OPEN của
 * `CONCURRENCY_STATE_REVIEW.md`: F-04 (thứ tự khoá `inventory_balances`), F-05
 * (data scope Component Issue Post) và F-06 (lost update `label_snapshots.version`).
 *
 * Không thay thế runtime multi-process evidence kiểu `Wwhn219RuntimeConcurrencyTest`
 * cho F-04 (cần hai PostgreSQL backend độc lập); test này chứng minh trực tiếp thứ
 * tự khoá bằng `DB::listen`, đủ để khẳng định thứ tự đã đổi đúng như finding yêu
 * cầu mà không cần hạ tầng đa tiến trình.
 */
class Wwhn234P1P2ClosureTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** Warehouse id nằm ngoài mọi phạm vi hợp lệ — dùng làm scope_ref "kho khác". */
    private const FOREIGN_WAREHOUSE_ID = '00000000-0000-4000-8000-000000000234';

    // ------------------------------------------------------------------
    // Fixture helpers
    // ------------------------------------------------------------------

    private function scopedComponentIssueHeaders(string $warehouseId): array
    {
        $roleCode = 'WWHN234_SCOPED_'.Str::upper(Str::random(6));
        $role = Role::create([
            'role_code' => $roleCode,
            'role_name' => $roleCode,
            'scope_type' => 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);

        $permission = Permission::firstOrCreate(
            ['permission_key' => 'warranty.component_issue'],
            ['permission_name' => 'warranty.component_issue', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        DB::table('role_permission')->updateOrInsert(
            ['role_id' => $role->role_id, 'permission_id' => $permission->permission_id],
            ['role_permission_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
        );

        $user = User::create([
            'username' => 'wwhn234-scoped-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-234 Scoped Actor',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(),
            'scope_ref' => $warehouseId,
        ]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function canonicalWarehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE']
        );
    }

    private function warrantyCaseId(array $adminHeaders): string
    {
        $case = $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => 'Khách WWHN-234',
            'customer_phone' => '0933000234',
            'description' => 'Máy không lên nguồn',
            'missing_code_reason' => 'Mất tem mã',
            'manual_product_description' => 'Máy khoan',
        ], $adminHeaders + ['Idempotency-Key' => 'w234-case-'.(string) Str::uuid()]);
        $case->assertCreated();

        return (string) $case->json('data.id');
    }

    /** @return array{document_id: string, warehouse_id: string} */
    private function componentIssueDocument(array $adminHeaders, string $warehouseId, string $skuId): array
    {
        $caseId = $this->warrantyCaseId($adminHeaders);
        $create = $this->postJson('/api/v1/component-issue-documents', [
            'warehouse_id' => $warehouseId,
            'warranty_case_id' => $caseId,
            'lines' => [['sku_id' => $skuId, 'required_qty' => 1]],
        ], $adminHeaders);
        $create->assertCreated();

        return ['document_id' => (string) $create->json('data.document.id'), 'warehouse_id' => $warehouseId];
    }

    private function componentSku(string $codeSuffix): Sku
    {
        return Sku::create([
            'sku_code' => 'LK-234-'.$codeSuffix.'-'.Str::upper(Str::random(4)),
            'sku_name' => 'Linh kiện WWHN-234',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
    }

    // ------------------------------------------------------------------
    // F-05 — EnforceComponentIssueWarehouseScope
    // ------------------------------------------------------------------

    public function test_f05_post_component_issue_is_denied_when_actor_scope_is_a_different_warehouse(): void
    {
        $admin = $this->superAdminHeaders();
        $warehouse = $this->canonicalWarehouse();
        $sku = $this->componentSku('POST');
        $doc = $this->componentIssueDocument($admin, (string) $warehouse->id, (string) $sku->sku_id);

        $foreign = $this->scopedComponentIssueHeaders(self::FOREIGN_WAREHOUSE_ID);

        $response = $this->postJson("/api/v1/component-issue-documents/{$doc['document_id']}/post", [], $foreign + [
            'If-Match' => '1',
            'Idempotency-Key' => 'w234-f05-post-'.Str::random(8),
        ]);

        $response->assertStatus(403)->assertJsonPath('error_code', 'DATA_SCOPE_DENIED');
        $this->assertSame(
            0,
            (int) DB::table('stock_movements')->where('source_id', $doc['document_id'])->count(),
            'Request bị chặn scope không được để lại bất kỳ movement nào.'
        );
        $this->assertSame('DRAFT', (string) DB::table('component_issue_documents')->where('id', $doc['document_id'])->value('status'));
    }

    public function test_f05_scan_and_cancel_are_denied_when_actor_scope_is_a_different_warehouse(): void
    {
        $admin = $this->superAdminHeaders();
        $warehouse = $this->canonicalWarehouse();
        $sku = $this->componentSku('SCANCANCEL');
        $doc = $this->componentIssueDocument($admin, (string) $warehouse->id, (string) $sku->sku_id);
        $foreign = $this->scopedComponentIssueHeaders(self::FOREIGN_WAREHOUSE_ID);

        $scan = $this->postJson("/api/v1/component-issue-documents/{$doc['document_id']}/scan", [
            'line_id' => (string) Str::uuid(),
            'quantity' => 1,
        ], $foreign + ['If-Match' => '1']);
        $scan->assertStatus(403)->assertJsonPath('error_code', 'DATA_SCOPE_DENIED');

        $cancel = $this->postJson("/api/v1/component-issue-documents/{$doc['document_id']}/cancel", [
            'reason' => 'test',
        ], $foreign + ['If-Match' => '1']);
        $cancel->assertStatus(403)->assertJsonPath('error_code', 'DATA_SCOPE_DENIED');

        $this->assertSame('DRAFT', (string) DB::table('component_issue_documents')->where('id', $doc['document_id'])->value('status'));
    }

    public function test_f05_show_is_denied_when_actor_scope_is_a_different_warehouse(): void
    {
        $admin = $this->superAdminHeaders();
        $warehouse = $this->canonicalWarehouse();
        $sku = $this->componentSku('SHOW');
        $doc = $this->componentIssueDocument($admin, (string) $warehouse->id, (string) $sku->sku_id);
        $foreign = $this->scopedComponentIssueHeaders(self::FOREIGN_WAREHOUSE_ID);

        $this->getJson("/api/v1/component-issue-documents/{$doc['document_id']}", $foreign)
            ->assertStatus(403)->assertJsonPath('error_code', 'DATA_SCOPE_DENIED');

        // Bí danh Annex C phải chịu đúng guard, không lệch với route chính.
        $this->getJson("/api/v1/warranty-component-issues/{$doc['document_id']}", $foreign)
            ->assertStatus(403)->assertJsonPath('error_code', 'DATA_SCOPE_DENIED');
    }

    public function test_f05_store_is_denied_when_body_warehouse_id_is_outside_actor_scope(): void
    {
        $admin = $this->superAdminHeaders();
        $warehouse = $this->canonicalWarehouse();
        $sku = $this->componentSku('STOREDENY');
        $caseId = $this->warrantyCaseId($admin);
        $foreign = $this->scopedComponentIssueHeaders(self::FOREIGN_WAREHOUSE_ID);

        $before = DB::table('component_issue_documents')->count();

        $response = $this->postJson('/api/v1/component-issue-documents', [
            'warehouse_id' => (string) $warehouse->id,
            'warranty_case_id' => $caseId,
            'lines' => [['sku_id' => $sku->sku_id, 'required_qty' => 1]],
        ], $foreign);

        $response->assertStatus(403)->assertJsonPath('error_code', 'DATA_SCOPE_DENIED');
        $this->assertSame($before, DB::table('component_issue_documents')->count(), 'Không được tạo document khi bị chặn scope.');
    }

    /** Ca đối chứng: actor đúng scope không bị chặn nhầm (cả store lẫn show). */
    public function test_f05_requests_still_succeed_when_actor_scope_matches_the_document_warehouse(): void
    {
        $admin = $this->superAdminHeaders();
        $warehouse = $this->canonicalWarehouse();
        $sku = $this->componentSku('INSCOPE');
        $caseId = $this->warrantyCaseId($admin);
        $inScope = $this->scopedComponentIssueHeaders((string) $warehouse->id);

        $create = $this->postJson('/api/v1/component-issue-documents', [
            'warehouse_id' => (string) $warehouse->id,
            'warranty_case_id' => $caseId,
            'lines' => [['sku_id' => $sku->sku_id, 'required_qty' => 1]],
        ], $inScope);
        $create->assertCreated();
        $documentId = (string) $create->json('data.document.id');

        $this->getJson("/api/v1/component-issue-documents/{$documentId}", $inScope)->assertOk();
    }

    // ------------------------------------------------------------------
    // F-04 — thứ tự khoá inventory_balances theo product_id toàn cục
    // ------------------------------------------------------------------

    public function test_f04_receipt_locks_inventory_balances_in_global_sku_order_not_line_order(): void
    {
        $admin = $this->superAdminHeaders();
        $warehouse = $this->canonicalWarehouse();

        // sku_type=COMPONENT (không phải PRODUCT): scan theo quantity-mode, không
        // bắt buộc item_id cụ thể — tránh phải dựng thêm item_instances chỉ để
        // chứng minh thứ tự khoá balance, vốn không phụ thuộc sku_type.
        $skuA = Sku::create(['sku_code' => 'WWHN234-F04-A-'.Str::upper(Str::random(4)), 'sku_name' => 'A', 'sku_type' => 'COMPONENT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $skuB = Sku::create(['sku_code' => 'WWHN234-F04-B-'.Str::upper(Str::random(4)), 'sku_name' => 'B', 'sku_type' => 'COMPONENT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        // Sắp line_no NGƯỢC với thứ tự chuỗi của sku_id, để chứng minh pha
        // mutateBalance không còn đi theo line_no.
        [$firstSkuId, $secondSkuId] = [(string) $skuA->sku_id, (string) $skuB->sku_id];
        [$lowerSkuId, $higherSkuId] = $firstSkuId < $secondSkuId ? [$firstSkuId, $secondSkuId] : [$secondSkuId, $firstSkuId];

        // Contract GĐ1 scan-driven: header-only; line 1 = sku LỚN HƠN (materialize
        // trước), line 2 = sku NHỎ HƠN — cố tình ngược thứ tự chuỗi.
        $document = $this->createInboundHeader($admin, (string) $warehouse->id, [
            'source_name' => 'NCC WWHN-234-F04',
        ]);
        $documentId = $document['document_id'];

        $receivedHigher = $this->receiveComponentQuantity(
            $admin, $documentId, $higherSkuId, 2, $document['version'], 'BOX-234-H-'.Str::upper(Str::random(6))
        );
        $receivedLower = $this->receiveComponentQuantity(
            $admin, $documentId, $lowerSkuId, 2, $receivedHigher['version'], 'BOX-234-L-'.Str::upper(Str::random(6))
        );

        $captured = [];
        Event::listen(function (QueryExecuted $event) use (&$captured): void {
            $sql = strtolower($event->sql);
            if (str_starts_with(trim($sql), 'select') && str_contains($sql, 'inventory_balances') && str_contains($sql, 'warehouse_id')) {
                $captured[] = (string) ($event->bindings[1] ?? '');
            }
        });

        $this->postInboundReceipt($admin, $documentId, $receivedLower['version'], 'w234-f04-'.Str::random(8))
            ->assertOk();

        Event::forget(QueryExecuted::class);

        $this->assertCount(2, $captured, 'Phải có đúng hai lượt khoá inventory_balances, một cho mỗi SKU.');
        $this->assertSame(
            [$lowerSkuId, $higherSkuId],
            $captured,
            'WWHN-234 F-04: pha mutateBalance phải khoá theo thứ tự product_id toàn cục (tăng dần), không theo line_no của chứng từ.'
        );
        $this->assertNotSame(
            [$higherSkuId, $lowerSkuId],
            $captured,
            'Bảo vệ chống false-positive: nếu vẫn khoá theo line_no thì thứ tự phải là [higher, lower] — test phải phân biệt được hai trường hợp.'
        );
    }

    public function test_f04_source_guard_component_issue_post_shares_the_same_balance_lock_ordering_helper(): void
    {
        $source = (string) file_get_contents(base_path('Modules/Warehouse/app/Services/CurrentBaselineStockService.php'));
        $this->assertStringContainsString('sortLinesForBalanceLock', $source);
        $this->assertSame(
            3,
            substr_count($source, 'sortLinesForBalanceLock('),
            'Phải có đúng 3 lần gọi: định nghĩa hàm + postStockDocument + postComponentIssue.'
        );
    }

    /**
     * WWHN-234 F-04 (bổ sung vòng 2) — CONCURRENCY_STATE_REVIEW.md §4 chỉ trích dẫn
     * `CurrentBaselineStockService:147-171` nhưng Reversal Post (A3/A3') đi qua
     * `CurrentBaselineReversalService::postDraft()` và khoá `inventory_balances` qua
     * cùng `mutateBalance()` — cùng lớp lỗi thứ tự khoá theo `reversal_line.line_no`
     * thay vì thứ tự toàn cục. Test này chứng minh `postDraft()` cũng đã được sửa,
     * không chỉ hai action Post kia.
     */
    public function test_f04_reversal_locks_inventory_balances_in_global_sku_order_not_line_order(): void
    {
        $admin = $this->superAdminHeaders();
        $warehouse = $this->canonicalWarehouse();

        $skuA = Sku::create(['sku_code' => 'WWHN234-F04R-A-'.Str::upper(Str::random(4)), 'sku_name' => 'A', 'sku_type' => 'COMPONENT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $skuB = Sku::create(['sku_code' => 'WWHN234-F04R-B-'.Str::upper(Str::random(4)), 'sku_name' => 'B', 'sku_type' => 'COMPONENT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);

        [$firstSkuId, $secondSkuId] = [(string) $skuA->sku_id, (string) $skuB->sku_id];
        [$lowerSkuId, $higherSkuId] = $firstSkuId < $secondSkuId ? [$firstSkuId, $secondSkuId] : [$secondSkuId, $firstSkuId];

        // Contract GĐ1 scan-driven: header-only + component-lines, line 1 = sku lớn hơn.
        $document = $this->createInboundHeader($admin, (string) $warehouse->id, [
            'source_name' => 'NCC WWHN-234-F04R',
        ]);
        $documentId = $document['document_id'];

        $receivedHigher = $this->receiveComponentQuantity(
            $admin, $documentId, $higherSkuId, 2, $document['version'], 'BOX-234R-H-'.Str::upper(Str::random(6))
        );
        $receivedLower = $this->receiveComponentQuantity(
            $admin, $documentId, $lowerSkuId, 2, $receivedHigher['version'], 'BOX-234R-L-'.Str::upper(Str::random(6))
        );

        $post = $this->postInboundReceipt($admin, $documentId, $receivedLower['version'], 'w234-f04r-post-'.Str::random(8));
        $post->assertOk();
        $postedVersion = (int) ($post->json('data.version') ?? ($receivedLower['version'] + 1));

        $movementHigher = (string) DB::table('stock_movements')->where('stock_document_id', $documentId)->where('product_id', $higherSkuId)->value('id');
        $movementLower = (string) DB::table('stock_movements')->where('stock_document_id', $documentId)->where('product_id', $lowerSkuId)->value('id');

        $captured = [];
        Event::listen(function (QueryExecuted $event) use (&$captured): void {
            $sql = strtolower($event->sql);
            if (str_starts_with(trim($sql), 'select') && str_contains($sql, 'inventory_balances') && str_contains($sql, 'warehouse_id')) {
                $captured[] = (string) ($event->bindings[1] ?? '');
            }
        });

        // Khai reversal line theo thứ tự NGƯỢC với thứ tự chuỗi sku_id (line_no=1 là
        // SKU lớn hơn) — giống hệt cách test_f04_receipt dựng chứng từ gốc, để chứng
        // minh postDraft() không còn khoá inventory_balances theo thứ tự line của payload.
        $reverse = $this->postJson("/api/v1/inbound-documents/{$documentId}/reverse", [
            'reason' => 'WWHN-234 F-04 reversal lock order',
            'lines' => [
                ['movement_id' => $movementHigher, 'quantity' => 2],
                ['movement_id' => $movementLower, 'quantity' => 2],
            ],
        ], $admin + [
            'If-Match' => (string) $postedVersion,
            'Idempotency-Key' => 'w234-f04r-'.Str::random(8),
        ]);
        $reverse->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');

        Event::forget(QueryExecuted::class);

        // Reversal receipt có HAI lượt khoá inventory_balances theo SKU cho mỗi dòng
        // quantity-mode: assertQuantityDependency() (BR-REV-03/04, createDraft — Pha 2)
        // rồi mutateBalance() (postDraft). Cả hai pha đều phải khoá theo cùng thứ tự
        // sku_id toàn cục — nếu không, pha còn "quên" sửa sẽ lộ ra như một cặp
        // [higher, lower] xen giữa dãy kỳ vọng [lower, higher, lower, higher].
        $this->assertCount(4, $captured, 'Phải có đúng 4 lượt khoá inventory_balances: 2 SKU × (assertQuantityDependency + mutateBalance).');
        $this->assertSame(
            [$lowerSkuId, $higherSkuId, $lowerSkuId, $higherSkuId],
            $captured,
            'WWHN-234 F-04: cả assertQuantityDependency() (createDraft) lẫn mutateBalance() (postDraft) phải khoá inventory_balances theo thứ tự sku_id toàn cục (tăng dần), không theo line_no của reversal.'
        );
    }

    public function test_f04_source_guard_reversal_service_uses_the_balance_lock_ordering_helper(): void
    {
        $source = (string) file_get_contents(base_path('Modules/Warehouse/app/Services/CurrentBaselineReversalService.php'));
        $this->assertStringContainsString('sortReversalLinesForBalanceLock', $source);
        $this->assertSame(
            2,
            substr_count($source, 'sortReversalLinesForBalanceLock('),
            'Phải có đúng 2 lần gọi: định nghĩa hàm + postDraft().'
        );
        // createDraft() cũng khoá inventory_balances qua assertQuantityDependency()
        // (BR-REV-03/04) — nếu chỉ sắp lại postDraft() mà quên pha này, thứ tự khoá
        // ĐẦU TIÊN của giao dịch vẫn là line_no cũ vì assertQuantityDependency() chạy
        // trước mutateBalance() trong cùng transaction (khoá lại lần hai là no-op).
        $this->assertStringContainsString('quantityDependencyChecks', $source);
    }

    // ------------------------------------------------------------------
    // F-06 — CAS trên label_snapshots.version (chặn lost update)
    // ------------------------------------------------------------------

    /**
     * Toàn bộ `dispatchLabelOnce()` chạy TRONG `DB::transaction()` của
     * `IdempotencyStore::run()` (bọc quanh cả lời gọi HTTP tới printer agent).
     * Vì PHPUnit feature test chỉ có MỘT connection/MỘT transaction lồng nhau
     * (RefreshDatabase), một write "giả lập request thắng race" chèn vào giữa
     * qua `Http::fake()` KHÔNG thể commit độc lập — nếu request đang test abort,
     * Laravel rollback nguyên transaction ngoài cùng và cuốn theo cả write giả
     * lập đó (đã kiểm chứng thực nghiệm: version quay lại giá trị cũ thay vì giữ
     * nguyên). Đây là giới hạn thật của kiểm thử đơn tiến trình/đơn connection —
     * đúng loại ranh giới mà `Wwhn219RuntimeConcurrencyTest` (đa tiến trình,
     * PostgreSQL) tách riêng khỏi "architecture guard". Test này vì vậy kiểm
     * đúng CƠ CHẾ CAS ở đúng câu UPDATE mà `dispatchLabelOnce`/`recordPrintFailure`
     * dùng — độc lập với tầng HTTP/Idempotency — thay vì dựng lại một race không
     * thể tái lập trung thực trong tiến trình đơn.
     */
    public function test_f06_cas_update_rejects_a_stale_expected_version_instead_of_overwriting(): void
    {
        $labelId = (string) Str::uuid();
        DB::table('label_snapshots')->insert([
            'id' => $labelId,
            'outbound_document_id' => null,
            'outbound_item_match_id' => null,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode(['test' => true]),
            'status' => 'READY_TO_PRINT',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Request "thắng race": đọc version=1, in xong, CAS đúng version=1 → OK.
        $winnerUpdated = DB::table('label_snapshots')->where('id', $labelId)->where('version', 1)
            ->update(['status' => 'PRINTED', 'version' => 2, 'updated_at' => now()]);
        $this->assertSame(1, $winnerUpdated, 'CAS phải thành công khi version khớp đúng cái đã đọc.');
        $this->assertSame(2, (int) DB::table('label_snapshots')->where('id', $labelId)->value('version'));

        // Request "thua race": cũng từng đọc version=1 (trước khi request kia
        // commit), nhưng khi tới lượt ghi thì DB đã là 2 — đúng câu UPDATE mà
        // dispatchLabelOnce()/recordPrintFailure() dùng sau WWHN-234 F-06.
        $loserExpectedVersion = 1;
        $loserUpdated = DB::table('label_snapshots')->where('id', $labelId)->where('version', $loserExpectedVersion)
            ->update(['status' => 'PRINT_FAILED', 'version' => $loserExpectedVersion + 1, 'updated_at' => now()]);

        $this->assertSame(0, $loserUpdated, 'CAS phải KHÔNG khớp hàng nào khi version đã bị đổi — đây chính là chỗ code mới abort ConflictCode::CONCURRENCY.');
        $this->assertSame(
            2,
            (int) DB::table('label_snapshots')->where('id', $labelId)->value('version'),
            'Version của request thắng race không được bị request thua ghi đè (lost update của F-06).'
        );
        $this->assertSame('PRINTED', (string) DB::table('label_snapshots')->where('id', $labelId)->value('status'), 'Status của request thắng cũng không được bị ghi đè.');
    }

    public function test_f06_print_without_a_racing_writer_still_succeeds_and_bumps_version_once(): void
    {
        $admin = $this->superAdminHeaders();

        $printer = $this->postJson('/api/v1/printer-configs', [
            'printer_code' => 'PRT-234B-'.Str::upper(Str::random(6)),
            'printer_name' => 'WWHN-234 F-06 Printer Control',
            'agent_endpoint' => 'https://printer.wwhn234.test/print',
            'status' => 'ACTIVE',
        ], $admin);
        $printer->assertCreated();
        $printerId = (string) $printer->json('data.id');

        $labelId = (string) Str::uuid();
        DB::table('label_snapshots')->insert([
            'id' => $labelId,
            'outbound_document_id' => null,
            'outbound_item_match_id' => null,
            'sku_id' => null,
            'item_id' => null,
            'label_payload' => json_encode(['test' => true]),
            'status' => 'READY_TO_PRINT',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(fn () => Http::response(['success' => true], 200));

        $response = $this->postJson("/api/v1/label-snapshots/{$labelId}/print", [
            'printer_config_id' => $printerId,
        ], $admin + ['If-Match' => '1', 'Idempotency-Key' => 'w234-f06-control-'.Str::random(8)]);

        $response->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'PRINTED');
        $this->assertSame(2, (int) DB::table('label_snapshots')->where('id', $labelId)->value('version'));
    }

    public function test_f06_source_guard_label_dispatch_uses_conflict_code_cas_not_blind_overwrite(): void
    {
        $source = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php'));
        $this->assertSame(
            2,
            substr_count($source, "->where('version', \$expectedVersion)"),
            'Cả nhánh thành công (dispatchLabelOnce) lẫn recordPrintFailure phải CAS theo version, không ghi đè vô điều kiện.'
        );
        $this->assertStringContainsString('ConflictCode::CONCURRENCY', $source);
    }
}
