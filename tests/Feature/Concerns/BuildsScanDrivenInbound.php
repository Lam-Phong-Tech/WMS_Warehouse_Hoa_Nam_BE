<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;

/**
 * GĐ1-REQ-004 (scan-driven inbound): phiếu nhập được tạo HEADER-ONLY, dòng SKU
 * materialize từ quét thực tế — POST /inbound-documents với `lines` bị 422
 * INBOUND_SCAN_DRIVEN. Trait này thay cho các helper draftInbound() cũ (tạo phiếu
 * kèm lines/qty_planned) trong toàn bộ test suite.
 *
 * Dùng kèm PreparesOutboundFixtures (superAdminHeaders/warehouseFixture/
 * productSkuFixture) hoặc bất kỳ nguồn headers/warehouse nào tương đương.
 */
trait BuildsScanDrivenInbound
{
    protected function compositeProductQr(string $skuCode, string $itemUnique, ?string $serial = null): string
    {
        $raw = "HN1|SKU={$skuCode}|ITEM={$itemUnique}";

        return $serial !== null ? $raw."|SER={$serial}" : $raw;
    }

    protected function componentSkuFixture(string $code, bool $serialTracked = false): Sku
    {
        return Sku::create([
            'sku_code' => $code,
            'sku_name' => 'Component inbound test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => $serialTracked,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Tạo phiếu nhập header-only theo contract GĐ1.
     *
     * @return array{document_id: string, version: int}
     */
    protected function createInboundHeader(array $headers, string $warehouseId, array $overrides = []): array
    {
        $create = $this->postJson('/api/v1/inbound-documents', array_merge([
            'dst_warehouse_id' => $warehouseId,
            'source_name' => 'NCC scan-driven test',
        ], $overrides), $headers)->assertCreated();

        return [
            'document_id' => (string) $create->json('data.id'),
            'version' => (int) ($create->json('data.version') ?? 1),
        ];
    }

    /**
     * Quét $qty PRODUCT item (composite QR) vào phiếu — dòng SKU tự materialize.
     *
     * @return array{version: int, line_id: ?string, item_uniques: list<string>}
     */
    protected function scanProductItems(
        array $headers,
        string $documentId,
        string $skuCode,
        int $qty,
        int $version,
        ?string $uniquePrefix = null
    ): array {
        $uniquePrefix ??= 'SN-'.Str::upper(Str::random(6));
        $lineId = null;
        $uniques = [];
        for ($i = 1; $i <= $qty; $i++) {
            $unique = "{$uniquePrefix}-".str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $uniques[] = $unique;
            $response = $this->postJson("/api/v1/inbound-documents/{$documentId}/scans", [
                'quantity' => 1,
                'code_value' => $this->compositeProductQr($skuCode, $unique),
                // SKU mới lần đầu: khai loại PRODUCT (mã item mặc định là sản phẩm
                // trong helper này). SKU đã tồn tại thì backend bỏ qua trường này.
                'new_sku_type' => 'PRODUCT',
            ], $headers + ['If-Match' => (string) $version])->assertOk();
            $version = (int) $response->json('data.version');
            $lineId = (string) $response->json('data.line_id');
        }

        return ['version' => $version, 'line_id' => $lineId, 'item_uniques' => $uniques];
    }

    /**
     * COMPONENT theo Box: materialize dòng qua /component-lines, đăng ký Box rồi
     * ghi nhận số đếm thực tế (quantity-mode, không cần code_value).
     *
     * @return array{version: int, line_id: string, container_id: string}
     */
    protected function receiveComponentQuantity(
        array $headers,
        string $documentId,
        string $componentSkuId,
        int $countedQty,
        int $version,
        ?string $boxCode = null
    ): array {
        $ensure = $this->postJson(
            "/api/v1/inbound-documents/{$documentId}/component-lines",
            ['sku_id' => $componentSkuId],
            $headers
        )->assertOk();
        $lineId = (string) $ensure->json('data.line_id');

        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => $boxCode ?? 'BOX-'.Str::upper(Str::random(8)),
        ], $headers)->assertSuccessful();
        $containerId = (string) ($box->json('data.container_id') ?? $box->json('container_id'));

        $scan = $this->postJson("/api/v1/inbound-documents/{$documentId}/scans", [
            'quantity' => $countedQty,
            'line_id' => $lineId,
            'container_id' => $containerId,
        ], $headers + ['If-Match' => (string) $version])->assertOk();

        return [
            'version' => (int) $scan->json('data.version'),
            'line_id' => $lineId,
            'container_id' => $containerId,
        ];
    }

    /**
     * One-call: header-only + quét PRODUCT theo spec rồi trả về trạng thái sẵn sàng
     * Post Receipt. $lines: danh sách ['sku_code' => ..., 'qty' => ...].
     *
     * @return array{document_id: string, version: int, lines: array<string, array{line_id: ?string, item_uniques: list<string>}>}
     */
    protected function scanDrivenInboundFixture(array $headers, string $warehouseId, array $lines): array
    {
        $document = $this->createInboundHeader($headers, $warehouseId);
        $result = ['document_id' => $document['document_id'], 'version' => $document['version'], 'lines' => []];
        foreach ($lines as $line) {
            $scanned = $this->scanProductItems(
                $headers,
                $document['document_id'],
                (string) $line['sku_code'],
                (int) $line['qty'],
                $result['version'],
                $line['unique_prefix'] ?? null
            );
            $result['version'] = $scanned['version'];
            $result['lines'][(string) $line['sku_code']] = [
                'line_id' => $scanned['line_id'],
                'item_uniques' => $scanned['item_uniques'],
            ];
        }

        return $result;
    }

    /** Post Receipt với Idempotency-Key + If-Match đúng contract. */
    protected function postInboundReceipt(array $headers, string $documentId, int $version, ?string $idempotencyKey = null)
    {
        return $this->postJson(
            "/api/v1/inbound-documents/{$documentId}/post-receipt",
            [],
            $headers + [
                'If-Match' => (string) $version,
                'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid(),
            ]
        );
    }
}
