<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Database\QueryException;
use Modules\Warehouse\Models\Sku;

/**
 * Final BA 2026-08-25 stock runtime.
 *
 * DiagnosticCurrentBaselineStockService already implements scan-driven receipt
 * lines and actual-scan posting. This layer closes the remaining BA gap: a valid
 * composite QR may carry a SKU that has not been mastered yet. Such SKU is
 * materialized through the canonical SkuAggregateService before the normal scan
 * engine runs, so the same duplicate-item, candidate-item and ledger guards remain
 * authoritative.
 */
class BaM1CurrentBaselineStockService extends DiagnosticCurrentBaselineStockService
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
                $this->ensureInboundSkuExists((string) $parsed->skuCode, $actorId);
            }
        }

        return parent::scanStockDocument($documentId, $documentType, $payload, $actorId);
    }

    private function ensureInboundSkuExists(string $skuCode, ?string $actorId): void
    {
        $skuCode = strtoupper(trim($skuCode));
        if ($skuCode === '') {
            ApiError::abort(422, 'INVALID_PAYLOAD', 'SKU trong QR không được để trống.');
        }

        $existing = Sku::query()->where('sku_code', $skuCode)->first();
        if ($existing !== null) {
            if (strtoupper((string) $existing->status) !== 'ACTIVE') {
                ApiError::abort(422, 'SKU_NOT_ACTIVE', 'SKU trong QR đã tồn tại nhưng không ACTIVE.', [
                    'sku_code' => $skuCode,
                ]);
            }

            return;
        }

        try {
            app(SkuAggregateService::class)->create([
                'sku_code' => $skuCode,
                'sku_name' => $skuCode,
                'sku_type' => 'PRODUCT',
                'is_serial_tracked' => true,
                'status' => 'ACTIVE',
            ], $actorId, request()?->attributes->get('request_id'));
        } catch (QueryException $exception) {
            // Concurrent scans may race to create the same new SKU. A unique-key
            // loser is safe if the winner produced an ACTIVE canonical row.
            $raced = Sku::query()->where('sku_code', $skuCode)->first();
            if ($raced === null || strtoupper((string) $raced->status) !== 'ACTIVE') {
                throw $exception;
            }
        }
    }
}
