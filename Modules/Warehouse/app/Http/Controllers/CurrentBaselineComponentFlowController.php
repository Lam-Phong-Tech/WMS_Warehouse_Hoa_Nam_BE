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
use Modules\Warehouse\Services\ComponentContainerSubledgerService;
use Modules\Warehouse\Services\CurrentBaselineStockService;

class CurrentBaselineComponentFlowController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CurrentBaselineStockService $stock,
        private readonly ComponentContainerSubledgerService $containers,
    ) {}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'component_issue_no' => ['nullable', 'string', 'max:80', 'unique:component_issue_documents,component_issue_no'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'warranty_case_id' => ['required', 'uuid', 'exists:warranty_case,warranty_case_id'],
            'document_date' => ['required', 'date'],
            'purpose' => ['nullable', 'in:WARRANTY'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'lines.*.required_qty' => ['required', 'integer', 'min:1'],
        ]);

        $id = DB::transaction(function () use ($validated, $request): string {
            $warehouse = DB::table('warehouses')->where('id', $validated['warehouse_id'])->lockForUpdate()->first();
            if ($warehouse === null || strtoupper((string) $warehouse->status) !== 'ACTIVE') {
                ApiError::abort(422, 'WAREHOUSE_NOT_ACTIVE', 'Component Issue yêu cầu warehouse ACTIVE.');
            }
            $case = DB::table('warranty_case')->where('warranty_case_id', $validated['warranty_case_id'])->lockForUpdate()->first();
            if ($case === null || in_array($case->status, ['RETURNED', 'CANCELLED'], true)) {
                ApiError::abort(422, 'WARRANTY_CASE_NOT_ACTIVE', 'Component Issue yêu cầu warranty case active.');
            }

            $documentId = (string) Str::uuid();
            DB::table('component_issue_documents')->insert([
                'id' => $documentId,
                'component_issue_no' => $validated['component_issue_no'] ?? ('CID-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4))),
                'warehouse_id' => $validated['warehouse_id'],
                'warranty_case_id' => $validated['warranty_case_id'],
                'document_date' => $validated['document_date'],
                'purpose' => 'WARRANTY',
                'status' => 'DRAFT',
                'created_at' => now(),
                'created_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
                'version' => 1,
            ]);

            foreach (array_values($validated['lines']) as $index => $line) {
                $sku = DB::table('sku')->where('sku_id', $line['sku_id'])->first();
                if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE' || strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
                    ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'Component Issue chỉ nhận SKU COMPONENT ACTIVE.');
                }
                DB::table('component_issue_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'component_issue_document_id' => $documentId,
                    'line_no' => $index + 1,
                    'sku_id' => $line['sku_id'],
                    'required_qty' => (int) $line['required_qty'],
                    'scanned_qty' => 0,
                    'created_at' => now(),
                    'created_by' => $this->actorId(),
                    'updated_at' => now(),
                    'updated_by' => $this->actorId(),
                    'version' => 1,
                ]);
            }

            AuditLogger::record(
                action: 'CREATE_COMPONENT_ISSUE',
                objectType: 'component_issue_document',
                objectId: $documentId,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                afterData: ['status' => 'DRAFT', 'purpose' => 'WARRANTY', 'version' => 1],
            );

            return $documentId;
        }, 3);

        return $this->respondCreated($this->payload($id), 'Component Issue DRAFT created; stock_effect=NONE.');
    }

    public function scan(Request $request, string $id)
    {
        $validated = $request->validate([
            'line_id' => ['required', 'uuid'],
            'item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
            'container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $validated, $expectedVersion): array {
            $document = DB::table('component_issue_documents')->where('id', $id)->lockForUpdate()->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy Component Issue.');
            }
            $this->assertVersion($document, $expectedVersion);
            if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được scan Component Issue.');
            }
            $case = DB::table('warranty_case')->where('warranty_case_id', $document->warranty_case_id)->lockForUpdate()->first();
            if ($case === null || in_array($case->status, ['RETURNED', 'CANCELLED'], true)) {
                ApiError::abort(422, 'WARRANTY_CASE_NOT_ACTIVE', 'Warranty case không còn active.');
            }

            $line = DB::table('component_issue_lines')->where('id', $validated['line_id'])->where('component_issue_document_id', $id)->lockForUpdate()->first();
            if ($line === null) {
                ApiError::abort(404, 'COMPONENT_LINE_NOT_FOUND', 'Không tìm thấy Component Issue line.');
            }
            $sku = DB::table('sku')->where('sku_id', $line->sku_id)->first();
            if ($sku === null || strtoupper((string) $sku->sku_type) !== 'COMPONENT' || strtoupper((string) $sku->status) !== 'ACTIVE') {
                ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'Line phải là COMPONENT ACTIVE.');
            }

            $hasItem = ! empty($validated['item_id']);
            $hasContainer = ! empty($validated['container_id']);
            if ($hasItem === $hasContainer) {
                ApiError::abort(422, 'COMPONENT_TARGET_XOR', 'Mỗi scan chọn đúng một item_id hoặc container_id.');
            }
            $quantity = (int) $validated['quantity'];
            $after = (int) $line->scanned_qty + $quantity;
            if ($after > (int) $line->required_qty) {
                ApiError::abort(422, 'SCAN_EXCEEDS_REQUIRED', 'Component scan vượt required_qty.');
            }

            if ($hasItem) {
                if ($quantity !== 1) {
                    ApiError::abort(422, 'SERIAL_QUANTITY_MUST_BE_ONE', 'Serial component quantity phải = 1.');
                }
                $item = DB::table('item_instances')->where('item_id', $validated['item_id'])->lockForUpdate()->first();
                if ($item === null || (string) $item->sku_id !== (string) $line->sku_id || $item->item_status !== 'IN_STOCK') {
                    ApiError::abort(422, 'COMPONENT_ITEM_NOT_AVAILABLE', 'Component item sai SKU hoặc không IN_STOCK.');
                }
                if (DB::table('component_issue_items as ci')
                    ->join('component_issue_lines as cl', 'cl.id', '=', 'ci.component_issue_line_id')
                    ->where('cl.component_issue_document_id', $id)
                    ->where('ci.item_id', $validated['item_id'])
                    ->exists()) {
                    ApiError::abort(409, 'DUPLICATE_COMPONENT_ITEM', 'Item đã được scan trong Component Issue này.');
                }
            } else {
                $this->containers->assertContainerAvailable((string) $validated['container_id'], (string) $line->sku_id, $quantity);
            }

            DB::table('component_issue_items')->insert([
                'id' => (string) Str::uuid(),
                'component_issue_line_id' => $line->id,
                'item_id' => $validated['item_id'] ?? null,
                'container_id' => $validated['container_id'] ?? null,
                'quantity' => $quantity,
                'created_at' => now(),
                'created_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
                'version' => 1,
            ]);
            DB::table('component_issue_lines')->where('id', $line->id)->update([
                'scanned_qty' => $after,
                'version' => ((int) $line->version) + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            DB::table('component_issue_documents')->where('id', $id)->update([
                'status' => 'SCANNING',
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);

            return [
                'component_issue_document_id' => $id,
                'line_id' => (string) $line->id,
                'required_qty' => (int) $line->required_qty,
                'scanned_qty' => $after,
                'status' => 'SCANNING',
                'version' => $expectedVersion + 1,
                'stock_effect' => 'NONE',
            ];
        }, 3);

        return $this->respondSuccess($result, 'Component scan validated; stock_effect=NONE.');
    }

    public function cancel(Request $request, string $id)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $expectedVersion = $this->expectedVersion($request);
        $result = DB::transaction(function () use ($id, $validated, $expectedVersion): array {
            $document = DB::table('component_issue_documents')->where('id', $id)->lockForUpdate()->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy Component Issue.');
            }
            $this->assertVersion($document, $expectedVersion);
            if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được Cancel.');
            }
            DB::table('component_issue_documents')->where('id', $id)->update([
                'status' => 'CANCELLED',
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            AuditLogger::record(
                action: 'CANCEL_COMPONENT_ISSUE',
                objectType: 'component_issue_document',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: request()?->attributes->get('request_id'),
                reason: $validated['reason'],
                beforeData: ['status' => $document->status, 'version' => $expectedVersion],
                afterData: ['status' => 'CANCELLED', 'version' => $expectedVersion + 1],
            );
            return ['component_issue_document_id' => $id, 'status' => 'CANCELLED', 'version' => $expectedVersion + 1, 'stock_effect' => 'NONE'];
        }, 3);

        return $this->respondSuccess($result, 'Component Issue cancelled.');
    }

    public function post(Request $request, string $id)
    {
        $expectedVersion = $this->expectedVersion($request);
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);
        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            "post_component_issue_{$id}",
            $this->actorId(),
            $hash,
            function () use ($id, $expectedVersion, $key, $hash): array {
                $result = DB::transaction(function () use ($id, $expectedVersion, $key, $hash): array {
                    $document = DB::table('component_issue_documents')->where('id', $id)->lockForUpdate()->first();
                    if ($document === null) {
                        ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy Component Issue.');
                    }
                    $this->assertVersion($document, $expectedVersion);
                    if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
                        ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được Post.');
                    }
                    $case = DB::table('warranty_case')->where('warranty_case_id', $document->warranty_case_id)->lockForUpdate()->first();
                    if ($case === null || in_array($case->status, ['RETURNED', 'CANCELLED'], true)) {
                        ApiError::abort(422, 'WARRANTY_CASE_NOT_ACTIVE', 'Warranty case không còn active tại thời điểm Post.');
                    }
                    foreach (DB::table('component_issue_lines')->where('component_issue_document_id', $id)->lockForUpdate()->get() as $line) {
                        if ((int) $line->scanned_qty !== (int) $line->required_qty) {
                            ApiError::abort(422, 'FULL_POST_REQUIRED', 'Mọi Component line phải scanned_qty = required_qty.');
                        }
                    }

                    $this->containers->consumeDocument($id);
                    // CurrentBaselineStockService re-validates all stock invariants and
                    // expects DRAFT. The temporary DRAFT write is private to this outer
                    // transaction; external state remains SCANNING until final POSTED.
                    DB::table('component_issue_documents')->where('id', $id)->update([
                        'status' => 'DRAFT',
                        'version' => $expectedVersion + 1,
                        'updated_at' => now(),
                        'updated_by' => $this->actorId(),
                    ]);
                    $posted = $this->stock->postComponentIssue($id, $this->actorId(), $key, $hash);
                    $posted['version'] = $expectedVersion + 1;
                    return $posted;
                }, 3);
                return [200, $this->envelope('Component Issue posted.', $result)];
            }
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function payload(string $id): array
    {
        $lines = DB::table('component_issue_lines')->where('component_issue_document_id', $id)->orderBy('line_no')->get();
        return [
            'document' => DB::table('component_issue_documents')->where('id', $id)->first(),
            'lines' => $lines->map(fn (object $line): array => [
                ...(array) $line,
                'items' => DB::table('component_issue_items')->where('component_issue_line_id', $line->id)->get()->map(fn (object $row): array => (array) $row)->all(),
            ])->all(),
        ];
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho Component Issue mutation.');
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
        if ((int) $document->version !== $expected) {
            ApiError::abort(412, 'PRECONDITION_FAILED', 'Component Issue version mismatch.', [
                'expected_version' => $expected,
                'current_version' => (int) $document->version,
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
