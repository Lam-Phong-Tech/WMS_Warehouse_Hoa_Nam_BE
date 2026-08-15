<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '24. Putaway', description: 'BP-WMS-03: component BOX -> TRAY, stock-neutral')]
class PutawayController extends Controller
{
    use ApiResponse;

    #[OA\Post(
        path: '/api/v1/inbound-documents/{id}/boxes',
        operationId: 'inbound_register_component_box',
        summary: 'Đăng ký BOX cho dòng nhập COMPONENT trước khi scan',
        security: [['bearerAuth' => []]],
        tags: ['24. Putaway'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['line_id', 'box_code'],
            properties: [
                new OA\Property(property: 'line_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'box_code', type: 'string', example: 'BOX-COMP-0001'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'BOX đã tồn tại và hợp lệ'),
            new OA\Response(response: 201, description: 'Đăng ký BOX thành công'),
            new OA\Response(response: 409, description: 'BOX đang thuộc luồng khác hoặc state conflict'),
            new OA\Response(response: 422, description: 'Dòng không phải COMPONENT / dữ liệu không hợp lệ'),
        ],
    )]
    public function registerInboundBox(Request $request, string $documentId)
    {
        $payload = $request->validate([
            'line_id' => ['required', 'uuid'],
            'box_code' => ['required', 'string', 'max:100'],
        ]);
        $actorId = $this->actorId();

        $result = DB::transaction(function () use ($payload, $documentId, $actorId): array {
            $document = DB::table('stock_documents')
                ->where('id', $documentId)
                ->where('doc_type', 'receipt')
                ->lockForUpdate()
                ->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiếu nhập.');
            }
            if (! in_array((string) $document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ phiếu DRAFT/SCANNING mới được đăng ký BOX.');
            }

            $line = DB::table('stock_document_lines')
                ->where('id', $payload['line_id'])
                ->where('stock_document_id', $documentId)
                ->lockForUpdate()
                ->first();
            if ($line === null) {
                ApiError::abort(404, 'DOCUMENT_LINE_NOT_FOUND', 'Không tìm thấy dòng nhập kho.');
            }

            $sku = DB::table('sku')->where('sku_id', $line->product_id)->first();
            if ($sku === null || strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
                ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'BOX putaway chỉ áp dụng cho SKU COMPONENT.');
            }
            if (strtoupper((string) $sku->status) !== 'ACTIVE') {
                ApiError::abort(422, 'SKU_NOT_ACTIVE', 'SKU COMPONENT phải ACTIVE.');
            }

            $boxCode = strtoupper(trim((string) $payload['box_code']));
            $box = DB::table('containers')->where('container_code', $boxCode)->lockForUpdate()->first();
            if ($box !== null) {
                $this->assertBoxReusable($box, (string) $line->product_id);
                $this->assertBoxNotUsedByOtherActiveReceipt((string) $box->container_id, $documentId);

                return $this->boxResponse($box, false);
            }

            $boxId = (string) Str::uuid();
            DB::table('containers')->insert([
                'container_id' => $boxId,
                'container_code' => $boxCode,
                'container_type' => 'BOX',
                'physical_code_id' => null,
                'primary_sku_id' => $line->product_id,
                'parent_container_id' => null,
                'zone_code' => null,
                'location_hint' => null,
                'status' => 'EMPTY',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $box = DB::table('containers')->where('container_id', $boxId)->first();

            AuditLogger::record(
                action: 'CREATE_RECEIPT_BOX',
                objectType: 'container',
                objectId: $boxId,
                actorId: $actorId,
                requestId: $request->attributes->get('request_id'),
                afterData: (array) $box,
            );

            return $this->boxResponse($box, true);
        }, 3);

        return $result['created']
            ? $this->respondCreated($result, 'Đã đăng ký BOX cho dòng nhập COMPONENT.')
            : $this->respondSuccess($result, 'BOX đã tồn tại và hợp lệ.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/tasks',
        operationId: 'putaway_task_list',
        summary: 'PTW-00 - Danh sách BOX chờ xếp khay và DRAFT đang xử lý',
        security: [['bearerAuth' => []]],
        tags: ['24. Putaway'],
        parameters: [new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Danh sách tác vụ putaway')],
    )]
    public function tasks(Request $request)
    {
        $keyword = trim((string) $request->query('search', $request->query('keyword', '')));
        $boxes = DB::table('containers as c')
            ->leftJoin('sku as s', 's.sku_id', '=', 'c.primary_sku_id')
            ->where('c.container_type', 'BOX')
            ->where(function ($query): void {
                $query->where('c.status', 'RECEIVED_PENDING_PUTAWAY')
                    ->orWhereExists(function ($draft): void {
                        $draft->selectRaw('1')
                            ->from('putaway_events as p')
                            ->whereColumn('p.box_container_id', 'c.container_id')
                            ->where('p.status', 'DRAFT');
                    });
            })
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($nested) use ($keyword): void {
                    $nested->where('c.container_code', 'like', "%{$keyword}%")
                        ->orWhere('s.sku_code', 'like', "%{$keyword}%")
                        ->orWhere('s.sku_name', 'like', "%{$keyword}%");
                });
            })
            ->orderBy('c.container_code')
            ->get([
                'c.container_id', 'c.container_code', 'c.primary_sku_id',
                'c.parent_container_id', 'c.location_hint', 'c.status', 'c.version',
                's.sku_code', 's.sku_name',
            ]);

