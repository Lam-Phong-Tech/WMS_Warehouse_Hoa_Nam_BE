<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Auth\Repositories\Contracts\PermissionRepositoryInterface;
use OpenApi\Attributes as OA;

/**
 * Chỉ tra cứu — permission là danh mục hệ thống cố định theo route middleware
 * `permission:<CODE>`, không cho tạo/sửa/xoá tự do qua API.
 */
class PermissionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PermissionRepositoryInterface $permissions) {}

    #[OA\Get(
        path: '/api/v1/permissions',
        operationId: 'permission_list',
        summary: 'Danh sách permission (phân trang, filter theo keyword/status/scope_type)',
        security: [['bearerAuth' => []]],
        tags: ['04. Permissions'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách permission'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền permission.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->permissions->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    #[OA\Get(
        path: '/api/v1/permissions/{id}',
        operationId: 'permission_detail',
        summary: 'Chi tiết permission',
        security: [['bearerAuth' => []]],
        tags: ['04. Permissions'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết permission'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy permission'),
        ]
    )]
    public function show(string $id)
    {
        $permission = $this->permissions->find($id);

        return $permission ? $this->respondSuccess($permission) : $this->respondNotFound('Không tìm thấy permission.');
    }
}
