<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\CurrentBaselineStockService;

/**
 * Alignment 2026-08-15:
 * Scan -> aggregate -> preview/print/apply ONE Packing Label per outbound document -> Post Issue.
 * RFID is optional for PRODUCT outbound and never blocks Post Issue.
 */
class AlignedOutboundFlowController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CurrentBaselineStockService $stock,
        private readonly CurrentBaselineStockFlowController $legacyFlow,
    ) {}

    public function outboundScan(Request $request, string $id)
    {
        $payload = $this->scanPayload($request);
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $payload, $expectedVersion): array {
            $document = $this->lockDocument($id);
            $this->assertVersion($document, $expectedVersion);
            if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được scan.');
            }
            if ($document->outbound_request_id === null) {
                ApiError::abort(422, 'OUTBOUND_REQUEST_REQUIRED', 'Outbound phải được sinh từ toa/request nguồn.');
            }

            $line = DB::table('stock_document_lines')
                ->where('id', $payload['line_id'])
                ->where('stock_document_id', $id)
                ->first();
            if ($line === null) {
                ApiError::abort(404, 'DOCUMENT_LINE_NOT_FOUND', 'Không tìm thấy line trong phiếu xuất.');
            }
            $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
            if ($sku === null || strtoupper((string) $sku->sku_type) !== 'PRODUCT') {
                ApiError::abort(422, 'PRODUCT_SKU_REQUIRED', 'Outbound sản phẩm chỉ nhận SKU loại PRODUCT.');
            }

            $resolvedItemId = $this->resolveItemId($payload);
            if ($resolvedItemId !== null) {
                $this->assertNoDuplicateItemInDocument($id, $resolvedItemId);
            }

            $result = $this->stock->scanStockDocument($id, 'issue', $payload, $this->actorId());
            $newVersion = $expectedVersion + 1;
            DB::table('stock_documents')->where('id', $id)->update([
                'version' => $newVersion,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);
            $this->attachScanEventToEvidence($id, (string) $line->id, $result['item_id'] ?? null);

            $result['version'] = $newVersion;
            $result['label_snapshot_id'] = null;
            $result['label_status'] = 'NOT_CREATED';
            $result['packing_label_ready'] = $this->isFullScan($id);
            $result['rfid_required'] = false;

            return $result;
        }, 3);

        return $this->respondSuccess(
            $result,
            $result['packing_label_ready']
                ? 'Scan đủ toàn bộ phiếu. Có thể tạo/preview/in 01 Packing Label cho lô; stock_effect=NONE.'
                : 'Item đã match. Tiếp tục scan; chưa tạo nhãn và chưa trừ tồn.'
        )->header('ETag', '"'.((int) $result['version']).'"');
    }

    public function outboundUnmatch(Request $request, string $id)
    {
        if (DB::table('label_snapshots')->where('outbound_document_id', $id)->exists()) {
            ApiError::abort(
                409,
                'PACKING_LABEL_ALREADY_CREATED',
                'Đã tạo Packing Label cho lô. Không được thay đổi match sau khi snapshot nhãn được tạo; hãy Cancel phiếu và tạo lại nếu dữ liệu lô sai.'
            );
        }

        return $this->legacyFlow->outboundUnmatch($request, $id);
    }

    public function storePackingLabel(Request $request)
    {
        $validated = $request->validate([
            'outbound_document_id' => ['required', 'uuid', 'exists:stock_documents,id'],
            'customer_phone' => ['required', 'string', 'min:6', 'max:50'],
        ]);
        $documentId = (string) $validated['outbound_document_id'];

        $existing = DB::table('label_snapshots')->where('outbound_document_id', $documentId)->first();
        if ($existing !== null) {
            return $this->respondSuccess($this->labelPayload((string) $existing->id), 'Packing Label đã tồn tại; trả lại snapshot hiện hành.');
        }

        $snapshotId = DB::transaction(function () use ($documentId, $validated): string {
            $document = $this->lockDocument($documentId);
            if ((string) $document->status !== 'SCANNING') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ phiếu SCANNING mới được tạo Packing Label.');
            }
            if (! $this->isFullScan($documentId)) {
                ApiError::abort(422, 'FULL_SCAN_REQUIRED_FOR_LABEL', 'Chỉ tạo Packing Label sau khi mọi line đã scan đủ chính xác.');
            }

            $existing = DB::table('label_snapshots')->where('outbound_document_id', $documentId)->lockForUpdate()->first();
            if ($existing !== null) {
                return (string) $existing->id;
            }

            $lines = DB::table('stock_document_lines as l')
                ->leftJoin('sku as s', 's.sku_id', '=', 'l.product_id')
                ->where('l.stock_document_id', $documentId)
                ->orderBy('l.line_no')
                ->get(['l.product_id', 'l.qty_planned', 'l.scanned_qty', 's.sku_code', 's.sku_name']);
            $activeMatches = DB::table('outbound_item_matches as m')
                ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
                ->where('l.stock_document_id', $documentId)
                ->where(function ($query): void {
                    $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
                })
                ->get(['m.quantity', 'm.item_id']);

            $snapshotId = (string) Str::uuid();
            $labelCode = 'PL-'.(string) $document->doc_no;
            $payload = [
                'label_code' => $labelCode,
                'outbound_no' => (string) $document->doc_no,
                'outbound_document_id' => $documentId,
                'recipient_name' => (string) ($document->recipient_name ?? ''),
                'recipient_address' => (string) ($document->recipient_address ?? ''),
                'customer_phone' => trim((string) $validated['customer_phone']),
                'item_count' => (int) $activeMatches->sum('quantity'),
                'sku_count' => $lines->pluck('product_id')->unique()->count(),
                'sku_summary' => $lines->map(fn (object $line): array => [
                    'sku_id' => (string) $line->product_id,
                    'sku_code' => (string) ($line->sku_code ?? ''),
                    'sku_name' => (string) ($line->sku_name ?? ''),
                    'quantity' => (int) $line->qty_planned,
                ])->values()->all(),
                'created_at' => now()->toIso8601String(),
            ];

            DB::table('label_snapshots')->insert([
                'id' => $snapshotId,
                'outbound_document_id' => $documentId,
                'outbound_item_match_id' => null,
                'sku_id' => null,
                'item_id' => null,
                'label_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'status' => 'PENDING_PRINT',
                'applied_at' => null,
                'applied_by' => null,
                'version' => 1,
                'created_at' => now(),
                'created_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);

            AuditLogger::record(
                action: 'PACKING_LABEL_CREATED',
                objectType: 'label_snapshot',
                objectId: $snapshotId,
                actorId: $this->actorId(),
                requestId: request()?->attributes->get('request_id'),
                afterData: [
                    'outbound_document_id' => $documentId,
                    'label_code' => $labelCode,
                    'label_cardinality' => 'ONE_PER_OUTBOUND_DOCUMENT',
                    'item_count' => (int) $activeMatches->sum('quantity'),
                    'sku_count' => $lines->pluck('product_id')->unique()->count(),
                ],
            );

            return $snapshotId;
        }, 3);

        return $this->respondCreated($this->labelPayload($snapshotId), 'Đã tạo 01 Packing Label bất biến cho phiếu/lô xuất; chưa trừ tồn.');
    }

    public function applyPackingLabel(Request $request, string $id)
    {
        $snapshot = DB::table('label_snapshots')->where('id', $id)->lockForUpdate()->first();
        if ($snapshot === null) {
            ApiError::abort(404, 'LABEL_NOT_FOUND', 'Không tìm thấy Packing Label.');
        }
        if (empty($snapshot->outbound_document_id)) {
            ApiError::abort(409, 'LEGACY_ITEM_LABEL_NOT_APPLICABLE', 'Snapshot nhãn item legacy không thể dùng làm Packing Label của lô.');
        }
        if ((string) $snapshot->status !== 'PRINTED') {
            ApiError::abort(422, 'LABEL_NOT_PRINTED', 'Chỉ xác nhận dán nhãn sau khi Packing Label đã PRINTED.');
        }

        $document = $this->lockDocument((string) $snapshot->outbound_document_id);
        if ((string) $document->status !== 'SCANNING') {
            ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ phiếu SCANNING mới được xác nhận dán Packing Label.');
        }

        if ($snapshot->applied_at === null) {
            DB::table('label_snapshots')->where('id', $id)->update([
                'applied_at' => now(),
                'applied_by' => $this->actorId(),
                'version' => ((int) ($snapshot->version ?? 0)) + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            AuditLogger::record(
                action: 'PACKING_LABEL_APPLIED',
                objectType: 'label_snapshot',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                afterData: ['outbound_document_id' => (string) $snapshot->outbound_document_id, 'applied' => true],
            );
        }

        return $this->respondSuccess($this->labelPayload($id), 'Đã xác nhận dán Packing Label; stock_effect=NONE.');
    }

    public function postIssue(Request $request, string $id)
    {
        $expectedVersion = $this->expectedVersion($request);
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            'post_issue_'.$id,
            $this->actorId(),
            $hash,
            function () use ($id, $expectedVersion, $key, $hash): array {
                $result = DB::transaction(function () use ($id, $expectedVersion, $key, $hash): array {
                    $document = $this->lockDocument($id);
                    $this->assertVersion($document, $expectedVersion);
                    if ((string) $document->status !== 'SCANNING') {
                        ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ SCANNING mới được Post Issue.');
                    }
                    if ($document->outbound_request_id === null) {
                        ApiError::abort(422, 'OUTBOUND_REQUEST_REQUIRED', 'Outbound phải liên kết toa/request nguồn.');
                    }
                    if (! $this->isFullScan($id)) {
                        ApiError::abort(422, 'FULL_POST_REQUIRED', 'Mọi line phải scan đủ chính xác trước Post Issue.');
                    }

                    $label = DB::table('label_snapshots')->where('outbound_document_id', $id)->lockForUpdate()->first();
                    if ($label === null) {
                        ApiError::abort(422, 'PACKING_LABEL_REQUIRED', 'Phải tạo đúng 01 Packing Label cho lô trước Post Issue.');
                    }
                    if ((string) $label->status !== 'PRINTED') {
                        ApiError::abort(422, 'LABEL_NOT_PRINTED', 'Packing Label phải PRINTED trước Post Issue.');
                    }
                    if ($label->applied_at === null) {
                        ApiError::abort(422, 'PACKING_LABEL_NOT_APPLIED', 'Phải xác nhận đã dán Packing Label trước Post Issue.');
                    }

                    DB::table('stock_documents')->where('id', $id)->update([
                        'version' => $expectedVersion + 1,
                        'updated_by' => $this->actorId(),
                        'updated_at' => now(),
                    ]);
                    $posted = $this->stock->postStockDocument($id, 'issue', $this->actorId(), $key, $hash);
                    $posted['version'] = $expectedVersion + 1;
                    $posted['packing_label_id'] = (string) $label->id;
                    $posted['packing_label_applied'] = true;
                    $posted['rfid_required'] = false;

                    return $posted;
                }, 3);

                return [200, $this->envelope('Post Issue thành công; tồn được trừ tại bước hoàn tất này.', $result)];
            }
        );

        $response = response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
        $version = data_get($body, 'data.version');
        if ($version !== null) {
            $response->header('ETag', '"'.((int) $version).'"');
        }

        return $response;
    }

    private function isFullScan(string $documentId): bool
    {
        $lines = DB::table('stock_document_lines')->where('stock_document_id', $documentId)->get(['qty_planned', 'scanned_qty']);

        return $lines->isNotEmpty() && $lines->every(fn (object $line): bool => (int) $line->qty_planned > 0 && (int) $line->qty_planned === (int) $line->scanned_qty);
    }

    private function labelPayload(string $id): array
    {
        $snapshot = DB::table('label_snapshots')->where('id', $id)->first();
        if ($snapshot === null) {
            return [];
        }
        $payload = json_decode((string) $snapshot->label_payload, true) ?: [];

        return [
            ...(array) $snapshot,
            'label_code' => $payload['label_code'] ?? null,
            'outbound_no' => $payload['outbound_no'] ?? null,
            'customer_name' => $payload['recipient_name'] ?? null,
            'customer_address' => $payload['recipient_address'] ?? null,
            'customer_phone' => $payload['customer_phone'] ?? null,
            'item_count' => $payload['item_count'] ?? 0,
            'sku_count' => $payload['sku_count'] ?? 0,
            'sku_summary' => $payload['sku_summary'] ?? [],
            'applied' => $snapshot->applied_at !== null,
        ];
    }

    private function scanPayload(Request $request): array
    {
        $validated = $request->validate([
            'line_id' => ['required', 'uuid'],
            'quantity' => ['required_without:qty', 'integer', 'min:1'],
            'qty' => ['required_without:quantity', 'integer', 'min:1'],
            'code_value' => ['nullable', 'string', 'max:200'],
            'item_id' => ['nullable', 'uuid'],
            'container_id' => ['nullable', 'uuid'],
            // accepted only for backward-compatible clients; printing is deliberately deferred
            // until the whole outbound document has been scanned and aggregated.
            'printer_config_id' => ['nullable', 'uuid', 'exists:printer_configs,id'],
        ]);
        $validated['quantity'] = (int) ($request->input('quantity') ?? $request->input('qty'));
        unset($validated['printer_config_id']);

        return $validated;
    }

    private function lockDocument(string $id): object
    {
        $document = DB::table('stock_documents')->where('id', $id)->where('doc_type', 'issue')->lockForUpdate()->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu xuất.');
        }

        return $document;
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho scan/post mutation.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên không âm.');
        }

        return (int) $header;
    }

    private function assertVersion(object $document, int $expected): void
    {
        if ((int) ($document->version ?? 0) !== $expected) {
            ApiError::abort(412, 'PRECONDITION_FAILED', 'Document version mismatch.', [
                'expected_version' => $expected,
                'current_version' => (int) ($document->version ?? 0),
            ]);
        }
    }

    private function resolveItemId(array $payload): ?string
    {
        if (! empty($payload['item_id'])) {
            return (string) $payload['item_id'];
        }
        if (empty($payload['code_value'])) {
            return null;
        }
        $physical = DB::table('physical_codes')
            ->where('code_value', strtoupper(trim((string) $payload['code_value'])))
            ->where('status', 'ACTIVE')
            ->first();
        if ($physical === null) {
            return null;
        }
        if (($physical->assigned_object_type ?? null) === 'ITEM' && ! empty($physical->assigned_object_id)) {
            return (string) $physical->assigned_object_id;
        }
        $itemId = DB::table('item_instances')->where('physical_code_id', $physical->physical_code_id)->value('item_id');

        return $itemId ? (string) $itemId : null;
    }

    private function assertNoDuplicateItemInDocument(string $documentId, string $itemId): void
    {
        $exists = DB::table('outbound_item_matches as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.outbound_line_id')
            ->where('l.stock_document_id', $documentId)
            ->where('e.item_id', $itemId)
            ->where(function ($sub): void {
                $sub->whereNull('e.status')->orWhere('e.status', '!=', 'REMOVED');
            })
            ->exists();
        if ($exists) {
            ApiError::abort(409, 'DUPLICATE_ITEM_IN_DOCUMENT', 'Một item chỉ được scan/match một lần trong cùng document.');
        }
    }

    private function attachScanEventToEvidence(string $documentId, string $lineId, mixed $itemId): void
    {
        if (! DB::getSchemaBuilder()->hasColumn('outbound_item_matches', 'scan_event_id')) {
            return;
        }
        $scanEventId = DB::table('scan_event as se')
            ->join('scan_batch as sb', 'sb.scan_batch_id', '=', 'se.scan_batch_id')
            ->where('sb.source_id', $documentId)
            ->when($itemId, fn ($query) => $query->where('se.resolved_object_id', $itemId))
            ->orderByDesc('se.created_at')
            ->value('se.scan_event_id');
        if ($scanEventId === null) {
            return;
        }
        $row = DB::table('outbound_item_matches')
            ->where('outbound_line_id', $lineId)
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->orderByDesc('created_at')
            ->first();
        if ($row !== null) {
            DB::table('outbound_item_matches')->where('id', $row->id)->update(['scan_event_id' => $scanEventId]);
        }
    }

    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'request_id' => request()?->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }

    private function actorId(): string
    {
        $id = auth('api')->id();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Authentication required.');
        }

        return (string) $id;
    }
}
