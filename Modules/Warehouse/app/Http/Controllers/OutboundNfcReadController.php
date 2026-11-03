<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Models\AuditLog;
use Modules\Warehouse\Support\NfcTimestamp;
use OpenApi\Attributes as OA;

/** Read-only NFC projection for the outbound web screen. */
class OutboundNfcReadController extends Controller
{
    #[OA\Get(
        path: '/api/v1/outbound-documents/nfc-items',
        operationId: 'outbound_nfc_items',
        summary: 'Sản phẩm trong phiếu xuất kèm NFC đang hoạt động',
        description: 'Không truyền document_id: chỉ phiếu POSTED. Có document_id: đọc item đã đối chiếu của phiếu DRAFT/SCANNING/POSTED. Không trả item của phiếu CANCELLED. Read-only, stock-neutral.',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'document_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'keyword', in: 'query', description: 'Mã item, serial, SKU hoặc UID NFC đang hoạt động.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'nfc_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ALL', 'ASSIGNED', 'UNASSIGNED'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'data: trang item + NFC; meta.tab_totals tính trên toàn bộ kết quả tìm kiếm trước lọc chip và phân trang.'),
            new OA\Response(response: 403, description: 'Thiếu outbound.view.'),
            new OA\Response(response: 404, description: 'Phiếu không thuộc phạm vi kho.'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'document_id' => ['nullable', 'uuid'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'nfc_status' => ['nullable', Rule::in(['ALL', 'ASSIGNED', 'UNASSIGNED'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $documentId = $input['document_id'] ?? null;
        if ($documentId !== null) {
            $this->requireDocument($request, $documentId);
        }

        $query = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->join('item_instances as i', 'i.item_id', '=', 'm.item_id')
            ->join('sku as s', 's.sku_id', '=', 'i.sku_id')
            ->where('d.doc_type', 'issue')
            ->whereIn('m.status', ['ACTIVE', 'POSTED']);
        $this->scopeDocuments($query, $request);
        if ($documentId === null) {
            $query->where('d.status', 'POSTED');
        } else {
            $query->where('d.id', $documentId)->whereIn('d.status', ['DRAFT', 'SCANNING', 'POSTED']);
        }
        $keyword = trim((string) ($input['keyword'] ?? ''));
        if ($keyword !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($keyword)).'%';
            $query->where(function (Builder $search) use ($pattern): void {
                foreach (['i.item_code', 'i.serial_number', 's.sku_code', 's.sku_name'] as $field) {
                    $search->orWhereRaw("LOWER({$field}) LIKE ? ESCAPE '!'", [$pattern]);
                }
                $search->orWhereExists(fn (Builder $tag): Builder => $this->activeTag($tag)
                    ->whereRaw("LOWER(pc.hardware_uid) LIKE ? ESCAPE '!'", [$pattern]));
            });
        }
        $all = (clone $query)->count();
        $assigned = (clone $query)->whereExists(fn (Builder $tag): Builder => $this->activeTag($tag))->count();
        $status = $input['nfc_status'] ?? 'ALL';
        if ($status === 'ASSIGNED') {
            $query->whereExists(fn (Builder $tag): Builder => $this->activeTag($tag));
        } elseif ($status === 'UNASSIGNED') {
            $query->whereNotExists(fn (Builder $tag): Builder => $this->activeTag($tag));
        }
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 20);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('d.created_at')->orderBy('m.id')
            ->forPage($page, $perPage)
            ->get([
                'd.id as document_id', 'd.doc_no as document_no', 'd.status as document_status',
                'd.src_warehouse_id', 'm.item_id', 'i.item_code', 'i.serial_number',
                's.sku_code', 's.sku_name',
            ]);
        $itemIds = $rows->pluck('item_id')->unique()->values()->all();
        $tags = $itemIds === [] ? collect() : DB::table('physical_codes as pc')
            ->leftJoin('app_user as u', 'u.user_id', '=', 'pc.assigned_by')
            ->where('pc.carrier_type', 'NFC')->where('pc.status', 'ACTIVE')
            ->where(function (Builder $tag) use ($itemIds): void {
                $tag->whereIn('pc.item_id', $itemIds)->orWhereIn('pc.assigned_object_id', $itemIds);
            })
            ->orderByDesc('pc.assigned_at')->orderByDesc('pc.created_at')
            ->get(['pc.physical_code_id', 'pc.item_id', 'pc.assigned_object_id', 'pc.nfc_warehouse_id', 'pc.hardware_uid', 'pc.assigned_at', 'pc.created_at', 'u.display_name as actor_name']);
        $data = $rows->map(function (object $row) use ($tags): array {
            $tag = $tags->first(fn (object $candidate): bool =>
                ((string) ($candidate->item_id ?? $candidate->assigned_object_id) === (string) $row->item_id)
                && ($candidate->nfc_warehouse_id === null || (string) $candidate->nfc_warehouse_id === (string) $row->src_warehouse_id));

            return [
                'document_id' => (string) $row->document_id,
                'document_no' => (string) $row->document_no,
                'document_status' => (string) $row->document_status,
                'item_id' => (string) $row->item_id,
                'item_code' => $row->item_code,
                'serial_number' => $row->serial_number,
                'sku_code' => (string) $row->sku_code,
                'sku_name' => $row->sku_name,
                'nfc_status' => $tag === null ? 'UNASSIGNED' : 'ASSIGNED',
                'nfc' => $tag === null ? null : [
                    'physical_code_id' => (string) $tag->physical_code_id,
                    'hardware_uid' => $tag->hardware_uid,
                    'assigned_at' => NfcTimestamp::read($tag->assigned_at ?? $tag->created_at),
                    'actor_name' => $tag->actor_name,
                ],
            ];
        })->all();

        return response()->json([
            'success' => true, 'data' => $data,
            'meta' => [
                'current_page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'tab_totals' => ['ALL' => $all, 'ASSIGNED' => $assigned, 'UNASSIGNED' => $all - $assigned],
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/outbound-documents/{id}/items/{itemId}/nfc-history',
        operationId: 'outbound_item_nfc_history',
        summary: 'Lịch sử NFC của sản phẩm trong phiếu xuất',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [new OA\Response(response: 200, description: 'Sự kiện gắn/thu hồi qua mọi chip từng liên kết item.')],
    )]
    public function history(Request $request, string $id, string $itemId): JsonResponse
    {
        $input = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $document = $this->requireDocument($request, $id);
        if (! in_array($document->status, ['DRAFT', 'SCANNING', 'POSTED'], true)) {
            abort(404);
        }
        if (! Str::isUuid($itemId) || ! DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->where('l.stock_document_id', $id)->where('m.item_id', $itemId)
            ->whereIn('m.status', ['ACTIVE', 'POSTED'])->exists()) {
            abort(404);
        }
        $tagIds = DB::table('physical_codes as pc')
            ->where('pc.carrier_type', 'NFC')
            ->where(function (Builder $tag) use ($itemId): void {
                $tag->where('pc.item_id', $itemId)->orWhere('pc.assigned_object_id', $itemId);
            })
            ->where(function (Builder $tag) use ($document): void {
                $tag->whereNull('pc.nfc_warehouse_id')->orWhere('pc.nfc_warehouse_id', $document->src_warehouse_id);
            })->pluck('pc.physical_code_id')->all();
        $perPage = (int) ($input['per_page'] ?? 20);
        $page = (int) ($input['page'] ?? 1);
        $events = DB::table((new AuditLog)->getTable().' as a')
            ->join('physical_codes as pc', 'pc.physical_code_id', '=', 'a.object_id')
            ->leftJoin('app_user as u', 'u.user_id', '=', 'a.actor_id')
            ->where('a.object_type', 'physical_code')->whereIn('a.object_id', $tagIds)
            ->whereIn('a.action', ['ASSIGN_ITEM_NFC', 'REVOKE_ITEM_NFC'])
            ->orderByDesc('a.created_at')->forPage($page, $perPage)
            ->get(['a.id', 'a.action', 'a.result', 'a.reason', 'a.created_at', 'u.display_name as actor_name', 'pc.hardware_uid']);
        $total = DB::table((new AuditLog)->getTable().' as a')
            ->where('a.object_type', 'physical_code')->whereIn('a.object_id', $tagIds)
            ->whereIn('a.action', ['ASSIGN_ITEM_NFC', 'REVOKE_ITEM_NFC'])->count();

        return response()->json([
            'success' => true,
            'data' => $events->map(fn (object $event): array => [
                'id' => (string) $event->id, 'action' => (string) $event->action,
                'result' => $event->result, 'reason' => $event->reason,
                'actor_name' => $event->actor_name, 'hardware_uid' => $event->hardware_uid,
                'created_at' => NfcTimestamp::read($event->created_at),
            ])->all(),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ]);
    }

    private function activeTag(Builder $query): Builder
    {
        return $query->selectRaw('1')->from('physical_codes as pc')
            ->where('pc.carrier_type', 'NFC')->where('pc.status', 'ACTIVE')
            ->where(function (Builder $tag): void {
                $tag->whereColumn('pc.item_id', 'm.item_id')->orWhereColumn('pc.assigned_object_id', 'm.item_id');
            })
            ->where(function (Builder $tag): void {
                $tag->whereNull('pc.nfc_warehouse_id')->orWhereColumn('pc.nfc_warehouse_id', 'd.src_warehouse_id');
            });
    }

    private function scopeDocuments(Builder $query, Request $request): void
    {
        $user = $request->user();
        if ($user !== null && method_exists($user, 'warehouseScopeIds')) {
            $ids = $user->warehouseScopeIds();
            if (is_array($ids)) {
                $query->whereIn('d.src_warehouse_id', $ids);
            }
        }
    }

    private function requireDocument(Request $request, string $id): object
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $query = DB::table('stock_documents as d')->where('d.id', $id)->where('d.doc_type', 'issue');
        $this->scopeDocuments($query, $request);
        return $query->first(['d.id', 'd.src_warehouse_id', 'd.status']) ?? abort(404);
    }
}
