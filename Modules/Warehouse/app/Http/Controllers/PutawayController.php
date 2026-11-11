<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\CacheService;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\IdempotentEnvelope;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '24. Putaway', description: 'BP-WMS-03: component BOX -> TRAY, stock-neutral')]
class PutawayController extends Controller
{
    use ApiResponse;
    use ResolvesActor;

    private const OPERATION_CONFIRM = 'confirm';

    private const OPERATION_CANCEL = 'cancel';

    /**
     * WWHN-193 · DEV193-P1-03R — hợp đồng phục hồi sau mất response (DEC-PTW-RETRY-001).
     *
     * Vấn đề: confirm/cancel chỉ được bảo vệ bằng If-Match. Khi client gửi request thành
     * công nhưng **mất response** (timeout mạng, đóng tab, proxy cắt), lần retry mang đúng
     * If-Match cũ sẽ rơi vào nhánh `status !== 'DRAFT'` và nhận 409 "trần" — không thể phân
     * biệt "chính mình vừa làm xong" với "người khác vừa kết thúc tác vụ này", nên FE không
     * có cách phục hồi xác định. Đó là "controlled 409 nhưng chưa formal recovery semantics".
     *
     * Hợp đồng mới, hai tầng, không phá contract Annex C (Idempotency-Key vẫn KHÔNG bắt buộc):
     *
     *  1. CÓ `Idempotency-Key` → đi qua IdempotencyStore. Retry cùng key + cùng payload trả
     *     lại đúng snapshot 200 đã commit kèm `data.replayed = true` và header
     *     `Idempotent-Replay: true` (SRS §8.2: "unless this is an idempotent replay of a
     *     prior success"; §13.2 header list đã khai Idempotency-Key). Cùng key khác payload
     *     → 409 IDEMPOTENCY_CONFLICT; đụng lease đang chạy → 409 CONCURRENCY_CONFLICT. Đây
     *     là đường phục hồi *xác định*, khuyến nghị cho FE.
     *  2. KHÔNG có key (client cũ) → vẫn 409 STATE_CONFLICT như trước, nhưng kèm
     *     `error.details.recovery` đủ để phục hồi máy-đọc-được: outcome, có an toàn coi như
     *     thành công không, và hành động kế tiếp.
     *
     * Cả hai tầng đều giữ nguyên bất biến stock-neutral: không nhánh nào sinh stock movement.
     */
    private const RECOVERY_ALREADY_APPLIED_BY_CALLER = 'ALREADY_APPLIED_BY_CALLER';

    private const RECOVERY_ALREADY_APPLIED_BY_OTHER = 'ALREADY_APPLIED_BY_OTHER';

    private const RECOVERY_FINALIZED_DIFFERENT_OUTCOME = 'FINALIZED_DIFFERENT_OUTCOME';

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
            ->select([
                'c.container_id', 'c.container_code', 'c.primary_sku_id',
                'c.parent_container_id', 'c.location_hint', 'c.status', 'c.version', 'c.updated_at',
                's.sku_code', 's.sku_name',
            ]);

        // Annex C PutawayTaskPage: phân trang ở tầng SQL. Các số liệu phụ được nạp
        // theo lô cho đúng trang hiện tại thay vì 4 truy vấn/BOX (N+1).
        $page = $boxes->paginate($this->perPage($request));
        $boxIds = collect($page->items())->pluck('container_id')->map(fn ($id): string => (string) $id)->all();
        $quantities = $this->postedQuantitiesByBox($boxIds);
        $drafts = $this->latestDraftsByBox($boxIds);
        $receipts = $this->latestPostedReceiptsByBox($boxIds);
        $trayCodes = $this->trayCodes($drafts);

