<?php

namespace Modules\Warehouse\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\Warehouse\Support\SkuImportMultipartBooleanNormalizer;

/**
 * Compatibility bridge for the SKU Excel multipart contract.
 *
 * Browser FormData emits "true"/"false" strings for boolean fields. Normalize
 * only this endpoint and only the two documented boolean inputs before the
 * controller runs Laravel validation. Invalid values remain invalid and still
 * produce the normal 422 response.
 */
final class SkuImportRequestCompatibilityServiceProvider extends ServiceProvider
{
    public function boot(Request $request): void
    {
        if (! $request->isMethod('POST') || ! $request->is('api/v1/skus/import-excel')) {
            return;
        }

        $normalized = SkuImportMultipartBooleanNormalizer::normalize(
            $request->only(['update_existing', 'dry_run'])
        );

        if ($normalized !== []) {
            $request->merge($normalized);
        }
    }
}
