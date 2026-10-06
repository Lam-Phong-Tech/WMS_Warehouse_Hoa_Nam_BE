<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\Validation\InputLimits;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use OpenApi\Attributes as OA;

class MiniAppDashboardController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    private const SECTIONS = [
        'inbound' => ['permission' => 'inbound.view', 'doc_type' => StockDocument::TYPE_RECEIPT, 'warehouse_column' => 'dst_warehouse_id'],
        'outbound' => ['permission' => 'outbound.view', 'doc_type' => StockDocument::TYPE_ISSUE, 'warehouse_column' => 'src_warehouse_id'],
    ];

    #[OA\Get(
        path: '/api/v1/mini-app/dashboard/summary',
        operationId: 'mini_app_dashboard_summary',
        x: ['permission' => 'inbound.view|outbound.view'],
        summary: 'Mini App - số liệu tổng hợp cho thẻ KPI màn Trang chủ',
        description: 'Một lần gọi cho các thẻ KPI. pending_approval_count là snapshot HIỆN TẠI (không theo `date`): phiếu SCANNING đã quét đủ mọi dòng — đúng điều kiện status=WAITING_APPROVAL của /mini-app/inbound-documents và ready_for_issue=1 của /mini-app/outbound-documents. posted_count đếm phiếu có posted_at trong ngày `date` theo múi giờ ứng dụng, kể cả phiếu sau đó bị đảo (REVERSED). Khối inbound/outbound là null khi người gọi thiếu inbound.view/outbound.view; hai số tổng chỉ cộng các khối được xem. Chịu warehouse data-scope của người gọi. Read-only, cache 20s bám stock:version.',
        security: [['bearerAuth' => []]],
        tags: ['22. Mini App'],
        parameters: [
            new OA\Parameter(name: 'date', in: 'query', description: 'YYYY-MM-DD. Mặc định hôm nay (Asia/Ho_Chi_Minh). Chỉ áp cho posted_count.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', description: 'Thu hẹp về một kho. Ngoài data-scope của người gọi trả 403 DATA_SCOPE_DENIED.', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Số liệu tổng hợp', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'timezone', type: 'string', example: 'Asia/Ho_Chi_Minh'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'data_scope', type: 'string', enum: ['GLOBAL', 'WAREHOUSE_SCOPED']),
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
                ]),
            ])),
            new OA\Response(response: 403, description: 'ACCESS_DENIED khi thiếu cả inbound.view lẫn outbound.view; DATA_SCOPE_DENIED khi warehouse_id ngoài data-scope.'),
            new OA\Response(response: 422, description: 'date sai định dạng YYYY-MM-DD hoặc ngoài cửa sổ ngày cho phép; warehouse_id không phải uuid.'),
        ]
    )]
    public function summary(Request $request)
    {
        $validated = $request->validate([
            'date' => array_merge(['nullable', 'date_format:Y-m-d'], InputLimits::date()),
            'warehouse_id' => ['nullable', 'uuid'],
        ]);

        $date = $validated['date'] ?? now()->toDateString();
        $warehouseId = $validated['warehouse_id'] ?? null;
        $visible = [];
        foreach (self::SECTIONS as $key => $section) {
            $visible[$key] = (bool) $request->user()?->hasPermission($section['permission']);
        }

        $payload = $this->cachedReadPayload(
            'miniapp:dashboard:summary',
            array_merge(['date' => $date, 'warehouse_id' => $warehouseId], $visible),
            fn (): array => $this->buildSummary($date, $warehouseId, $visible),
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

        return $this->respondSuccess($payload);
    }

    private function buildSummary(string $date, ?string $warehouseId, array $visible): array
    {
        $scope = $this->readCacheScopeIds();
        $dayStart = Carbon::parse($date)->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();

        $summary = [
            'date' => $date,
            'timezone' => (string) config('app.timezone'),
            'warehouse_id' => $warehouseId,
            'data_scope' => $scope === null ? 'GLOBAL' : 'WAREHOUSE_SCOPED',
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
