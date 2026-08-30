<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runtime stock service for the current BA baseline.
 *
 * 2026-08-25 BA alignment:
 * - inbound quantity is derived from actual scans, never gated by a pre-entered qty_planned;
 * - unused pre-planned inbound lines are discarded at Post Receipt;
 * - existing canonical duplicate/candidate guards stay authoritative;
 * - outbound keeps the aligned reservation diagnostics and planned-quantity semantics.
 */
class DiagnosticCurrentBaselineStockService extends AlignedOutboundStockService
{
    private const ACTIVE_OUTBOUND_STATUSES = ['DRAFT', 'SCANNING'];

    public function scanStockDocument(
        string $documentId,
        string $documentType,
        array $payload,
        ?string $actorId
    ): array {
        if ($documentType === 'issue') {
            $itemId = $this->resolveExistingItemIdForPayload($payload);
            if ($itemId !== null) {
                $this->assertNoActiveOutboundReservation($documentId, $itemId);
            }
        } elseif ($documentType === 'receipt') {
            // BA 2026-08-25: inbound is scan-driven. qty_planned is retained only as
            // a compatibility/storage column and mirrors the quantity physically
            // scanned. It is not a user-entered expectation or an over-scan gate.
            $payload = $this->prepareScanDrivenInboundLine($documentId, $payload, $actorId);
        }

        return parent::scanStockDocument($documentId, $documentType, $payload, $actorId);
    }

    public function postStockDocument(
        string $documentId,
        string $documentType,
        ?string $actorId,
        string $idempotencyKey,
        string $requestHash,
        array $auditAfterData = []
    ): array {
        if ($documentType !== 'receipt') {
            return parent::postStockDocument(
                $documentId,
                $documentType,
                $actorId,
                $idempotencyKey,
                $requestHash,
                $auditAfterData
            );
        }

        return DB::transaction(function () use ($documentId, $documentType, $actorId, $idempotencyKey, $requestHash, $auditAfterData): array {
            // Historical/legacy drafts may still contain expected lines. They are
            // not authoritative under the scan-driven baseline. Lines never scanned
            // have no stock effect and can be removed before posting; scanned lines
            // are normalized to actual scanned quantity so the canonical posting
            // engine remains the single stock mutation implementation.
            $lines = DB::table('stock_document_lines')
                ->where('stock_document_id', $documentId)
                ->lockForUpdate()
                ->get(['id', 'scanned_qty']);

            foreach ($lines as $line) {
                $actual = (int) $line->scanned_qty;
                if ($actual <= 0) {
                    DB::table('stock_document_lines')->where('id', $line->id)->delete();

                    continue;
                }

                // completion_type/shortage_reason đã bị gỡ khỏi schema (migration
                // 2026_08_10_000620) — ghi vào sẽ nổ SQL trên mọi DB đã migrate.
                DB::table('stock_document_lines')->where('id', $line->id)->update([
                    'qty_planned' => $actual,
                    'qty_actual' => $actual,
                    'updated_at' => now(),
                ]);
            }

            return parent::postStockDocument(
                $documentId,
                $documentType,
                $actorId,
                $idempotencyKey,
                $requestHash,
                $auditAfterData + ['inbound_quantity_source' => 'ACTUAL_SCAN']
            );
        }, 3);
    }