        $rows = $boxes->map(function ($box): array {
            $receipt = $this->latestPostedReceipt((string) $box->container_id);
            $quantity = (int) DB::table('inbound_scan_entries as e')
                ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
                ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
                ->where('e.container_id', $box->container_id)
                ->where('d.status', 'POSTED')
                ->sum('e.quantity');
            $draft = DB::table('putaway_events')
                ->where('box_container_id', $box->container_id)
                ->where('status', 'DRAFT')
                ->orderByDesc('created_at')
                ->first();
            $targetTray = $draft?->to_tray_id
                ? DB::table('containers')->where('container_id', $draft->to_tray_id)->value('container_code')
                : null;

            return [
                'id' => (string) $box->container_id,
                'box_container_id' => (string) $box->container_id,
                'box_code' => (string) $box->container_code,
                'sku_id' => (string) ($box->primary_sku_id ?? ''),
                'sku_code' => (string) ($box->sku_code ?? ''),
                'sku_name' => (string) ($box->sku_name ?? ''),
                'quantity' => $quantity,
                'receipt_id' => $receipt?->id,
                'receipt_code' => $receipt?->doc_no,
                'warehouse_id' => $receipt?->dst_warehouse_id,
                'status' => (string) $box->status,
                'location_hint' => $box->location_hint,
                'container_version' => (int) $box->version,
                'draft_id' => $draft?->id,
                'draft_version' => $draft ? (int) $draft->version : null,
                'to_tray_id' => $draft?->to_tray_id,
                'to_tray_code' => $targetTray,
            ];
        })->values();

