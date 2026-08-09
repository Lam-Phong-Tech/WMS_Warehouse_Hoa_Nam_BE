<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\Warehouse;

/**
 * Canonical SKU fallback for scan/classify.
 *
 * The legacy resolver still understands products.code for upgraded PostgreSQL
 * environments. SQLite does not report VIEWs through Schema::hasTable reliably,
 * so a legitimate SKU can otherwise be treated as missing. This class is bound
 * globally as ScanClassificationService and only handles that exact final 404;
 * all item/container/warehouse/context rules remain owned by the parent service.
 */
class CanonicalScanClassificationService extends ScanClassificationService
{
    public function classify(
        string $rawCode,
        string $context,
        Warehouse $warehouse,
        bool $hideCrossWarehouseExistence = false
    ): array {
        try {
            return parent::classify($rawCode, $context, $warehouse, $hideCrossWarehouseExistence);
        } catch (HttpResponseException $exception) {
            if (! $this->isScanCodeNotFound($exception)) {
                throw $exception;
            }

            $code = trim($rawCode);
            [$sku, $resolvedSource] = $this->canonicalSku($code);
            if ($sku === null) {
                throw $exception;
            }

            $status = strtoupper((string) ($sku->status ?? 'ACTIVE'));
            if ($status !== 'ACTIVE') {
                ApiError::abort(409, 'SCAN_SKU_INACTIVE', 'SKU đang không hoạt động.', [
                    'status' => (string) ($sku->status ?? ''),
                ]);
            }

            $balance = DB::table('inventory_balances')
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $sku->sku_id)
                ->where('stock_status', 'AVAILABLE')
                ->first();

            return [
                'classification' => 'SKU',
                'item_id' => null,
                'sku_id' => (string) $sku->sku_id,
                'product_id' => (string) $sku->sku_id,
                'sku_code' => (string) $sku->sku_code,
                'stock_status' => 'AVAILABLE',
                'object_status' => (string) ($sku->status ?? 'ACTIVE'),
                'container_id' => null,
                'location_hint' => null,
                'on_hand_qty' => $balance->on_hand_qty ?? null,
                'available_qty' => $balance->available_qty ?? null,
                'resolved_source' => $resolvedSource,
                'contract_version' => self::CONTRACT_VERSION,
                'stock_effect' => self::STOCK_EFFECT_NONE,
                'movement_created' => false,
                'posting_event_created' => false,
                'idempotency_cache_written' => false,
            ];
        }
    }

    /** @return array{0:object|null,1:string} */
    private function canonicalSku(string $code): array
    {
        $sku = DB::table('sku')->where('sku_code', $code)->first();
        if ($sku !== null) {
            return [$sku, 'sku.sku_code'];
        }

        $physical = DB::table('physical_code')->where('code_value', $code)->first();
        if ($physical === null) {
            return [null, 'sku.sku_code'];
        }

        $assignedType = strtoupper((string) ($physical->assigned_object_type ?? ''));
        $assignedId = $physical->assigned_object_id ?? null;
        if (! in_array($assignedType, ['SKU', 'PRODUCT'], true)
            || ! is_string($assignedId)
            || $assignedId === '') {
            return [null, 'physical_code'];
        }

        if (strtoupper((string) ($physical->status ?? 'ACTIVE')) !== 'ACTIVE') {
            ApiError::abort(409, 'SCAN_CODE_INACTIVE', 'Mã vật lý đang không hoạt động.');
        }

        return [
            DB::table('sku')->where('sku_id', $assignedId)->first(),
            'physical_code',
        ];
    }

    private function isScanCodeNotFound(HttpResponseException $exception): bool
    {
        $response = $exception->getResponse();
        if ($response->getStatusCode() !== 404) {
            return false;
        }

        $payload = json_decode((string) $response->getContent(), true);

        return is_array($payload)
            && ($payload['error_code'] ?? $payload['error']['code'] ?? null) === 'SCAN_CODE_NOT_FOUND';
    }
}