    /**
     * Ensure the canonical scanner always sees a line with capacity exactly equal
     * to the next actual scan. For composite QR the SKU embedded in the code is
     * authoritative and a line is created automatically when this is the first scan
     * of that SKU. No expected SKU/quantity entry is required beforehand.
     */
    private function prepareScanDrivenInboundLine(string $documentId, array $payload, ?string $actorId = null): array
    {
        return DB::transaction(function () use ($documentId, $payload, $actorId): array {
            $document = DB::table('stock_documents')
                ->where('id', $documentId)
                ->where('doc_type', 'receipt')
                ->lockForUpdate()
                ->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nhập.');
            }
            if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được scan.');
            }

            $quantity = (int) ($payload['quantity'] ?? 0);
            if ($quantity <= 0) {
                ApiError::abort(422, 'INVALID_QUANTITY', 'Số lượng scan phải là số nguyên lớn hơn 0.');
            }

            // Mã HỘP linh kiện BOX-<SKU>-<seq>: hệ thống tự nhận diện, tự tạo dòng
            // COMPONENT + đăng ký BOX rồi ghi nhận số lượng đếm tay — nhân viên
            // không phải chọn SKU / thêm dòng / đăng ký hộp thủ công trước.
            if (! empty($payload['code_value'])) {
                $box = app(BoxCodeParser::class)->parse((string) $payload['code_value']);
                if ($box !== null) {
                    return $this->prepareScanDrivenBoxLine($documentId, $payload, $box, $quantity, $actorId);
                }
            }

            $line = null;
            if (! empty($payload['line_id'])) {
                $line = DB::table('stock_document_lines')
                    ->where('id', $payload['line_id'])
                    ->where('stock_document_id', $documentId)
                    ->lockForUpdate()
                    ->first();
                if ($line === null) {
                    ApiError::abort(404, 'DOCUMENT_LINE_NOT_FOUND', 'Không tìm thấy line trong phiếu nhập.');
                }
            } elseif (! empty($payload['code_value'])) {
                $parsed = app(ProductQrPayloadParser::class)->parse((string) $payload['code_value']);
                if (! $parsed->isValid()) {
                    // Keep canonical parser/error semantics in the parent service.
                    return $payload;
                }

                $skuId = null;
                if ($parsed->isComposite()) {
                    $sku = app(ProductIdentityResolver::class)->requireActiveSkuByCode((string) $parsed->skuCode);
                    $skuId = (string) $sku->sku_id;
                } else {
                    $item = app(ProductIdentityResolver::class)->findItemByUnique((string) $parsed->normalizedCodeValue);
                    $skuId = $item?->sku_id !== null ? (string) $item->sku_id : null;
                }

                if ($skuId !== null) {
                    $line = DB::table('stock_document_lines')
                        ->where('stock_document_id', $documentId)
                        ->where('product_id', $skuId)
                        ->orderBy('line_no')
                        ->lockForUpdate()
                        ->first();

                    if ($line === null) {
                        $lineId = (string) Str::uuid();
                        $lineNo = ((int) DB::table('stock_document_lines')
                            ->where('stock_document_id', $documentId)
                            ->max('line_no')) + 1;
                        // Line mới có "capacity exactly equal to the next actual scan"
                        // (docblock trên) nên qty_planned = qty_actual = $quantity;
                        // scanned_qty = 0 vẫn hợp lệ vì CHECK hiện hành
                        // chk_stock_document_line_quantity_shape chỉ đòi
                        // scanned_qty <= qty_planned — migration 2026_08_27_130000 đã nới
                        // "> 0" thành ">= 0" cho dòng receipt theo baseline GĐ1.
                        DB::table('stock_document_lines')->insert([
                            'id' => $lineId,
                            'stock_document_id' => $documentId,
                            'line_no' => $lineNo,
                            'product_id' => $skuId,
                            'qty_planned' => $quantity,
                            'qty_actual' => $quantity,
                            'scanned_qty' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $line = DB::table('stock_document_lines')->where('id', $lineId)->lockForUpdate()->first();
                    }

                    $payload['line_id'] = (string) $line->id;
                }
            }

            if ($line !== null) {
                DB::table('stock_document_lines')->where('id', $line->id)->update([
                    'qty_planned' => (int) $line->scanned_qty + $quantity,
                    'updated_at' => now(),
                ]);
            }

            return $payload;
        }, 3);
    }

