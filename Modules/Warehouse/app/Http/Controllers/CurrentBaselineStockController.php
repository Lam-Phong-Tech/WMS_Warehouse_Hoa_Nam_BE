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

class CurrentBaselineStockController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CurrentBaselineStockService $stock) {}

    public function inboundScan(Request $request, string $id)
    {
        return $this->respondSuccess(
            $this->stock->scanStockDocument($id, 'receipt', $this->scanPayload($request), $this->actorId()),
            'Inbound scan validated.'
        );
    }

    public function outboundScan(Request $request, string $id)
    {
        return $this->respondSuccess(
            $this->stock->scanStockDocument($id, 'issue', $this->scanPayload($request), $this->actorId()),
            'Outbound item matched.'
        );
    }

    public function postReceipt(Request $request, string $id)
    {
        return $this->idempotent($request, "post_receipt_{$id}", function (string $key, string $hash) use ($id): array {
            return [200, $this->envelope(
                'Hạch toán nhập kho thành công.',
                $this->stock->postStockDocument($id, 'receipt', $this->actorId(), $key, $hash)
            )];
        });
    }

    public function postIssue(Request $request, string $id)
    {
        return $this->idempotent($request, "post_issue_{$id}", function (string $key, string $hash) use ($id): array {
            return [200, $this->envelope(
                'Hạch toán xuất kho thành công.',
                $this->stock->postStockDocument($id, 'issue', $this->actorId(), $key, $hash)
            )];
        });
    }

    public function reverseInbound(Request $request, string $id)
    {
        return $this->reverse($request, $id, 'receipt');
    }

    public function reverseOutbound(Request $request, string $id)
    {
        return $this->reverse($request, $id, 'issue');
    }

    public function storeComponentIssueDocument(Request $request)
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
            'lines.*.items' => ['required', 'array', 'min:1'],
            'lines.*.items.*.item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
            'lines.*.items.*.container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'lines.*.items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $id = DB::transaction(function () use ($validated): string {
            $case = DB::table('warranty_case')
                ->where('warranty_case_id', $validated['warranty_case_id'])
                ->lockForUpdate()
                ->first();
            if ($case === null || in_array($case->status, ['RETURNED', 'CANCELLED'], true)) {
                ApiError::abort(422, 'WARRANTY_CASE_NOT_ACTIVE', 'Component Issue yêu cầu warranty case đang active.');
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
                'version' => 0,
            ]);

            foreach (array_values($validated['lines']) as $lineIndex => $line) {
                $sku = DB::table('sku')->where('sku_id', $line['sku_id'])->lockForUpdate()->first();
                if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE' || strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
                    ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'Component Issue chỉ chấp nhận SKU COMPONENT đang ACTIVE.');
                }

                $scanned = collect($line['items'])->sum(fn (array $item): int => (int) $item['quantity']);
                if ($scanned !== (int) $line['required_qty']) {
                    ApiError::abort(422, 'FULL_POST_REQUIRED', 'Tổng quantity của component items phải bằng required_qty trước khi tạo DRAFT sẵn sàng Post.', [
                        'line' => $lineIndex + 1,
                        'required_qty' => (int) $line['required_qty'],
                        'scanned_qty' => $scanned,
                    ]);
                }

                $lineId = (string) Str::uuid();
                DB::table('component_issue_lines')->insert([
                    'id' => $lineId,
                    'component_issue_document_id' => $documentId,
                    'line_no' => $lineIndex + 1,
                    'sku_id' => $line['sku_id'],
                    'required_qty' => (int) $line['required_qty'],
                    'scanned_qty' => $scanned,
                    'created_at' => now(),
                    'created_by' => $this->actorId(),
                    'updated_at' => now(),
                    'updated_by' => $this->actorId(),
                    'version' => 0,
                ]);

                foreach ($line['items'] as $item) {
                    if (empty($item['item_id']) && empty($item['container_id'])) {
                        ApiError::abort(422, 'COMPONENT_ITEM_TARGET_REQUIRED', 'Mỗi component issue item phải chỉ rõ item_id hoặc container_id.');
                    }
                    if (! empty($item['item_id'])) {
                        $instance = DB::table('item_instances')->where('item_id', $item['item_id'])->lockForUpdate()->first();
                        if ($instance === null || (string) $instance->sku_id !== (string) $line['sku_id'] || $instance->item_status !== 'IN_STOCK') {
                            ApiError::abort(422, 'COMPONENT_ITEM_NOT_AVAILABLE', 'Item component không thuộc SKU hoặc không IN_STOCK.');
                        }
                        if ((int) $item['quantity'] !== 1) {
                            ApiError::abort(422, 'SERIAL_QUANTITY_MUST_BE_ONE', 'Component item có item_id chỉ cho phép quantity=1.');
                        }
                    }

                    DB::table('component_issue_items')->insert([
                        'id' => (string) Str::uuid(),
                        'component_issue_line_id' => $lineId,
                        'item_id' => $item['item_id'] ?? null,
                        'container_id' => $item['container_id'] ?? null,
                        'quantity' => (int) $item['quantity'],
                        'created_at' => now(),
                        'created_by' => $this->actorId(),
                        'updated_at' => now(),
                        'updated_by' => $this->actorId(),
                        'version' => 0,
                    ]);
                }
            }

            AuditLogger::record(
                action: 'CREATE_COMPONENT_ISSUE',
                objectType: 'component_issue_document',
                objectId: $documentId,
                actorId: $this->actorId(),
                requestId: request()?->attributes->get('request_id'),
                afterData: ['status' => 'DRAFT', 'purpose' => 'WARRANTY'],
            );

            return $documentId;
        }, 3);

        return $this->respondCreated($this->componentPayload($id), 'Component Issue created; stock_effect=NONE.');
    }

    public function postComponentIssueDocument(Request $request, string $id)
    {
        return $this->idempotent($request, "post_component_issue_{$id}", function (string $key, string $hash) use ($id): array {
            return [200, $this->envelope(
                'Component Issue posted.',
                $this->stock->postComponentIssue($id, $this->actorId(), $key, $hash)
            )];
        });
    }

    private function scanPayload(Request $request): array
    {
        return $request->validate([
            'line_id' => ['required', 'uuid'],
            'quantity' => ['required_without:qty', 'integer', 'min:1'],
            'qty' => ['required_without:quantity', 'integer', 'min:1'],
            'code_value' => ['nullable', 'string', 'max:150'],
            'item_id' => ['nullable', 'uuid'],
            'container_id' => ['nullable', 'uuid'],
        ]) + [
            'quantity' => (int) ($request->input('quantity') ?? $request->input('qty')),
        ];
    }

    private function reverse(Request $request, string $id, string $type)
    {
        $payload = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'condition_status' => ['nullable', 'in:SELLABLE,DEFECTIVE,NON_SELLABLE'],
            'lines' => ['nullable', 'array'],
            'lines.*.movement_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        return $this->idempotent($request, "reverse_stock_document_{$id}", function (string $key, string $hash) use ($id, $type, $payload): array {
            return [200, $this->envelope(
                'Reversal posted; original document remains immutable POSTED.',
                $this->stock->reverseStockDocument($id, $type, $payload, $this->actorId(), $key, $hash)
            )];
        });
    }

    private function componentPayload(string $id): array
    {
        return [
            'document' => DB::table('component_issue_documents')->where('id', $id)->first(),
            'lines' => DB::table('component_issue_lines')->where('component_issue_document_id', $id)->orderBy('line_no')->get(),
        ];
    }

    private function idempotent(Request $request, string $operation, callable $callback)
    {
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);
        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            $operation,
            $this->actorId(),
            $hash,
            fn (): array => $callback($key, $hash)
        );

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
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

    private function actorId(): ?string
    {
        $id = auth('api')->id();
        return $id === null ? null : (string) $id;
    }
}
