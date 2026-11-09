<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Warehouse\Http\Controllers\AlignedOutboundDocumentController;
use Modules\Warehouse\Http\Controllers\AlignedOutboundFlowController;
use Modules\Warehouse\Http\Controllers\BaM1OutboundFlowController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineDocumentController;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-195 — register R2 (DEV195-P1-01R2 và DEV195-P1-05R2).
 *
 * Hai defect gốc mà bộ test này khoá lại:
 *
 * 1. DEV195-P1-05R2 "ghost POST/PATCH, max 200 vs runtime 150, label state cũ":
 *    routes/api.php từng trỏ CurrentBaselineStockFlowController rồi bị
 *    Flow06AlignmentServiceProvider override sang Aligned* lúc boot. Ai dựng
 *    contract từ bảng route tĩnh đều mô tả nhầm handler đã bị supersede. Test
 *    khẳng định bảng route == handler chạy thật, và file contract
 *    docs/api-contract/WWHN-195-outbound-openapi.yaml khớp 1-1 với route runtime.
 *
 * 2. DEV195-P1-01R2 "mode inferred": ERD bảng 25 có
 *    scan_events.scan_source CHECK IN (CAMERA, MANUAL, HARDWARE) nhưng API không
 *    nhận field, nên giá trị do trigger DB đoán theo physical_code_id — cách đoán
 *    đó không bao giờ sinh ra 'CAMERA'. Test khẳng định client khai được mode
 *    tường minh, giá trị xuống tới evidence, và mode không bị khoá theo phiếu.
 */