        $rows = collect($page->items())->map(function ($box) use ($quantities, $drafts, $receipts, $trayCodes): array {
            $boxId = (string) $box->container_id;
            $receipt = $receipts[$boxId] ?? null;
            $quantity = (int) ($quantities[$boxId] ?? 0);
            $draft = $drafts[$boxId] ?? null;
            $targetTray = $draft?->to_tray_id ? ($trayCodes[(string) $draft->to_tray_id] ?? null) : null;

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
                // Persisted BOX time; refreshing this list must not change it.
                'updated_at' => $box->updated_at !== null
                    ? Carbon::parse($box->updated_at, config('app.timezone'))->toISOString()
                    : null,
                'draft_id' => $draft?->id,
                'draft_version' => $draft ? (int) $draft->version : null,
                'to_tray_id' => $draft?->to_tray_id,
                'to_tray_code' => $targetTray,
            ];
        })->values();

        return $this->respondPaginated(
            new LengthAwarePaginator($rows->all(), $page->total(), $page->perPage(), $page->currentPage()),
            'Danh sách BOX chờ xếp khay.'
        );
    }

    /**
     * @param  list<string>  $boxIds
     * @return array<string, int>
     */
    private function postedQuantitiesByBox(array $boxIds): array
    {
        if ($boxIds === []) {
            return [];
        }

        return DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
            ->whereIn('e.container_id', $boxIds)
            ->where('d.status', 'POSTED')
            ->groupBy('e.container_id')
            ->selectRaw('e.container_id, COALESCE(SUM(e.quantity), 0) AS total')
            ->pluck('total', 'container_id')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    /**
     * @param  list<string>  $boxIds
     * @return array<string, object>
     */
    private function latestDraftsByBox(array $boxIds): array
    {
        if ($boxIds === []) {
            return [];
        }

        return DB::table('putaway_events')
            ->whereIn('box_container_id', $boxIds)
            ->where('status', 'DRAFT')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('box_container_id')
            ->map(fn ($rows) => $rows->first())
            ->all();
    }

    /**
     * @param  list<string>  $boxIds
     * @return array<string, object>
     */
    private function latestPostedReceiptsByBox(array $boxIds): array
    {
        $map = [];
        foreach ($boxIds as $boxId) {
            $receipt = $this->latestPostedReceipt($boxId);
            if ($receipt !== null) {
                $map[$boxId] = $receipt;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, object>  $drafts
     * @return array<string, string>
     */
    private function trayCodes(array $drafts): array
    {
        $trayIds = array_values(array_filter(array_map(
            fn ($draft): ?string => $draft?->to_tray_id !== null ? (string) $draft->to_tray_id : null,
            $drafts
        )));
        if ($trayIds === []) {
            return [];
        }

        return DB::table('containers')
            ->whereIn('container_id', $trayIds)
            ->pluck('container_code', 'container_id')
            ->map(fn ($code): string => (string) $code)
            ->all();
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
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'DEC-PTW-RETRY-001: tuỳ chọn. Có key thì retry sau mất response trả lại đúng kết quả cũ kèm replayed=true; không có key thì retry nhận 409 STATE_CONFLICT kèm error.details.recovery.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CONFIRMED, stock_effect=NONE (replayed=true nếu là retry cùng Idempotency-Key)'),
            new OA\Response(response: 409, description: 'STATE_CONFLICT kèm recovery outcome, hoặc IDEMPOTENCY_CONFLICT / CONCURRENCY_CONFLICT'),
            new OA\Response(response: 412, description: 'Version conflict'),
        ],
    )]
    public function confirm(Request $request, string $id)
    {
        return $this->transitionPutaway($request, $id, self::OPERATION_CONFIRM);
    }

    /**
     * @return array<string, mixed>
     */
    private function applyConfirm(Request $request, string $id, int $expectedVersion, string $actorId): array
    {
        $result = DB::transaction(function () use ($id, $expectedVersion, $actorId, $request): array {
            $event = DB::table('putaway_events')->where('id', $id)->lockForUpdate()->first();
            if ($event === null) {
                ApiError::abort(404, 'PUTAWAY_NOT_FOUND', 'Không tìm thấy tác vụ putaway.');
            }
            if ((string) $event->status !== 'DRAFT') {
                $this->abortWithRecovery($event, 'CONFIRMED', $expectedVersion, $actorId, self::OPERATION_CONFIRM);
            }
            $this->assertVersion($event, $expectedVersion);

            $boxId = (string) ($event->box_container_id ?: $event->container_id);
            $trayId = (string) ($event->to_tray_id ?: $event->to_parent_container_id);
            $box = DB::table('containers')->where('container_id', $boxId)->lockForUpdate()->first();
            $tray = DB::table('containers')->where('container_id', $trayId)->lockForUpdate()->first();
            $this->assertBoxPutawayEligible($box, true);
            $this->assertTrayUsable($tray);
            $this->assertNoCycle($boxId, $trayId);
            $this->assertReceiptLinkageStillValid($event, $boxId);

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

            // Stock-neutral nhưng đổi location_hint của box — "Vị trí gợi ý"
            // (InventoryBalanceController::buildContainerHints) đọc qua cache
            // cùng version namespace với tồn kho; không bump ở đây thì cache cũ
            // (TTL stock_short) tiếp tục trả location cũ dù Putaway đã CONFIRMED.
            DB::afterCommit(fn () => app(CacheService::class)->bumpVersion('stock:version'));

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
        ], 'Putaway CONFIRMED; không phát sinh biến động tồn.')->getData(true);
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
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, description: 'DEC-PTW-RETRY-001: tuỳ chọn, cùng ngữ nghĩa như confirm.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CANCELLED, stock-neutral (replayed=true nếu là retry cùng Idempotency-Key)'),
            new OA\Response(response: 409, description: 'STATE_CONFLICT kèm recovery outcome, hoặc IDEMPOTENCY_CONFLICT / CONCURRENCY_CONFLICT'),
        ],
    )]
    public function cancel(Request $request, string $id)
    {
        return $this->transitionPutaway($request, $id, self::OPERATION_CANCEL);
    }

    /**
     * @return array<string, mixed>
     */
    private function applyCancel(Request $request, string $id, int $expectedVersion, string $actorId): array
    {
        $event = DB::transaction(function () use ($id, $expectedVersion, $actorId, $request) {
            $event = DB::table('putaway_events')->where('id', $id)->lockForUpdate()->first();
            if ($event === null) {
                ApiError::abort(404, 'PUTAWAY_NOT_FOUND', 'Không tìm thấy tác vụ putaway.');
            }
            if ((string) $event->status !== 'DRAFT') {
                $this->abortWithRecovery($event, 'CANCELLED', $expectedVersion, $actorId, self::OPERATION_CANCEL);
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

            DB::afterCommit(fn () => app(CacheService::class)->bumpVersion('stock:version'));

            return $updatedEvent;
        }, 3);

        return $this->respondSuccess($this->eventResponse($event), 'Đã hủy tác vụ putaway.')->getData(true);
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
            ->leftJoin('sku as s', 's.sku_id', '=', 'b.primary_sku_id')
            ->where('p.status', 'CONFIRMED')
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($nested) use ($keyword): void {
                    $nested->where('b.container_code', 'like', "%{$keyword}%")
                        ->orWhere('ft.container_code', 'like', "%{$keyword}%")
                        ->orWhere('tt.container_code', 'like', "%{$keyword}%");
                });
            })
            ->orderByDesc('p.confirmed_at')
            // Trước đây hard-limit 500 dòng và im lặng cắt phần còn lại; phân trang
            // thật để client biết tổng số bản ghi.
            ->select([
                'p.id', 'p.box_container_id', 'p.from_tray_id', 'p.to_tray_id',
                'p.location_hint', 'p.note', 'p.confirmed_at', 'p.created_at', 'p.version',
                'b.container_code as box_code', 'b.status as box_status', 'b.primary_sku_id',
                'ft.container_code as old_tray_code',
                'tt.container_code as new_tray_code', 'u.display_name as actor_name',
                's.sku_code', 's.sku_name', 's.unit',
            ])
            ->paginate($this->perPage($request));

        $boxIds = collect($rows->items())
            ->pluck('box_container_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        $quantities = $this->postedQuantitiesByBox($boxIds);
        $receipts = $this->latestPostedReceiptsByBox($boxIds);

        $mapped = collect($rows->items())
            ->map(function ($row) use ($quantities, $receipts): array {
                $boxId = (string) $row->box_container_id;
                $receipt = $receipts[$boxId] ?? null;

                return [
                    'id' => (string) $row->id,
                    'box_container_id' => $boxId,
                    'box_code' => (string) ($row->box_code ?? ''),
                    'box_status' => (string) ($row->box_status ?? ''),
                    'sku_id' => (string) ($row->primary_sku_id ?? ''),
                    'sku_code' => (string) ($row->sku_code ?? ''),
                    'sku_name' => (string) ($row->sku_name ?? ''),
                    'unit' => (string) ($row->unit ?? ''),
                    'quantity' => (int) ($quantities[$boxId] ?? 0),
                    'receipt_code' => $receipt?->doc_no,
                    'old_tray_code' => $row->old_tray_code,
                    'new_tray_code' => $row->new_tray_code,
                    'location_hint' => $row->location_hint,
                    'note' => $row->note,
                    'actor_name' => $row->actor_name,
                    'created_at' => $row->confirmed_at ?: $row->created_at,
                    'version' => (int) $row->version,
                    'stock_effect' => 'NONE',
                    'movement_created' => false,
                ];
            })->values();

        return $this->respondPaginated(
            new LengthAwarePaginator($mapped->all(), $rows->total(), $rows->perPage(), $rows->currentPage()),
            'Lịch sử putaway đã xác nhận.'
        );
    }

    /**
     * Điểm vào chung của confirm/cancel: bọc thao tác trong hợp đồng retry DEC-PTW-RETRY-001.
     */
    private function transitionPutaway(Request $request, string $id, string $operation)
    {
        $expectedVersion = $this->expectedVersion($request);
        $actorId = $this->actorId();
        // Annex C khai confirm/cancel chỉ bắt buộc If-Match, nên key là TUỲ CHỌN: thiếu key
        // không được 428, có key thì phải hành xử đúng như operation idempotent bắt buộc.
        $key = IdempotencyKeyGuard::optional($request);
        $hash = IdempotencyStore::requestHash($request);

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            'putaway_'.$operation.'_'.$id,
            $actorId,
            $hash,
            function () use ($request, $id, $operation, $expectedVersion, $actorId): array {
                $payload = $operation === self::OPERATION_CONFIRM
                    ? $this->applyConfirm($request, $id, $expectedVersion, $actorId)
                    : $this->applyCancel($request, $id, $expectedVersion, $actorId);

                return [200, $payload];
            }
        );

        // Cờ replay phải hiện ở BODY chứ không chỉ header: proxy/log tuỳ biến hay nuốt header,
        // còn QA/FE đọc body (WWHN-234 · F-09).
        $body = IdempotentEnvelope::withReplayFlag($body, $replay);

        $response = response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
        $version = data_get($body, 'data.version');
        if ($version !== null) {
            $response->header('ETag', '"'.((int) $version).'"');
        }

        return $response;
    }

    /**
     * 409 STATE_CONFLICT có ngữ nghĩa phục hồi (nhánh không dùng Idempotency-Key).
     *
     * Không trả về id của actor khác: caller có thể không có quyền đọc hồ sơ người dùng, nên
     * chỉ phát cờ boolean `applied_by_caller` — đủ để FE quyết định, không lộ danh tính.
     */
    private function abortWithRecovery(object $event, string $targetStatus, int $expectedVersion, string $actorId, string $operation): void
    {
        $current = strtoupper((string) $event->status);
        $currentVersion = (int) ($event->version ?? 0);
        $lastActor = (string) ($current === 'CONFIRMED'
            ? ($event->confirmed_by ?? $event->updated_by ?? '')
            : ($event->updated_by ?? ''));
        $appliedByCaller = $lastActor !== '' && $lastActor === $actorId && $currentVersion === $expectedVersion + 1;

        if ($current === $targetStatus) {
            $outcome = $appliedByCaller
                ? self::RECOVERY_ALREADY_APPLIED_BY_CALLER
                : self::RECOVERY_ALREADY_APPLIED_BY_OTHER;
            $message = $operation === self::OPERATION_CONFIRM
                ? 'Tác vụ putaway này đã được xác nhận trước đó; kết quả nghiệp vụ mong muốn đã đạt.'
                : 'Tác vụ putaway này đã được hủy trước đó; kết quả nghiệp vụ mong muốn đã đạt.';
        } else {
            $outcome = self::RECOVERY_FINALIZED_DIFFERENT_OUTCOME;
            $message = 'Tác vụ putaway đã kết thúc ở trạng thái khác với thao tác đang yêu cầu; tải lại trước khi quyết định.';
        }

        ApiError::abort(409, 'STATE_CONFLICT', $message, [
            'putaway_event_id' => (string) $event->id,
            'operation' => $operation,
            'current_status' => $current,
            'current_version' => $currentVersion,
            'expected_version' => $expectedVersion,
            'confirmed_at' => $event->confirmed_at ?? null,
            'recovery' => [
                'contract' => 'DEC-PTW-RETRY-001',
                'outcome' => $outcome,
                'applied_by_caller' => $appliedByCaller,
                // Cả hai nhánh ALREADY_APPLIED_* đều đạt đúng kết quả nghiệp vụ mà caller
                // yêu cầu trên CHÍNH tác vụ này, nên FE tải lại rồi đi tiếp là an toàn.
                'safe_to_treat_as_success' => $outcome !== self::RECOVERY_FINALIZED_DIFFERENT_OUTCOME,
                'next_action' => $outcome === self::RECOVERY_FINALIZED_DIFFERENT_OUTCOME
                    ? 'RELOAD_AND_REVIEW'
                    : 'RELOAD_AND_CONTINUE',
                'idempotent_retry_hint' => 'Gửi lại cùng Idempotency-Key để nhận đúng response cũ thay vì 409.',
            ],
            'stock_effect' => 'NONE',
            'movement_created' => false,
        ]);
    }

    /**
     * WWHN-193 (DEV193-P1-05R) — DRAFT phải còn neo vào một phiếu nhập POSTED tại thời điểm
     * confirm, không chỉ tại thời điểm tạo. Chặn cả DRAFT tồn dư sinh trước khi linkage trở
     * thành bắt buộc và trường hợp phiếu bị đảo/huỷ trong lúc DRAFT còn treo.
     */
    private function assertReceiptLinkageStillValid(object $event, string $boxId): void
    {
        $documentId = $event->stock_document_id ?? null;
        if ($documentId !== null) {
            $stillPosted = DB::table('stock_documents')
                ->where('id', $documentId)
                ->where('status', 'POSTED')
                ->exists();
            if ($stillPosted) {
                return;
            }
        }

        if ($this->latestPostedReceipt($boxId) !== null) {
            return;
        }

        ApiError::abort(
            409,
            'BOX_NOT_PUTAWAY_ELIGIBLE',
            'BOX không còn gắn với phiếu nhập đã POSTED; không thể xác nhận putaway.',
            [
                'box_container_id' => $boxId,
                'stock_document_id' => $documentId,
                'reason' => 'NO_POSTED_RECEIPT',
            ],
        );
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
            ApiError::abort(412, 'STALE_VERSION', 'Version không khớp, tải lại dữ liệu trước khi thao tác.', [
                'expected_version' => $expectedVersion,
                'current_version' => (int) ($aggregate->version ?? 0),
            ]);
        }
    }
}