        return $this->respondSuccess($rows, 'Danh sách BOX chờ xếp khay.');
    }

    #[OA\Post(
        path: '/api/v1/putaway/tasks',
        operationId: 'putaway_task_create',
        summary: 'PTW-01 - Tạo putaway DRAFT từ BOX sang TRAY',
        security: [['bearerAuth' => []]],
        tags: ['24. Putaway'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['box_container_id', 'to_tray_id'],
            properties: [
                new OA\Property(property: 'box_container_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'to_tray_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'location_hint', type: 'string', nullable: true),
                new OA\Property(property: 'note', type: 'string', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Tạo DRAFT thành công'),
            new OA\Response(response: 409, description: 'BOX không ở trạng thái putaway hoặc đã có DRAFT'),
            new OA\Response(response: 422, description: 'BOX/TRAY không hợp lệ hoặc tạo cycle'),
        ],
    )]
    public function storeTask(Request $request)
    {
        $payload = $request->validate([
            'box_container_id' => ['required', 'uuid', 'exists:containers,container_id'],
            'to_tray_id' => ['required', 'uuid', 'different:box_container_id', 'exists:containers,container_id'],
            'location_hint' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $actorId = $this->actorId();

        $event = DB::transaction(function () use ($payload, $actorId) {
            $box = DB::table('containers')->where('container_id', $payload['box_container_id'])->lockForUpdate()->first();
            $tray = DB::table('containers')->where('container_id', $payload['to_tray_id'])->lockForUpdate()->first();
            $this->assertBoxPutawayEligible($box);
            $this->assertTrayUsable($tray);
            $this->assertNoCycle((string) $box->container_id, (string) $tray->container_id);

            $existing = DB::table('putaway_events')
                ->where('box_container_id', $box->container_id)
                ->where('status', 'DRAFT')
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                ApiError::abort(409, 'PUTAWAY_DRAFT_EXISTS', 'BOX đã có putaway DRAFT chưa xử lý.', [
                    'putaway_event_id' => (string) $existing->id,
                    'version' => (int) $existing->version,
                ]);
            }

            $eventId = (string) Str::uuid();
            $receipt = $this->latestPostedReceipt((string) $box->container_id);
            DB::table('putaway_events')->insert([
                'id' => $eventId,
                'stock_document_id' => $receipt?->id,
                'warehouse_id' => $receipt?->dst_warehouse_id,
                'container_id' => $box->container_id,
                'box_container_id' => $box->container_id,
                'from_parent_container_id' => $box->parent_container_id,
                'to_parent_container_id' => $tray->container_id,
                'from_tray_id' => $box->parent_container_id,
                'to_tray_id' => $tray->container_id,
                'from_location_hint' => $box->location_hint,
                'to_location_hint' => $payload['location_hint'] ?? $tray->location_hint,
                'location_hint' => $payload['location_hint'] ?? $tray->location_hint,
                'status' => 'DRAFT',
                'confirmed_at' => now(),
                'created_by' => $actorId,
                'confirmed_by' => null,
                'note' => $payload['note'] ?? null,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
                'updated_by' => $actorId,
            ]);

            return DB::table('putaway_events')->where('id', $eventId)->first();
        }, 3);

        AuditLogger::record(
            action: 'PUTAWAY_DRAFT_CREATE',
            objectType: 'putaway_event',
            objectId: (string) $event->id,
            actorId: $actorId,
            requestId: $request->attributes->get('request_id'),
            afterData: (array) $event,
        );

        return $this->respondCreated($this->eventResponse($event), 'Đã tạo tác vụ putaway DRAFT.');
    }

    #[OA\Post(
        path: '/api/v1/putaway/tasks/{id}/confirm',
        operationId: 'putaway_task_confirm',
        summary: 'Xác nhận putaway; cập nhật BOX->TRAY, không tạo stock movement',
        security: [['bearerAuth' => []]],
        tags: ['24. Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CONFIRMED, stock_effect=NONE'),
            new OA\Response(response: 412, description: 'Version conflict'),
        ],
    )]
    public function confirm(Request $request, string $id)
    {
        $expectedVersion = $this->expectedVersion($request);
        $actorId = $this->actorId();

        $result = DB::transaction(function () use ($id, $expectedVersion, $actorId, $request): array {
            $event = DB::table('putaway_events')->where('id', $id)->lockForUpdate()->first();
            if ($event === null) {
                ApiError::abort(404, 'PUTAWAY_NOT_FOUND', 'Không tìm thấy tác vụ putaway.');
            }
            if ((string) $event->status !== 'DRAFT') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ putaway DRAFT mới được xác nhận.');
            }
            $this->assertVersion($event, $expectedVersion);

            $boxId = (string) ($event->box_container_id ?: $event->container_id);
            $trayId = (string) ($event->to_tray_id ?: $event->to_parent_container_id);
            $box = DB::table('containers')->where('container_id', $boxId)->lockForUpdate()->first();
            $tray = DB::table('containers')->where('container_id', $trayId)->lockForUpdate()->first();
            $this->assertBoxPutawayEligible($box, true);
            $this->assertTrayUsable($tray);
            $this->assertNoCycle($boxId, $trayId);

            $movementBefore = DB::table('stock_movements')->count();
            DB::table('containers')->where('container_id', $boxId)->update([
                'parent_container_id' => $trayId,
                'location_hint' => $event->location_hint ?: $event->to_location_hint ?: $tray->location_hint,
                'status' => 'STORED',
                'version' => ((int) $box->version) + 1,
                'updated_at' => now(),
            ]);
            DB::table('putaway_events')->where('id', $id)->update([
                'status' => 'CONFIRMED',
                'confirmed_at' => now(),
                'confirmed_by' => $actorId,
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
                'updated_by' => $actorId,
            ]);
            $movementAfter = DB::table('stock_movements')->count();
            if ($movementAfter !== $movementBefore) {
                throw new \LogicException('Putaway invariant violated: stock movement count changed.');
            }

            $updatedEvent = DB::table('putaway_events')->where('id', $id)->first();
            $updatedBox = DB::table('containers')->where('container_id', $boxId)->first();

            AuditLogger::record(
                action: 'PUTAWAY_CONFIRM',
                objectType: 'putaway_event',
                objectId: $id,
                actorId: $actorId,
                requestId: $request->attributes->get('request_id'),
                beforeData: ['status' => 'DRAFT', 'version' => $expectedVersion],
                afterData: ['status' => 'CONFIRMED', 'version' => $expectedVersion + 1, 'box_container_id' => $updatedBox->container_id],
            );

            return [
                'event' => $updatedEvent,
                'box' => $updatedBox,
            ];
        }, 3);

        return $this->respondSuccess([
            ...$this->eventResponse($result['event']),
            'box_status' => (string) $result['box']->status,
            'box_version' => (int) $result['box']->version,
            'stock_effect' => 'NONE',
            'movement_created' => false,
        ], 'Putaway CONFIRMED; không phát sinh biến động tồn.');
    }

    #[OA\Post(
        path: '/api/v1/putaway/tasks/{id}/cancel',
        operationId: 'putaway_task_cancel',
        summary: 'Hủy putaway DRAFT',
        security: [['bearerAuth' => []]],
        tags: ['24. Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'CANCELLED, stock-neutral')],
    )]
    public function cancel(Request $request, string $id)
    {
        $expectedVersion = $this->expectedVersion($request);
        $actorId = $this->actorId();

        $event = DB::transaction(function () use ($id, $expectedVersion, $actorId, $request) {
            $event = DB::table('putaway_events')->where('id', $id)->lockForUpdate()->first();
            if ($event === null) {
                ApiError::abort(404, 'PUTAWAY_NOT_FOUND', 'Không tìm thấy tác vụ putaway.');
            }
            if ((string) $event->status !== 'DRAFT') {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ putaway DRAFT mới được hủy.');
            }
            $this->assertVersion($event, $expectedVersion);
            DB::table('putaway_events')->where('id', $id)->update([
                'status' => 'CANCELLED',
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
                'updated_by' => $actorId,
            ]);

            $updatedEvent = DB::table('putaway_events')->where('id', $id)->first();

            AuditLogger::record(
                action: 'PUTAWAY_CANCEL',
                objectType: 'putaway_event',
                objectId: $id,
                actorId: $actorId,
                requestId: $request->attributes->get('request_id'),
                beforeData: ['status' => 'DRAFT', 'version' => $expectedVersion],
                afterData: ['status' => 'CANCELLED', 'version' => $expectedVersion + 1],
            );

            return $updatedEvent;
        }, 3);

        return $this->respondSuccess($this->eventResponse($event), 'Đã hủy tác vụ putaway.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/history',
        operationId: 'putaway_history',
        summary: 'Lịch sử putaway CONFIRMED',
        security: [['bearerAuth' => []]],
        tags: ['24. Putaway'],
        responses: [new OA\Response(response: 200, description: 'Lịch sử putaway append-only')],
    )]
    public function history(Request $request)
    {
        $keyword = trim((string) $request->query('search', $request->query('keyword', '')));
        $rows = DB::table('putaway_events as p')
            ->leftJoin('containers as b', 'b.container_id', '=', 'p.box_container_id')
            ->leftJoin('containers as ft', 'ft.container_id', '=', 'p.from_tray_id')
            ->leftJoin('containers as tt', 'tt.container_id', '=', 'p.to_tray_id')
            ->leftJoin('app_user as u', 'u.user_id', '=', 'p.confirmed_by')
            ->where('p.status', 'CONFIRMED')
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($nested) use ($keyword): void {
                    $nested->where('b.container_code', 'like', "%{$keyword}%")
                        ->orWhere('ft.container_code', 'like', "%{$keyword}%")
                        ->orWhere('tt.container_code', 'like', "%{$keyword}%");
                });
            })
            ->orderByDesc('p.confirmed_at')
            ->limit(500)
            ->get([
                'p.id', 'p.box_container_id', 'p.from_tray_id', 'p.to_tray_id',
                'p.location_hint', 'p.note', 'p.confirmed_at', 'p.created_at', 'p.version',
                'b.container_code as box_code', 'ft.container_code as old_tray_code',
                'tt.container_code as new_tray_code', 'u.display_name as actor_name',
            ])
            ->map(fn ($row): array => [
                'id' => (string) $row->id,
                'box_container_id' => (string) $row->box_container_id,
                'box_code' => (string) ($row->box_code ?? ''),
                'old_tray_code' => $row->old_tray_code,
                'new_tray_code' => $row->new_tray_code,
                'location_hint' => $row->location_hint,
                'note' => $row->note,
                'actor_name' => $row->actor_name,
                'created_at' => $row->confirmed_at ?: $row->created_at,
                'version' => (int) $row->version,
                'stock_effect' => 'NONE',
                'movement_created' => false,
            ])->values();

        return $this->respondSuccess($rows, 'Lịch sử putaway đã xác nhận.');
    }

    private function assertBoxReusable(object $box, string $skuId): void
    {
        if ((string) $box->container_type !== 'BOX') {
            ApiError::abort(422, 'BOX_CODE_CONFLICT', 'Mã đã tồn tại nhưng không phải BOX.');
        }
        if ((string) $box->primary_sku_id !== $skuId) {
            ApiError::abort(422, 'BOX_SKU_MISMATCH', 'Một BOX chỉ được chứa một SKU COMPONENT.');
        }
        if ($box->parent_container_id !== null || in_array((string) $box->status, ['CLOSED', 'ISSUED', 'STORED'], true)) {
            ApiError::abort(409, 'BOX_NOT_RECEIVABLE', 'BOX đã được lưu kho/đóng hoặc thuộc vị trí khác.');
        }
    }

    private function assertBoxNotUsedByOtherActiveReceipt(string $boxId, string $documentId): void
    {
        $conflict = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('e.container_id', $boxId)
            ->where('d.id', '<>', $documentId)
            ->whereIn('d.status', ['DRAFT', 'SCANNING'])
            ->exists();
        if ($conflict) {
            ApiError::abort(409, 'BOX_IN_OTHER_ACTIVE_RECEIPT', 'BOX đang được dùng bởi một phiếu nhập khác chưa kết thúc.');
        }
    }

    private function assertBoxPutawayEligible(?object $box, bool $allowDraftTarget = false): void
    {
        if ($box === null || (string) $box->container_type !== 'BOX') {
            ApiError::abort(422, 'BOX_REQUIRED', 'Putaway yêu cầu container BOX.');
        }
        $allowed = $allowDraftTarget
            ? ['RECEIVED_PENDING_PUTAWAY', 'STORED']
            : ['RECEIVED_PENDING_PUTAWAY', 'STORED'];
        if (! in_array((string) $box->status, $allowed, true)) {
            ApiError::abort(409, 'BOX_NOT_PUTAWAY_ELIGIBLE', 'BOX chưa POST Receipt hoặc không ở trạng thái cho phép putaway.');
        }
    }

    private function assertTrayUsable(?object $tray): void
    {
        if ($tray === null || (string) $tray->container_type !== 'TRAY') {
            ApiError::abort(422, 'TRAY_REQUIRED', 'Điểm đến putaway phải là TRAY.');
        }
        if (in_array((string) $tray->status, ['CLOSED', 'ISSUED'], true)) {
            ApiError::abort(422, 'TRAY_NOT_USABLE', 'TRAY đã đóng hoặc không còn khả dụng.');
        }
    }

    private function assertNoCycle(string $boxId, string $trayId): void
    {
        $cursor = $trayId;
        $seen = [];
        while ($cursor !== '') {
            if ($cursor === $boxId || isset($seen[$cursor])) {
                ApiError::abort(422, 'CONTAINER_CYCLE', 'Putaway sẽ tạo vòng lặp container.');
            }
            $seen[$cursor] = true;
            $parent = DB::table('containers')->where('container_id', $cursor)->value('parent_container_id');
            $cursor = $parent ? (string) $parent : '';
        }
    }

    private function latestPostedReceipt(string $boxId): ?object
    {
        return DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->where('e.container_id', $boxId)
            ->where('d.status', 'POSTED')
            ->orderByDesc('d.posted_at')
            ->first(['d.id', 'd.doc_no', 'd.dst_warehouse_id']);
    }

    private function eventResponse(object $event): array
    {
        return [
            'id' => (string) $event->id,
            'box_container_id' => (string) ($event->box_container_id ?: $event->container_id),
            'from_tray_id' => $event->from_tray_id ?: $event->from_parent_container_id,
            'to_tray_id' => $event->to_tray_id ?: $event->to_parent_container_id,
            'location_hint' => $event->location_hint ?: $event->to_location_hint,
            'status' => (string) $event->status,
            'version' => (int) $event->version,
            'note' => $event->note,
            'stock_effect' => 'NONE',
            'movement_created' => false,
        ];
    }

    private function boxResponse(object $box, bool $created): array
    {
        return [
            'container_id' => (string) $box->container_id,
            'box_code' => (string) $box->container_code,
            'sku_id' => (string) $box->primary_sku_id,
            'status' => (string) $box->status,
            'version' => (int) $box->version,
            'created' => $created,
            'stock_effect' => 'NONE',
            'movement_created' => false,
        ];
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên không âm.');
        }

        return (int) $header;
    }

    private function assertVersion(object $aggregate, int $expectedVersion): void
    {
        if ((int) ($aggregate->version ?? 0) !== $expectedVersion) {
            ApiError::abort(412, 'PRECONDITION_FAILED', 'Version không khớp, tải lại dữ liệu trước khi thao tác.', [
                'expected_version' => $expectedVersion,
                'current_version' => (int) ($aggregate->version ?? 0),
            ]);
        }
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
