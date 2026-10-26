<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\Database\SchemaCache;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\Validation\InputLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Events\StockDocumentCreated;
use Modules\Warehouse\Support\Concerns\ResolvesActorOrNull;

/**
 * Mini App adapter for creating an outbound DRAFT without going through Web WMS.
 *
 * The public Mini App action is direct, but the server still materializes the
 * canonical source chain atomically:
 * mini-app source import -> outbound_request -> request lines -> issue DRAFT -> document lines.
 * This preserves the invariant required by the canonical scan/post services:
 * every outbound stock_document has an outbound_request_id.
 *
 * Creation is stock-neutral. Inventory can only decrease later at Post Issue.
 */
class MiniAppOutboundDraftController extends Controller
{
    use CachesReadPayload;
    use ResolvesActorOrNull;

    private function actorId(): string
    {
        $id = $this->actorIdOrNull();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Chưa xác thực người dùng Mini App.');
        }

        return $id;
    }

    public function store(Request $request): JsonResponse
    {
        // Các field text tự do sau sẽ đi vào json_encode(..., JSON_THROW_ON_ERROR)
        // để tính source_checksum (xem createDraft()). 'string' của Laravel chỉ
        // kiểm tra is_string(), không kiểm tra encoding — byte không hợp lệ UTF-8
        // (vd người dùng dán từ nguồn encoding khác) qua được validate rồi làm
        // json_encode ném JsonException không ai bắt => 500 thay vì 422. Chặn
        // ngay tại validation, trước khi chạm business logic.
        $utf8Rule = function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $fail('Trường :attribute chứa ký tự không hợp lệ (không phải UTF-8).');
            }
        };

        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid'],
            'name' => ['nullable', 'string', 'max:200', $utf8Rule],
            'request_no' => ['nullable', 'string', 'max:80', $utf8Rule],
            'request_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.InputLimits::DATE_MIN, 'before_or_equal:'.InputLimits::pastDateMax()],
            'recipient_name' => ['required', 'string', 'max:200', $utf8Rule],
            'recipient_address' => ['nullable', 'string', 'max:1000', $utf8Rule],
            'recipient_contact_phone' => ['nullable', 'string', 'max:50', 'regex:/^0\d{9}$/'],
            'note' => ['nullable', 'string', 'max:1000', $utf8Rule],
            'expected_total_qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.sku_id' => ['nullable', 'uuid'],
            'lines.*.sku_code' => ['nullable', 'string', 'max:100', $utf8Rule],
            'lines.*.required_qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'lines.*.note' => ['nullable', 'string', 'max:2000', $utf8Rule],
        ], [
            'recipient_contact_phone.regex' => 'Số điện thoại phải gồm đúng 10 chữ số và bắt đầu bằng số 0.',
        ]);

        $actorId = $this->actorId();
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            'mini_app_outbound_create',
            $actorId,
            $hash,
            function () use ($validated, $actorId, $key): array {
                $data = DB::transaction(fn (): array => $this->createDraft($validated, $actorId, $key), 3);

                return [201, $this->envelope(
                    $data,
                    'Đã tạo phiếu xuất nháp từ Mini App; chưa quét hàng và chưa trừ tồn.'
                )];
            }
        );

        $version = (int) data_get($body, 'data.version', 1);

        return response()->json($body, $code)
            ->header('ETag', '"'.$version.'"')
            ->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function createDraft(array $payload, string $actorId, string $idempotencyKey): array
    {
        $warehouseId = (string) $payload['warehouse_id'];
        $warehouse = DB::table('warehouses')
            ->where('id', $warehouseId)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->first();
        if ($warehouse === null) {
            ApiError::abort(422, 'WAREHOUSE_NOT_ACTIVE', 'Kho nguồn không tồn tại hoặc không ACTIVE.');
        }

        // MVP Hoa Nam operates exactly one active warehouse. Keep the same guard
        // as canonical outbound-request Confirm so Mini App cannot create a second
        // warehouse interpretation of the outbound flow.
        if (DB::table('warehouses')->where('status', 'ACTIVE')->count() !== 1) {
            ApiError::abort(
                422,
                'ACTIVE_WAREHOUSE_BASELINE_VIOLATION',
                'MVP yêu cầu chính xác một kho Hoa Nam ACTIVE trước khi tạo phiếu xuất.'
            );
        }

        $resolvedLines = $this->resolveAndGroupLines((array) $payload['lines']);
        $totalQty = array_sum(array_column($resolvedLines, 'required_qty'));
        if ($totalQty !== (int) $payload['expected_total_qty']) {
            ApiError::abort(422, 'OUTBOUND_EXPECTED_QTY_MISMATCH', 'Tổng số lượng các dòng không khớp expected_total_qty.', [
                'expected_total_qty' => (int) $payload['expected_total_qty'],
                'lines_total_qty' => $totalQty,
            ]);
        }

        $requestNo = trim((string) ($payload['request_no'] ?? ''));
        if ($requestNo === '') {
            $requestNo = 'MA-OUT-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
        }
        if (DB::table('outbound_requests')->where('request_no', $requestNo)->exists()) {
            ApiError::abort(409, 'DUPLICATE_OUTBOUND_REQUEST_NO', 'Số yêu cầu xuất đã tồn tại.', [
                'request_no' => $requestNo,
            ]);
        }

        $recipientName = trim((string) $payload['recipient_name']);
        $recipientAddress = trim((string) ($payload['recipient_address'] ?? ''));
        $recipientAddress = $recipientAddress === '' ? null : $recipientAddress;
        $recipientPhone = trim((string) ($payload['recipient_contact_phone'] ?? ''));
        $recipientPhone = $recipientPhone === '' ? null : $recipientPhone;
        $requestDate = (string) ($payload['request_date'] ?? now()->toDateString());
        $name = trim((string) ($payload['name'] ?? ''));
        $note = trim((string) ($payload['note'] ?? ''));
        $documentNote = $note !== '' ? $note : ($name !== '' ? $name : null);

        // A Mini App direct create still gets an internal source-import record so
        // request/import traceability remains compatible with the canonical model.
        $importId = (string) Str::uuid();
        $sourceChecksum = hash('sha256', json_encode([
            'actor_id' => $actorId,
            'idempotency_key' => $idempotencyKey,
            'warehouse_id' => $warehouseId,
            'request_no' => $requestNo,
            'lines' => $resolvedLines,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $sourceKey = 'mini-app/outbound/'.IdempotencyStore::businessKey(
            $idempotencyKey,
            'mini_app_outbound_create',
            $actorId
        );

        $importRow = [
            'id' => $importId,
            'import_no' => 'MA-IMP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
            'source_file_name' => 'mini-app-direct.json',
            'source_key' => $sourceKey,
            'source_checksum' => $sourceChecksum,
            'status' => 'CONSUMED',
            'total_rows' => count($resolvedLines),
            'valid_rows' => count($resolvedLines),
            'error_rows' => 0,
            'validated_at' => now(),
            'consumed_at' => now(),
            'consumed_by' => $actorId,
            'version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (SchemaCache::hasColumn('outbound_request_imports', 'uploaded_at')) {
            $importRow['uploaded_at'] = now();
            $importRow['uploaded_by'] = $actorId;
        }
        DB::table('outbound_request_imports')->insert($importRow);

        $requestId = (string) Str::uuid();
        $requestInsert = [
            'id' => $requestId,
            'request_no' => $requestNo,
            'import_id' => $importId,
            'recipient_name' => $recipientName,
            'recipient_address' => $recipientAddress,
            'request_date' => $requestDate,
            'status' => 'OPEN',
            'source_checksum' => $sourceChecksum,
            'version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (SchemaCache::hasColumn('outbound_requests', 'customer_name')) {
            $requestInsert['customer_name'] = $recipientName;
            $requestInsert['customer_address'] = $recipientAddress;
            $requestInsert['customer_phone'] = $recipientPhone;
        }
        DB::table('outbound_requests')->insert($requestInsert);

        $requestLines = [];
        foreach ($resolvedLines as $index => $line) {
            $requestLineId = (string) Str::uuid();
            DB::table('outbound_request_lines')->insert([
                'id' => $requestLineId,
                'outbound_request_id' => $requestId,
                'line_no' => $index + 1,
                'sku_id' => $line['sku_id'],
                'required_qty' => $line['required_qty'],
                'note' => $line['note'],
                'version' => 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $requestLines[] = [$requestLineId, $line];
        }

        $documentId = (string) Str::uuid();
        $docNo = 'PX-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
        $documentCreatedAt = now();
        $documentInsert = [
            'id' => $documentId,
            'doc_type' => 'issue',
            'doc_no' => $docNo,
            'doc_date' => $requestDate,
            'status' => 'DRAFT',
            'src_warehouse_id' => $warehouseId,
            'dst_warehouse_id' => null,
            'outbound_request_id' => $requestId,
            'recipient_name' => $recipientName,
            'recipient_address' => $recipientAddress,
            'recipient_contact_phone' => $recipientPhone,
            'note' => $documentNote,
            'create_idempotency_key' => 'ma-out-'.substr(hash('sha256', $actorId.'|'.$idempotencyKey), 0, 48),
            'version' => 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => $documentCreatedAt,
            'updated_at' => $documentCreatedAt,
        ];
        DB::table('stock_documents')->insert($documentInsert);
        event(new StockDocumentCreated($documentId, $docNo, 'issue', $warehouseId, $actorId));

        $responseLines = [];
        foreach ($requestLines as $index => [$requestLineId, $line]) {
            $documentLineId = (string) Str::uuid();
            DB::table('stock_document_lines')->insert([
                'id' => $documentLineId,
                'stock_document_id' => $documentId,
                'outbound_request_line_id' => $requestLineId,
                'line_no' => $index + 1,
                'product_id' => $line['sku_id'],
                'qty_planned' => $line['required_qty'],
                'qty_actual' => $line['required_qty'],
                'scanned_qty' => 0,
                'note' => $line['note'],
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $responseLines[] = [
                'line_id' => $documentLineId,
                'sku_id' => $line['sku_id'],
                'sku_code' => $line['sku_code'],
                'sku_name' => $line['sku_name'],
                'required_qty' => $line['required_qty'],
                'scanned_qty' => 0,
            ];
        }

        // Canonical lifecycle: once a DRAFT issue exists for the request, the
        // request itself is CONSUMED. Canceling the DRAFT later can reopen it.
        DB::table('outbound_requests')->where('id', $requestId)->update([
            'status' => 'CONSUMED',
            'version' => 2,
            'updated_by' => $actorId,
            'updated_at' => now(),
        ]);

        AuditLogger::record(
            action: 'MINI_APP_OUTBOUND_DRAFT_CREATED',
            objectType: 'stock_document',
            objectId: $documentId,
            actorId: $actorId,
            requestId: request()?->attributes->get('request_id'),
            afterData: [
                'doc_no' => $docNo,
                'status' => 'DRAFT',
                'outbound_request_id' => $requestId,
                'expected_total_qty' => $totalQty,
                'stock_effect' => 'NONE',
                'source' => 'MINI_APP',
            ],
        );
        // Tạo toa + phiếu xuất DRAFT là stock-neutral nhưng đã hiện ngay trong read
        // cache danh sách toa/phiếu xuất; không bump là màn hình vẫn trống hết TTL.
        DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));

        return [
            'document_id' => $documentId,
            'doc_no' => $docNo,
            'outbound_request_id' => $requestId,
            'request_no' => $requestNo,
            'source_import_id' => $importId,
            'name' => $name !== '' ? $name : null,
            'status' => 'DRAFT',
            'mini_app_status' => 'PICKING',
            'draft' => [
                'expires_at' => $documentCreatedAt->copy()->addWeek()->toIso8601String(),
                'is_expired' => false,
                'retention_days' => 7,
            ],
            'warehouse' => [
                'id' => (string) $warehouse->id,
                'code' => (string) $warehouse->code,
                'name' => (string) $warehouse->name,
                'status' => (string) $warehouse->status,
                'is_suspended' => false,
                'suspension_reason' => $warehouse->suspension_reason ?? null,
            ],
            'can_continue' => true,
            'continue_from' => 'PICKING',
            'version' => 1,
            'expected_total_qty' => $totalQty,
            'scanned_total_qty' => 0,
            'remaining_qty' => $totalQty,
            'progress_percent' => 0,
            'ready_for_issue' => false,
            'stock_effect' => 'NONE',
            'movement_created' => false,
            'lines' => $responseLines,
        ];
    }

    /**
     * Resolve every line to one ACTIVE PRODUCT SKU and aggregate duplicate SKU
     * rows so scan auto-match has one unambiguous planned quantity per SKU.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<int,array{sku_id:string,sku_code:string,sku_name:string,required_qty:int,note:?string}>
     */
    private function resolveAndGroupLines(array $lines): array
    {
        $grouped = [];

        foreach ($lines as $index => $line) {
            $skuIdInput = trim((string) ($line['sku_id'] ?? ''));
            $skuCodeInput = strtoupper(trim((string) ($line['sku_code'] ?? '')));
            if ($skuIdInput === '' && $skuCodeInput === '') {
                ApiError::abort(422, 'PRODUCT_SKU_REQUIRED', 'Mỗi dòng phải có sku_id hoặc sku_code.', [
                    'line_index' => $index,
                ]);
            }

            $skuById = $skuIdInput !== '' ? DB::table('sku')->where('sku_id', $skuIdInput)->first() : null;
            $skuByCode = $skuCodeInput !== ''
                ? (DB::table('sku')->where('sku_code', $skuCodeInput)->first()
                    ?? DB::table('sku')->whereRaw('UPPER(sku_code) = ?', [$skuCodeInput])->first())
                : null;

            if ($skuIdInput !== '' && $skuById === null) {
                ApiError::abort(422, 'SKU_NOT_FOUND', 'sku_id không tồn tại.', ['line_index' => $index]);
            }
            if ($skuCodeInput !== '' && $skuByCode === null) {
                ApiError::abort(422, 'SKU_NOT_FOUND', 'sku_code không tồn tại.', [
                    'line_index' => $index,
                    'sku_code' => $skuCodeInput,
                ]);
            }
            if ($skuById !== null && $skuByCode !== null && (string) $skuById->sku_id !== (string) $skuByCode->sku_id) {
                ApiError::abort(422, 'WRONG_SKU', 'sku_id và sku_code không trỏ cùng một SKU.', [
                    'line_index' => $index,
                ]);
            }

            $sku = $skuById ?? $skuByCode;
            if ($sku === null
                || strtoupper((string) $sku->status) !== 'ACTIVE'
                || strtoupper((string) $sku->sku_type) !== 'PRODUCT') {
                ApiError::abort(422, 'PRODUCT_SKU_REQUIRED', 'Phiếu xuất Mini App chỉ nhận SKU PRODUCT ACTIVE.', [
                    'line_index' => $index,
                    'sku_code' => $sku?->sku_code,
                ]);
            }

            $skuId = (string) $sku->sku_id;
            $qty = (int) $line['required_qty'];
            $lineNote = trim((string) ($line['note'] ?? ''));
            if (! isset($grouped[$skuId])) {
                $grouped[$skuId] = [
                    'sku_id' => $skuId,
                    'sku_code' => (string) $sku->sku_code,
                    'sku_name' => (string) ($sku->sku_name ?? $sku->name ?? $sku->sku_code),
                    'required_qty' => 0,
                    'note' => $lineNote !== '' ? $lineNote : null,
                ];
            }
            $grouped[$skuId]['required_qty'] += $qty;
        }

        return array_values($grouped);
    }

    /** @param array<string,mixed> $data */
    private function envelope(array $data, string $message): array
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
}
