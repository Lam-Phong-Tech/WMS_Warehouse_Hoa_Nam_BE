<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\P06CatalogController;
use Modules\Warehouse\Http\Middleware\P03JsonEnvelope;

// P06 is additive and read-only; existing product/item/inventory APIs are unchanged.
Route::prefix('v1/mini-app/catalog')->middleware(['auth:api', P03JsonEnvelope::class])->group(function (): void {
    Route::get('context', [P06CatalogController::class, 'context'])->name('context');
    Route::middleware('permission:sku.view')->group(function (): void {
        Route::get('skus', [P06CatalogController::class, 'skus'])->name('skus.index');
        Route::get('skus/{id}', [P06CatalogController::class, 'sku'])->name('skus.show');
        Route::get('skus/{id}/stock', [P06CatalogController::class, 'stock'])->middleware('permission:inventory.view')->name('skus.stock');
        Route::get('skus/{id}/gallery', [P06CatalogController::class, 'gallery'])->name('skus.gallery');
        Route::get('skus/{id}/timeline', [P06CatalogController::class, 'skuTimeline'])->middleware('permission:inventory.trace|warranty.view|warranty.manage')->name('skus.timeline');
        Route::middleware('permission:item_instance.view')->group(function (): void {
            Route::get('items', [P06CatalogController::class, 'items'])->name('items.index');
            Route::get('items/{id}', [P06CatalogController::class, 'item'])->name('items.show');
            Route::get('items/{id}/timeline', [P06CatalogController::class, 'itemTimeline'])->middleware('permission:inventory.trace|warranty.view|warranty.manage')->name('items.timeline');
        });
    });
});
