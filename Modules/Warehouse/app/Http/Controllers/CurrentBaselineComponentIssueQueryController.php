<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class CurrentBaselineComponentIssueQueryController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    #[OA\Get(
        path: '/api/v1/component-issue-documents/{id}',
        operationId: 'component_issue_query_show',
        summary: 'Chi tiết phiếu xuất linh kiện bảo hành',
        security: [['bearerAuth' => []]],
        tags: ['14. Component Issue'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'document + lines (kèm items) + stock_effect + movement_created'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warranty.component_issue hoặc ngoài warehouse data scope'),
            new OA\Response(response: 404, description: 'RESOURCE_NOT_FOUND'),
        ]
    )]
    #[OA\Get(
        path: '/api/v1/warranty-component-issues/{id}',
        operationId: 'component_issue_query_show_alias',
        summary: 'Chi tiết phiếu xuất linh kiện bảo hành (Annex C alias)',
        security: [['bearerAuth' => []]],
        tags: ['14. Component Issue'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'document + lines (kèm items) + stock_effect + movement_created'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warranty.component_issue hoặc ngoài warehouse data scope'),
            new OA\Response(response: 404, description: 'RESOURCE_NOT_FOUND'),
        ]
    )]
    public function show(string $id)
    {
        // Quyền `warranty.component_issue` + EnforceComponentIssueWarehouseScope chạy
        // ở tầng route (routes/api.php + mini_app.php) nên 403 luôn xảy ra TRƯỚC cache
        // — một cache hit không thể nuốt mất 403 của phiếu ngoài data scope.
        // 404 cũng nằm ngoài cache: không cache negative, và không để exception ném từ
        // trong closure khiến CacheService chạy resolver lần hai.
        $document = DB::table('component_issue_documents')->where('id', $id)->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy Component Issue.');
        }

        // TTL 'search' (120s): chứng từ đang lưu thông. `status` nằm trong khoá vì
        // stock_effect/movement_created suy ra từ nó; bước Post đi qua mutateBalance()
        // nên `stock:version` cũng tự bump.
        $payload = $this->cachedReadPayload(
            'warehouse:component-issue:detail',
            ['id' => $id, 'status' => (string) ($document->status ?? '')],
            fn (): array => $this->buildShowPayload($id, $document),
            'search',
            'stock:version',
        );

        return $this->respondSuccess($payload);
    }

    /**
     * Phần chạy SQL của show(), tách ra để cache được. Trả về ĐÚNG mảng mà
     * respondSuccess() vẫn nhận trước đây — không đổi hình dạng response.
     *
     * @return array<string, mixed>
     */
    private function buildShowPayload(string $id, object $document): array
    {
        $lines = DB::table('component_issue_lines')
            ->where('component_issue_document_id', $id)
            ->orderBy('line_no')
            ->get()
            ->map(function (object $line): array {
                $sku = DB::table('sku')->where('sku_id', $line->sku_id)->first();
                $items = DB::table('component_issue_items')
                    ->where('component_issue_line_id', $line->id)
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn (object $item): array => (array) $item)
                    ->all();

                return [
                    ...(array) $line,
                    'sku_code' => $sku?->sku_code,
                    'sku_name' => $sku?->sku_name,
                    'items' => $items,
                ];
            })
            ->all();

        return [
            'document' => (array) $document,
            'lines' => $lines,
            'stock_effect' => $document->status === 'POSTED' ? 'DECREASE' : 'NONE',
            'movement_created' => $document->status === 'POSTED',
        ];
    }
}
