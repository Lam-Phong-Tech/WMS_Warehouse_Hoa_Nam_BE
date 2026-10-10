<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\P06ReadSnapshot;
use Modules\Warehouse\Support\P09WarrantyPage;
use Modules\Warehouse\Support\P09WarrantyScope as Scope;
use Throwable;

/** P20 is a read projection of owner documents, never a Post or request receipt. */
final class P20ComponentHistoryController extends Controller
{
    public const CONTRACT = 'p20-component-history-v1';

    public const HEADER = 'X-Component-History-Contract';

    public function index(Request $request, string $id): JsonResponse
    {
        if (! $this->usesContract($request)) {
            return app(MiniAppWarrantyController::class)->componentIssues($request, $id);
        }
        $this->query($request, ['warehouse_id', 'page', 'per_page', 'cursor'], true);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $id): JsonResponse {
            $scope = $this->scope($request);
            $case = Scope::requireCase($scope, $id); // Closed cases remain readable.
            $this->schema('component_issue_documents', ['id', 'warranty_case_id', 'warehouse_id', 'status', 'posted_at', 'created_at', 'version', 'component_issue_no']);
            $query = $this->documents($scope)->where('d.warranty_case_id', $case->warranty_case_id)->where('d.status', 'POSTED');
            // P09 cursors are opaque and scoped. Include the projection and sort
            // in the fingerprint so a legacy created_at cursor cannot be reused.
            $pagingScope = $scope + ['component_history_contract' => self::CONTRACT, 'component_history_sort' => 'posted_at_desc_id_desc'];
            [$ids, $meta] = P09WarrantyPage::read($request, clone $query, $pagingScope, 'd.id', 'd.posted_at');
            $documents = $query->whereIn('d.id', $ids)->get('d.*')->keyBy('id');
            $rows = array_map(function (string $documentId) use ($documents, $case, $scope): array {
                $document = $documents[$documentId];

                return ['component_issue_document_id' => $documentId, 'document_no' => $document->component_issue_no,
                    'warranty_case_id' => $case->warranty_case_id, 'warehouse_id' => $scope['warehouse_id'],
                    'status' => $document->status, 'version' => (int) $document->version,
                    'posted_at' => $this->instant($document->posted_at), 'created_at' => $this->instant($document->created_at),
                    'posted_time_status' => $document->posted_at === null ? 'NOT_RECORDED' : 'SOURCE_TIMESTAMP',
                    'can_open' => true, 'detail_url' => '/api/v1/mini-app/warranty-component-issues/'.$documentId];
            }, $ids);

            return $this->reply($request, $rows, $scope, $snapshot, $meta + [
                'sort' => ['posted_at DESC NULLS LAST', 'id DESC'], 'count_unit' => 'POSTED_DOCUMENT',
                'detail_hydration' => 'OWNER_GET_BY_IMMUTABLE_ID', 'list_has_lines' => false,
            ]);
        });
    }

    /** All Mini App detail reads recheck the link's ACL, even without opt-in. */
    public function show(Request $request, string $id): JsonResponse
    {
        $p20 = $this->usesContract($request);
        $this->query($request, ['warehouse_id'], $p20);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $id, $p20): JsonResponse {
            $scope = $this->scope($request);
            if (! Str::isUuid($id)) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu xuất linh kiện.');
            }
            $document = $this->documents($scope)->where('d.id', strtolower($id))->first('d.*');
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu xuất linh kiện trong kho.');
            }
            Scope::requireCase($scope, (string) $document->warranty_case_id);
            if ($p20 && $document->status !== 'POSTED') {
                ApiError::abort(409, 'COMPONENT_HISTORY_NOT_POSTED', 'Phiếu này chưa thuộc lịch sử xuất đã ghi sổ.');
            }
            $lineQuery = DB::table('component_issue_lines as l')->where('l.component_issue_document_id', $document->id);
            Scope::isolate($lineQuery, 'component_issue_lines', 'l', $scope);
            $lines = $lineQuery->orderBy('l.line_no')->orderBy('l.id')->get('l.*');
            $itemQuery = DB::table('component_issue_items as i')->whereIn('i.component_issue_line_id', $lines->pluck('id'));
            Scope::isolate($itemQuery, 'component_issue_items', 'i', $scope);
            $items = $itemQuery->orderBy('i.created_at')->orderBy('i.id')->get('i.*');
            $skuQuery = DB::table('sku as s')->whereIn('s.sku_id', $lines->pluck('sku_id'));
            Scope::isolate($skuQuery, 'sku', 's', $scope);
            $skus = $skuQuery->get(['s.sku_id', 's.sku_code', 's.sku_name', 's.unit'])->keyBy('sku_id');
            $data = ['document' => $this->redact((array) $document, $scope), 'lines' => [],
                'stock_effect' => $document->status === 'POSTED' ? 'DECREASE' : 'NONE',
                'movement_created' => $document->status === 'POSTED'];
            if (! $p20) {
                foreach ($lines as $line) {
                    $sku = $skus->get($line->sku_id);
                    $data['lines'][] = $this->redact((array) $line, $scope) + [
                        'sku_code' => $sku?->sku_code, 'sku_name' => $sku?->sku_name,
                        'items' => $items->where('component_issue_line_id', $line->id)->map(fn (object $item): array => $this->redact((array) $item, $scope))->values()->all(),
                    ];
                }

                return $this->reply($request, $data, $scope, $snapshot, ['projection' => 'LEGACY_DETAIL_WITH_P09_READ_SCOPE']);
            }
            $this->schema('stock_movements', ['id', 'source_type', 'source_id', 'source_line_id', 'warehouse_id', 'sku_id', 'movement_type', 'qty_delta']);
            $movementQuery = DB::table('stock_movements as m')->where('m.source_type', 'COMPONENT_ISSUE_DOCUMENT')
                ->where('m.source_id', $document->id)->where('m.warehouse_id', $scope['warehouse_id'])->where('m.movement_type', 'COMPONENT_ISSUE');
            Scope::isolate($movementQuery, 'stock_movements', 'm', $scope);
            $movements = $movementQuery->orderBy('m.id')->get('m.*');
            [$events, $scanSource] = $this->events($scope, $document, $items->where('status', 'POSTED')->pluck('scan_event_id')->filter()->unique()->all());
            $statuses = [];
            $total = 0;
            $totalsByUnit = [];
            foreach ($lines as $line) {
                $sku = $skus->get($line->sku_id);
                $lineItems = $items->where('component_issue_line_id', $line->id);
                $postedItems = $lineItems->where('status', 'POSTED');
                $effects = $movements->where('source_line_id', $line->id);
                $quantity = 0;
                $evidenceQuantity = 0;
                $status = $effects->isEmpty() || $postedItems->isEmpty() ? 'INCOMPLETE' : 'CONFIRMED';
                foreach ($effects as $effect) {
                    $delta = $this->positive($effect->qty_delta === null ? null : -(float) $effect->qty_delta);
                    if ($delta === null || $effect->sku_id !== $line->sku_id) {
                        $status = 'MISMATCH';
                    } else {
                        $quantity += $delta;
                    }
                }
                $rows = [];
                foreach ($postedItems as $item) {
                    $qty = $this->positive($item->quantity);
                    $target = ! empty($item->item_id) && empty($item->container_id) ? 'ITEM'
                        : (empty($item->item_id) && ! empty($item->container_id) ? 'BOX' : null);
                    if ($qty === null || $target === null || ($target === 'ITEM' && $qty !== 1)) {
                        $status = 'MISMATCH';
                    } else {
                        $evidenceQuantity += $qty;
                    }
                    $event = $events->get($item->scan_event_id);
                    $eventStatus = $scanSource !== 'AVAILABLE' ? 'UNAVAILABLE' : ($event === null ? 'NOT_RECORDED' : 'RECORDED');
                    if ($event !== null && ($event->resolved_object_type !== ($target === 'ITEM' ? 'ITEM' : 'CONTAINER')
                        || $event->resolved_object_id !== ($item->item_id ?? $item->container_id) || $event->result_status !== 'VALID')) {
                        $event = null;
                        $eventStatus = 'IDENTITY_MISMATCH';
                    }
                    if ($event !== null && (! is_string($event->raw_code) || $event->raw_code === '')) {
                        $eventStatus = 'NOT_RECORDED';
                    }
                    $rows[] = ['id' => $item->id, 'line_id' => $line->id, 'sku_id' => $line->sku_id,
                        'item_id' => $item->item_id, 'container_id' => $item->container_id, 'target_type' => $target,
                        'status' => $item->status, 'quantity' => $qty, 'scan_event_id' => $event?->scan_event_id,
                        'scan_batch_id' => $event?->scan_batch_id, 'raw_code' => $eventStatus === 'RECORDED' ? $event->raw_code : null,
                        'raw_code_status' => $eventStatus, 'scan_result_status' => $event?->result_status,
                        'scanned_at' => $this->instant($event?->created_at)];
                }
                if ($status === 'CONFIRMED' && ($quantity !== $evidenceQuantity
                    || $this->positive($line->scanned_qty) !== $quantity
                    || $lineItems->whereNotIn('status', ['POSTED', 'REMOVED'])->isNotEmpty())) {
                    $status = 'MISMATCH';
                }
                $issued = $status === 'CONFIRMED' ? $quantity : null;
                $statuses[] = $status;
                $total += $issued ?? 0; // Internal only; never returned when a line is unknown.
                if ($issued !== null && $sku?->unit !== null) {
                    $totalsByUnit[$sku->unit] = ($totalsByUnit[$sku->unit] ?? 0) + $issued;
                }
                $data['lines'][] = ['id' => $line->id, 'line_no' => (int) $line->line_no, 'sku_id' => $line->sku_id,
                    'sku_code' => $sku?->sku_code, 'sku_name' => $sku?->sku_name, 'unit' => $sku?->unit,
                    'unit_source' => $sku?->unit === null ? 'NOT_RECORDED' : 'CURRENT_SKU_CATALOG',
                    'required_qty' => $line->required_qty === null ? null : (int) $line->required_qty,
                    'scanned_qty' => $line->scanned_qty === null ? null : (int) $line->scanned_qty,
                    'issued_qty' => $issued, 'quantity_status' => $status,
                    'quantity_basis' => 'NEGATIVE_OWNER_MOVEMENTS_MATCH_POSTED_ITEMS_AND_SCANNED_QTY',
                    'version' => (int) $line->version, 'note' => $scope['pii'] ? $line->note : null,
                    'note_redacted' => ! $scope['pii'], 'items' => $rows,
                    'removed_item_count' => $lineItems->where('status', 'REMOVED')->count(),
                    'posted_item_count' => $postedItems->count(),
                    'scan_event_count' => count(array_unique(array_filter(array_column($rows, 'scan_event_id')))),
                    'movements' => $effects->map(fn (object $m): array => ['movement_id' => $m->id,
                        'line_id' => $m->source_line_id, 'sku_id' => $m->sku_id, 'qty_delta' => $m->qty_delta === null ? null : (int) $m->qty_delta,
                        'occurred_at' => $this->instant($m->occurred_at ?? null)])->values()->all()];
            }
            $complete = $lines->isNotEmpty() && count(array_filter($statuses, static fn (string $s): bool => $s !== 'CONFIRMED')) === 0;
            if ($movements->whereNotIn('source_line_id', $lines->pluck('id')->all())->isNotEmpty()) {
                $complete = false;
                $statuses[] = 'MISMATCH';
            }
            $data['document']['posted_at'] = $this->instant($document->posted_at);
            $data['document']['created_at'] = $this->instant($document->created_at);
            $data['document']['posted_time_status'] = $document->posted_at === null ? 'NOT_RECORDED' : 'SOURCE_TIMESTAMP';
            $data['history'] = ['contract' => self::CONTRACT, 'is_operation_receipt' => false,
                'quantity_total' => $complete ? $total : null,
                'quantity_status' => $complete ? 'CONFIRMED' : (in_array('MISMATCH', $statuses, true) ? 'MISMATCH' : 'INCOMPLETE'),
                'quantity_total_basis' => 'SUM_LINE_NATIVE_ISSUED_QUANTITIES_NOT_ROWS_OR_CODES',
                'totals_by_unit' => $complete ? $totalsByUnit : null,
                'unit_provenance' => 'CURRENT_SKU_CATALOG_NOT_HISTORICAL_UNIT_SNAPSHOT',
                'scan_source_status' => $scanSource, 'read_stock_effect' => 'NONE'];
            $data['movement_created'] = $movements->isNotEmpty();

            return $this->reply($request, $data, $scope, $snapshot, ['projection' => self::CONTRACT]);
        });
    }

    private function events(array $scope, object $document, array $ids): array
    {
        $required = ['scan_event_id', 'scan_batch_id', 'raw_code', 'resolved_object_type', 'resolved_object_id', 'result_status', 'created_at'];
        if (array_diff($required, Schema::getColumnListing('scan_event')) !== []
            || array_diff(['scan_batch_id', 'source_type', 'source_id', 'warehouse_id'], Schema::getColumnListing('scan_batch')) !== []) {
            return [collect(), 'UNAVAILABLE'];
        }
        $q = DB::table('scan_event as e')->join('scan_batch as b', 'b.scan_batch_id', '=', 'e.scan_batch_id')
            ->whereIn('e.scan_event_id', $ids)->where('b.source_type', 'COMPONENT_ISSUE_DOCUMENT')
            ->where('b.source_id', $document->id)->where('b.warehouse_id', $scope['warehouse_id']);
        Scope::isolate($q, 'scan_event', 'e', $scope);
        Scope::isolate($q, 'scan_batch', 'b', $scope);

        return [$q->get(array_map(static fn (string $column): string => 'e.'.$column, $required))->keyBy('scan_event_id'), 'AVAILABLE'];
    }

    private function scope(Request $request): array
    {
        $scope = Scope::resolve($request);
        if (! $scope['documents'] || ! $scope['component']) {
            ApiError::abort(403, 'COMPONENT_ISSUE_READ_FORBIDDEN', 'Cần documents.read và warranty.component_issue để đọc phiếu linh kiện.');
        }

        return $scope;
    }

    private function documents(array $scope): Builder
    {
        $query = DB::table('component_issue_documents as d')->where('d.warehouse_id', $scope['warehouse_id']);

        return Scope::isolate($query, 'component_issue_documents', 'd', $scope);
    }

    private function usesContract(Request $request): bool
    {
        if ($request->hasHeader(self::HEADER) && $request->header(self::HEADER) !== self::CONTRACT) {
            ApiError::abort(422, 'COMPONENT_HISTORY_CONTRACT_INVALID', 'Contract đọc lịch sử linh kiện không được hỗ trợ.');
        }

        return $request->hasHeader(self::HEADER);
    }

    private function query(Request $request, array $allowed, bool $explicitWarehouse): void
    {
        // Some JSON clients serialize an empty GET envelope. It carries no
        // parameters; populated, scalar or malformed bodies are still rejected.
        $emptyBody = in_array(trim((string) $request->getContent()), ['', '[]', '{}'], true);
        if (array_diff(array_keys($request->query()), $allowed) !== [] || ! $emptyBody || $request->allFiles() !== []) {
            ApiError::abort(422, 'COMPONENT_HISTORY_UNKNOWN_FILTER', 'Chỉ gửi query theo contract đọc, không gửi body/status/limit.');
        }
        Validator::make($request->query(), ['warehouse_id' => [$explicitWarehouse ? 'required' : 'sometimes', 'uuid']])->validate();
    }

    private function schema(string $table, array $columns): void
    {
        if (array_diff($columns, Schema::getColumnListing($table)) !== []) {
            ApiError::abort(503, 'COMPONENT_HISTORY_SOURCE_UNAVAILABLE', 'Nguồn lịch sử linh kiện chưa đủ schema để đối chiếu.');
        }
    }

    private function redact(array $row, array $scope): array
    {
        if (! $scope['pii']) {
            foreach (['note', 'cancel_reason', 'created_by', 'updated_by', 'posted_by', 'cancelled_by'] as $key) {
                if (array_key_exists($key, $row)) {
                    $row[$key] = null;
                }
            }
        }

        return $row + ['notes_redacted' => ! $scope['pii']];
    }

    private function positive(mixed $value): ?int
    {
        return is_numeric($value) && (float) $value > 0 && (float) $value < PHP_INT_MAX && floor((float) $value) === (float) $value ? (int) $value : null;
    }

    private function instant(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value, (string) config('app.timezone', 'UTC'))->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function reply(Request $request, mixed $data, array $scope, array $snapshot, array $meta): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data,
            'meta' => Scope::metadata($scope) + ['component_history_contract' => self::CONTRACT,
                'request_id' => $request->attributes->get('request_id'), 'timestamp' => now()->toIso8601String(),
                'snapshot' => $snapshot, 'read_only' => true, 'operation_receipt' => false] + $meta])
            ->header('Cache-Control', 'private, no-store')->header('Vary', self::HEADER);
    }
}