    /**
     * Luồng scan-driven cho mã HỘP linh kiện (BOX-<SKU>-<seq>).
     *
     * Nhân viên chỉ quét mã hộp; hệ thống:
     *  1) resolve SKU COMPONENT từ mã (BaM1StockService đã materialize nếu mới),
     *  2) tự tạo dòng nhập COMPONENT cho SKU đó (qty_planned bơm theo số quét),
     *  3) đăng ký/tái sử dụng BOX (primary_sku_id = SKU),
     *  4) chuyển payload sang dạng "đếm theo BOX" (line_id + container_id +
     *     quantity, KHÔNG còn code_value) để canonical scanner ghi nhận số lượng
     *     đếm tay giống hệt luồng component-by-box đã có.
     *
     * @param  array{box_code: string, sku_code: string, box_seq: string}  $box
     */
    private function prepareScanDrivenBoxLine(
        string $documentId,
        array $payload,
        array $box,
        int $quantity,
        ?string $actorId
    ): array {
        $sku = DB::table('sku')->where('sku_code', $box['sku_code'])->lockForUpdate()->first();
        if ($sku === null) {
            // BaM1StockService materialize COMPONENT SKU trước khi tới đây; thiếu
            // nghĩa là tầng auto-create không chạy (vd gọi service trực tiếp).
            ApiError::abort(422, 'BOX_SKU_NOT_FOUND', 'Không resolve được SKU linh kiện từ mã hộp.', [
                'box_code' => $box['box_code'],
                'sku_code' => $box['sku_code'],
            ]);
        }
        if (strtoupper((string) $sku->status) !== 'ACTIVE') {
            ApiError::abort(422, 'SKU_NOT_ACTIVE', 'SKU linh kiện của hộp không ACTIVE.');
        }
        if (strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
            ApiError::abort(422, 'BOX_SKU_NOT_COMPONENT', 'Mã hộp trỏ tới SKU không phải COMPONENT.', [
                'box_code' => $box['box_code'],
                'sku_code' => $box['sku_code'],
                'sku_type' => (string) $sku->sku_type,
            ]);
        }

        $skuId = (string) $sku->sku_id;

        // Ensure dòng nhập COMPONENT (mirror BaM1InboundComponentController::ensureLine).
        $line = DB::table('stock_document_lines')
            ->where('stock_document_id', $documentId)
            ->where('product_id', $skuId)
            ->orderBy('line_no')
            ->lockForUpdate()
            ->first();
        if ($line === null) {
            $lineId = (string) Str::uuid();
            $lineNo = ((int) DB::table('stock_document_lines')
                ->where('stock_document_id', $documentId)
                ->max('line_no')) + 1;
            DB::table('stock_document_lines')->insert([
                'id' => $lineId,
                'stock_document_id' => $documentId,
                'line_no' => $lineNo,
                'product_id' => $skuId,
                'qty_planned' => 0,
                'qty_actual' => 0,
                'scanned_qty' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $line = DB::table('stock_document_lines')->where('id', $lineId)->lockForUpdate()->first();
        }

        // Đăng ký/tái sử dụng BOX (stock-neutral) qua registrar dùng chung.
        $registered = app(InboundBoxRegistrar::class)->ensure(
            $documentId,
            $skuId,
            $box['box_code'],
            $actorId,
            request()?->attributes->get('request_id'),
        );

        // qty_planned = "capacity exactly equal to the next actual scan" để canonical
        // scanner (base) không chặn SCAN_EXCEEDS_REQUIRED; post normalize về số quét thực.
        DB::table('stock_document_lines')->where('id', $line->id)->update([
            'qty_planned' => (int) $line->scanned_qty + $quantity,
            'updated_at' => now(),
        ]);

        $payload['line_id'] = (string) $line->id;
        $payload['container_id'] = (string) $registered['box']->container_id;
        // Bỏ code_value: base resolveItem() sẽ không cố tra mã hộp như ITEM
        // (mã hộp không phải physical_code của item) — ghi nhận thành scan đếm tay.
        unset($payload['code_value']);

        return $payload;
    }

    private function assertNoActiveOutboundReservation(string $documentId, string $itemId): void
    {
        // Only enrich conflicts inside the current source warehouse so the error
        // does not disclose metadata from another warehouse data scope. Parent
        // canonical guards remain the last line of defense for races/conflicts.
        $currentWarehouseId = DB::table('stock_documents')
            ->where('id', $documentId)
            ->where('doc_type', 'issue')
            ->value('src_warehouse_id');

        $conflictQuery = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('m.item_id', $itemId)
            ->where('d.id', '!=', $documentId)
            ->where('d.doc_type', 'issue')
            ->whereIn('d.status', self::ACTIVE_OUTBOUND_STATUSES)
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            });

        if ($currentWarehouseId !== null) {
            $conflictQuery->where('d.src_warehouse_id', $currentWarehouseId);
        }

        $conflict = $conflictQuery
            ->select([
                'm.id as match_id',
                'd.id as document_id',
                'd.doc_no',
                'd.status',
                'd.outbound_request_id',
            ])
            ->first();

        if ($conflict === null) {
            return;
        }

        $documentNo = trim((string) ($conflict->doc_no ?? ''));
        $displayDocument = $documentNo !== ''
            ? $documentNo
            : (string) $conflict->document_id;
        $status = strtoupper((string) $conflict->status);

        ApiError::abort(
            409,
            'ITEM_ALREADY_MATCHED',
            "Item đang được giữ bởi phiếu xuất {$displayDocument} ({$status}). Hãy gỡ match, hủy hoặc hoàn tất phiếu đó trước khi quét item vào phiếu hiện tại.",
            [
                'item_id' => $itemId,
                'conflicting_document_id' => (string) $conflict->document_id,
                'conflicting_document_no' => $documentNo !== '' ? $documentNo : null,
                'conflicting_document_status' => $status,
                'conflicting_outbound_request_id' => $conflict->outbound_request_id !== null
                    ? (string) $conflict->outbound_request_id
                    : null,
                'conflicting_match_id' => (string) $conflict->match_id,
            ]
        );
    }
}
