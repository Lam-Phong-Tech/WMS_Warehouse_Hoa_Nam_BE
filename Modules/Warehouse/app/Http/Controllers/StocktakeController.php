<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\StocktakeSession;
use Modules\Warehouse\Models\StocktakeVariance;
use Modules\Warehouse\Services\StocktakeAdjustmentService;
use Modules\Warehouse\Services\StocktakeService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '12. Stocktake', description: 'Kiểm kê ba lớp SKU / Item / Container')]
class StocktakeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly StocktakeService $stocktakes,
        private readonly StocktakeAdjustmentService $adjustments
    ) {}

    #[OA\Get(
        path: '/api/v1/stocktake-sessions',
        operationId: 'stocktake_session_list',
        summary: 'Danh sách stocktake session theo warehouse data scope',
        security: [['bearerAuth' => []]],
        tags: ['12. Stocktake'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách session trong data scope'),
            new OA\Response(response: 403, description: 'Warehouse ngoài data scope'),
        ]
    )]
    public function index(Request $request)
    {
        $query = StocktakeSession::query()->orderByDesc('created_at');
        $scopeIds = $this->user()->warehouseScopeIds();
        if ($scopeIds !== null) {
            $query->whereIn('warehouse_id', $scopeIds);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('warehouse_id')) {
            $warehouseId = (string) $request->input('warehouse_id');
            if (! $this->user()->canAccessWarehouse($warehouseId)) {
                ApiError::abort(403, 'WAREHOUSE_SCOPE_DENIED', 'Kho yêu cầu nằm ngoài data scope.');
            }
            $query->where('warehouse_id', $warehouseId);
        }

        return $this->respondPaginated($query->paginate((int) $request->input('per_page', 15)));
    }

    #[OA\Get(
        path: '/api/v1/stocktake-sessions/{id}',
        operationId: 'stocktake_session_detail',
        summary: 'Chi tiết session, count và variance trong data scope',
        security: [['bearerAuth' => []]],
        tags: ['12. Stocktake'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết session'),
            new OA\Response(response: 403, description: 'Warehouse ngoài data scope'),
            new OA\Response(response: 404, description: 'Không tìm thấy session'),
        ]
    )]
    public function show(string $id)
    {
        $session = $this->stocktakes->findScopedSession($id, $this->user());

        return $this->respondSuccess($session->load(['counts', 'variances']));
    }

    #[OA\Post(
        path: '/api/v1/stocktake-sessions',
        operationId: 'stocktake_session_create',
        summary: 'Tạo session ở trạng thái DRAFT',
        description: 'Contract duy nhất: create luôn trả DRAFT; snapshot chỉ sinh khi gọi /start.',
        security: [['bearerAuth' => []]],
        tags: ['12. Stocktake'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['warehouse_id'],
            properties: [
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'session_no', type: 'string', maxLength: 80, nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Session DRAFT, session_version=1'),
            new OA\Response(response: 403, description: 'Warehouse ngoài data scope'),
            new OA\Response(response: 422, description: 'Kho hoặc payload không hợp lệ'),
        ]
    )]
    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'session_no' => ['nullable', 'string', 'max:80', 'unique:stocktake_session,session_no'],
        ]);
        $result = $this->stocktakes->createSession($validated, $this->user());

        return $this->respondCreated($result['session'], 'Tạo stocktake session DRAFT thành công');
    }

    #[OA\Post(
        path: '/api/v1/stocktake-sessions/{id}/start',
        operationId: 'stocktake_session_start',
        summary: 'Start DRAFT → IN_PROGRESS và chụp snapshot ba grain',
        security: [['bearerAuth' => []]],
        tags: ['12. Stocktake'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['session_version'],
            properties: [new OA\Property(property: 'session_version', type: 'integer', minimum: 1)]
        )),
        responses: [
            new OA\Response(response: 200, description: 'IN_PROGRESS và số snapshot SKU/ITEM/CONTAINER'),
            new OA\Response(response: 409, description: 'Sai state hoặc session_version stale'),
        ]
    )]
    public function start(Request $request, string $id)
    {
        $validated = $request->validate(['session_version' => ['required', 'integer', 'min:1']]);
        $result = $this->stocktakes->startSession($id, (int) $validated['session_version'], $this->user());

        return $this->respondSuccess($result, 'Bắt đầu stocktake và tạo snapshot thành công');
    }

    #[OA\Post(
        path: '/api/v1/stocktake-sessions/{id}/counts',
        operationId: 'stocktake_count_record',
        summary: 'Ghi count theo grain_type/grain_id, chống duplicate ở DB',
        security: [['bearerAuth' => []]],
        tags: ['12. Stocktake'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['lines'],
            properties: [new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                required: ['grain_type', 'grain_id', 'product_id', 'stock_status', 'actual_qty'],
                properties: [
                    new OA\Property(property: 'grain_type', type: 'string', enum: ['SKU', 'ITEM', 'CONTAINER']),
                    new OA\Property(property: 'grain_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'stock_status', type: 'string', enum: ['AVAILABLE', 'DAMAGED', 'RESERVED']),
                    new OA\Property(property: 'actual_qty', type: 'number', minimum: 0),
                    new OA\Property(property: 'counted_at', type: 'string', format: 'date-time', nullable: true),
                ]
            ))]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Count được ghi'),
            new OA\Response(response: 409, description: 'Duplicate grain hoặc sai state'),
            new OA\Response(response: 422, description: 'Grain không hợp lệ'),
        ]
    )]
    public function recordCounts(Request $request, string $id)
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.grain_type' => ['required', 'string', 'in:SKU,ITEM,CONTAINER'],
            'lines.*.grain_id' => ['required', 'uuid'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'lines.*.stock_status' => ['required', 'string', 'in:AVAILABLE,DAMAGED,RESERVED'],
            'lines.*.actual_qty' => ['required', 'numeric', 'min:0'],
            'lines.*.counted_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        $result = $this->stocktakes->recordCounts($id, $validated['lines'], $this->user());

        return $this->respondSuccess($result, 'Ghi nhận stocktake count thành công');
    }

    #[OA\Post(
        path: '/api/v1/stocktake-sessions/{id}/review',
        operationId: 'stocktake_session_review',
        summary: 'Tính expected_at_count bằng snapshot + signed SUM(qty_delta)',
        description: 'ADJUST_OUT/ISSUE_OUT là qty_delta âm. Không đảo dấu theo movement_type lần hai.',
        security: [['bearerAuth' => []]],
        tags: ['12. Stocktake'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['session_version'],
            properties: [new OA\Property(property: 'session_version', type: 'integer', minimum: 1)]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Session REVIEWED và variance canonical'),
            new OA\Response(response: 409, description: 'Thiếu count, sai state hoặc stale version'),
        ]
    )]
    public function review(Request $request, string $id)
    {
        $validated = $request->validate(['session_version' => ['required', 'integer', 'min:1']]);
        $result = $this->stocktakes->reviewSession($id, (int) $validated['session_version'], $this->user());

        return $this->respondSuccess($result, 'Review stocktake thành công');
    }

    #[OA\Post(
        path: '/api/v1/stocktake-sessions/{id}/acknowledge-movements',
        operationId: 'stocktake_acknowledge_movements',
        summary: 'Xác nhận chính xác movement backend phát hiện cho từng variance',
        security: [['bearerAuth' => []]],
        tags: ['12. Stocktake'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['session_version', 'lines'],
            properties: [
                new OA\Property(property: 'session_version', type: 'integer', minimum: 1),
                new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
                    required: ['variance_id', 'movement_ids'],
                    properties: [
                        new OA\Property(property: 'variance_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'movement_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Lưu acknowledged_by/at và exact movement IDs'),
            new OA\Response(response: 409, description: 'Acknowledged IDs không khớp detected IDs'),
            new OA\Response(response: 422, description: 'Variance thiếu hoặc thuộc session khác'),
        ]
    )]
    public function acknowledgeMovements(Request $request, string $id)
    {
        $validated = $request->validate([
            'session_version' => ['required', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.variance_id' => ['required', 'uuid'],
            'lines.*.movement_ids' => ['required', 'array'],
            'lines.*.movement_ids.*' => ['uuid', 'distinct'],
        ]);
        $result = $this->stocktakes->acknowledgeMovements(
            $id,
            (int) $validated['session_version'],
            $validated['lines'],
            $this->user()
        );

        return $this->respondSuccess($result, 'Xác nhận movement kiểm kê thành công');
    }

    #[OA\Get(
        path: '/api/v1/stocktake-sessions/{id}/variances',
        operationId: 'stocktake_variance_list',
        summary: 'Danh sách variance của đúng session và data scope',
        security: [['bearerAuth' => []]],
        tags: ['12. Stocktake'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Variance của session')]
    )]
    public function variances(string $id)
    {
        $session = $this->stocktakes->findScopedSession($id, $this->user());

        return $this->respondSuccess(
            StocktakeVariance::query()->where('session_id', $session->session_id)->orderBy('grain_type')->orderBy('grain_key')->get()
        );
    }

    #[OA\Post(
        path: '/api/v1/stocktake-sessions/{id}/apply-adjustment',
        operationId: 'stocktake_apply_adjustment',
        summary: 'Apply variance đã review theo posting_event idempotency và CAS',
        description: 'Payload chỉ nhận variance_ids. Backend tự lấy expected/actual/variance đã review, khóa session và cập nhật balance + signed movement + physical item/container trong cùng transaction.',
        security: [['bearerAuth' => []]],
        tags: ['12. Stocktake'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['session_version', 'variance_ids', 'reason'],
            properties: [
                new OA\Property(property: 'session_version', type: 'integer', minimum: 1),
                new OA\Property(property: 'variance_ids', type: 'array', minItems: 1, items: new OA\Items(type: 'string', format: 'uuid')),
                new OA\Property(property: 'reason', type: 'string', maxLength: 500),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Commit hoặc replay response_snapshot đã commit'),
            new OA\Response(response: 403, description: 'Warehouse ngoài data scope'),
            new OA\Response(response: 409, description: 'CAS, state, stale baseline, race hoặc same-key/different-payload'),
            new OA\Response(response: 422, description: 'Variance thiếu/foreign hoặc physical grain không hợp lệ'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key hoặc chưa acknowledge movement'),
        ]
    )]
    public function applyAdjustment(Request $request, string $id)
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Header Idempotency-Key là bắt buộc.');
        }
        if (mb_strlen($idempotencyKey) > 100) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'Idempotency-Key tối đa 100 ký tự.');
        }

        $validated = $request->validate([
            'session_version' => ['required', 'integer', 'min:1'],
            'variance_ids' => ['required', 'array', 'min:1', 'max:500'],
            'variance_ids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $requestHash = $this->adjustments->requestHash(
            $id,
            (int) $validated['session_version'],
            $validated['variance_ids'],
            $validated['reason']
        );
        $result = $this->adjustments->apply(
            $id,
            (int) $validated['session_version'],
            $validated['variance_ids'],
            $validated['reason'],
            $idempotencyKey,
            $requestHash,
            $this->user()
        );

        return $this->respondSuccess($result['data'], $result['replay']
            ? 'Replay kết quả stocktake adjustment đã commit'
            : 'Áp dụng stocktake adjustment thành công');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $user;
    }
}
