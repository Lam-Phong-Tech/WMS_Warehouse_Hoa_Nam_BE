<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\CurrentBaselineReversalService;
use Modules\Warehouse\Services\CurrentBaselineStockService;

class CurrentBaselineStockFlowController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CurrentBaselineStockService $stock,
        private readonly CurrentBaselineReversalService $reversal,
    ) {}

    public function inboundScan(Request $request, string $id)
    {
        $payload = $this->scanPayload($request);
        $expectedVersion = $this->expectedVersion($request);
        $result = $this->versionedScan($id, 'receipt', $payload, $expectedVersion);
        return $this->respondSuccess($result, 'Inbound scan validated; stock_effect=NONE.');
    }

    public function outboundScan(Request $request, string $id)
    {
        $payload = $this->scanPayload($request);
        $expectedVersion = $this->expectedVersion($request);
        $result = $this->versionedScan($id, 'issue', $payload, $expectedVersion);

        if (! empty($result['item_id'])) {
            $result += $this->createAndDispatchLabel($request, $id, (string) $result['line_id'], (string) $result['item_id']);
        }

        return $this->respondSuccess($result, 'Outbound item matched; label result is included; stock_effect=NONE.');
    }

    public function postReceipt(Request $request, string $id)
    {
        return $this->postDocument($request, $id, 'receipt');
    }

    public function postIssue(Request $request, string $id)
    {
        return $this->postDocument($request, $id, 'issue');
    }

    public function reverseInbound(Request $request, string $id)
    {
        return $this->reverse($request, $id, 'receipt');
    }

    public function reverseOutbound(Request $request, string $id)
    {
        return $this->reverse($request, $id, 'issue');
    }

    private function versionedScan(string $documentId, string $type, array $payload, int $expectedVersion): array
    {
        return DB::transaction(function () use ($documentId, $type, $payload, $expectedVersion): array {
            $document = $this->lockDocument($documentId, $type);
            $this->assertVersion($document, $expectedVersion);
            if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được scan.');
            }

            $line = DB::table('stock_document_lines')->where('id', $payload['line_id'])->where('stock_document_id', $documentId)->first();
            if ($line === null) {
                ApiError::abort(404, 'DOCUMENT_LINE_NOT_FOUND', 'Không tìm thấy line trong document.');
            }
            $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
            if ($type === 'issue' && ($sku === null || strtoupper((string) $sku->sku_type) !== 'PRODUCT')) {
                ApiError::abort(422, 'PRODUCT_SKU_REQUIRED', 'Outbound sản phẩm không được scan COMPONENT; linh kiện chỉ xuất qua Component Issue WARRANTY.');
            }
            if ($type === 'issue' && $document->outbound_request_id === null) {
                ApiError::abort(422, 'OUTBOUND_REQUEST_REQUIRED', 'Outbound phải được sinh từ toa/request đã consume theo BP-WMS-04.');
            }

            $resolvedItemId = $this->resolveItemId($payload);
            if ($resolvedItemId !== null) {
                $this->assertNoDuplicateItemInDocument($documentId, $type, $resolvedItemId);
            }

            $result = $this->stock->scanStockDocument($documentId, $type, $payload, $this->actorId());
            $newVersion = $expectedVersion + 1;
            DB::table('stock_documents')->where('id', $documentId)->update([
                'version' => $newVersion,
                'updated_by' => $this->actorId(),
                'updated_at' => now(),
            ]);
            $this->attachScanEventToEvidence($documentId, $type, (string) $line->id, $result['item_id'] ?? null);
            $result['version'] = $newVersion;

            return $result;
        }, 3);
    }

    private function postDocument(Request $request, string $id, string $type)
    {
        $expectedVersion = $this->expectedVersion($request);
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);
        $operation = ($type === 'receipt' ? 'post_receipt_' : 'post_issue_').$id;

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            $operation,
            $this->actorId(),
            $hash,
            function () use ($id, $type, $expectedVersion, $key, $hash): array {
                $result = DB::transaction(function () use ($id, $type, $expectedVersion, $key, $hash): array {
                    $document = $this->lockDocument($id, $type);
                    $this->assertVersion($document, $expectedVersion);
                    if ($document->status !== 'SCANNING') {
                        ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ SCANNING mới được Post.');
                    }
                    if ($type === 'issue') {
                        if ($document->outbound_request_id === null) {
                            ApiError::abort(422, 'OUTBOUND_REQUEST_REQUIRED', 'Outbound phải liên kết toa/request nguồn.');
                        }
                        $this->assertLabelsPrinted($id);
                    }

                    // Increment while document is still mutable. PostgreSQL protects a
                    // POSTED row from every later UPDATE, so the version bump must be
                    // part of the same outer transaction before the status becomes POSTED.
                    DB::table('stock_documents')->where('id', $id)->update([
                        'version' => $expectedVersion + 1,
                        'updated_by' => $this->actorId(),
                        'updated_at' => now(),
                    ]);

                    $posted = $this->stock->postStockDocument($id, $type, $this->actorId(), $key, $hash);
                    $posted['version'] = $expectedVersion + 1;
                    return $posted;
                }, 3);

                return [200, $this->envelope($type === 'receipt' ? 'Post Receipt thành công.' : 'Post Issue thành công.', $result)];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function reverse(Request $request, string $id, string $type)
    {
        $payload = $request->validate([
            'reversal_document_id' => ['nullable', 'uuid'],
            'reason_code' => ['nullable', 'string', 'max:80'],
            'reason' => ['required_without:reversal_document_id', 'string', 'max:2000'],
            'condition_status' => [$type === 'issue' ? 'required_without:reversal_document_id' : 'nullable', 'in:SELLABLE,DEFECTIVE,NON_SELLABLE'],
            'physical_return_confirmed' => ['nullable', 'boolean'],
            'lines' => ['required_without:reversal_document_id', 'array', 'min:1'],
            'lines.*.movement_id' => ['required_with:lines', 'uuid'],
            'lines.*.quantity' => ['required_with:lines', 'integer', 'min:1'],
            'lines.*.item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
        ]);
        $expectedVersion = $this->expectedVersion($request);
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);
        $operation = 'reverse_stock_document_'.$id.'_'.($payload['reversal_document_id'] ?? 'new');

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            $operation,
            $this->actorId(),
            $hash,
            function () use ($id, $type, $payload, $key, $hash, $expectedVersion): array {
                $result = $this->reversal->execute($id, $type, $payload, $this->actorId(), $key, $hash, $expectedVersion);
                return [200, $this->envelope('Reversal command processed; original document remains POSTED.', $result)];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function createAndDispatchLabel(Request $request, string $documentId, string $lineId, string $itemId): array
    {
        $match = DB::table('outbound_item_matches')->where('outbound_line_id', $lineId)->where('item_id', $itemId)->orderByDesc('created_at')->first();
        if ($match === null) {
            ApiError::abort(500, 'OUTBOUND_MATCH_MISSING', 'Scan đã thành công nhưng không tìm thấy match để tạo label snapshot.');
        }

        $snapshot = DB::table('label_snapshots')->where('outbound_item_match_id', $match->id)->first();
        if ($snapshot === null) {
            $document = DB::table('stock_documents')->where('id', $documentId)->first();
            $line = DB::table('stock_document_lines')->where('id', $lineId)->first();
            $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
            $snapshotId = (string) Str::uuid();
            DB::table('label_snapshots')->insert([
                'id' => $snapshotId,
                'outbound_item_match_id' => $match->id,
                'sku_id' => $line->product_id,
                'item_id' => $itemId,
                'label_payload' => json_encode([
                    'outbound_no' => $document->doc_no,
                    'recipient_name' => $document->recipient_name,
                    'recipient_address' => $document->recipient_address,
                    'sku_code' => $sku?->sku_code,
                    'sku_name' => $sku?->sku_name,
                    'item_id' => $itemId,
                ], JSON_UNESCAPED_UNICODE),
                'status' => 'PENDING_PRINT',
                'version' => 1,
                'created_at' => now(),
                'created_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            $snapshot = DB::table('label_snapshots')->where('id', $snapshotId)->first();
        }

        if ($snapshot->status === 'PRINTED') {
            return ['label_snapshot_id' => (string) $snapshot->id, 'label_status' => 'PRINTED'];
        }

        $printerId = $request->input('printer_config_id');
        $printerQuery = DB::table('printer_configs')->where('status', 'ACTIVE');
        $printer = $printerId ? $printerQuery->where('id', $printerId)->first() : $printerQuery->orderBy('printer_code')->first();
        if ($printer === null || empty($printer->agent_endpoint)) {
            return $this->recordPrintResult($snapshot, $printer?->id, 'PRINT_FAILED', 'Không có printer agent ACTIVE/configured.');
        }

        $status = 'PRINT_FAILED';
        $error = null;
        try {
            $response = Http::acceptJson()->timeout(2)->post((string) $printer->agent_endpoint, [
                'printer_code' => $printer->printer_code,
                'label_size' => $printer->label_size,
                'label' => json_decode((string) $snapshot->label_payload, true),
                'request_id' => $request->attributes->get('request_id'),
                'reprint' => false,
            ]);
            if ($response->successful() && (bool) $response->json('success', true)) {
                $status = 'PRINTED';
            } else {
                $error = 'Printer agent rejected command: HTTP '.$response->status();
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return $this->recordPrintResult($snapshot, $printer->id, $status, $error);
    }

    private function recordPrintResult(object $snapshot, ?string $printerId, string $status, ?string $error): array
    {
        $eventId = (string) Str::uuid();
        DB::transaction(function () use ($snapshot, $printerId, $status, $error, $eventId): void {
            DB::table('label_print_events')->insert([
                'id' => $eventId,
                'label_snapshot_id' => $snapshot->id,
                'printer_config_id' => $printerId,
                'status' => $status,
                'error_message' => $error,
                'printed_at' => $status === 'PRINTED' ? now() : null,
                'version' => 1,
                'created_at' => now(),
                'created_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            DB::table('label_snapshots')->where('id', $snapshot->id)->update([
                'status' => $status,
                'version' => ((int) ($snapshot->version ?? 0)) + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
        }, 3);

        AuditLogger::record(
            action: 'LABEL_DIRECT_PRINT',
            objectType: 'label_snapshot',
            objectId: (string) $snapshot->id,
            actorId: $this->actorId(),
            requestId: request()?->attributes->get('request_id'),
            afterData: ['status' => $status, 'printer_config_id' => $printerId, 'error' => $error],
        );

        return [
            'label_snapshot_id' => (string) $snapshot->id,
            'label_print_event_id' => $eventId,
            'label_status' => $status,
            'print_error' => $error,
        ];
    }

    private function assertLabelsPrinted(string $documentId): void
    {
        $matches = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->join('sku as s', 's.sku_id', '=', 'l.product_id')
            ->where('l.stock_document_id', $documentId)
            ->where('s.sku_type', 'PRODUCT')
            ->whereNotNull('m.item_id')
            ->get(['m.id', 'm.item_id']);

        foreach ($matches as $match) {
            $status = DB::table('label_snapshots')->where('outbound_item_match_id', $match->id)->value('status');
            if ($status !== 'PRINTED') {
                ApiError::abort(422, 'LABEL_NOT_PRINTED', 'Mọi PRODUCT item phải có label PRINTED trước Post Issue.', [
                    'item_id' => (string) $match->item_id,
                    'label_status' => $status,
                ]);
            }
        }
    }

    private function assertNoDuplicateItemInDocument(string $documentId, string $type, string $itemId): void
    {
        $table = $type === 'receipt' ? 'inbound_scan_entries' : 'outbound_item_matches';
        $lineColumn = $type === 'receipt' ? 'inbound_line_id' : 'outbound_line_id';
        if (DB::table("{$table} as e")
            ->join('stock_document_lines as l', 'l.id', '=', "e.{$lineColumn}")
            ->where('l.stock_document_id', $documentId)
            ->where('e.item_id', $itemId)
            ->exists()) {
            ApiError::abort(409, 'DUPLICATE_ITEM_IN_DOCUMENT', 'Một item chỉ được scan/match một lần trong cùng document.');
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
        $physical = DB::table('physical_codes')->where('code_value', strtoupper(trim((string) $payload['code_value'])))->where('status', 'ACTIVE')->first();
        if ($physical === null) {
            return null;
        }
        if (($physical->assigned_object_type ?? null) === 'ITEM' && ! empty($physical->assigned_object_id)) {
            return (string) $physical->assigned_object_id;
        }
        $itemId = DB::table('item_instances')->where('physical_code_id', $physical->physical_code_id)->value('item_id');
        return $itemId ? (string) $itemId : null;
    }

    private function attachScanEventToEvidence(string $documentId, string $type, string $lineId, mixed $itemId): void
    {
        if (! DB::getSchemaBuilder()->hasColumn($type === 'receipt' ? 'inbound_scan_entries' : 'outbound_item_matches', 'scan_event_id')) {
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

        $table = $type === 'receipt' ? 'inbound_scan_entries' : 'outbound_item_matches';
        $lineColumn = $type === 'receipt' ? 'inbound_line_id' : 'outbound_line_id';
        $row = DB::table($table)->where($lineColumn, $lineId)->when($itemId, fn ($query) => $query->where('item_id', $itemId))->orderByDesc('created_at')->first();
        if ($row !== null) {
            DB::table($table)->where('id', $row->id)->update(['scan_event_id' => $scanEventId]);
        }
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
            'printer_config_id' => ['nullable', 'uuid', 'exists:printer_configs,id'],
        ]);
        $validated['quantity'] = (int) ($request->input('quantity') ?? $request->input('qty'));
        return $validated;
    }

    private function lockDocument(string $id, string $type): object
    {
        $document = DB::table('stock_documents')->where('id', $id)->where('doc_type', $type)->lockForUpdate()->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy stock document.');
        }
        return $document;
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho scan/post/reversal mutation.');
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

    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => ['request_id' => request()?->attributes->get('request_id'), 'timestamp' => now()->toIso8601String()],
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
