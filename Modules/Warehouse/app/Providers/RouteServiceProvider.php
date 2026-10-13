<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\MiniAppNfcController;
use Modules\Warehouse\Http\Controllers\P07NfcController;
use Modules\Warehouse\Http\Middleware\DocumentTimestampResponse;
use Modules\Warehouse\Http\Middleware\MiniAppDraftHandoff;
use Modules\Warehouse\Http\Middleware\P05OutboundSource;
use Modules\Warehouse\Http\Middleware\P07NfcReadbackGuard;
use Modules\Warehouse\Http\Middleware\P07NfcRecoveryGuard;
use Modules\Warehouse\Http\Middleware\P09WarrantyAttachmentGuard;
use Modules\Warehouse\Http\Middleware\P12DocumentReadMetadata;
use Modules\Warehouse\Http\Middleware\P19ScannerReadGuard;
use Modules\Warehouse\Services\AttachmentUploadReceipts;
use Modules\Warehouse\Services\MiniAppDraftService;
use Modules\Warehouse\Services\P19ComponentCommand;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Warehouse';

    public function boot(): void
    {
        // Applies to existing and route-cached NFC endpoints without duplicate
        // routes or changes to their permission middleware/canonical writer.
        $this->app->bind(MiniAppNfcController::class, P07NfcController::class);
        parent::boot();
    }

    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapPublicApiRoutes();
        $this->mapMiniAppRoutes();
        $this->mapWorkShiftRoutes();
        $this->mapImportRoutes();
        $this->mapGovernanceRoutes();
        $this->mapWebRoutes();
        Route::middleware('api')->prefix('api')->name('api.p03.')->group(module_path($this->name, '/routes/p03_drafts.php'));
        Route::middleware('api')->prefix('api')->name('api.p04.')->group(module_path($this->name, '/routes/p04_inbound.php'));
        Route::middleware('api')->prefix('api')->name('api.p05.')->group(module_path($this->name, '/routes/p05_outbound.php'));
        Route::middleware('api')->prefix('api')->name('api.p06.')->group(module_path($this->name, '/routes/p06_catalog.php'));
        Route::middleware('api')->prefix('api')->name('api.p07.')->group(module_path($this->name, '/routes/p07_nfc.php'));
        Route::middleware('api')->prefix('api')->name('api.p08.')->group(module_path($this->name, '/routes/p08_history.php'));
        Route::middleware('api')->prefix('api')->name('api.p09.')->group(module_path($this->name, '/routes/p09_warranty.php'));
        Route::middleware('api')->prefix('api')->name('api.p12-mixed.')->group(module_path($this->name, '/routes/p12_mixed_documents.php'));
        Route::middleware('api')->prefix('api')->name('api.upload-receipts.')->group(module_path($this->name, '/routes/attachment_upload_receipts.php'));
        // Recovery belongs to P19. No quantity/error/waiting/closed P24 APIs.
        Route::get('api/v1/mini-app/component-issue-requests/{requestId}', [P19ComponentCommand::class, 'check'])
            ->middleware(['api', 'auth:api', 'permission:warranty.component_issue'])->name('api.p19.command.check');
        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true)
                && in_array($route->uri(), DocumentTimestampResponse::READ_ROUTES, true)) {
                $route->middleware(DocumentTimestampResponse::class);
            }
            if (str_starts_with($route->uri(), 'api/v1/mini-app/warranty-component-issues')
                || str_starts_with($route->uri(), 'api/v1/mini-app/component-issue-documents')) {
                if (in_array('POST', $route->methods(), true) && str_ends_with($route->uri(), '/resolve-code')) {
                    $route->middleware(P19ScannerReadGuard::class);
                } elseif (array_intersect(['POST', 'PATCH', 'DELETE'], $route->methods()) !== []) {
                    $route->middleware(P19ComponentCommand::class);
                }
            }
            if (in_array('GET', $route->methods(), true)
                && (isset(P12DocumentReadMetadata::READ_ROUTES[$route->uri()])
                    || $route->uri() === P12DocumentReadMetadata::DOWNLOAD_ROUTE)) {
                $route->middleware(P12DocumentReadMetadata::class);
            }
            if ($route->uri() === 'api/v1/mini-app/warranty-cases/{id}/attachments'
                || str_starts_with($route->uri(), 'api/v1/mini-app/warranty-attachments/')) {
                $route->middleware(P09WarrantyAttachmentGuard::class);
            }
            if (in_array('POST', $route->methods(), true) && isset(AttachmentUploadReceipts::UPLOAD_ROUTES[$route->uri()])) {
                $route->middleware(AttachmentUploadReceipts::class);
            }
            if (isset(MiniAppDraftService::RECORD_ROUTES[$route->uri()]) && in_array('POST', $route->methods(), true)) {
                $route->middleware(MiniAppDraftHandoff::class);
            }
            if ($route->uri() === 'api/v1/mini-app/outbound/record' && in_array('POST', $route->methods(), true)) {
                // Inside the P03 receipt transaction; untouched legacy calls pass through.
                $route->middleware(P05OutboundSource::class);
            }
            if (($route->uri() === 'api/v1/mini-app/nfc/resolve-tag' && in_array('POST', $route->methods(), true))
                || ($route->uri() === 'api/v1/mini-app/items/{itemId}/nfc' && in_array('PATCH', $route->methods(), true))) {
                $route->middleware(P07NfcReadbackGuard::class);
            }
            if (in_array($route->uri(), ['api/v1/mini-app/items/{itemId}/nfc',
                'api/v1/mini-app/items/{itemId}/nfc/prepare', 'api/v1/mini-app/items/{itemId}/nfc/confirmation',
                'api/v1/mini-app/items/{itemId}/nfc/release'], true)) {
                $route->middleware(P07NfcRecoveryGuard::class);
            }
        }
    }

    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }

    protected function mapApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/api.php'));
    }

    /**
     * Storefront công khai của App PV (`/api/v1/public/*`). File route riêng vì
     * nhóm này chạy envelope riêng và ẩn danh hoàn toàn — xem
     * routes/public_api.php.
     */
    protected function mapPublicApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.public.')->group(module_path($this->name, '/routes/public_api.php'));
    }

    /**
     * Dedicated adapter routes for the Warehouse Mini App. They are loaded from a
     * separate file so the established Web WMS API surface remains unchanged.
     */
    protected function mapMiniAppRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.mini-app.')->group(module_path($this->name, '/routes/mini_app.php'));
    }

    protected function mapWorkShiftRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/work_shifts.php'));
    }

    protected function mapImportRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/import.php'));
    }

    protected function mapGovernanceRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/governance.php'));
    }
}
