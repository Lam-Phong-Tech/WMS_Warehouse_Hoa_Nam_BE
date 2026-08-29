<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\PublicApi\PublicAppController;
use Modules\Warehouse\Http\Controllers\PublicApi\PublicCatalogController;
use Modules\Warehouse\Http\Controllers\PublicApi\PublicQuoteRequestController;

/**
 * Mặt storefront công khai của App PV — `/api/v1/public/*`.
 *
 * File riêng, KHÔNG gộp vào routes/api.php, vì nhóm này có ba khác biệt cứng so
 * với phần còn lại của hệ thống: envelope riêng, ẩn danh hoàn toàn, và limiter
 * riêng. Để lẫn vào file 500 dòng kia thì sớm muộn cũng có route bị thêm nhầm
 * vào bên trong nhóm và trở thành public ngoài ý muốn.
 *
 * Envelope KHÔNG được ép ở đây mà ở EnsurePublicApiEnvelope đăng ký toàn cục
 * trong bootstrap/app.php. Đặt theo nhóm route thì 405/404-không-khớp-route
 * (ném ra trong lúc khớp route, trước mọi middleware của nhóm) sẽ thoát ra
 * ngoài với envelope nội bộ. Đăng ký toàn cục cũng bao trọn 429 của throttle
 * bên dưới.
 *
 * Mọi route ở đây là ẩn danh có chủ đích và phải được khai báo song song ở:
 *   - tests/Feature/Wwhn249PvSurfaceGovernanceTest::DECLARED_ANONYMOUS_ROUTES
 *   - scripts/generate-endpoint-permission-matrix.mjs PUBLIC_ALLOWLIST
 *   - docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md
 * Thiếu một chỗ là gate fail-closed sẽ đỏ — đó là chủ ý, không phải phiền toái.
 */
Route::prefix('v1/public')
    ->middleware(['throttle:public-api'])
    ->group(function (): void {
        // Bộ đôi FE gọi song song lúc khởi động app.
        Route::get('config', [PublicAppController::class, 'config']);
        Route::get('home', [PublicAppController::class, 'home']);

        Route::get('health/version', [PublicAppController::class, 'healthVersion']);

        Route::get('categories', [PublicCatalogController::class, 'categories']);
        Route::get('facets', [PublicCatalogController::class, 'facets']);

        // `related` phải khai TRƯỚC `{slug}`; ngược lại "{slug}" sẽ nuốt luôn
        // đoạn "products/abc/related" nếu về sau ai đó nới pattern của slug.
        Route::get('products', [PublicCatalogController::class, 'products']);
        Route::get('products/{slug}/related', [PublicCatalogController::class, 'related']);
        Route::get('products/{slug}', [PublicCatalogController::class, 'product']);
    });

/**
 * Endpoint GHI duy nhất của mặt công khai. Limiter riêng, chặt hơn nhiều nhóm
 * đọc: một form lead-gen ẩn danh mà dùng chung ngưỡng 120 req/phút thì mở cửa
 * cho spam.
 */
Route::prefix('v1/public')
    ->middleware(['throttle:public-quote-request'])
    ->group(function (): void {
        Route::post('quote-requests', [PublicQuoteRequestController::class, 'store']);
    });
