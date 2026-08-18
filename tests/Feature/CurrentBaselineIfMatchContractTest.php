<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * WWHN-309 / DEV309-P2-02 — sau khi gỡ middleware chết `CurrentBaselineIfMatch`,
 * bài test này đổi vai: từ "khoá hình dạng của middleware" sang "khoá nơi
 * optimistic locking THẬT SỰ được enforce", đồng thời chặn việc dựng lại lớp
 * middleware song song.
 *
 * Lý do gỡ (DEC-API-IFMATCH-001): middleware không được gắn vào route nào, nhưng
 * vẫn là nguồn duy nhất của hai error code `RESOURCE_ID_REQUIRED` và
 * `VERSION_CONTRACT_MISSING` trong catalog publish cho FE/QA — tức là contract
 * quảng cáo hai mã mà không đường chạy nào có thể trả về. Ngoài ra nếu ai đó gắn
 * nó vào route, `version` sẽ bị tăng hai lần (một ở middleware, một ở helper của
 * controller).
 */
class CurrentBaselineIfMatchContractTest extends TestCase
{
    public function test_dead_route_level_if_match_middleware_stays_removed(): void
    {
        $this->assertFileDoesNotExist(
            base_path('app/Http/Middleware/CurrentBaselineIfMatch.php'),
            'Middleware CurrentBaselineIfMatch đã bị gỡ theo DEC-API-IFMATCH-001. '
            .'Muốn dùng lại optimistic locking ở tầng route thì phải gắn vào route thật, '
            .'cập nhật HN-BE-API-003 và chứng minh không double-increment version.'
        );

        foreach (['bootstrap/app.php', 'config/app.php'] as $path) {
            $file = base_path($path);
            if (! is_file($file)) {
                continue;
            }
            $this->assertStringNotContainsString(
                'CurrentBaselineIfMatch',
                (string) file_get_contents($file),
                $path.' vẫn tham chiếu middleware đã gỡ.'
            );
        }
    }

    public function test_published_error_catalog_has_no_phantom_codes_from_dead_middleware(): void
    {
        $catalogPath = base_path('docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.json');
        $this->assertFileExists($catalogPath);

        /** @var array{codes: array<int, array{code: string, first_seen?: string}>} $catalog */
        $catalog = json_decode((string) file_get_contents($catalogPath), true);
        $codes = array_column($catalog['codes'] ?? [], 'first_seen', 'code');

        foreach (['RESOURCE_ID_REQUIRED', 'VERSION_CONTRACT_MISSING'] as $phantom) {
            $this->assertArrayNotHasKey(
                $phantom,
                $codes,
                "Catalog vẫn publish {$phantom} — mã này chỉ tồn tại trong middleware đã gỡ, "
                .'FE/QA sẽ viết case cho một mã không đường chạy nào trả về.'
            );
        }

        foreach ($codes as $code => $firstSeen) {
            $this->assertStringNotContainsString(
                'CurrentBaselineIfMatch',
                (string) $firstSeen,
                "Error code {$code} vẫn trỏ tới middleware đã gỡ; chạy lại node scripts/generate-error-code-catalog.mjs."
            );
        }
    }

    public function test_remaining_mutable_storage_has_version_migration(): void
    {
        $migration = (string) file_get_contents(base_path('Modules/Warehouse/database/migrations/2026_08_10_000730_add_version_to_remaining_mutable_aggregates.php'));
        foreach (['warehouses', 'agency', 'defect_catalog', 'product_master', 'import_batches', 'item_instances', 'app_user', 'role'] as $table) {
            $this->assertStringContainsString($table, $migration);
        }
        $this->assertStringContainsString("unsignedInteger('version')->default(1)", $migration);
    }

    public function test_current_handlers_keep_strict_if_match_without_generic_double_increment(): void
    {
        foreach ([
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php',
        ] as $path) {
            $source = (string) file_get_contents(base_path($path));
            $this->assertStringContainsString('If-Match', $source, $path);
            $this->assertStringContainsString('PRECONDITION_REQUIRED', $source, $path);
        }
    }
}
