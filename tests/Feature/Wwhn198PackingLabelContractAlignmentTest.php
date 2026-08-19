<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-198 / DEV198-P1-06 — gate chống trôi giữa OpenAPI WWHN-195 và runtime thật.
 *
 * Finding gốc: OpenAPI mô tả hành vi nhãn của CurrentBaselineStockFlowController
 * ("mỗi item match xong thì tự tạo label_snapshot và dispatch in ngay",
 * label_status PENDING_PRINT), trong khi route `POST /outbound-documents/{id}/scan`
 * đã bị Flow06AlignmentServiceProvider override sang AlignedOutboundFlowController
 * với hành vi controlling khác hẳn (DR-HN-20260817, SRS §5.2/§6/§15): 01 nhãn cấp
 * lô, chỉ sinh khi full match, sinh ở DRAFT, KHÔNG auto-dispatch in.
 *
 * Test này lái HTTP thật rồi đối chiếu ngược lại chính file OpenAPI, nên hai bên
 * không thể lệch nhau lần nữa mà CI vẫn xanh.
 */
class Wwhn198PackingLabelContractAlignmentTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** @return array<string,mixed> */
    private function contract(): array
    {
        $path = base_path('docs/api-contract/WWHN-195-outbound-openapi.json');
        $this->assertFileExists($path, 'Hợp đồng outbound WWHN-195 phải tồn tại để đối chiếu.');

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Dựng phiếu xuất DRAFT 1 dòng cần 2 unit PRODUCT serial-tracked + 2 item IN_STOCK.
     *
     * @return array<string,mixed>
     */
    private function documentNeedingTwoUnits(string $skuCode): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 2);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 2, 'TOA-'.Str::upper(Str::random(6)));
        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);

        return [
            'headers' => $headers,
            'document_id' => $created['document_id'],
            'line_id' => $created['line_ids'][0],
            'item_a' => $this->seedItemInStock($sku->sku_id),
            'item_b' => $this->seedItemInStock($sku->sku_id),
        ];
    }

    private function scan(array $ctx, string $itemId, int $version): TestResponse
    {
        return $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/scan", [
            'line_id' => $ctx['line_id'],
            'quantity' => 1,
            'item_id' => $itemId,
        ], $ctx['headers'] + ['If-Match' => (string) $version]);
    }

    public function test_openapi_declares_the_batch_level_draft_label_behaviour(): void
    {
        $contract = $this->contract();

        $declared = $contract['x-packing-label-contract'] ?? null;
        $this->assertIsArray($declared, 'OpenAPI phải khai x-packing-label-contract để chỉ có MỘT controlling behavior.');
        $this->assertSame('ONE_PER_OUTBOUND_DOCUMENT', $declared['cardinality']);
        $this->assertSame('FULL_MATCH', $declared['created_at']);
        $this->assertSame('DRAFT', $declared['created_status']);
        $this->assertFalse($declared['auto_dispatch_print'], 'Runtime không tự in tại bước scan; contract không được nói ngược lại.');
        $this->assertSame(['DRAFT', 'PREVIEWED', 'READY_TO_PRINT', 'PRINTED', 'PRINT_FAILED'], $declared['lifecycle']);
        $this->assertSame('READY_TO_PRINT', $declared['frozen_from']);

        // PENDING_PRINT là trạng thái legacy: không được xuất hiện trong enum của
        // response scan nữa, nếu không FE lại chờ một nhãn tự in không bao giờ tới.
        $enum = $contract['components']['schemas']['OutboundScanResponse']['properties']['label_status']['enum'];
        $this->assertNotContains('PENDING_PRINT', $enum);
        $this->assertContains('NOT_CREATED', $enum);
        $this->assertContains('DRAFT', $enum);
    }

    /**
     * DEV198-P1-06 (nguyên nhân gốc, không chỉ triệu chứng): hợp đồng từng khai
     * những mã lỗi KHÔNG tồn tại trong code — `OUTBOUND_MATCH_NOT_ACTIVE`,
     * `PACKING_LABEL_FROZEN`, `IDEMPOTENCY_KEY_REUSED`. FE bắt lỗi theo tên mã sẽ
     * không bao giờ khớp; QA viết assertion theo hợp đồng thì đỏ oan hoặc xanh giả.
     * Test này quét mọi mã lỗi được khai trong hợp đồng outbound và bắt buộc từng
     * mã phải tồn tại trong source PHP, nên không thể phát sinh mã ma lần nữa.
     */
    public function test_every_error_code_declared_in_the_contract_exists_in_runtime_source(): void
    {
        $contract = $this->contract();

        $declared = [];
        foreach ($contract['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                foreach (($operation['responses'] ?? []) as $status => $response) {
                    preg_match_all('/\b[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+\b/', (string) ($response['description'] ?? ''), $found);
                    foreach ($found[0] as $code) {
                        $declared[$code][] = strtoupper((string) $method)." {$path} {$status}";
                    }
                }
            }
        }
        $this->assertNotEmpty($declared, 'Hợp đồng outbound phải khai ít nhất một mã lỗi.');

        // Nhãn trạng thái/mô tả, không phải mã lỗi do ApiError::abort ném ra.
        $notErrorCodes = [
            'HTTP', 'RFID', 'ETAG', 'OPENAPI', 'DRAFT', 'SCANNING', 'POSTED', 'CANCELLED',
            'PENDING_RETURN', 'READY_TO_PRINT', 'PRINT_FAILED', 'NOT_CREATED', 'IF_MATCH',
            'STOCK_EFFECT', 'DEC_RFID_FALLBACK_001',
        ];

        $sources = '';
        foreach ([base_path('app'), base_path('Modules')] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $sources .= (string) file_get_contents($file->getPathname());
                }
            }
        }

        $phantoms = [];
        foreach ($declared as $code => $locations) {
            if (in_array($code, $notErrorCodes, true)) {
                continue;
            }
            if (! str_contains($sources, "'".$code."'") && ! str_contains($sources, '"'.$code.'"')) {
                $phantoms[] = $code.' (khai tại: '.implode(', ', array_unique($locations)).')';
            }
        }

        $this->assertSame(
            [],
            $phantoms,
            "Hợp đồng outbound khai mã lỗi không tồn tại trong source:\n".implode("\n", $phantoms)
        );
    }

    public function test_partial_scan_creates_no_label_and_full_match_creates_exactly_one_draft_label(): void
    {
        $contract = $this->contract();
        $enum = $contract['components']['schemas']['OutboundScanResponse']['properties']['label_status']['enum'];

        $ctx = $this->documentNeedingTwoUnits('PL-CONTRACT-001');

        // Scan 1/2 — chưa full match nên chưa có nhãn.
        $first = $this->scan($ctx, $ctx['item_a'], 1)->assertOk();
        $this->assertNull($first->json('data.label_snapshot_id'), 'Chưa full match thì không được có nhãn.');
        $this->assertSame('NOT_CREATED', $first->json('data.label_status'));
        $this->assertFalse($first->json('data.packing_label_ready'));
        $this->assertContains($first->json('data.label_status'), $enum, 'label_status runtime phải nằm trong enum OpenAPI.');
        $this->assertSame(0, DB::table('label_snapshots')->where('outbound_document_id', $ctx['document_id'])->count());

        // Scan 2/2 — full match: đúng 01 nhãn, ở DRAFT, và KHÔNG có lệnh in nào.
        $second = $this->scan($ctx, $ctx['item_b'], 2)->assertOk();
        $this->assertSame('DRAFT', $second->json('data.label_status'));
        $this->assertTrue($second->json('data.packing_label_ready'));
        $this->assertNotNull($second->json('data.label_snapshot_id'));
        $this->assertContains($second->json('data.label_status'), $enum);
        $this->assertFalse($second->json('data.rfid_required'), 'RFID không gate scan (DEC-RFID-FALLBACK-001).');

        $this->assertSame(
            1,
            DB::table('label_snapshots')->where('outbound_document_id', $ctx['document_id'])->count(),
            'Nhãn là cấp lô: đúng 01 cho mỗi phiếu, không phải mỗi item.'
        );
        $this->assertSame(
            0,
            DB::table('label_print_events')->count(),
            'Bước scan không được tự dispatch lệnh in — đó là hành vi cũ đã bị thay thế.'
        );
    }

    public function test_post_issue_is_blocked_while_the_label_is_still_draft_with_the_documented_error_code(): void
    {
        $contract = $this->contract();
        $documented = $contract['paths']['/api/v1/outbound-documents/{id}/post-issue']['post']['responses']['422']['description'];

        $ctx = $this->documentNeedingTwoUnits('PL-CONTRACT-002');
        $this->scan($ctx, $ctx['item_a'], 1)->assertOk();
        $this->scan($ctx, $ctx['item_b'], 2)->assertOk();

        $response = $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/post-issue", [], $ctx['headers'] + [
            'If-Match' => '3',
            'Idempotency-Key' => 'wwhn198-postissue-'.Str::random(12),
        ]);

        $response->assertStatus(422);
        $code = (string) $response->json('error.code');
        $this->assertContains($code, ['LABEL_NOT_PRINTED', 'PACKING_LABEL_REQUIRED', 'PACKING_LABEL_NOT_APPLIED']);
        $this->assertStringContainsString(
            $code,
            $documented,
            "Mã {$code} do runtime trả về phải được khai trong OpenAPI 422 của post-issue."
        );

        // Không được trừ tồn khi bị chặn.
        $this->assertSame(0, DB::table('stock_movements')->where('stock_document_id', $ctx['document_id'])->count());
    }

    public function test_unmatch_discards_the_unfrozen_draft_label_as_documented(): void
    {
        $contract = $this->contract();
        $this->assertStringContainsString(
            'PACKING_LABEL_ALREADY_CREATED',
            (string) $contract['paths']['/api/v1/outbound-documents/{id}/unmatch']['post']['responses']['409']['description'],
            'OpenAPI phải khai nhánh nhãn đã đóng băng chặn unmatch.'
        );

        $ctx = $this->documentNeedingTwoUnits('PL-CONTRACT-003');
        $this->scan($ctx, $ctx['item_a'], 1)->assertOk();
        $this->scan($ctx, $ctx['item_b'], 2)->assertOk();
        $this->assertSame(1, DB::table('label_snapshots')->where('outbound_document_id', $ctx['document_id'])->count());

        $matchId = (string) DB::table('outbound_item_matches')->where('item_id', $ctx['item_b'])->value('id');
        $this->postJson("/api/v1/outbound-documents/{$ctx['document_id']}/unmatch", [
            'outbound_item_match_id' => $matchId,
        ], $ctx['headers'] + ['If-Match' => '3'])->assertOk();

        $this->assertSame(
            0,
            DB::table('label_snapshots')->where('outbound_document_id', $ctx['document_id'])->count(),
            'Nhãn DRAFT chưa đóng băng phải bị huỷ khi sửa match, để lần full match sau sinh lại đúng nội dung.'
        );
        $this->assertDatabaseHas('audit_log', ['action' => 'PACKING_LABEL_DISCARDED']);
    }
}
