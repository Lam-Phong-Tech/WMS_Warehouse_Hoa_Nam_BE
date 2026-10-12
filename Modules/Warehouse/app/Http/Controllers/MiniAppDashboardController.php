<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\Validation\InputLimits;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use OpenApi\Attributes as OA;

class MiniAppDashboardController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    public const CONTRACT = 'mini-app-p02-v2';

    private const SECTIONS = [
        'inbound' => ['permission' => 'inbound.view', 'doc_type' => StockDocument::TYPE_RECEIPT, 'warehouse_column' => 'dst_warehouse_id'],
        'outbound' => ['permission' => 'outbound.view', 'doc_type' => StockDocument::TYPE_ISSUE, 'warehouse_column' => 'src_warehouse_id'],
    ];

    #[OA\Get(
        path: '/api/v1/mini-app/dashboard/summary',
        operationId: 'mini_app_dashboard_summary',
        x: ['permission' => 'inbound.view|outbound.view|warranty.view|warranty.manage'],
        summary: 'Mini App - số liệu tổng hợp cho thẻ KPI màn Trang chủ',
        description: 'P02 v2. data_scope là phạm vi dữ liệu thực trả; authorization_scope là phạm vi quyền trước bộ lọc. Có warehouse_id thì data_scope=WAREHOUSE_SCOPED, kể cả role GLOBAL. pending_approval_count là snapshot hiện tại: SCANNING đã quét đủ mọi dòng. posted_count theo posted_at trong ngày date, kể cả phiếu sau đó REVERSED. warranty.open_count là snapshot hiện tại theo warranty-owner-v1, gồm RECEIVED/CHECKING/REPAIRING/COMPLETED và TEMP_ONLY có owner; không theo date. Khối thiếu quyền trả null, không phải 0. Hai tổng chứng từ không cộng bảo hành. GET query-only; JWT hiện có, không yêu cầu ca làm việc mới. Stock cache ngắn; bảo hành đọc mới, không phải snapshot nguyên tử chung. P12 không thay KPI P02.',
        security: [['bearerAuth' => []]],
        tags: ['22. Mini App'],
        parameters: [
            new OA\Parameter(name: 'date', in: 'query', description: 'YYYY-MM-DD. Mặc định hôm nay theo múi giờ ứng dụng. Chỉ áp cho posted_count.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', description: 'UUID kho thực, thu hẹp trong quyền hiện có. Bỏ tham số để đọc toàn phạm vi được phép; không tự chọn kho hiện hành.', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'KPI P02 v2', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'meta', type: 'object', properties: [
                    new OA\Property(property: 'contract', type: 'string', enum: [self::CONTRACT]),
                    new OA\Property(property: 'request_id', type: 'string'),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'timezone', type: 'string', example: 'Asia/Ho_Chi_Minh'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'data_scope', type: 'string', enum: ['GLOBAL', 'WAREHOUSE_SCOPED'], description: 'Phạm vi dữ liệu sau bộ lọc.'),
                    new OA\Property(property: 'authorization_scope', type: 'string', enum: ['GLOBAL', 'WAREHOUSE_SCOPED'], description: 'Phạm vi role trước bộ lọc; không phải phạm vi KPI.'),
                    new OA\Property(property: 'pending_approval_count', type: 'integer'),
                    new OA\Property(property: 'posted_count', type: 'integer'),
                    new OA\Property(property: 'inbound', type: 'object', nullable: true, properties: [
                        new OA\Property(property: 'pending_approval_count', type: 'integer'),
                        new OA\Property(property: 'posted_count', type: 'integer'),
                    ]),
                    new OA\Property(property: 'outbound', type: 'object', nullable: true, properties: [
                        new OA\Property(property: 'pending_approval_count', type: 'integer'),
                        new OA\Property(property: 'posted_count', type: 'integer'),
                    ]),
                    new OA\Property(property: 'warranty', type: 'object', nullable: true, properties: [
                        new OA\Property(property: 'open_count', type: 'integer', minimum: 0),
                        new OA\Property(property: 'open_statuses', type: 'array', items: new OA\Items(type: 'string', enum: WarrantyWarehouseScope::OPEN_STATUSES)),
                        new OA\Property(property: 'warehouse_scope', type: 'object', properties: [
                            new OA\Property(property: 'contract', type: 'string', enum: [WarrantyWarehouseScope::VERSION]),
                            new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
                            new OA\Property(property: 'unassigned_policy', type: 'string', enum: ['INCLUDED', 'EXCLUDED']),
                            new OA\Property(property: 'ownership_complete', type: 'boolean', description: 'Coverage flag của nguồn owner, không phải số hồ sơ chưa gán trong kho.'),
                        ]),
                    ]),
                ]),
            ])),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu cả bốn quyền đọc hoặc warehouse_id ngoài data scope.'),
            new OA\Response(response: 404, description: 'Kho được yêu cầu không tồn tại.'),
            new OA\Response(response: 422, description: 'Bộ lọc sai, ngoài contract hoặc GET có payload khác rỗng.'),
        ]
    )]
    public function summary(Request $request)
    {
        // One input bag for stock and warranty: a GET body must not select a
        // different warehouse from the query consumed by warranty-owner-v1.
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        if ($body !== [] || $request->allFiles() !== []) {
            ApiError::abort(422, 'P02_QUERY_ONLY', 'KPI P02 chỉ nhận bộ lọc trong query của GET.');
        }
        if (array_diff(array_keys($request->query()), ['date', 'warehouse_id']) !== []) {
            ApiError::abort(422, 'P02_UNKNOWN_FILTER', 'Bộ lọc ngoài contract KPI P02.');
        }
        $validated = Validator::make($request->query(), [
            'date' => array_merge(['nullable', 'date_format:Y-m-d'], InputLimits::date()),
            'warehouse_id' => ['sometimes', 'required', 'uuid'],
        ])->validate();

        $date = $validated['date'] ?? now()->toDateString();
        $warehouseId = isset($validated['warehouse_id']) ? strtolower($validated['warehouse_id']) : null;
        if ($warehouseId !== null) {
            $request->query->set('warehouse_id', $warehouseId);
            if (! $request->user()?->canAccessWarehouse($warehouseId)) {
                ApiError::abort(403, 'DATA_SCOPE_DENIED', 'Kho yêu cầu nằm ngoài data scope hiện hành.');
            }
            if (! Warehouse::query()->whereKey($warehouseId)->exists()) {
                ApiError::abort(404, 'WAREHOUSE_NOT_FOUND', 'Không tìm thấy kho.');
            }
        }
        $scope = $this->readCacheScopeIds();
        $visible = [];
        foreach (self::SECTIONS as $key => $section) {
            $visible[$key] = (bool) $request->user()?->hasPermission($section['permission']);
        }

        // New payload namespace avoids reusing the role-only data_scope from v1.
        // No cache flush, business write, new owner inference or new permissions.
        $payload = $this->cachedReadPayload(
            'miniapp:dashboard:summary:p02-v2',
            array_merge(['date' => $date, 'warehouse_id' => $warehouseId], $visible),
            fn (): array => $this->buildSummary($date, $warehouseId, $visible, $scope),
            'stock_short',
            'stock:version',
        );

        $canReadWarranty = $request->user()?->hasPermission('warranty.view') || $request->user()?->hasPermission('warranty.manage');
        $payload['warranty'] = $canReadWarranty ? [
            'open_count' => WarrantyWarehouseScope::query($request)
                ->whereIn('status', WarrantyWarehouseScope::OPEN_STATUSES)->count(),
            'open_statuses' => WarrantyWarehouseScope::OPEN_STATUSES,
            'warehouse_scope' => WarrantyWarehouseScope::metadata($request),
        ] : null;

        $response = $this->respondSuccess($payload);
        $envelope = $response->getData(true);
        $envelope['meta']['contract'] = self::CONTRACT;

        return $response->setData($envelope)->header('Cache-Control', 'private, no-store');
    }

    private function buildSummary(string $date, ?string $warehouseId, array $visible, ?array $scope): array
    {
        $dayStart = Carbon::parse($date)->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();

        $summary = [
            'date' => $date,
            'timezone' => (string) config('app.timezone'),
            'warehouse_id' => $warehouseId,
            'data_scope' => $warehouseId === null && $scope === null ? 'GLOBAL' : 'WAREHOUSE_SCOPED',
            'authorization_scope' => $scope === null ? 'GLOBAL' : 'WAREHOUSE_SCOPED',
            'pending_approval_count' => 0,
            'posted_count' => 0,
        ];

        foreach (self::SECTIONS as $key => $section) {
            if (! $visible[$key]) {
                $summary[$key] = null;

                continue;
            }

            $documents = fn (): Builder => StockDocument::query()
                ->where('doc_type', $section['doc_type'])
                ->when($scope !== null, fn (Builder $query) => $query->whereIn($section['warehouse_column'], $scope))
                ->when($warehouseId !== null, fn (Builder $query) => $query->where($section['warehouse_column'], $warehouseId));

            // Phải trùng điều kiện "chờ duyệt" của danh sách phiếu Mini App, không thì badge lệch hàng chờ.
            $pending = $documents()
                ->where('status', StockDocument::STATUS_SCANNING)
                ->whereHas('lines')
                ->whereDoesntHave('lines', fn ($line) => $line->whereColumn('scanned_qty', '<>', 'qty_planned'))
                ->count();

            $posted = $documents()
                ->where('posted_at', '>=', $dayStart)
                ->where('posted_at', '<', $dayEnd)
                ->count();

            $summary[$key] = ['pending_approval_count' => $pending, 'posted_count' => $posted];
            $summary['pending_approval_count'] += $pending;
            $summary['posted_count'] += $posted;
        }

        return $summary;
    }
}
