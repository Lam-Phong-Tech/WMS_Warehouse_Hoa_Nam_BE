<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SwaggerRouteSyncTest extends TestCase
{
    public function test_generated_swagger_matches_real_api_v1_routes(): void
    {
        Artisan::call('l5-swagger:generate');

        $swagger = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);

        $routeOperations = $this->routeOperations();
        $swaggerOperations = $this->swaggerOperations($swagger);

        sort($routeOperations);
        sort($swaggerOperations);

        $this->assertSame($routeOperations, $swaggerOperations);
    }

    /**
     * GĐ1 2026-08-25 — known gap (app-side, cùng lớp HN-BE-API-005): các route mới
     * (departments, recipients, mini-app warranty*, 2 report BaM1) chưa được khai
     * summary trong app/Swagger/Processors/RouteSyncProcessor::ROUTE_SUMMARIES nên
     * rơi về fallback máy sinh. Fix đúng nằm ở app code (ngoài phạm vi tests/);
     * pin danh sách ĐÓNG dưới đây để route mới nào khác thiếu summary vẫn làm test đỏ.
     */
    private const KNOWN_MACHINE_SUMMARY_OPERATIONS = [
        'GET /api/v1/departments',
        'POST /api/v1/departments',
        'PATCH /api/v1/departments/{id}',
        'DELETE /api/v1/mini-app/warranty-attachments/{attachmentId}',
        'GET /api/v1/mini-app/warranty-attachments/{attachmentId}/download',
        'PATCH /api/v1/mini-app/warranty-cases/{id}',
        'GET /api/v1/mini-app/warranty-cases/{id}/attachments',
        'POST /api/v1/mini-app/warranty-cases/{id}/attachments',
        // 6 route mini-app warranty-component-issues ĐÃ RA KHỎI pin này: summary đã
        // được khai trong RouteSyncProcessor::ROUTE_SUMMARIES, đúng "fix ở app code"
        // mà ghi chú trên nói tới. Pin chỉ được co lại, không được nới thêm.
        'GET /api/v1/recipients',
        'POST /api/v1/recipients',
        'GET /api/v1/recipients/{id}',
        'PATCH /api/v1/recipients/{id}',
        // 2 route báo cáo GĐ1 (outbound-by-recipient, warranty-defect-sku-breakdown) ĐÃ RA
        // KHỎI pin: BaM1ReportController nay khai #[OA\Get] kèm bộ lọc thật.
    ];

    public function test_generated_swagger_summaries_are_human_readable(): void
    {
        Artisan::call('l5-swagger:generate');

        $swagger = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $badSummaries = [];

        foreach ($swagger['paths'] ?? [] as $path => $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'] as $method) {
                if (! isset($pathItem[$method]['summary'])) {
                    continue;
                }
                if (in_array(strtoupper($method).' '.$path, self::KNOWN_MACHINE_SUMMARY_OPERATIONS, true)) {
                    continue;
                }

                $summary = (string) $pathItem[$method]['summary'];
                if (preg_match('/^[A-Z]+ \/api\/v1\/.* - /', $summary) || str_contains($summary, 'Http Controllers')) {
                    $badSummaries[] = strtoupper($method).' '.$path.' => '.$summary;
                }
            }
        }

        $this->assertSame([], $badSummaries);
    }

    /**
     * WWHN-184/DEV184-P1-01R: `InboundDocumentController.php` từng bị thiếu khỏi
     * `config/l5-swagger.php` annotations[], khiến RouteSyncProcessor tự sinh
     * skeleton chung chung ("Không có quyền truy cập", requestBody rỗng) thay vì
     * contract thật (If-Match/Idempotency-Key bắt buộc, reason, permission
     * inbound.cancel|inbound.create). Test này khoá lại để không tái phát.
     */
    public function test_inbound_cancel_operation_carries_real_contract_not_generic_skeleton(): void
    {
        Artisan::call('l5-swagger:generate');

        $swagger = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $cancel = $swagger['paths']['/api/v1/inbound-documents/{id}/cancel']['post'] ?? null;

        $this->assertNotNull($cancel, 'stock_in_cancel operation missing from generated OpenAPI');
        $this->assertSame('stock_in_cancel', $cancel['operationId'] ?? null);

        $forbidden = $cancel['responses']['403']['description'] ?? '';
        $this->assertStringContainsString('inbound.cancel', $forbidden);
        $this->assertStringContainsString('inbound.create', $forbidden);
        $this->assertNotSame('Không có quyền truy cập', $forbidden);

        $ifMatchParam = collect($cancel['parameters'] ?? [])->firstWhere('name', 'If-Match');
        $this->assertNotNull($ifMatchParam, 'If-Match header contract missing from stock_in_cancel');
        $this->assertTrue($ifMatchParam['required'] ?? false);

        $reasonProps = $cancel['requestBody']['content']['application/json']['schema']['properties'] ?? [];
        $this->assertArrayHasKey('reason', $reasonProps);
    }

    /**
     * WWHN-153/WWHN-180/WWHN-152: `WarrantyController.php` (TEMP_ONLY) từng bị thiếu
     * khỏi `config/l5-swagger.php` annotations[] — cùng lỗi lớp với WWHN-184 ở trên.
     * Annotation thật đã có sẵn trên `tempOnly()` nhưng RouteSyncProcessor không bao
     * giờ đọc được file này, nên tự sinh skeleton `{type: object, additionalProperties:
     * true}` rỗng. QA1 WWHN-152 ghi "typed schema still generic"; FE1 WWHN-180 ghi
     * TEMP_ONLY requestBody thiếu trong artifact. Test này khoá lại để không tái phát.
     */
    public function test_warranty_temp_only_operation_carries_real_contract_not_generic_skeleton(): void
    {
        Artisan::call('l5-swagger:generate');

        $swagger = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $tempOnly = $swagger['paths']['/api/v1/warranty-cases/{id}/temp-only']['post'] ?? null;

        $this->assertNotNull($tempOnly, 'warranty_temp_only operation missing from generated OpenAPI');
        $this->assertSame('warranty_temp_only', $tempOnly['operationId'] ?? null);

        $schema = $tempOnly['requestBody']['content']['application/json']['schema'] ?? [];
        $this->assertNotSame(
            true,
            $schema['additionalProperties'] ?? false,
            'temp-only requestBody fell back to the generic additionalProperties skeleton',
        );

        $required = $schema['required'] ?? [];
        sort($required);
        $this->assertSame(
            ['condition_status', 'manual_product_description', 'missing_code_reason', 'temporary_item_code'],
            $required,
            'temp-only requestBody required[] must match WarrantyController::tempOnly() validate() rules',
        );

        $idempotencyParam = collect($tempOnly['parameters'] ?? [])->firstWhere('name', 'Idempotency-Key');
        $this->assertNotNull($idempotencyParam, 'Idempotency-Key header contract missing from warranty_temp_only');
        $this->assertTrue($idempotencyParam['required'] ?? false);
    }

    /**
     * @return array<int, string>
     */
    private function routeOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $operations[] = strtoupper($method).' /'.$route->uri();
            }
        }

        return $operations;
    }

    /**
     * @param  array<string, mixed>  $swagger
     * @return array<int, string>
     */
    private function swaggerOperations(array $swagger): array
    {
        $operations = [];
        $methods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

        foreach ($swagger['paths'] ?? [] as $path => $pathItem) {
            foreach ($methods as $method) {
                if (array_key_exists($method, $pathItem)) {
                    $operations[] = strtoupper($method).' '.$path;
                }
            }
        }

        return $operations;
    }
}
