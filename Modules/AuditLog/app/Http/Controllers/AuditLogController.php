<?php

namespace Modules\AuditLog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Repositories\Contracts\AuditLogRepositoryInterface;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogRepositoryInterface $auditLogs) {}

    #[OA\Get(
        path: '/api/v1/audit-logs',
        operationId: 'audit_log_list',
        summary: 'Nhật ký thao tác hệ thống (chỉ đọc — ghi chỉ qua AuditLogger::record() nội bộ, không có endpoint ghi)',
        security: [['bearerAuth' => []]],
        tags: ['15. Audit Log'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách audit log (actor_id/action/object_type/object_id/before_data/after_data/created_at)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền audit_log.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->auditLogs->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }
}
