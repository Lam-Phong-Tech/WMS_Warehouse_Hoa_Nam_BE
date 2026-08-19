<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Warehouse\Http\Controllers\AlignedOutboundFlowController;
use Modules\Warehouse\Services\AlignedOutboundStockService;
use Modules\Warehouse\Services\CurrentBaselineStockService;

/**
 * Runtime overrides for the 2026-08-15 current business baseline.
 * They are registered after the normal Warehouse routes so identical method/URI
 * pairs resolve to these canonical handlers without keeping two active semantics.
 */
class Flow06AlignmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CurrentBaselineStockService::class, AlignedOutboundStockService::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            Route::middleware(['api', 'auth:api'])->prefix('api/v1')->group(function (): void {
                // WWHN-195 / DEV195-P1-05R2: bốn override outbound (GET danh sách,
                // /scan, /unmatch, /post-issue) đã được đưa thẳng vào
                // Modules/Warehouse/routes/api.php nên không khai lại ở đây. Giữ
                // override ở hai nơi sẽ tái lập đúng cái bẫy cũ: bảng route tĩnh mô tả
                // một handler còn runtime chạy handler khác, nên contract/Swagger dựng
                // từ route bị sai mà không ai phát hiện.
                //
                // Hai route label-snapshots dưới đây VẪN phải override vì routes/api.php
                // trỏ CurrentBaselineOperationsController (nhãn theo snapshot lẻ), còn
                // baseline hiện hành là 01 Packing Label cho mỗi phiếu xuất (SRS §5.2).
                // Override giữ nguyên permission theo §8.1 Enforcement standard.
                Route::post('label-snapshots', [AlignedOutboundFlowController::class, 'storePackingLabel'])
                    ->middleware('permission:label.print');
                Route::post('label-snapshots/{id}/apply', [AlignedOutboundFlowController::class, 'applyPackingLabel'])
                    ->middleware('permission:label.print');

                // Toàn bộ route Flow 06 (import-match, label-log, warranty-defects)
                // đã trỏ thẳng controller Aligned trong routes/api.php nên không còn
                // override runtime nào cho báo cáo ở đây — chỉ giữ override luồng xuất.
            });
        });
    }
}