class Wwhn195OutboundContractAlignmentTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const CONTRACT_PATH = 'docs/api-contract/WWHN-195-outbound-openapi.yaml';

    /** Bề mặt outbound canonical: path => [method => action]. */
    private const CANONICAL_SURFACE = [
        'api/v1/outbound-documents' => [
            'GET' => AlignedOutboundDocumentController::class.'@index',
        ],
        'api/v1/outbound-documents/{id}' => [
            'PATCH' => CurrentBaselineDocumentController::class.'@updateOutbound',
        ],
        'api/v1/outbound-documents/{id}/scan' => [
            'POST' => AlignedOutboundFlowController::class.'@outboundScan',
        ],
        'api/v1/outbound-documents/{id}/scans' => [
            'POST' => AlignedOutboundFlowController::class.'@outboundScan',
        ],
        'api/v1/outbound-documents/{id}/unmatch' => [
            'POST' => AlignedOutboundFlowController::class.'@outboundUnmatch',
        ],
        // GĐ1 2026-08-25: Post Issue chạy qua BaM1OutboundFlowController (kế thừa
        // Aligned*, bypassPackingLabelGate=true) — stock issue độc lập với nhãn.
        'api/v1/outbound-documents/{id}/post-issue' => [
            'POST' => BaM1OutboundFlowController::class.'@postIssue',
        ],
    ];

    public function test_route_table_matches_the_handler_that_actually_runs(): void
    {
        foreach (self::CANONICAL_SURFACE as $uri => $methods) {
            foreach ($methods as $method => $action) {
                $matching = collect(Route::getRoutes()->getRoutes())
                    ->filter(fn ($route) => in_array($method, $route->methods(), true) && $route->uri() === $uri)
                    ->values();

                $this->assertCount(
                    1,
                    $matching,
                    "{$method} {$uri} phải được đăng ký đúng một lần. Đăng ký hai nơi (routes/api.php + ".
                    'ServiceProvider) chính là cái bẫy đã làm contract WWHN-195 mô tả sai handler.'
                );
                $this->assertSame($action, $matching[0]->getActionName(), "Sai handler cho {$method} {$uri}");
            }
        }
    }

    public function test_direct_create_is_absent_and_draft_update_is_registered(): void
    {
        // SRS v1.12 §8.1: "Confirm creates exactly one outbound DRAFT; no direct
        // POST /outbound-documents create operation." Phiếu DRAFT được sửa header
        // qua PATCH có kiểm soát (AMD-API-052); không có PUT thay thế.
        foreach ([['POST', 'api/v1/outbound-documents'], ['PUT', 'api/v1/outbound-documents/{id}']] as [$method, $uri]) {
            $found = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($route) => in_array($method, $route->methods(), true) && $route->uri() === $uri);
            $this->assertNull($found, "Ghost operation {$method} {$uri} không được tồn tại (§8.1).");
        }

        $patch = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => in_array('PATCH', $route->methods(), true) && $route->uri() === 'api/v1/outbound-documents/{id}');
        $this->assertNotNull($patch, 'PATCH /outbound-documents/{id} phải tồn tại cho phiếu DRAFT.');
        $this->assertSame(CurrentBaselineDocumentController::class.'@updateOutbound', $patch->getActionName());

        $source = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/OutboundDocumentController.php'));
        $this->assertStringNotContainsString("operationId: 'stock_out_create'", $source);
        $updateSource = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php'));
        $this->assertStringContainsString("operationId: 'stock_out_update'", $updateSource);
    }

    public function test_published_contract_declares_exactly_the_runtime_outbound_surface(): void
    {
        $contract = Yaml::parseFile(base_path(self::CONTRACT_PATH));
        $declared = [];
        foreach ($contract['paths'] as $path => $operations) {
            foreach ($operations as $method => $_operation) {
                $declared[] = strtoupper($method).' '.ltrim($path, '/');
            }
        }
        sort($declared);

        $runtime = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/outbound-documents')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $runtime[] = $method.' '.$route->uri();
            }
        }
        $runtime = array_values(array_unique($runtime));
        sort($runtime);

        $this->assertSame(
            $runtime,
            $declared,
            'docs/api-contract/WWHN-195-outbound-openapi.yaml phải được sinh từ route canonical: '.
            'không thừa ghost operation, không thiếu operation đang chạy.'
        );
    }

    public function test_code_value_max_length_is_identical_in_contract_and_runtime(): void
    {
        // Change Pack QR/SKU 20/08/2026: trần nâng 150 -> 512 cho RAW composite
        // payload — contract, literal runtime và boundary test đổi đồng bộ.
        $contract = Yaml::parseFile(base_path(self::CONTRACT_PATH));
        $declaredMax = $contract['components']['schemas']['OutboundScanRequest']['properties']['code_value']['maxLength'];
        $this->assertSame(512, $declaredMax);

        $controller = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php'));
        $this->assertStringContainsString("'code_value' => ['nullable', 'string', 'max:512']", $controller);

        // Và runtime thật sự từ chối 513 ký tự.
        $headers = $this->superAdminHeaders();
        $fixture = $this->scanReadyDocument($headers, 'PROD-W195-LEN');

        $this->postJson("/api/v1/outbound-documents/{$fixture['document_id']}/scan", [
            'line_id' => $fixture['line_id'],
            'quantity' => 1,
            'code_value' => str_repeat('A', 513),
        ], $headers + ['If-Match' => '1'])->assertStatus(422);
    }

    public function test_packing_label_uses_document_level_draft_lifecycle_not_legacy_per_item_print(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->scanReadyDocument($headers, 'PROD-W195-LABEL');

        $scan = $this->postJson("/api/v1/outbound-documents/{$fixture['document_id']}/scan", [
            'line_id' => $fixture['line_id'],
            'quantity' => 1,
            'item_id' => $fixture['item_id'],
        ], $headers + ['If-Match' => '1']);

        // Full match (line có đúng 1 unit) → tạo/đảm bảo 01 Packing Label DRAFT.
        $scan->assertOk()
            ->assertJsonPath('data.packing_label_ready', true)
            ->assertJsonPath('data.label_status', 'DRAFT');

        $labels = DB::table('label_snapshots')->where('outbound_document_id', $fixture['document_id'])->get();
        $this->assertCount(1, $labels, 'SRS §5.2: đúng 01 Packing Label cho mỗi phiếu xuất.');
        $this->assertSame('DRAFT', (string) $labels[0]->status, 'Nhãn khởi tạo ở DRAFT, không phải PENDING_PRINT.');
        $this->assertNull($labels[0]->outbound_item_match_id, 'Annex C: legacy per-Item-label operations = 0.');

        // Không có lệnh in nào được bắn trong lúc scan.
        $this->assertSame(0, DB::table('label_print_events')->count());

        // GĐ1 2026-08-25: nhãn KHÔNG còn gate Post Issue — phiếu full-scan được
        // Post ngay cả khi nhãn còn DRAFT; response khai bypass tường minh.
        $this->postJson("/api/v1/outbound-documents/{$fixture['document_id']}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk()
            ->assertJsonPath('data.packing_label_bypassed', true)
            ->assertJsonPath('data.rfid_required', false);

        // Nguồn đã sạch model nhãn cũ (chỉ còn nhắc trong comment giải thích lý do gỡ).
        $legacy = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php'));
        $this->assertStringNotContainsString('private function createAndDispatchLabel', $legacy);
        $this->assertStringNotContainsString('$this->createAndDispatchLabel(', $legacy);
        $this->assertStringNotContainsString("'status' => 'PENDING_PRINT'", $legacy);
        $this->assertStringNotContainsString("action: 'LABEL_DIRECT_PRINT'", $legacy);
    }

    public function test_scan_source_is_explicit_and_reaches_audit_evidence(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->scanReadyDocument($headers, 'PROD-W195-MODE-A');

        $response = $this->postJson("/api/v1/outbound-documents/{$fixture['document_id']}/scan", [
            'line_id' => $fixture['line_id'],
            'quantity' => 1,
            'item_id' => $fixture['item_id'],
            'scan_source' => 'CAMERA',
        ], $headers + ['If-Match' => '1']);

        $response->assertOk()
            ->assertJsonPath('data.scan_source', 'CAMERA')
            ->assertJsonPath('data.scan_source_origin', 'EXPLICIT');

        $this->assertSame(
            ['CAMERA'],
            $this->recordedScanSources($fixture['document_id']),
            'Mode client khai phải xuống tới scan evidence, không bị trigger DB đoán đè.'
        );
    }

    public function test_scan_source_falls_back_to_inference_and_is_labelled_as_such(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->scanReadyDocument($headers, 'PROD-W195-MODE-B');

        $this->postJson("/api/v1/outbound-documents/{$fixture['document_id']}/scan", [
            'line_id' => $fixture['line_id'],
            'quantity' => 1,
            'item_id' => $fixture['item_id'],
        ], $headers + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.scan_source', 'MANUAL')
            ->assertJsonPath('data.scan_source_origin', 'INFERRED');
    }

    public function test_invalid_scan_source_is_rejected_against_the_erd_check_values(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->scanReadyDocument($headers, 'PROD-W195-MODE-C');

        $this->postJson("/api/v1/outbound-documents/{$fixture['document_id']}/scan", [
            'line_id' => $fixture['line_id'],
            'quantity' => 1,
            'item_id' => $fixture['item_id'],
            'scan_source' => 'RFID_GUN',
        ], $headers + ['If-Match' => '1'])->assertStatus(422);
    }

    public function test_mode_is_per_scan_event_and_not_locked_to_the_document(): void
    {
        // AC-SRS-005 + NFR-SRS-021: camera bị từ chối/không hỗ trợ phải rơi về nhập
        // tay ngay giữa luồng, nên không được khoá mode theo phiếu.
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-W195-SWITCH');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 2);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 2, 'TOA-W195-SWITCH');
        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $documentId = $created['document_id'];
        $lineId = $created['line_ids'][0];

        $cameraItem = $this->seedItemInStock($sku->sku_id);
        $this->seedRfidCode($cameraItem);
        $codeValue = 'QR-'.Str::upper(Str::random(8));
        $this->seedScanCode($cameraItem, $codeValue);

        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'code_value' => $codeValue,
            'scan_source' => 'CAMERA',
        ], $headers + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.scan_source', 'CAMERA');

        $manualItem = $this->seedItemInStock($sku->sku_id);
        $this->seedRfidCode($manualItem);

        // Cùng phiếu, cùng permission outbound.scan, đổi sang nhập tay: phải OK.
        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $manualItem,
            'scan_source' => 'MANUAL',
        ], $headers + ['If-Match' => '2'])
            ->assertOk()
            ->assertJsonPath('data.scan_source', 'MANUAL')
            ->assertJsonPath('data.scan_source_origin', 'EXPLICIT');

        // Hai scan event của cùng phiếu giữ hai mode khác nhau — bằng chứng mode
        // là thuộc tính của từng lần quét chứ không bị khoá theo document.
        $this->assertSame(['CAMERA', 'MANUAL'], $this->recordedScanSources($documentId));
    }

    public function test_annex_c_alias_scans_behaves_identically_to_scan(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->scanReadyDocument($headers, 'PROD-W195-ALIAS');

        $this->postJson("/api/v1/outbound-documents/{$fixture['document_id']}/scans", [
            'line_id' => $fixture['line_id'],
            'quantity' => 1,
            'item_id' => $fixture['item_id'],
            'scan_source' => 'HARDWARE',
        ], $headers + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.scan_source', 'HARDWARE')
            ->assertJsonPath('data.label_status', 'DRAFT');
    }

    /** @return array{document_id: string, line_id: string, item_id: string, sku_id: string} */
    private function scanReadyDocument(array $headers, string $skuCode): array
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 1);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, 'TOA-'.Str::upper(Str::random(8)));
        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);

        $itemId = $this->seedItemInStock($sku->sku_id);
        $this->seedRfidCode($itemId);

        return [
            'document_id' => $created['document_id'],
            'line_id' => $created['line_ids'][0],
            'item_id' => $itemId,
            'sku_id' => $sku->sku_id,
        ];
    }

    /**
     * Tập scan_source đã ghi cho document (sắp xếp, loại trùng).
     *
     * Không so theo "event mới nhất" vì nhiều scan trong cùng một test dùng chung
     * timestamp giây nên thứ tự không xác định — so theo tập giá trị mới là bằng
     * chứng đúng cho câu hỏi "mode có bị khoá theo phiếu không".
     *
     * Hai driver đảo chiều tên bảng (migration 000791 chỉ chạy trên pgsql): trên
     * PostgreSQL `scan_events` số nhiều là bảng vật lý, trên sqlite là `scan_event`
     * số ít. Tra theo bảng nào đang thật sự mang cột scan_source.
     *
     * @return array<int,string>
     */
    private function recordedScanSources(string $documentId): array
    {
        $batchIds = DB::table('scan_batch')->where('source_id', $documentId)->pluck('scan_batch_id');
        $this->assertNotEmpty($batchIds, 'Scan phải để lại scan_batch (NFR-SRS-004).');

        foreach (['scan_events', 'scan_event'] as $table) {
            if (! Schema::hasTable($table) && ! Schema::hasView($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'scan_source')) {
                continue;
            }

            $values = DB::table($table)
                ->whereIn('scan_batch_id', $batchIds)
                ->whereNotNull('scan_source')
                ->pluck('scan_source')
                ->map(fn ($value): string => (string) $value)
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($values !== []) {
                return $values;
            }
        }

        $this->fail('Không tìm thấy scan_source nào được ghi cho phiếu '.$documentId.'.');
    }
}
