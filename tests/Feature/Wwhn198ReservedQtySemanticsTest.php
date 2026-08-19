<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-198 / DEV198-P1-04R — chốt ngữ nghĩa `inventory_balances.reserved_qty`
 * theo DEC-INV-RESERVED-001.
 *
 * Vấn đề gốc: field luôn bằng 0 nhưng không ai nói rõ "0 nghĩa là gì". FE map nó
 * thành `reservedQty`; ai đó có thể hiểu là "không giữ chỗ gì cả" hoặc tự tính
 * khả dụng = on_hand - reserved và ra số sai.
 *
 * Quyết định: giữ chỗ là IDENTITY-LEVEL (`outbound_item_matches.status='ACTIVE'`),
 * không phải quantity-level. `reserved_qty` chỉ là compatibility field hằng 0.
 * Test này khoá cả hai vế: (a) contract máy đọc được nói đúng như vậy, (b) runtime
 * thật không bao giờ ghi khác 0, kể cả sau khi đã giữ item và sau khi Post Issue.
 */
class Wwhn198ReservedQtySemanticsTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** @return array<string,mixed> */
    private function reservedContract(): array
    {
        $path = base_path('docs/api-contract/WWHN-198-reserved-qty-contract.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_contract_pins_identity_level_reservation_not_quantity_level(): void
    {
        $contract = $this->reservedContract();

        $this->assertSame('DEC-INV-RESERVED-001', $contract['decision']);
        $this->assertSame('COMPATIBILITY_FIELD', $contract['semantics']);
        $this->assertSame(0, $contract['constant_value']);
        $this->assertFalse($contract['is_authoritative_for_availability']);
        $this->assertSame('IDENTITY_LEVEL', $contract['reservation_model']);
        $this->assertSame('outbound_item_matches', $contract['reservation_source_of_truth']['table']);
        $this->assertFileExists(base_path($contract['decision_document']));
    }

    public function test_reserved_qty_stays_zero_while_an_item_is_actually_reserved_by_a_scan(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('RESV-SEM-001');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 3);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-'.Str::upper(Str::random(6)));
        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $itemId = $this->seedItemInStock($sku->sku_id);

        $this->postJson("/api/v1/outbound-documents/{$created['document_id']}/scan", [
            'line_id' => $created['line_ids'][0],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        $balance = DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where('sku_id', $sku->sku_id)
            ->where('stock_status', 'AVAILABLE')
            ->first();

        // Item ĐANG bị giữ chỗ thật — nhưng bằng match ACTIVE, không phải bằng con số.
        $this->assertSame(
            1,
            DB::table('outbound_item_matches')->where('item_id', $itemId)->where('status', 'ACTIVE')->count(),
            'Giữ chỗ phải hiện diện ở tầng identity (outbound_item_matches ACTIVE).'
        );
        $this->assertSame(0, (int) $balance->reserved_qty, 'reserved_qty là compatibility field hằng 0 (DEC-INV-RESERVED-001).');
        $this->assertSame(3, (int) $balance->available_qty, 'Scan stock-neutral: available_qty chưa đổi cho tới khi Post Issue.');
        $this->assertSame(3, (int) $balance->on_hand_qty);
    }

    public function test_reserved_qty_is_still_zero_after_post_issue_and_availability_comes_from_available_qty(): void
    {
        $headers = $this->superAdminHeaders();
        $ctx = $this->readyToPostIssueDocument($headers, 'RESV-SEM-002');

        $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn198-resv-'.Str::random(12),
        ])->assertOk();

        $balance = DB::table('inventory_balances')
            ->where('warehouse_id', $ctx['warehouse_id'])
            ->where('sku_id', $ctx['sku_id'])
            ->where('stock_status', 'AVAILABLE')
            ->first();

        $this->assertSame(0, (int) $balance->reserved_qty, 'Post Issue không được bắt đầu ghi reserved_qty mà không có Decision Record thay thế.');
        $this->assertSame(0, (int) $balance->available_qty, 'Chỉ Post Issue mới trừ tồn; available_qty là nguồn khả dụng.');
        $this->assertSame(0, (int) $balance->on_hand_qty);
    }

    public function test_no_production_code_path_writes_a_non_zero_reserved_qty(): void
    {
        // Guard tĩnh bổ sung cho hai test runtime ở trên: nếu ai đó thêm một đường
        // ghi reserved_qty mới, test này đỏ ngay tại review chứ không đợi tới UAT.
        $roots = [base_path('app'), base_path('Modules')];
        $offenders = [];
        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (! str_contains($source, 'reserved_qty')) {
                    continue;
                }
                // Chỉ chấp nhận: (a) gán hằng 0 khi ghi DB, hoặc (b) đọc lại chính
                // giá trị đang có để trả về response/report (passthrough). Mọi biểu
                // thức khác nghĩa là có người bắt đầu tính toán reserved_qty thật.
                preg_match_all("/'reserved_qty'\s*=>\s*([^,\n]+)/", $source, $matches);
                foreach ($matches[1] as $expression) {
                    $expression = trim($expression);
                    $isZeroLiteral = preg_match('/^(0|0\.0|0\.0f)$/', $expression) === 1;
                    $isPassthroughRead = str_contains($expression, 'reserved_qty');
                    $isCastDeclaration = in_array($expression, ["'integer'", "'float'", "'decimal:2'"], true);
                    if (! $isZeroLiteral && ! $isPassthroughRead && ! $isCastDeclaration) {
                        $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).': '.$expression;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Có code ghi reserved_qty khác 0, vi phạm DEC-INV-RESERVED-001: \n".implode("\n", $offenders)
        );
    }
}
