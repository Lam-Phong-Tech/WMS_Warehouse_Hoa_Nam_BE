<?php

namespace Modules\AuditLog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Repositories\Contracts\AuditLogRepositoryInterface;
use Modules\AuditLog\Services\AuditLogger;
use Modules\AuditLog\Services\AuditObjectReferenceResolver;
use Modules\Auth\Models\User;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    use ApiResponse;

    /**
     * Prefix quyền -> module audit log. Dùng để suy phạm vi module mà 1 user
     * (không phải super admin / không có `audit_log.view_all`) được phép xem —
     * mục 6: "Người dùng chỉ được xem lịch sử trong phạm vi module/quyền của
     * mình". Giữ đồng bộ thủ công với AuditLogger::classifyModule() (module
     * được GHI vào audit_log) — 2 nơi cùng khái niệm nhưng khác input (permission
     * key so với object_type) nên không gộp chung được thành 1 hàm.
     */
    private const MODULE_BY_PERMISSION_PREFIX = [
        'inbound.' => AuditLogger::MODULE_INBOUND,
        'putaway.' => AuditLogger::MODULE_INBOUND,
        'outbound.' => AuditLogger::MODULE_OUTBOUND,
        'warranty.' => AuditLogger::MODULE_WARRANTY,
        'inventory.' => AuditLogger::MODULE_STOCKTAKE,
    ];

    public function __construct(
        private readonly AuditLogRepositoryInterface $auditLogs,
        private readonly AuditObjectReferenceResolver $objectReferences,
    ) {}

    #[OA\Get(
        path: '/api/v1/audit-logs',
        operationId: 'audit_log_list',
        summary: 'Lấy danh sách nhật ký thao tác',
        description: 'Hỗ trợ phân trang, lọc theo user_id, action, module, khoảng thời gian (date_from/date_to) và tìm kiếm (q): mô tả/lý do, hành động (mã hoặc nhãn), hoặc người thực hiện (tên/email). Yêu cầu quyền audit_log.view; không có audit_log.view_all (và không phải super admin) thì kết quả chỉ giới hạn trong các module tương ứng quyền nghiệp vụ hiện có của actor.',
        security: [['bearerAuth' => []]],
        tags: ['15. Audit Log'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'q', in: 'query', description: 'Tìm theo mô tả/lý do, hành động (mã hoặc nhãn), hoặc người thực hiện (tên/email)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'actions[]', in: 'query', description: 'Mã hành động khớp nhãn tìm kiếm (OR với q)', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
            new OA\Parameter(name: 'user_id', in: 'query', description: 'Lọc theo actor_id (UUID)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'action', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'module', in: 'query', description: 'SYSTEM | INBOUND | OUTBOUND | WARRANTY | STOCKTAKE', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', description: 'Lọc created_at >= (Y-m-d hoặc Y-m-d H:i:s)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_to', in: 'query', description: 'Lọc created_at <= (Y-m-d hoặc Y-m-d H:i:s)', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách audit log (actor/action/module/object_type/object_id/description/before_data/after_data/created_at)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền audit_log.view'),
        ]
    )]
    public function index(Request $request)
    {
        $filters = $request->all();
        $filters['sort_by'] = $filters['sort_by'] ?? 'created_at';
        $filters['sort_order'] = $filters['sort_order'] ?? 'desc';

        $allowedModules = $this->allowedModulesFor($request->user());

        $page = $this->auditLogs->paginate(
            $filters,
            (int) $request->input('per_page', 10),
            $allowedModules === null ? null : fn ($query) => $query->whereIn('module', $allowedModules),
        );
        // `object_code`: số phiếu / mã hồ sơ / serial... để màn nhật ký không phải
        // hiện UUID `object_id` cho người dùng — xem AuditObjectReferenceResolver.
        $this->objectReferences->attach($page->getCollection());

        return $this->respondPaginated($page);
    }

    #[OA\Get(
        path: '/api/v1/audit-logs/me',
        operationId: 'audit_log_my_history',
        summary: 'Lịch sử thay đổi quyền/role/thông tin TRÊN chính tài khoản đang đăng nhập (không cần quyền audit_log.view)',
        security: [['bearerAuth' => []]],
        tags: ['15. Audit Log'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'action', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lịch sử thay đổi trên chính tài khoản (object_type=user, object_id=chính user đang gọi)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function myHistory(Request $request)
    {
        $filters = $request->all();
        $userId = (string) $request->user()->user_id;

        $page = $this->auditLogs->paginate(
            $filters,
            (int) $request->input('per_page', 10),
            // Ép cứng object_type/object_id về chính user đang gọi bằng scope
            // closure (không đi qua $filterable) — không cho client tự truyền
            // giá trị khác để xem lịch sử người khác qua endpoint tự-xem, và
            // tránh guard "cột *_id phải numeric" của BaseRepository (object_id
            // ở đây là UUID string).
            fn ($query) => $query->where('object_type', 'user')->where('object_id', $userId),
        );
        $this->objectReferences->attach($page->getCollection());

        return $this->respondPaginated($page);
    }

    /**
     * @return array<int, string>|null null = không giới hạn (super admin hoặc audit_log.view_all)
     */
    private function allowedModulesFor(?User $user): ?array
    {
        if ($user === null) {
            return [];
        }

        if ($user->isSuperAdmin() || $user->hasPermission('audit_log.view_all')) {
            return null;
        }

        $modules = [];
        foreach ($user->permissionCodes() as $permissionKey) {
            foreach (self::MODULE_BY_PERMISSION_PREFIX as $prefix => $module) {
                if (str_starts_with($permissionKey, $prefix)) {
                    $modules[$module] = true;
                }
            }
        }

        // Không có quyền nghiệp vụ theo module nào (chỉ giữ audit_log.view trần) ->
        // mặc định cho xem nhóm SYSTEM (nhật ký hệ thống/danh mục) thay vì trả rỗng
        // hoàn toàn, tránh trải nghiệm "trắng trang" khó hiểu.
        if ($modules === []) {
            return [AuditLogger::MODULE_SYSTEM];
        }

        return array_keys($modules);
    }
}
