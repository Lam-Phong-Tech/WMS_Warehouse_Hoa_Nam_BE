<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use OpenApi\Attributes as OA;

/**
 * Current MVP contract for the initial COMPONENT BOX -> TRAY putaway.
 *
 * A DRAFT is only a proposed physical mapping and therefore MUST remain
 * stock-neutral and unconfirmed until the explicit confirm endpoint runs.
 * Relocation of an already STORED box is a separate business operation and
 * must never be smuggled through this initial putaway route.
 */
class CurrentBaselinePutawayTaskController extends Controller
{
    use ApiResponse;
    use ResolvesActor;

    #[OA\Post(
        path: '/api/v1/putaway-tasks',
        operationId: 'putaway_task_create',
        summary: 'Tạo tác vụ putaway DRAFT (BOX COMPONENT -> TRAY, stock-neutral)',
        security: [['bearerAuth' => []]],
        tags: ['24. Putaway'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['box_container_id', 'to_tray_id'],
                properties: [
                    new OA\Property(property: 'box_container_id', type: 'string', format: 'uuid', example: 'b6f2a1d4-3c9e-4a2b-8f1d-7e6c5b4a3210'),
                    new OA\Property(property: 'to_tray_id', type: 'string', format: 'uuid', description: 'Phải khác box_container_id', example: 'c7a3b2e5-4d0f-4b3c-9a2e-8f7d6c5b4321'),
                    new OA\Property(property: 'location_hint', type: 'string', maxLength: 255, nullable: true, example: 'Kệ A1-Tầng 2'),
                    new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true, example: 'Xếp khay theo lô nhập ngày 21/08'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Đã tạo tác vụ putaway DRAFT (stock_effect=NONE)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 409, description: 'PUTAWAY_DRAFT_EXISTS hoặc BOX_NOT_PUTAWAY_ELIGIBLE (chưa có Posted Receipt / sai trạng thái)'),
            new OA\Response(response: 422, description: 'BOX_REQUIRED, TRAY_REQUIRED, TRAY_NOT_USABLE, CONTAINER_CYCLE hoặc payload không hợp lệ'),
        ]
    )]
    public function store(Request $request)
    {
        $payload = $request->validate([
            'box_container_id' => ['required', 'uuid', 'exists:containers,container_id'],
            'to_tray_id' => ['required', 'uuid', 'different:box_container_id', 'exists:containers,container_id'],
            'location_hint' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $actorId = $this->actorId();

        $event = DB::transaction(function () use ($payload, $actorId, $request) {
            $box = DB::table('containers')
                ->where('container_id', $payload['box_container_id'])
                ->lockForUpdate()
                ->first();
            $tray = DB::table('containers')
                ->where('container_id', $payload['to_tray_id'])
                ->lockForUpdate()
                ->first();

            $this->assertInitialPutawayBoxEligible($box);
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
            $receipt = $this->assertPostedReceiptLinkage((string) $box->container_id, (string) $box->status);
            $locationHint = $payload['location_hint'] ?? $tray->location_hint;
            $now = now();

            DB::table('putaway_events')->insert([
                'id' => $eventId,
                'stock_document_id' => $receipt->id,
                'warehouse_id' => $receipt->dst_warehouse_id,
                'container_id' => $box->container_id,
                'box_container_id' => $box->container_id,
                'from_parent_container_id' => $box->parent_container_id,
                'to_parent_container_id' => $tray->container_id,
                'from_tray_id' => $box->parent_container_id,
                'to_tray_id' => $tray->container_id,
                'from_location_hint' => $box->location_hint,
                'to_location_hint' => $locationHint,
                'location_hint' => $locationHint,
                'status' => 'DRAFT',
                'confirmed_at' => null,
                'created_by' => $actorId,
                'confirmed_by' => null,
                'note' => $payload['note'] ?? null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'updated_by' => $actorId,
            ]);

            $event = DB::table('putaway_events')->where('id', $eventId)->first();

            AuditLogger::record(
                action: 'PUTAWAY_DRAFT_CREATE',
                objectType: 'putaway_event',
                objectId: (string) $event->id,
                actorId: $actorId,
                requestId: $request->attributes->get('request_id'),
                afterData: (array) $event,
            );

            return $event;
        }, 3);

        return $this->respondCreated($this->eventResponse($event), 'Đã tạo tác vụ putaway DRAFT.');
    }

    private function assertInitialPutawayBoxEligible(?object $box): void
    {
        if ($box === null || strtoupper((string) $box->container_type) !== 'BOX') {
            ApiError::abort(422, 'BOX_REQUIRED', 'Putaway yêu cầu container BOX.');
        }

        if (strtoupper((string) $box->status) !== 'RECEIVED_PENDING_PUTAWAY') {
            ApiError::abort(
                409,
                'BOX_NOT_PUTAWAY_ELIGIBLE',
                'Initial Putaway chỉ nhận BOX ở trạng thái RECEIVED_PENDING_PUTAWAY; BOX đã STORED phải dùng nghiệp vụ relocation riêng.',
                ['box_status' => (string) $box->status],
            );
        }
    }

    private function assertTrayUsable(?object $tray): void
    {
        if ($tray === null || strtoupper((string) $tray->container_type) !== 'TRAY') {
            ApiError::abort(422, 'TRAY_REQUIRED', 'Điểm đến putaway phải là TRAY.');
        }

        if (in_array(strtoupper((string) $tray->status), ['CLOSED', 'ISSUED'], true)) {
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
            $parent = DB::table('containers')
                ->where('container_id', $cursor)
                ->value('parent_container_id');
            $cursor = $parent ? (string) $parent : '';
        }
    }

    /**
     * WWHN-193 (DEV193-P1-05R) — Initial Putaway phải neo vào một Posted Receipt thật.
     *
     * Trước đây eligibility chỉ dựa vào `containers.status = RECEIVED_PENDING_PUTAWAY` và
     * receipt chỉ được nạp *tuỳ chọn* để điền metadata (`$receipt?->id`). Trạng thái đó
     * lại từng đặt được qua POST/PATCH /containers, nên một BOX chưa hề có phiếu nhập
     * POSTED vẫn tạo được putaway task, ra `putaway_events.stock_document_id = NULL` —
     * mất hẳn liên kết chứng từ mà SRS §6 ("Create sau Receipt") và ERD putaway_events
     * yêu cầu. Nay linkage là điều kiện tiên quyết, kiểm ở cả tầng dữ liệu chứ không chỉ
     * tầng trạng thái, nên dù trạng thái bị đặt sai bằng đường nào thì putaway vẫn dừng.
     */
    private function assertPostedReceiptLinkage(string $boxId, string $boxStatus): object
    {
        $receipt = $this->latestPostedReceipt($boxId);
        if ($receipt === null) {
            ApiError::abort(
                409,
                'BOX_NOT_PUTAWAY_ELIGIBLE',
                'BOX chưa gắn với phiếu nhập đã POSTED; Initial Putaway chỉ được tạo sau Post Receipt.',
                [
                    'box_container_id' => $boxId,
                    'box_status' => $boxStatus,
                    'reason' => 'NO_POSTED_RECEIPT',
                ],
            );
        }

        return $receipt;
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

}
