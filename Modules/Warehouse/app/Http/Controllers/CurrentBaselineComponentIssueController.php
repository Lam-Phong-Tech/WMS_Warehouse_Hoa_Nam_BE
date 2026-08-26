<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\IdempotentEnvelope;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\ComponentContainerSubledgerService;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Modules\Warehouse\Support\Concerns\ResolvesActorOrNull;

class CurrentBaselineComponentIssueController extends Controller
{
    use ApiResponse;
    use ResolvesActorOrNull { actorIdOrNull as actorId; }

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
            'lines.*.items' => ['required', 'array', 'min:1'],
            'lines.*.items.*.item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
            'lines.*.items.*.container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'lines.*.items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $id = DB::transaction(function () use ($validated, $request): string {
            $warehouse = DB::table('warehouses')->where('id', $validated['warehouse_id'])->lockForUpdate()->first();
            if ($warehouse === null || strtoupper((string) $warehouse->status) !== 'ACTIVE') {
                ApiError::abort(422, 'WAREHOUSE_NOT_ACTIVE', 'Component Issue yêu cầu warehouse ACTIVE.');
            }

            $case = DB::table('warranty_case')->where('warranty_case_id', $validated['warranty_case_id'])->lockForUpdate()->first();
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
                    ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'Component Issue chỉ chấp nhận SKU COMPONENT ACTIVE.');
                }

                $scanned = collect($line['items'])->sum(fn (array $item): int => (int) $item['quantity']);
                if ($scanned !== (int) $line['required_qty']) {
                    ApiError::abort(422, 'FULL_POST_REQUIRED', 'Tổng component item quantity phải bằng required_qty.', [
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
                    $hasItem = ! empty($item['item_id']);
                    $hasContainer = ! empty($item['container_id']);
                    if ($hasItem === $hasContainer) {
                        ApiError::abort(422, 'COMPONENT_TARGET_XOR', 'Mỗi component item phải chọn đúng một item_id hoặc container_id.');
                    }

                    if ($hasItem) {
                        $instance = DB::table('item_instances')->where('item_id', $item['item_id'])->lockForUpdate()->first();
                        if ($instance === null || (string) $instance->sku_id !== (string) $line['sku_id'] || $instance->item_status !== 'IN_STOCK') {
                            ApiError::abort(422, 'COMPONENT_ITEM_NOT_AVAILABLE', 'Serial component item không thuộc SKU hoặc không IN_STOCK.');
                        }
                        if ((int) $item['quantity'] !== 1) {
                            ApiError::abort(422, 'SERIAL_QUANTITY_MUST_BE_ONE', 'Serial component item bắt buộc quantity=1.');
                        }
                    } else {
                        $this->containers->assertContainerAvailable((string) $item['container_id'], (string) $line['sku_id'], (int) $item['quantity']);
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
                requestId: $request->attributes->get('request_id'),
                afterData: ['status' => 'DRAFT', 'purpose' => 'WARRANTY', 'stock_effect' => 'NONE'],
            );

            return $documentId;
        }, 3);

        return $this->respondCreated($this->payload($id), 'Đã tạo phiếu xuất linh kiện; tồn kho chưa thay đổi (stock_effect=NONE).');
    }

    public function post(Request $request, string $id)
    {
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);
        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            "post_component_issue_{$id}",
            $this->actorId(),
            $hash,
            function () use ($id, $key, $hash): array {
                // IdempotencyStore holds the outer transaction. Container subledger,
                // inventory balance, movement, item state and document state therefore
                // commit or rollback together.
                $this->containers->consumeDocument($id);
                $result = $this->stock->postComponentIssue($id, $this->actorId(), $key, $hash);

                return [200, $this->envelope('Component Issue posted.', $result)];
            }
        );

        // WWHN-234 F-09: Component Issue Post là action đổi tồn, cùng hợp đồng replay.
        $body = IdempotentEnvelope::withReplayFlag($body, $replay);

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function payload(string $id): array
    {
        $lines = DB::table('component_issue_lines')->where('component_issue_document_id', $id)->orderBy('line_no')->get();

        return [
            'document' => DB::table('component_issue_documents')->where('id', $id)->first(),
            'lines' => $lines->map(fn (object $line): array => [
                ...(array) $line,
                'items' => DB::table('component_issue_items')->where('component_issue_line_id', $line->id)->get(),
            ])->all(),
        ];
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

}
