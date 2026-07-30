<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Models\DocumentStatusHistory;
use OpenApi\Attributes as OA;

/** Lịch sử chuyển trạng thái chứng từ (append-only, read-only). */
class DocumentStatusHistoryController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/document-status-history',
        operationId: 'document_status_history_list',
        summary: 'Lịch sử chuyển trạng thái chứng từ (inbound/outbound documents)',
        security: [['bearerAuth' => []]],
        tags: ['06. Warehouse'],
        parameters: [
            new OA\Parameter(name: 'stock_document_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách lịch sử chuyển trạng thái'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function index(Request $request)
    {
        $query = DocumentStatusHistory::query();

        if ($request->filled('stock_document_id')) {
            $query->where('stock_document_id', $request->input('stock_document_id'));
        }

        return $this->respondPaginated($query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 15)));
    }
}
