<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use App\Support\Cache\CacheService;
use App\Support\ConflictCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Events\StockDocumentScanStarted;
use Modules\Warehouse\Support\ProductQrPayload;

class CurrentBaselineStockService
{
    private const ACTIVE_DOCUMENT_STATUSES = ['DRAFT', 'SCANNING'];

    private const AVAILABLE = 'AVAILABLE';

    public function scanStockDocument(
        string $documentId,
        string $documentType,
        array $payload,
        ?string $actorId
    ): array {
        return DB::transaction(function () use ($documentId, $documentType, $payload, $actorId): array {
            $document = $this->lockDocument($documentId, $documentType);
            if (! in_array($document->status, self::ACTIVE_DOCUMENT_STATUSES, true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ chứng từ DRAFT/SCANNING mới được scan.');
            }

            // DEC-QR-001: backend parse composite PRODUCT QR authoritative; FE chỉ
            // gửi raw payload. Payload không composite đi tiếp nhánh legacy y cũ.
            $parsed = $this->parseScanPayload($payload);
            $embeddedSku = $parsed !== null && $parsed->isComposite()
                ? $this->identityResolver()->requireActiveSkuByCode((string) $parsed->skuCode)
                : null;

            $line = $this->resolveLineForScan($documentId, $payload, $embeddedSku, $parsed);

            $quantity = (int) $payload['quantity'];
            if ($quantity <= 0) {
                ApiError::abort(422, 'INVALID_QUANTITY', 'Số lượng scan phải là số nguyên lớn hơn 0.');
            }

            $planned = (int) $line->qty_planned;
            $scanned = (int) $line->scanned_qty;
            if ($scanned + $quantity > $planned) {
                ApiError::abort(422, 'SCAN_EXCEEDS_REQUIRED', 'Số lượng scan vượt số lượng yêu cầu của dòng.');
            }

            $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
            if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE') {
                ApiError::abort(422, 'SKU_NOT_ACTIVE', 'SKU không tồn tại hoặc không ACTIVE.');
            }
            if ($documentType === 'issue' && strtoupper((string) $sku->sku_type) !== 'PRODUCT') {
                ApiError::abort(422, 'PRODUCT_SKU_REQUIRED', 'Outbound sản phẩm không được scan COMPONENT; linh kiện chỉ xuất qua Component Issue WARRANTY.');
            }

            // Inbound Carton Scan v1.0 §3.2: PRODUCT scan mang container_id (active
            // carton) phải trỏ tới CARTON còn dùng được và khớp primary SKU. Nhánh
            // COMPONENT+BOX đã được EnsureInboundComponentBox middleware guard riêng.
            if ($documentType === 'receipt') {
                $this->assertReceiptCartonUsable($line, $sku, $payload);
            }

            [$item, $candidate] = $this->resolveScanTarget($payload, $line, $sku, $parsed, $embeddedSku, $documentType, $documentId);
            $requiresItem = strtoupper((string) $sku->sku_type) === 'PRODUCT' || (bool) $sku->is_serial_tracked;
            if ($requiresItem && $item === null && $candidate === null) {
                ApiError::abort(422, 'ITEM_REQUIRED', 'PRODUCT/serial-tracked line bắt buộc scan một item cụ thể.');
            }
            if (($item !== null || $candidate !== null) && $quantity !== 1) {
                ApiError::abort(422, 'SERIAL_QUANTITY_MUST_BE_ONE', 'Scan item cụ thể chỉ cho phép quantity=1.');
            }

            if ($documentType === 'issue') {
                $this->assertIssueAvailability($document, $line, $quantity);
                if ($item !== null) {
                    $this->assertItemAvailableForIssue($item, $documentId);
                }
            } elseif ($item !== null) {
                $this->assertItemAvailableForReceipt($item, $documentId);
            }

            if ($candidate !== null) {
                $this->assertCandidateAvailable($documentId, (string) $candidate['item_unique']);
            }

            if ($document->status === 'DRAFT') {
                DB::table('stock_documents')->where('id', $documentId)->update([
                    'status' => 'SCANNING',
                    'submitted_by' => $actorId,
                    'submitted_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->recordDocumentStatus($documentId, 'DRAFT', 'SCANNING', 'scan_start', $actorId);

                $scanWarehouseId = $documentType === 'receipt' ? $document->dst_warehouse_id : $document->src_warehouse_id;
                event(new StockDocumentScanStarted(
                    $documentId,
                    (string) $document->doc_no,
                    $documentType,
                    $scanWarehouseId !== null ? (string) $scanWarehouseId : null,
                    $actorId,
                ));
            }

            if ($documentType === 'receipt') {
                $this->recordInboundScan((string) $line->id, $item, $payload, $quantity, $actorId, $candidate);
            } else {
                $this->recordOutboundMatch((string) $line->id, $item, $payload, $quantity, $actorId);
            }

            DB::table('stock_document_lines')->where('id', $line->id)->update([
                'scanned_qty' => $scanned + $quantity,
                'updated_at' => now(),
            ]);

            $this->recordScanEvidence($document, $line, $item, $payload, $actorId, $parsed);

            $resolutionStatus = null;
            if ($candidate !== null) {
                $resolutionStatus = 'NEW_ITEM_CANDIDATE';
            } elseif ($item !== null) {
                $resolutionStatus = $parsed !== null && $parsed->isComposite() ? 'EXISTING_ITEM' : 'LEGACY_ITEM';
            }

            return [
                'document_id' => $documentId,
                'line_id' => (string) $line->id,
                'sku_id' => (string) $line->product_id,
                'item_id' => $item?->item_id,
                'quantity' => $quantity,
                'required_quantity' => $planned,
                'scanned_quantity' => $scanned + $quantity,
                'status' => 'SCANNING',
                'stock_effect' => 'NONE',
                'movement_created' => false,
                // Change Pack QR/SKU §8 — parsed identity cho FE hiển thị, không
                // phải để FE tự parse lại (AC-QR-10).
                'payload_format' => $parsed?->format,
                'payload_version' => $parsed?->payloadVersion,
                'sku_code' => $parsed !== null && $parsed->isComposite()
                    ? $parsed->skuCode
                    : (string) $sku->sku_code,
                'item_unique' => $parsed?->itemUnique,
                'serial_number' => $candidate !== null ? $candidate['serial_number'] : $item?->serial_number,
                'normalized_code_value' => $parsed?->normalizedCodeValue,
                'resolution_status' => $resolutionStatus,
            ];
        }, 3);
    }

    /**
     * Parse code_value theo DEC-QR-001; abort ngay tại đây cho payload hỏng để
     * không nhánh nào phía sau phải đoán cấu trúc (Change Pack §9).
     */
    private function parseScanPayload(array $payload): ?ProductQrPayload
    {
        if (! isset($payload['code_value']) || trim((string) $payload['code_value']) === '') {
            return null;
        }

        $parsed = app(ProductQrPayloadParser::class)->parse((string) $payload['code_value']);
        if ($parsed->parseStatus === ProductQrPayload::STATUS_UNSUPPORTED_VERSION) {
            ApiError::abort(422, 'UNSUPPORTED_PAYLOAD_VERSION', 'Version cấu trúc QR không được hỗ trợ.', [
                'payload_version' => $parsed->payloadVersion,
            ]);
        }
        if (! $parsed->isValid()) {
            ApiError::abort(422, 'INVALID_PAYLOAD', 'QR/Barcode composite sai cấu trúc; không thể parse SKU + item unique.');
        }

        return $parsed;
    }

    private function identityResolver(): ProductIdentityResolver
    {
        return app(ProductIdentityResolver::class);
    }

    /**
     * Dùng cho duplicate pre-check của controller (assertNoDuplicateItemInDocument):
     * resolve item ĐÃ TỒN TẠI từ payload scan, hiểu cả composite QR lẫn plain code.
     * Trả null khi chưa resolve được (candidate/mã lạ) — các guard chuyên sâu hơn
     * nằm trong scanStockDocument.
     */
    public function resolveExistingItemIdForPayload(array $payload): ?string
    {
        if (! empty($payload['item_id'])) {
            return (string) $payload['item_id'];
        }
        if (empty($payload['code_value'])) {
            return null;
        }

        $parsed = app(ProductQrPayloadParser::class)->parse((string) $payload['code_value']);
        if (! $parsed->isValid()) {
            return null;
        }

        $needle = $parsed->isComposite() ? (string) $parsed->itemUnique : (string) $parsed->normalizedCodeValue;
        $item = $this->identityResolver()->findItemByUnique($needle);

        return $item?->item_id !== null ? (string) $item->item_id : null;
    }

    /**
     * Xác định dòng chứng từ cho một lần scan.
     *
     * - line_id gửi tường minh: giữ hành vi cũ, nhưng composite phải khớp SKU
     *   embedded với SKU của dòng (AC-QR-02/05 — không cho override tay).
     * - line_id vắng: tự match theo SKU embedded (composite) hoặc SKU của item
     *   đã đăng ký (legacy) — Change Pack §6.1 bước 4: người dùng không chọn SKU
     *   cho từng lần scan.
     */
    private function resolveLineForScan(
        string $documentId,
        array $payload,
        ?object $embeddedSku,
        ?ProductQrPayload $parsed
    ): object {
        if (! empty($payload['line_id'])) {
            $line = DB::table('stock_document_lines')
                ->where('id', $payload['line_id'])
                ->where('stock_document_id', $documentId)
                ->lockForUpdate()
                ->first();
            if ($line === null) {
                ApiError::abort(404, 'DOCUMENT_LINE_NOT_FOUND', 'Không tìm thấy dòng chứng từ.');
            }
            if ($embeddedSku !== null && (string) $line->product_id !== (string) $embeddedSku->sku_id) {
                ApiError::abort(422, 'WRONG_SKU', 'SKU embedded trong QR không khớp SKU của dòng chứng từ.', [
                    'sku_code' => $parsed?->skuCode,
                    'line_id' => (string) $line->id,
                ]);
            }

            return $line;
        }

        $skuId = null;
        if ($embeddedSku !== null) {
            $skuId = (string) $embeddedSku->sku_id;
        } elseif ($parsed !== null) {
            // Legacy code không kèm line: chỉ auto-match được khi item đã đăng ký.
            $item = $this->identityResolver()->findItemByUnique((string) $parsed->normalizedCodeValue);
            if ($item === null) {
                ApiError::abort(422, 'PHYSICAL_CODE_NOT_FOUND', 'Barcode/QR không tồn tại hoặc không ACTIVE.');
            }
            $skuId = (string) $item->sku_id;
        } else {
            ApiError::abort(422, 'VALIDATION_ERROR', 'Thiếu line_id cho scan không kèm code_value.');
        }

        $lines = DB::table('stock_document_lines')
            ->where('stock_document_id', $documentId)
            ->where('product_id', $skuId)
            ->orderBy('line_no')
            ->lockForUpdate()
            ->get();
        if ($lines->isEmpty()) {
            ApiError::abort(422, 'WRONG_SKU', 'SKU của mã scan không có dòng nào trên chứng từ.', [
                'sku_code' => $parsed?->skuCode,
            ]);
        }

        // Ưu tiên dòng còn thiếu; mọi dòng đã đủ thì trả dòng đầu để guard
        // SCAN_EXCEEDS_REQUIRED phía sau báo đúng ngữ nghĩa EXCESS.
        foreach ($lines as $line) {
            if ((int) $line->scanned_qty < (int) $line->qty_planned) {
                return $line;
            }
        }

        return $lines->first();
    }

    /**
     * Resolve item/candidate cho một lần scan.
     *
     * @return array{0: ?object, 1: ?array{item_unique: string, serial_number: ?string}}
     */
    private function resolveScanTarget(
        array $payload,
        object $line,
        object $sku,
        ?ProductQrPayload $parsed,
        ?object $embeddedSku,
        string $documentType,
        string $documentId
    ): array {
        $isProductLine = strtoupper((string) $sku->sku_type) === 'PRODUCT';

        if ($parsed !== null && $parsed->isLegacy() && $isProductLine
            && ! (bool) config('warehouse.product_qr.legacy_enabled', true)) {
            // DEC-QR-04: hết cửa sổ transition thì mã PRODUCT bắt buộc composite.
            ApiError::abort(422, 'LEGACY_CODE_DISABLED', 'Mã PRODUCT dạng cũ đã bị tắt; hàng mới phải dùng QR composite chứa SKU + item unique.');
        }

        if ($parsed === null || $parsed->isLegacy()) {
            return [$this->resolveItem($payload, (string) $line->product_id), null];
        }

        // Composite: resolve theo item_unique đã chuẩn hoá.
        $item = $this->identityResolver()->findItemByUnique((string) $parsed->itemUnique);
        if ($item !== null) {
            if (! empty($payload['item_id']) && strtolower((string) $payload['item_id']) !== strtolower((string) $item->item_id)) {
                ApiError::abort(422, 'PHYSICAL_CODE_ITEM_MISMATCH', 'code_value và item_id không trỏ cùng item.');
            }
            $this->identityResolver()->assertItemMatchesSku($item, $embeddedSku, $parsed);

            // Khoá lại đúng hàng item trước khi dùng cho guard/subledger.
            $item = DB::table('item_instances')->where('item_id', $item->item_id)->lockForUpdate()->first();

            return [$item, null];
        }

        if ($documentType !== 'receipt') {
            // AC-QR-05: outbound chỉ nhận item đã tồn tại.
            ApiError::abort(422, 'UNKNOWN_CODE', 'QR hợp lệ nhưng item chưa được đăng ký trong hệ thống.', [
                'item_unique' => $parsed->itemUnique,
            ]);
        }

        if ($this->identityResolver()->identityOccupied((string) $parsed->itemUnique)) {
            // Mã đã bị chiếm bởi bản ghi không còn ACTIVE — không được đăng ký đè.
            ApiError::abort(409, 'ITEM_ALREADY_REGISTERED', 'item_unique đã được sử dụng bởi một định danh khác trong hệ thống.', [
                'item_unique' => $parsed->itemUnique,
            ]);
        }

        // AC-QR-03: new item candidate — chưa ghi item/physical code, đăng ký
        // atomically tại Post Receipt (DEC-QR-03).
        $serial = $parsed->serialNumber !== null && $parsed->serialNumber !== $parsed->itemUnique
            ? $parsed->serialNumber
            : null;

        return [null, ['item_unique' => (string) $parsed->itemUnique, 'serial_number' => $serial]];
    }

    /**
     * Duplicate guard cho new item candidate (AC-QR-03/QR-02): cùng phiếu →
     * DUPLICATE_ITEM_IN_DOCUMENT; phiếu inbound mở khác → ITEM_ALREADY_RECEIVING
     * (đối xứng với guard item đã tồn tại).
     */
    private function assertCandidateAvailable(string $documentId, string $itemUnique): void
    {
        if (! Schema::hasColumn('inbound_scan_entries', 'candidate_item_unique')) {
            ApiError::abort(422, 'INVALID_PAYLOAD', 'Schema chưa hỗ trợ new item candidate; hãy chạy migration mới nhất.');
        }

        $inDocument = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where('e.candidate_item_unique', $itemUnique)
            ->where(fn ($query) => self::activeScanEntry($query))
            ->exists();
        if ($inDocument) {
            ApiError::abort(409, 'DUPLICATE_ITEM_IN_DOCUMENT', 'Một item chỉ được scan/match một lần trong cùng document.');
        }

        $inOtherDocument = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('d.id', '!=', $documentId)
            ->whereIn('d.status', self::ACTIVE_DOCUMENT_STATUSES)
            ->where('e.candidate_item_unique', $itemUnique)
            ->where(fn ($query) => self::activeScanEntry($query))
            ->exists();
        if ($inOtherDocument) {
            ApiError::abort(409, 'ITEM_ALREADY_RECEIVING', 'Item đang thuộc một inbound chưa Post khác.');
        }
    }

    public function postStockDocument(
        string $documentId,
        string $documentType,
        ?string $actorId,
        string $idempotencyKey,
        string $requestHash,
        array $auditAfterData = []
    ): array {
        return DB::transaction(function () use ($documentId, $documentType, $actorId, $idempotencyKey, $requestHash, $auditAfterData): array {
            $document = $this->lockDocument($documentId, $documentType);
            if ($document->status !== 'SCANNING') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ chứng từ SCANNING mới được Post.');
            }

            $lines = DB::table('stock_document_lines')
                ->where('stock_document_id', $documentId)
                ->orderBy('line_no')
                ->lockForUpdate()
                ->get();
            if ($lines->isEmpty()) {
                ApiError::abort(422, 'DOCUMENT_HAS_NO_LINES', 'Chứng từ phải có ít nhất một dòng.');
            }

            foreach ($lines as $line) {
                if ((int) $line->scanned_qty !== (int) $line->qty_planned) {
                    ApiError::abort(422, 'FULL_POST_REQUIRED', 'Mọi dòng phải scan đủ chính xác trước khi Post.', [
                        'line_id' => (string) $line->id,
                        'required_qty' => (int) $line->qty_planned,
                        'scanned_qty' => (int) $line->scanned_qty,
                    ]);
                }
            }

            if ($documentType === 'issue') {
                $this->assertMatchedProductRfids($documentId, $lines);
            }

            if ($documentType === 'receipt') {
                // DEC-QR-03 / AC-QR-04: đăng ký item/physical code cho new item
                // candidate TRƯỚC vòng tạo movement để movement.item_id được gán
                // đúng, trong cùng transaction — lỗi ở bất kỳ bước nào rollback
                // toàn bộ; Idempotency replay không chạy lại closure nên không
                // thể tạo item lần hai.
                $this->registerCandidateItems($documentId, $actorId);
            }

            $movementIds = [];
            // Mảng song song với $movementIds, thêm để FE hiển thị mã biến động
            // đọc được (movement_no) thay vì UUID thô — không đổi shape của
            // $movementIds vì nó còn được dùng nguyên trạng cho recordPostingEvent/
            // AuditLogger (payload idempotency/audit không nên đổi hình dạng).
            $movements = [];
            // WWHN-234 F-04: khoá inventory_balances theo thứ tự product_id toàn
            // cục, không theo thứ tự line_no của chứng từ (đã khoá ở trên, giữ
            // nguyên cho stock_document_lines). Hai chứng từ chạm cùng tập SKU
            // nhưng khai dòng theo thứ tự ngược nhau vẫn khoá balance theo đúng
            // một thứ tự — điều kiện đủ để tránh deadlock chéo dưới tải cao.
            foreach ($this->sortLinesForBalanceLock($lines, 'product_id') as $line) {
                $warehouseId = $documentType === 'receipt' ? $document->dst_warehouse_id : $document->src_warehouse_id;
                if ($warehouseId === null) {
                    ApiError::abort(422, 'WAREHOUSE_REQUIRED', 'Chứng từ thiếu warehouse stock-effect.');
                }

                $movement = $this->mutateBalance(
                    (string) $warehouseId,
                    (string) $line->product_id,
                    (int) $line->qty_planned,
                    $documentType === 'receipt' ? 'RECEIPT' : 'ISSUE',
                    $documentType === 'receipt' ? 1 : -1,
                    'STOCK_DOCUMENT',
                    $documentId,
                    (string) $line->id,
                    $actorId,
                    sha1(($documentType === 'receipt' ? 'RECEIPT' : 'ISSUE').'|'.$documentId.'|'.$line->id),
                    self::AVAILABLE,
                    null,
                    null,
                    $this->resolveLineItemId($documentType, (string) $line->id)
                );
                $movementIds[] = $movement['id'];
                $movements[] = ['id' => $movement['id'], 'movement_no' => $movement['movement_no']];
            }

            if ($documentType === 'receipt') {
                $this->applyReceiptSubledger($documentId, $actorId);
            } else {
                $this->applyIssueSubledger($documentId);
            }

            DB::table('stock_documents')->where('id', $documentId)->update([
                'status' => 'POSTED',
                'posted_by' => $actorId,
                'posted_at' => now(),
                'updated_at' => now(),
            ]);
            $this->recordDocumentStatus($documentId, 'SCANNING', 'POSTED', $documentType === 'receipt' ? 'post_receipt' : 'post_issue', $actorId);
            $this->recordPostingEvent(
                ($documentType === 'receipt' ? 'post_receipt_' : 'post_issue_').$documentId,
                $documentType === 'receipt' ? 'POST_RECEIPT' : 'POST_ISSUE',
                $idempotencyKey,
                $requestHash,
                $documentId,
                $movementIds[0] ?? null,
                $actorId,
                ['document_id' => $documentId, 'status' => 'POSTED', 'movement_ids' => $movementIds]
            );

            AuditLogger::record(
                action: $documentType === 'receipt' ? 'POST_RECEIPT' : 'POST_ISSUE',
                objectType: 'stock_document',
                objectId: $documentId,
                actorId: $actorId,
                requestId: request()?->attributes->get('request_id'),
                beforeData: ['status' => 'SCANNING'],
                afterData: ['status' => 'POSTED', 'movement_ids' => $movementIds] + $auditAfterData,
            );

            return [
                'document_id' => $documentId,
                // Mã chứng từ đọc được (vd "PN-20260830153015-A1B2") — FE dùng để
                // hiển thị thay cho document_id thô trong card kết quả hoàn tất.
                'document_no' => $document->doc_no,
                'status' => 'POSTED',
                'movement_ids' => $movementIds,
                'movements' => $movements,
                'stock_effect' => $documentType === 'receipt' ? 'INCREASE' : 'DECREASE',
            ] + $auditAfterData;
        }, 3);
    }

    public function reverseStockDocument(
        string $documentId,
        string $documentType,
        array $payload,
        ?string $actorId,
        string $idempotencyKey,
        string $requestHash
    ): array {
        return DB::transaction(function () use ($documentId, $documentType, $payload, $actorId, $idempotencyKey, $requestHash): array {
            $document = $this->lockDocument($documentId, $documentType);
            if ($document->status !== 'POSTED') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ chứng từ POSTED mới được reversal.');
            }

            $condition = strtoupper((string) ($payload['condition_status'] ?? 'SELLABLE'));
            if (! in_array($condition, ['SELLABLE', 'DEFECTIVE', 'NON_SELLABLE'], true)) {
                ApiError::abort(422, 'INVALID_RETURN_CONDITION', 'condition_status không hợp lệ.');
            }

            if ($documentType === 'receipt') {
                $this->assertReceiptDependenciesReversible($documentId);
            }

            $originalMovements = DB::table('stock_movements')
                ->where('stock_document_id', $documentId)
                ->whereIn('movement_type', [$documentType === 'receipt' ? 'RECEIPT' : 'ISSUE'])
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();
            if ($originalMovements->isEmpty()) {
                ApiError::abort(422, 'NO_REVERSIBLE_MOVEMENT', 'Không có movement gốc hợp lệ để reversal.');
            }

            // §5.5 / BR-REV-05: reversal phải chỉ rõ movement + quantity. Bản cũ cho
            // phép payload rỗng rồi auto-đảo toàn bộ movement gốc — đó là đường tắt
            // bypass ý định "chỉ đảo đúng phần cần đảo" nên bị chặn tường minh, giống
            // guard REVERSAL_LINES_REQUIRED của CurrentBaselineReversalService.
            if (($payload['lines'] ?? []) === []) {
                ApiError::abort(422, 'REVERSAL_LINES_REQUIRED', 'Reversal phải chỉ rõ movement/quantity cần đảo; không auto-reverse toàn chứng từ.');
            }

            $requestedLines = collect($payload['lines'])->keyBy('movement_id');
            $reversalDocumentId = (string) Str::uuid();
            $chainId = (string) Str::uuid();
            DB::table('reversal_document')->insert([
                'reversal_document_id' => $reversalDocumentId,
                'document_no' => 'REV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'stock_document_id' => $documentId,
                'status' => 'DRAFT',
                'reason' => (string) ($payload['reason'] ?? 'Correction'),
                'condition_status' => $condition,
                'correction_chain_id' => $chainId,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actorId,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $movementIds = [];
            foreach ($originalMovements as $movement) {
                $alreadyReversed = (int) DB::table('reversal_line as rl')
                    ->join('reversal_document as rd', 'rd.reversal_document_id', '=', 'rl.reversal_document_id')
                    ->where('rl.original_movement_id', $movement->id)
                    ->where('rd.status', 'POSTED')
                    ->sum('rl.reversal_qty');
                $originalQty = abs((int) $movement->quantity);
                $remaining = $originalQty - $alreadyReversed;
                if ($remaining <= 0) {
                    continue;
                }

                $requested = $requestedLines->get($movement->id);
                $qty = $requested ? (int) $requested['quantity'] : $remaining;
                if ($qty <= 0 || $qty > $remaining) {
                    ApiError::abort(422, 'REVERSAL_QTY_EXCEEDED', 'Reversal quantity vượt reversible_qty.', [
                        'movement_id' => (string) $movement->id,
                        'reversible_qty' => $remaining,
                    ]);
                }

                $stockStatus = $documentType === 'issue'
                    ? match ($condition) {
                        'DEFECTIVE' => 'DEFECTIVE',
                        'NON_SELLABLE' => 'NON_SELLABLE',
                        default => 'AVAILABLE',
                    }
                : (string) ($movement->stock_status ?: self::AVAILABLE);
                $sign = $documentType === 'receipt' ? -1 : 1;

                $reversalLineId = (string) Str::uuid();
                DB::table('reversal_line')->insert([
                    'reversal_line_id' => $reversalLineId,
                    'reversal_document_id' => $reversalDocumentId,
                    'original_movement_id' => $movement->id,
                    'reversal_qty' => $qty,
                    'sku_id' => $movement->sku_id ?? $movement->product_id,
                    'item_id' => $movement->item_id ?? null,
                    'stock_status' => $stockStatus,
                    'reason' => (string) ($payload['reason'] ?? 'Correction'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $newMovement = $this->mutateBalance(
                    (string) $movement->warehouse_id,
                    (string) ($movement->sku_id ?? $movement->product_id),
                    $qty,
                    'REVERSAL',
                    $sign,
                    'REVERSAL_DOCUMENT',
                    $reversalDocumentId,
                    $reversalLineId,
                    $actorId,
                    sha1('REVERSAL|'.$reversalDocumentId.'|'.$movement->id.'|'.$qty),
                    $stockStatus,
                    (string) $movement->id,
                    $chainId
                );
                $movementIds[] = $newMovement['id'];
            }

            if ($movementIds === []) {
                ApiError::abort(409, 'ALREADY_FULLY_REVERSED', 'Chứng từ đã được reversal toàn bộ.');
            }

            if ($documentType === 'issue') {
                $this->applyReverseIssueSubledger($documentId, $condition);
            } else {
                $this->applyReverseReceiptSubledger($documentId);
            }

            DB::table('reversal_document')->where('reversal_document_id', $reversalDocumentId)->update([
                'status' => 'POSTED',
                'posted_at' => now(),
                'posted_by' => $actorId,
                'updated_at' => now(),
            ]);

            $this->recordPostingEvent(
                'reverse_stock_document_'.$documentId,
                $documentType === 'issue' ? 'REVERSE_ISSUE' : 'REVERSE_RECEIPT',
                $idempotencyKey,
                $requestHash,
                $reversalDocumentId,
                $movementIds[0] ?? null,
                $actorId,
                ['reversal_document_id' => $reversalDocumentId, 'movement_ids' => $movementIds]
            );

            AuditLogger::record(
                action: 'REVERSAL_POST',
                objectType: 'reversal_document',
                objectId: $reversalDocumentId,
                actorId: $actorId,
                requestId: request()?->attributes->get('request_id'),
                reason: $payload['reason'] ?? null,
                afterData: ['original_document_id' => $documentId, 'movement_ids' => $movementIds],
            );

            return [
                'original_document_id' => $documentId,
                'original_document_status' => 'POSTED',
                'reversal_document_id' => $reversalDocumentId,
                'reversal_status' => 'POSTED',
                'correction_chain_id' => $chainId,
                'movement_ids' => $movementIds,
            ];
        }, 3);
    }

    public function postComponentIssue(
        string $documentId,
        ?string $actorId,
        string $idempotencyKey,
        string $requestHash
    ): array {
        return DB::transaction(function () use ($documentId, $actorId, $idempotencyKey, $requestHash): array {
            $document = DB::table('component_issue_documents')->where('id', $documentId)->lockForUpdate()->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy Component Issue.');
            }
            if ($document->status !== 'DRAFT') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ Component Issue DRAFT mới được Post.');
            }
            if (strtoupper((string) $document->purpose) !== 'WARRANTY') {
                ApiError::abort(422, 'COMPONENT_PURPOSE_INVALID', 'Component Issue chỉ cho purpose=WARRANTY.');
            }

            $case = DB::table('warranty_case')->where('warranty_case_id', $document->warranty_case_id)->lockForUpdate()->first();
            if ($case === null || in_array($case->status, ['RETURNED', 'CANCELLED'], true)) {
                ApiError::abort(422, 'WARRANTY_CASE_NOT_ACTIVE', 'Component Issue yêu cầu warranty case đang active.');
            }

            $lines = DB::table('component_issue_lines')->where('component_issue_document_id', $documentId)->orderBy('line_no')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                ApiError::abort(422, 'DOCUMENT_HAS_NO_LINES', 'Component Issue phải có ít nhất một dòng.');
            }

            // Pha validate giữ nguyên thứ tự line_no (đã khoá ở trên) để lỗi đầu
            // tiên luôn xác định — không phụ thuộc thứ tự khoá balance bên dưới.
            $lineItemIds = [];
            foreach ($lines as $line) {
                $sku = DB::table('sku')->where('sku_id', $line->sku_id)->first();
                if ($sku === null || strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
                    ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'Mọi dòng Component Issue phải là SKU loại COMPONENT.');
                }
                if ((int) $line->scanned_qty !== (int) $line->required_qty) {
                    ApiError::abort(422, 'FULL_POST_REQUIRED', 'Component Issue chỉ Post khi scanned_qty = required_qty.');
                }

                $lineItemIds[$line->id] = DB::table('component_issue_items')->where('component_issue_line_id', $line->id)->whereNotNull('item_id')->pluck('item_id');
            }

            $movementIds = [];
            // WWHN-234 F-04: pha mutateBalance khoá inventory_balances theo
            // product_id toàn cục, tách khỏi thứ tự line_no dùng cho validate ở
            // trên — cùng lý do như postStockDocument().
            foreach ($this->sortLinesForBalanceLock($lines, 'sku_id') as $line) {
                $itemIds = $lineItemIds[$line->id];

                $movement = $this->mutateBalance(
                    (string) $document->warehouse_id,
                    (string) $line->sku_id,
                    (int) $line->required_qty,
                    'COMPONENT_ISSUE',
                    -1,
                    'COMPONENT_ISSUE_DOCUMENT',
                    $documentId,
                    (string) $line->id,
                    $actorId,
                    sha1('COMPONENT_ISSUE|'.$documentId.'|'.$line->id),
                    self::AVAILABLE,
                    null,
                    null,
                    $itemIds->count() === 1 ? (string) $itemIds->first() : null
                );
                $movementIds[] = $movement['id'];

                if ($itemIds->isNotEmpty()) {
                    DB::table('item_instances')->whereIn('item_id', $itemIds)->update([
                        'item_status' => 'ISSUED',
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('component_issue_documents')->where('id', $documentId)->update([
                'status' => 'POSTED',
                'idempotency_key' => $idempotencyKey,
                'posted_at' => now(),
                'posted_by' => $actorId,
                'updated_at' => now(),
                'updated_by' => $actorId,
            ]);
            $this->recordPostingEvent(
                'post_component_issue_'.$documentId,
                'POST_COMPONENT_ISSUE',
                $idempotencyKey,
                $requestHash,
                $documentId,
                $movementIds[0] ?? null,
                $actorId,
                ['component_issue_document_id' => $documentId, 'movement_ids' => $movementIds]
            );

            AuditLogger::record(
                action: 'POST_COMPONENT_ISSUE',
                objectType: 'component_issue_document',
                objectId: $documentId,
                actorId: $actorId,
                requestId: request()?->attributes->get('request_id'),
                afterData: ['status' => 'POSTED', 'movement_ids' => $movementIds],
            );

            return [
                'component_issue_document_id' => $documentId,
                'status' => 'POSTED',
                'movement_ids' => $movementIds,
                'stock_effect' => 'DECREASE',
            ];
        }, 3);
    }

    private function resolveItem(array $payload, string $skuId): ?object
    {
        $itemId = $payload['item_id'] ?? null;
        if (isset($payload['code_value'])) {
            $physical = DB::table('physical_codes')
                ->where('code_value', strtoupper(trim((string) $payload['code_value'])))
                ->where('status', 'ACTIVE')
                ->first();
            if ($physical === null) {
                ApiError::abort(422, 'PHYSICAL_CODE_NOT_FOUND', 'Barcode/QR không tồn tại hoặc không ACTIVE.');
            }

            $resolvedId = $physical->assigned_object_type === 'ITEM'
                ? $physical->assigned_object_id
                : DB::table('item_instances')->where('physical_code_id', $physical->physical_code_id)->value('item_id');
            if ($resolvedId === null) {
                ApiError::abort(422, 'PHYSICAL_CODE_UNASSIGNED', 'Mã vật lý chưa được map vào ITEM.');
            }
            if ($itemId !== null && strtolower((string) $itemId) !== strtolower((string) $resolvedId)) {
                ApiError::abort(422, 'PHYSICAL_CODE_ITEM_MISMATCH', 'code_value và item_id không trỏ cùng item.');
            }
            $itemId = $resolvedId;
        }

        if ($itemId === null) {
            return null;
        }

        $item = DB::table('item_instances')->where('item_id', $itemId)->lockForUpdate()->first();
        if ($item === null || (string) $item->sku_id !== $skuId) {
            ApiError::abort(422, 'ITEM_SKU_MISMATCH', 'Item không tồn tại hoặc không thuộc SKU của dòng.');
        }

        return $item;
    }

    private function assertItemAvailableForIssue(object $item, string $documentId): void
    {
        if ((string) $item->item_status !== 'IN_STOCK') {
            ApiError::abort(422, 'ITEM_NOT_AVAILABLE', 'Item phải ở trạng thái IN_STOCK trước khi match outbound.');
        }

        $conflict = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('m.item_id', $item->item_id)
            ->where('d.id', '!=', $documentId)
            ->whereIn('d.status', self::ACTIVE_DOCUMENT_STATUSES)
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            })
            ->first([
                'd.id as conflict_document_id',
                'd.status as conflict_document_status',
                'l.id as conflict_line_id',
            ]);
        if ($conflict !== null) {
            ApiError::abort(
                409,
                'ITEM_ALREADY_MATCHED',
                'Item đang được giữ bởi một phiếu xuất khác chưa hoàn tất.',
                [
                    'conflict_document_id' => (string) $conflict->conflict_document_id,
                    'conflict_document_status' => (string) $conflict->conflict_document_status,
                    'conflict_line_id' => (string) $conflict->conflict_line_id,
                ]
            );
        }
    }

    /**
     * ERD bảng 28: inbound_scan_entries.status ACTIVE/REMOVED "cho sửa trước Post".
     * Mọi tính toán tồn/subledger/guard chỉ được nhìn entry còn ACTIVE, nếu không
     * item đã gỡ vẫn bị Post vào tồn.
     */
    private static function activeScanEntry($query, string $alias = 'e')
    {
        return $query->whereNull("{$alias}.status")->orWhere("{$alias}.status", '!=', 'REMOVED');
    }

    /**
     * Inbound Carton Scan v1.0 §3.2: PRODUCT Item scan có active carton bắt buộc
     * mang container_id của carton đó; BE verify carton tồn tại/usable, đúng loại
     * CARTON và primary SKU khớp SKU của dòng nhập. Item lẻ (container_id null)
     * đi qua không đụng guard này. COMPONENT/BOX giữ nguyên guard riêng ở
     * EnsureInboundComponentBox nên không lặp lại tại đây.
     *
     * BA (2026-08-21, phản hồi cho luồng carton) xác nhận baseline: "một carton
     * PRODUCT chỉ chứa các sản phẩm cùng một SKU". Carton chưa đăng ký primary SKU
     * không được bỏ qua check — Item PRODUCT đầu tiên quét vào carton sẽ KHOÁ
     * primary_sku_id của carton theo đúng SKU đó (lockForUpdate để chống race hai
     * scan gần như đồng thời cùng khoá hai SKU khác nhau); mọi Item sau, kể cả từ
     * document khác, phải khớp đúng SKU đã khoá.
     */
    private function assertReceiptCartonUsable(object $line, object $sku, array $payload): void
    {
        $containerId = trim((string) ($payload['container_id'] ?? ''));
        if ($containerId === '' || strtoupper((string) $sku->sku_type) !== 'PRODUCT') {
            return;
        }

        $carton = DB::table('containers')->where('container_id', $containerId)->lockForUpdate()->first();
        if ($carton === null) {
            ApiError::abort(422, 'CARTON_NOT_FOUND', 'Không tìm thấy carton được gửi trong container_id.', [
                'container_id' => $containerId,
            ]);
        }
        if (strtoupper((string) $carton->container_type) !== 'CARTON') {
            ApiError::abort(422, 'CARTON_TYPE_INVALID', 'PRODUCT Item chỉ được scan vào container loại CARTON.', [
                'container_id' => $containerId,
                'container_type' => (string) $carton->container_type,
            ]);
        }
        // ERD bảng 22: CLOSED/ISSUED là container không còn dùng được cho scan mới
        // (cùng ngưỡng với scan/classify); các trạng thái khác vẫn nhận hàng.
        if (in_array(strtoupper((string) $carton->status), ['CLOSED', 'ISSUED'], true)) {
            ApiError::abort(409, 'CARTON_NOT_USABLE', 'Carton đã CLOSED/ISSUED nên không nhận scan mới.', [
                'container_id' => $containerId,
                'status' => (string) $carton->status,
            ]);
        }

        if ($carton->primary_sku_id === null) {
            DB::table('containers')->where('container_id', $containerId)->update([
                'primary_sku_id' => $line->product_id,
                'updated_at' => now(),
            ]);

            return;
        }

        // §5: "Carton SKU ≠ inbound line SKU → Reject".
        if ((string) $carton->primary_sku_id !== (string) $line->product_id) {
            ApiError::abort(422, 'CARTON_SKU_MISMATCH', 'Primary SKU của carton không khớp SKU của dòng nhập.', [
                'container_id' => $containerId,
                'carton_sku_id' => (string) $carton->primary_sku_id,
                'line_sku_id' => (string) $line->product_id,
            ]);
        }
    }

    private function assertItemAvailableForReceipt(object $item, string $documentId): void
    {
        // GĐ1-REQ-008: normal receipt is only for a new identity — bất kể
        // item_status (IN_STOCK/ISSUED/IN_WARRANTY/...). Any identity already in
        // the database must go through a controlled reversal/return workflow.
        ApiError::abort(409, 'ITEM_ALREADY_REGISTERED', 'Mã máy đã tồn tại trong hệ thống; hãy dùng luồng reversal/return được kiểm soát.', [
            'item_id' => (string) $item->item_id,
            'item_status' => (string) ($item->item_status ?? ''),
        ]);
    }

    private function assertIssueAvailability(object $document, object $line, int $quantity): void
    {
        $available = (int) (DB::table('inventory_balances')
            ->where('warehouse_id', $document->src_warehouse_id)
            ->where(function ($query) use ($line): void {
                $query->where('sku_id', $line->product_id)->orWhere('product_id', $line->product_id);
            })
            ->where('stock_status', self::AVAILABLE)
            ->value('available_qty') ?? 0);
        if ($available < $quantity) {
            ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Không đủ tồn khả dụng để scan outbound.', [
                'available_qty' => $available,
                'requested_qty' => $quantity,
                'sku_id' => (string) $line->product_id,
            ]);
        }
    }

    /**
     * @param  array{item_unique: string, serial_number: ?string}|null  $candidate
     */
    private function recordInboundScan(string $lineId, ?object $item, array $payload, int $quantity, ?string $actorId, ?array $candidate = null): void
    {
        // ERD bảng 28 yêu cầu status ACTIVE/REMOVED + captured_at/captured_by.
        // Trên PostgreSQL trigger hn_fill_scan_evidence_fields đã điền hộ, nhưng ghi
        // tường minh để sqlite (CI) không lệch nhánh và để evidence không phụ thuộc
        // trigger.
        $row = [
            'id' => (string) Str::uuid(),
            'inbound_line_id' => $lineId,
            'item_id' => $item?->item_id,
            'container_id' => $payload['container_id'] ?? null,
            'quantity' => $quantity,
            'scan_source' => isset($payload['code_value']) ? 'CAMERA_OR_MANUAL_CODE' : 'MANUAL',
            'created_at' => now(),
            'created_by' => $actorId,
            'updated_at' => now(),
            'updated_by' => $actorId,
            'version' => 0,
        ];
        foreach (['status' => 'ACTIVE', 'captured_at' => now(), 'captured_by' => $actorId] as $column => $value) {
            if (Schema::hasColumn('inbound_scan_entries', $column)) {
                $row[$column] = $value;
            }
        }
        // DEC-QR-03: candidate giữ identity trên entry cho tới Post Receipt.
        if ($candidate !== null) {
            $row['candidate_item_unique'] = $candidate['item_unique'];
            $row['candidate_serial_number'] = $candidate['serial_number'];
        }

        DB::table('inbound_scan_entries')->insert($row);
    }

    private function recordOutboundMatch(string $lineId, ?object $item, array $payload, int $quantity, ?string $actorId): void
    {
        if ($item !== null && DB::table('outbound_item_matches')
            ->where('outbound_line_id', $lineId)
            ->where('item_id', $item->item_id)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '!=', 'REMOVED');
            })
            ->exists()) {
            ApiError::abort(409, 'DUPLICATE_ITEM_MATCH', 'Item đã được match vào dòng outbound này.');
        }

        DB::table('outbound_item_matches')->insert([
            'id' => (string) Str::uuid(),
            'outbound_line_id' => $lineId,
            'item_id' => $item?->item_id,
            'container_id' => $payload['container_id'] ?? null,
            'quantity' => $quantity,
            'status' => 'ACTIVE',
            'matched_at' => now(),
            'matched_by' => $actorId,
            'created_at' => now(),
            'created_by' => $actorId,
            'updated_at' => now(),
            'updated_by' => $actorId,
            'version' => 0,
        ]);
    }

    private function recordScanEvidence(object $document, object $line, ?object $item, array $payload, ?string $actorId, ?ProductQrPayload $parsed = null): void
    {
        // scan_event là compatibility VIEW từ 000791 — hasTable() trả false cho
        // view trên PostgreSQL nên phải kiểm tra thêm hasView, nếu không evidence
        // NFR-SRS-004 bị bỏ qua trong im lặng.
        $hasScanEvent = Schema::hasTable('scan_event') || Schema::hasView('scan_event');
        if (! Schema::hasTable('scan_batch') || ! $hasScanEvent) {
            return;
        }

        // NFR-SRS-004: mọi scan (camera/nhập tay code_value lẫn chọn item_id trực
        // tiếp) đều để lại scan_event; raw_code fallback về mã vật lý của item.
        // Composite QR: raw payload là bắt buộc trong evidence (Change Pack §7)
        // nhưng cột code_value canonical trần 200 (ERD bảng 25) → cắt an toàn;
        // identity đã parse nằm đủ trong metadata bên dưới.
        $rawCode = isset($payload['code_value']) ? mb_substr(strtoupper(trim((string) $payload['code_value'])), 0, 200) : null;
        if ($rawCode === null || $rawCode === '') {
            if ($item !== null) {
                $physical = DB::table('physical_codes')
                    ->where('assigned_object_type', 'ITEM')
                    ->where('assigned_object_id', $item->item_id)
                    ->where('status', 'ACTIVE')
                    ->orderBy('created_at')
                    ->first(['code_value']);
                $rawCode = $physical?->code_value !== null ? (string) $physical->code_value : (string) $item->item_id;
            } else {
                $rawCode = (string) $line->product_id;
            }
        }

        // scan_event.physical_code_id là FK legacy trỏ physical_code (số ít),
        // khác bảng canonical physical_codes (số nhiều) dùng ở trên — tra lại
        // theo raw_code để không insert ID sai bảng.
        $legacyPhysicalId = $rawCode !== null && $rawCode !== ''
            ? DB::table('physical_code')->where('code_value', $rawCode)->value('physical_code_id')
            : null;

        $batchId = (string) Str::uuid();
        DB::table('scan_batch')->insert([
            'scan_batch_id' => $batchId,
            'source_type' => strtoupper((string) $document->doc_type).'_DOCUMENT',
            'source_id' => $document->id,
            'warehouse_id' => $document->doc_type === 'receipt' ? $document->dst_warehouse_id : $document->src_warehouse_id,
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
        $scanEventId = (string) Str::uuid();
        DB::table('scan_event')->insert([
            'scan_event_id' => $scanEventId,
            'scan_batch_id' => $batchId,
            'raw_code' => $rawCode,
            'physical_code_id' => $legacyPhysicalId,
            'resolved_object_type' => $item ? 'ITEM' : 'SKU',
            'resolved_object_id' => $item?->item_id ?? $line->product_id,
            'result_status' => 'VALID',
            'message' => 'Hợp lệ theo baseline hiện hành',
            'created_at' => now(),
        ]);

        $this->stampScanSource(
            $scanEventId,
            $payload['scan_source'] ?? null,
            $document->doc_type === 'receipt' ? 'INBOUND' : 'OUTBOUND',
            (string) $document->id
        );
        $this->stampScanPayloadAudit($scanEventId, $parsed);
    }

    /**
     * Change Pack QR/SKU §7 — audit parse của composite QR (raw_payload đầy đủ,
     * parsed sku/item, payload_version, parse_status) ghi vào scan_events.metadata
     * (jsonb, ERD bảng 25) thay vì thêm cột mới: cùng pattern defensive với
     * stampScanSource để driver/schema thiếu cột không chặn nghiệp vụ scan.
     */
    private function stampScanPayloadAudit(string $scanEventId, ?ProductQrPayload $parsed): void
    {
        if ($parsed === null || ! $parsed->isComposite()) {
            return;
        }

        $metadata = json_encode([
            'raw_payload' => $parsed->rawPayload,
            'payload_version' => $parsed->payloadVersion,
            'parsed_sku_code' => $parsed->skuCode,
            'parsed_item_unique' => $parsed->itemUnique,
            'parse_status' => $parsed->parseStatus,
        ], JSON_UNESCAPED_UNICODE);

        foreach (['scan_events' => 'id', 'scan_event' => 'scan_event_id'] as $table => $key) {
            if ((! Schema::hasTable($table) && ! Schema::hasView($table)) || ! Schema::hasColumn($table, 'metadata')) {
                continue;
            }

            try {
                $affected = DB::table($table)->where($key, $scanEventId)->update(['metadata' => $metadata]);
            } catch (\Throwable) {
                continue;
            }

            if ($affected > 0) {
                return;
            }
        }
    }

    /**
     * WWHN-195 / DEV195-P1-01R2 — ghi tường minh nguồn quét vào evidence canonical.
     *
     * `scan_event` (số ít) là view/bảng compatibility và KHÔNG lộ cột scan_source,
     * nên trước đây tầng ứng dụng không có đường nào ghi giá trị này: trên
     * PostgreSQL trigger `hn_fill_scan_evidence_fields` đoán
     * `physical_code_id IS NULL ? 'MANUAL' : 'HARDWARE'` — không bao giờ ra
     * 'CAMERA' dù ERD bảng 25 có CHECK cho phép. Hàm này ghi đè giá trị đoán bằng
     * mode client khai, và điền luôn context_type theo loại chứng từ.
     *
     * Cả hai driver đều được phục vụ: thử bảng canonical `scan_events` trước
     * (PostgreSQL — số nhiều là bảng vật lý), nếu không có dòng nào bị chạm thì
     * thử `scan_event` (sqlite — số ít là bảng vật lý). Thiếu cột/thiếu bảng thì
     * bỏ qua im lặng để không chặn nghiệp vụ scan.
     */
    private function stampScanSource(string $scanEventId, ?string $scanSource, string $contextType, string $contextId): void
    {
        if ($scanSource === null || $scanSource === '') {
            return;
        }

        foreach (['scan_events' => 'id', 'scan_event' => 'scan_event_id'] as $table => $key) {
            if (! Schema::hasTable($table) && ! Schema::hasView($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'scan_source')) {
                continue;
            }

            $values = ['scan_source' => $scanSource];
            if (Schema::hasColumn($table, 'context_type')) {
                $values['context_type'] = $contextType;
            }
            if (Schema::hasColumn($table, 'context_id')) {
                $values['context_id'] = $contextId;
            }

            try {
                $affected = DB::table($table)->where($key, $scanEventId)->update($values);
            } catch (\Throwable) {
                // View compatibility không cho UPDATE cột này trên driver hiện tại.
                continue;
            }

            if ($affected > 0) {
                return;
            }
        }
    }

    private function assertMatchedProductRfids(string $documentId, $lines): void
    {
        foreach ($lines as $line) {
            $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
            if ($sku === null || strtoupper((string) $sku->sku_type) !== 'PRODUCT') {
                continue;
            }

            $matches = DB::table('outbound_item_matches')
                ->where('outbound_line_id', $line->id)
                ->whereNotNull('item_id')
                ->where(function ($query): void {
                    $query->whereNull('status')->orWhere('status', '!=', 'REMOVED');
                })
                ->get();
            if ($matches->count() !== (int) $line->qty_planned) {
                ApiError::abort(422, 'PRODUCT_ITEM_MATCH_REQUIRED', 'Mỗi PRODUCT unit phải được match bằng item cụ thể trước Post Issue.', [
                    'line_id' => (string) $line->id,
                ]);
            }

            // SRS v1.12 REQ-SRS-011 / DEC-RFID-FALLBACK-001: RFID là OPTIONAL và
            // không phải Post Issue gate — Barcode/QR là định danh hợp lệ. Guard
            // ITEM_RFID_REQUIRED cũ (theo ERD proposal v1.6) đã bị gỡ theo baseline.
        }
    }

    /**
     * WWHN-234 F-04: trả lại các dòng đã lockForUpdate() theo thứ tự line_no,
     * nhưng SẮP LẠI theo `$skuColumn` (thứ tự chuỗi ổn định, tie-break bằng id)
     * chỉ để quyết định thứ tự gọi mutateBalance(). Lock trên
     * stock_document_lines/component_issue_lines đã lấy theo line_no ở trên và
     * KHÔNG đổi — hàm này chỉ đổi thứ tự khoá inventory_balances phía sau, để
     * hai chứng từ chạm cùng tập SKU nhưng khai dòng ngược nhau luôn khoá theo
     * đúng một thứ tự toàn cục thay vì thứ tự dòng riêng của từng chứng từ.
     *
     * @return list<object>
     */
    private function sortLinesForBalanceLock(iterable $lines, string $skuColumn): array
    {
        $items = $lines instanceof Collection ? $lines->all() : [...$lines];
        usort($items, fn (object $a, object $b): int => [(string) $a->{$skuColumn}, (string) $a->id] <=> [(string) $b->{$skuColumn}, (string) $b->id]);

        return $items;
    }

    /**
     * DEC-QR-03 / AC-QR-04: tạo Item_Instance + Physical_Code cho mọi new item
     * candidate còn ACTIVE của phiếu, atomically trong transaction Post Receipt.
     *
     * Item tạo với item_status=NEW rồi được applyReceiptSubledger (chạy ngay sau,
     * cùng transaction) chuyển IN_STOCK qua entry.item_id vừa gán — đi đúng một
     * đường state với item pre-seeded, không mở nhánh state riêng cho candidate.
     * Trùng identity (phiếu khác Post trước / mã được đăng ký ngoài luồng sau khi
     * scan) bị chặn fail-closed bằng re-check trong transaction; unique constraint
     * của physical_codes.code_value và item_instances.serial_number là backstop.
     */
    private function registerCandidateItems(string $documentId, ?string $actorId): void
    {
        if (! Schema::hasColumn('inbound_scan_entries', 'candidate_item_unique')) {
            return;
        }

        $candidates = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->whereNull('e.item_id')
            ->whereNotNull('e.candidate_item_unique')
            ->where(fn ($query) => self::activeScanEntry($query))
            ->lockForUpdate()
            ->get(['e.id as entry_id', 'e.candidate_item_unique', 'e.candidate_serial_number', 'l.product_id']);

        foreach ($candidates as $candidate) {
            $itemUnique = strtoupper(trim((string) $candidate->candidate_item_unique));
            $serial = $candidate->candidate_serial_number !== null && trim((string) $candidate->candidate_serial_number) !== ''
                ? strtoupper(trim((string) $candidate->candidate_serial_number))
                : $itemUnique;

            if (DB::table('physical_codes')->where('code_value', $itemUnique)->exists()
                || DB::table('item_instances')->where('serial_number', $serial)->exists()) {
                ApiError::abort(409, 'ITEM_ALREADY_REGISTERED', 'item_unique/serial của candidate đã được đăng ký bởi giao dịch khác; gỡ mã và quét lại.', [
                    'item_unique' => $itemUnique,
                ]);
            }

            $itemId = (string) Str::uuid();
            $itemRow = [
                'item_id' => $itemId,
                'sku_id' => (string) $candidate->product_id,
                'serial_number' => $serial,
                'item_status' => 'NEW',
                'item_condition' => 'NORMAL',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            // Cột chỉ có trên một driver (id/item_code/status/condition ở nhánh ERD
            // PostgreSQL; created_by tuỳ schema) — điền có điều kiện như các luồng khác.
            foreach ([
                'id' => $itemId,
                'item_code' => $itemUnique,
                'status' => 'NEW',
                'condition' => 'NORMAL',
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ] as $column => $value) {
                if (Schema::hasColumn('item_instances', $column)) {
                    $itemRow[$column] = $value;
                }
            }
            DB::table('item_instances')->insert($itemRow);

            $physicalCodeId = (string) Str::uuid();
            DB::table('physical_codes')->insert([
                'physical_code_id' => $physicalCodeId,
                'code_value' => $itemUnique,
                'code_type' => 'ITEM',
                'carrier_type' => 'QR',
                'hardware_uid' => null,
                'item_id' => $itemId,
                'container_id' => null,
                'assigned_object_type' => 'ITEM',
                'assigned_object_id' => $itemId,
                'is_primary' => true,
                'assigned_at' => now(),
                'assigned_by' => $actorId,
                // DEC-QR-001: composite V1 — đánh version để phân biệt mã phát
                // hành theo cấu trúc mới với mã legacy.
                'encoded_payload_version' => '1',
                'status' => 'ACTIVE',
                'version' => 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('item_instances')->where('item_id', $itemId)->update([
                'physical_code_id' => $physicalCodeId,
                'updated_at' => now(),
            ]);

            DB::table('inbound_scan_entries')->where('id', $candidate->entry_id)->update([
                'item_id' => $itemId,
                'updated_at' => now(),
                'updated_by' => $actorId,
            ]);

            AuditLogger::record(
                action: 'ITEM_REGISTERED_AT_POST_RECEIPT',
                objectType: 'item_instance',
                objectId: $itemId,
                actorId: $actorId,
                requestId: request()?->attributes->get('request_id'),
                afterData: [
                    'document_id' => $documentId,
                    'sku_id' => (string) $candidate->product_id,
                    'item_unique' => $itemUnique,
                    'physical_code_id' => $physicalCodeId,
                    'encoded_payload_version' => '1',
                ],
            );
        }
    }

    /**
     * DR-LOC-001 "container hint": Post Receipt là điểm duy nhất xác nhận stock thật, nên
     * đây cũng phải là điểm duy nhất materialize container linkage cho subledger — trước
     * bản sửa này, `inbound_scan_entries.container_id` chỉ được ghi lúc scan rồi bị bỏ
     * quên, không có mã nào chép nó sang `item_instances.container_id` (Item/CARTON) hay
     * `container_items` (COMPONENT theo BOX/quantity). Hệ quả: `InventoryBalanceController
     * ::buildContainerHints()` (cột "Vị trí gợi ý" của SCR-INV-01) và subledger drill-down
     * luôn đọc bảng trống, kể cả sau khi đã quét hộp và Post Receipt thành công.
     */
    private function applyReceiptSubledger(string $documentId, ?string $actorId = null): void
    {
        $entries = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where(fn ($query) => self::activeScanEntry($query))
            ->get(['e.item_id', 'e.container_id', 'e.quantity', 'l.product_id']);

        $itemEntries = $entries->whereNotNull('item_id');
        $itemIds = $itemEntries->pluck('item_id')->unique()->values();
        if ($itemIds->isNotEmpty()) {
            DB::table('item_instances')->whereIn('item_id', $itemIds)->update([
                'item_status' => 'IN_STOCK',
                'updated_at' => now(),
            ]);
        }
        // Item lẻ scan kèm carton (PRODUCT) hoặc item serial-tracked scan kèm BOX (COMPONENT)
        // đều mang container_id trên entry — chép sang item_instances để subledger
        // drill-down (InventoryBalanceController::subledger) thấy đúng carton/box hiện tại.
        foreach ($itemEntries->whereNotNull('container_id') as $entry) {
            DB::table('item_instances')->where('item_id', $entry->item_id)->update([
                'container_id' => $entry->container_id,
                'updated_at' => now(),
            ]);
        }

        // Component đếm theo BOX (quantity-mode, không có item_id) chưa từng có mapping
        // container_items nào — tạo mới ở đây, gộp theo (container, SKU) vì nhiều lượt
        // scan cùng hộp trong cùng phiếu là nhiều dòng inbound_scan_entries riêng lẻ.
        $boxGroups = $entries->whereNull('item_id')->whereNotNull('container_id')
            ->groupBy(fn ($entry): string => $entry->container_id.'|'.$entry->product_id);
        foreach ($boxGroups as $key => $group) {
            [$containerId, $skuId] = explode('|', (string) $key, 2);
            $quantity = (int) $group->sum('quantity');
            if ($quantity <= 0) {
                continue;
            }
            DB::table('container_items')->insert([
                'container_item_id' => (string) Str::uuid(),
                'container_id' => $containerId,
                'item_id' => null,
                'sku_id' => $skuId,
                'quantity' => $quantity,
                'status' => 'ACTIVE',
                'is_active' => true,
                'placed_at' => now(),
                'placed_by' => $actorId,
                'source_type' => 'RECEIPT',
                'source_id' => $documentId,
                'version' => 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function applyIssueSubledger(string $documentId): void
    {
        $activeMatches = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            })
            ->get(['m.id', 'm.item_id']);

        $itemIds = $activeMatches->pluck('item_id')->filter();
        if ($itemIds->isNotEmpty()) {
            $update = ['item_status' => 'ISSUED', 'updated_at' => now()];
            if (Schema::hasColumn('item_instances', 'issued_at')) {
                $update['issued_at'] = now();
            }
            DB::table('item_instances')->whereIn('item_id', $itemIds)->update($update);
        }

        // ERD state machine: match ACTIVE -> POSTED tại Post Issue.
        if ($activeMatches->isNotEmpty()) {
            DB::table('outbound_item_matches')
                ->whereIn('id', $activeMatches->pluck('id'))
                ->update(['status' => 'POSTED', 'updated_at' => now()]);
        }
    }

    private function applyReverseIssueSubledger(string $documentId, string $condition): void
    {
        $itemIds = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->whereNotNull('m.item_id')
            ->where(function ($query): void {
                $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
            })
            ->pluck('m.item_id');
        if ($itemIds->isEmpty()) {
            return;
        }

        $update = [
            'item_status' => 'IN_STOCK',
            'item_condition' => $condition === 'SELLABLE' ? 'NORMAL' : $condition,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('item_instances', 'issued_at')) {
            $update['issued_at'] = null;
        }
        DB::table('item_instances')->whereIn('item_id', $itemIds)->update($update);
    }

    private function applyReverseReceiptSubledger(string $documentId): void
    {
        $itemIds = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->whereNotNull('e.item_id')
            ->where(fn ($query) => self::activeScanEntry($query))
            ->pluck('e.item_id');
        if ($itemIds->isNotEmpty()) {
            DB::table('item_instances')->whereIn('item_id', $itemIds)->update([
                'item_status' => 'ADJUSTED_OUT',
                'updated_at' => now(),
            ]);
        }

        // Đối xứng với applyReceiptSubledger(): container_items materialize từ RECEIPT
        // này phải bị vô hiệu hoá cùng lúc, nếu không "Vị trí gợi ý" vẫn hiển thị hàng
        // đã đảo — cùng bất biến với CurrentBaselineContainerFlowController::removeItem.
        $mappings = DB::table('container_items')
            ->where('source_type', 'RECEIPT')
            ->where('source_id', $documentId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get(['container_item_id', 'container_id', 'version']);
        foreach ($mappings as $mapping) {
            DB::table('container_items')->where('container_item_id', $mapping->container_item_id)->update([
                'status' => 'REMOVED',
                'is_active' => false,
                'removed_at' => now(),
                'version' => ((int) ($mapping->version ?? 0)) + 1,
                'updated_at' => now(),
            ]);
            $remaining = DB::table('container_items')
                ->where('container_id', $mapping->container_id)
                ->where('is_active', true)
                ->exists();
            DB::table('containers')->where('container_id', $mapping->container_id)
                ->whereNotIn('status', ['CLOSED', 'STORED'])
                ->update([
                    'status' => $remaining ? 'PARTIAL' : 'EMPTY',
                    'updated_at' => now(),
                ]);
        }
    }

    private function assertReceiptDependenciesReversible(string $documentId): void
    {
        $blocked = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('item_instances as i', 'i.item_id', '=', 'e.item_id')
            ->where('l.stock_document_id', $documentId)
            ->whereNotNull('e.item_id')
            ->where(fn ($query) => self::activeScanEntry($query))
            ->whereNotIn('i.item_status', ['IN_STOCK', 'NEW', 'ADJUSTED_OUT'])
            ->exists();
        if ($blocked) {
            ApiError::abort(409, ConflictCode::DOWNSTREAM_DEPENDENCY, 'Receipt có item đã Issue/Warranty; phải đảo dependency theo thứ tự ngược.');
        }
    }

    private function mutateBalance(
        string $warehouseId,
        string $skuId,
        int $quantity,
        string $movementType,
        int $sign,
        string $sourceType,
        string $sourceId,
        ?string $sourceLineId,
        ?string $actorId,
        string $businessKey,
        string $stockStatus = self::AVAILABLE,
        ?string $originalMovementId = null,
        ?string $correctionChainId = null,
        ?string $itemId = null
    ): array {
        if ($quantity <= 0) {
            ApiError::abort(422, 'INVALID_QUANTITY', 'Stock quantity phải là số nguyên dương.');
        }
        if (DB::table('stock_movements')->where('business_key', $businessKey)->exists()) {
            ApiError::abort(409, 'DUPLICATE_STOCK_EFFECT', 'Stock effect đã được ghi trước đó.');
        }

        $balance = DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where(function ($query) use ($skuId): void {
                $query->where('sku_id', $skuId)->orWhere('product_id', $skuId);
            })
            ->where('stock_status', $stockStatus)
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            if ($sign < 0) {
                ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Không đủ tồn cho stock effect.');
            }
            $balanceId = (string) Str::uuid();
            DB::table('inventory_balances')->insert([
                'id' => $balanceId,
                'warehouse_id' => $warehouseId,
                'product_id' => $skuId,
                'sku_id' => $skuId,
                'stock_status' => $stockStatus,
                'quantity' => 0,
                'on_hand_qty' => 0,
                'available_qty' => 0,
                'reserved_qty' => 0,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $balance = DB::table('inventory_balances')->where('id', $balanceId)->lockForUpdate()->first();
        }

        $before = (int) $balance->quantity;
        $after = $before + ($sign * $quantity);
        if ($after < 0) {
            ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Stock effect sẽ làm tồn âm.');
        }

        $availableBefore = (int) $balance->available_qty;
        $availableAfter = $stockStatus === self::AVAILABLE ? $availableBefore + ($sign * $quantity) : $availableBefore;
        if ($availableAfter < 0) {
            ApiError::abort(422, 'INSUFFICIENT_AVAILABLE_STOCK', 'Stock effect sẽ làm available_qty âm.');
        }

        $updated = DB::table('inventory_balances')->where('id', $balance->id)->where('version', $balance->version)->update([
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'quantity' => $after,
            'on_hand_qty' => $after,
            'available_qty' => $availableAfter,
            'version' => ((int) $balance->version) + 1,
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            ApiError::abort(409, ConflictCode::CONCURRENCY, 'Tồn kho vừa bị thay đổi bởi thao tác khác. Vui lòng tải lại dữ liệu mới nhất rồi thử lại.');
        }

        $movementId = (string) Str::uuid();
        $movementNo = 'MV-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        DB::table('stock_movements')->insert([
            'id' => $movementId,
            'movement_no' => $movementNo,
            'business_key' => $businessKey,
            'movement_type' => $movementType,
            'warehouse_id' => $warehouseId,
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'stock_status' => $stockStatus,
            'quantity' => $quantity,
            'qty_before' => $before,
            'qty_delta' => $sign * $quantity,
            'qty_after' => $after,
            'stock_document_id' => $sourceType === 'STOCK_DOCUMENT' ? $sourceId : null,
            'stock_document_line_id' => $sourceType === 'STOCK_DOCUMENT' ? $sourceLineId : null,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_line_id' => $sourceLineId,
            'item_id' => $itemId,
            'original_movement_id' => $originalMovementId,
            'correction_chain_id' => $correctionChainId,
            'created_by' => $actorId,
            'request_id' => request()?->attributes->get('request_id'),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        // `mutateBalance()` là điểm chốt DUY NHẤT ghi inventory_balances (post-receipt,
        // post-issue, reversal, component/warranty issue đều đi qua đây) — bump 1
        // version namespace chung ở đây thay vì rải invalidation ra từng controller
        // nghiệp vụ (mục 12). Luôn nằm trong DB::transaction() của caller nên
        // afterCommit() chỉ chạy khi ghi tồn kho commit thật sự thành công.
        DB::afterCommit(fn () => $this->cacheService()->bumpVersion('stock:version'));

        return ['id' => $movementId, 'movement_no' => $movementNo, 'before' => $before, 'after' => $after];
    }

    private function cacheService(): CacheService
    {
        return app(CacheService::class);
    }

    /**
     * Resolve the single item backing a stock_document_line's posted movement, when
     * unambiguous. stock_movements.item_id is 1:1 (see CurrentBaselineReversalService,
     * which always sets it per reversal_line); a document line can carry multiple scanned
     * items when qty_planned > 1 for serial-tracked SKUs, and in that case the aggregate
     * movement genuinely has no single item to attribute — item_id stays null, matching the
     * documented read-side fallback in StockMovementController::traceByPhysicalCode().
     */
    private function resolveLineItemId(string $documentType, string $lineId): ?string
    {
        $itemIds = $documentType === 'receipt'
            ? DB::table('inbound_scan_entries')
                ->where('inbound_line_id', $lineId)
                ->whereNotNull('item_id')
                ->where(function ($query): void {
                    $query->whereNull('status')->orWhere('status', '!=', 'REMOVED');
                })
                ->pluck('item_id')
            : DB::table('outbound_item_matches')
                ->where('outbound_line_id', $lineId)
                ->whereNotNull('item_id')
                ->where(function ($query): void {
                    $query->whereNull('status')->orWhere('status', '!=', 'REMOVED');
                })
                ->pluck('item_id');

        return $itemIds->count() === 1 ? (string) $itemIds->first() : null;
    }

    private function lockDocument(string $documentId, string $documentType): object
    {
        $document = DB::table('stock_documents')
            ->where('id', $documentId)
            ->where('doc_type', $documentType)
            ->lockForUpdate()
            ->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy chứng từ.');
        }

        return $document;
    }

    private function recordDocumentStatus(string $documentId, ?string $from, string $to, string $action, ?string $actorId): void
    {
        if ($actorId === null || ! Schema::hasTable('document_status_history')) {
            return;
        }
        DB::table('document_status_history')->insert([
            'id' => (string) Str::uuid(),
            'stock_document_id' => $documentId,
            'from_status' => $from,
            'to_status' => $to,
            'action' => $action,
            'changed_by' => $actorId,
            'created_at' => now(),
        ]);
    }

    private function recordPostingEvent(
        string $operation,
        string $postingAction,
        string $idempotencyKey,
        string $requestHash,
        string $documentId,
        ?string $movementId,
        ?string $actorId,
        array $snapshot
    ): void {
        if (! Schema::hasTable('posting_event') && ! Schema::hasView('posting_event')) {
            return;
        }
        DB::table('posting_event')->insert([
            'posting_event_id' => (string) Str::uuid(),
            'operation' => $operation,
            // posting_action (ERD bảng 31, varchar(50)) là mã ngắn cố định
            // (POST_RECEIPT/POST_ISSUE/...); không lấy fallback từ $operation vì
            // $operation nhúng document UUID nên có thể vượt 50 ký tự (bug thật gây
            // SQLSTATE 22001 khi reverse trên PostgreSQL — SQLite không enforce length
            // nên không lộ ra; xem WWHN-190/191 evidence + migration 2026_08_18_150000).
            'posting_action' => $postingAction,
            'idempotency_key' => $idempotencyKey,
            // ERD bảng 31: request_hash char(64) — chuẩn hóa composite v2|md5 về sha256 digest.
            'request_hash' => self::postingRequestDigest($requestHash),
            'status' => 'COMMITTED',
            'document_id' => $documentId,
            'movement_id' => $movementId,
            'response_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
    }

    /**
     * posting_events.request_hash là char(64) (ERD bảng 31); IdempotencyStore
     * trả composite "v2:<sha256>|md5:<md5>" — lấy đúng sha256 digest 64 ký tự,
     * fallback hash lại cho định dạng lạ.
     */
    public static function postingRequestDigest(string $requestHash): string
    {
        if (preg_match('/^v2:([0-9a-f]{64})\|/', $requestHash, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^[0-9a-f]{64}$/', $requestHash) === 1) {
            return $requestHash;
        }

        return hash('sha256', $requestHash);
    }
}
