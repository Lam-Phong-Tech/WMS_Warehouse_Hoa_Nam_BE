<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * BA 2026-08-25 adapter over the canonical diagnostic stock service.
 * Unknown SKU embedded in a valid PRODUCT QR is materialized lazily before the
 * existing scan-driven inbound implementation resolves the identity/line.
 */
class BaM1StockService extends DiagnosticCurrentBaselineStockService
{
    public function scanStockDocument(
        string $documentId,
        string $documentType,
        array $payload,
        ?string $actorId
    ): array {
        if ($documentType === 'receipt' && ! empty($payload['code_value'])) {
            $parsed = app(ProductQrPayloadParser::class)->parse((string) $payload['code_value']);
            if ($parsed->isComposite() && $parsed->skuCode !== null) {
                $skuCode = strtoupper(trim((string) $parsed->skuCode));
                $existing = DB::table('sku')->where('sku_code', $skuCode)->first();

                if ($existing === null) {
                    try {
                        app(SkuAggregateService::class)->create([
                            'sku_code' => $skuCode,
                            'sku_name' => $skuCode,
                            'sku_type' => 'PRODUCT',
                            'status' => 'ACTIVE',
                        ], $actorId, request()?->attributes->get('request_id'));
                    } catch (Throwable $exception) {
                        // Concurrent first scans of the same unseen SKU can race on
                        // the unique sku_code constraint. If the other transaction
                        // won, continue with the canonical resolver; otherwise keep
                        // the original failure visible.
                        if (! DB::table('sku')->where('sku_code', $skuCode)->exists()) {
                            throw $exception;
                        }
                    }
                }
            }
        }

        return parent::scanStockDocument($documentId, $documentType, $payload, $actorId);
    }
}
