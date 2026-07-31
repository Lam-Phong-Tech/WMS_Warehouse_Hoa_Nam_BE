<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Models\DocumentStatusTransition;
use OpenApi\Attributes as OA;

/** Quản lý danh mục tham khảo của state machine chứng từ kho (không phải nguồn enforcement). */
class DocumentStatusTransitionController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/document-status-transitions',
        operationId: 'document_status_transition_list',
        summary: 'Danh sách transitions của state machine chứng từ kho',
        security: [['bearerAuth' => []]],
        tags: ['06. Warehouse'],
        parameters: [
            new OA\Parameter(name: 'doc_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['receipt', 'issue', 'transfer'])),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách transitions'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warehouse.view'),
        ]
    )]
    public function index(Request $request)
    {
        $query = DocumentStatusTransition::query();

        if ($request->filled('doc_type')) {
            $query->where('doc_type', $request->input('doc_type'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $this->respondPaginated(
            $query->orderBy('doc_type')->orderBy('from_status')->paginate((int) $request->input('per_page', 15))
        );
    }

    #[OA\Patch(
        path: '/api/v1/document-status-transitions/{id}/toggle',
        operationId: 'document_status_transition_toggle',
        summary: 'Bật/tắt một transition trong danh mục tham khảo',
        security: [['bearerAuth' => []]],
        tags: ['06. Warehouse'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Đã cập nhật transition'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền warehouse.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy transition'),
        ]
    )]
    public function toggle(string $id)
    {
        $transition = DocumentStatusTransition::find($id);

        if (! $transition) {
            return $this->respondNotFound('Không tìm thấy transition.');
        }

        $transition->update(['is_active' => ! $transition->is_active]);

        return $this->respondSuccess($transition, $transition->is_active ? 'Đã bật transition' : 'Đã tắt transition');
    }
}
