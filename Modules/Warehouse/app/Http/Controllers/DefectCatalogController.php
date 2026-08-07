<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreDefectCatalogRequest;
use Modules\Warehouse\Http\Requests\UpdateDefectCatalogRequest;
use Modules\Warehouse\Repositories\Contracts\DefectCatalogRepositoryInterface;
use OpenApi\Attributes as OA;

/**
 * Danh mục bệnh/lỗi máy dùng cho bảo hành và báo cáo.
 */
class DefectCatalogController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DefectCatalogRepositoryInterface $defects) {}

    #[OA\Get(
        path: '/api/v1/defect-catalogs',
        operationId: 'defect_catalog_list',
        summary: 'Danh sách lỗi/bệnh máy (phân trang, filter theo keyword/status)',
        security: [['bearerAuth' => []]],
        tags: ['17. Defect Catalog'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách lỗi/bệnh máy'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền defect_catalog.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->defects->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    #[OA\Post(
        path: '/api/v1/defect-catalogs',
        operationId: 'defect_catalog_create',
        summary: 'Tạo danh mục lỗi/bệnh máy mới',
        security: [['bearerAuth' => []]],
        tags: ['17. Defect Catalog'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['defect_code', 'defect_name'],
                properties: [
                    new OA\Property(property: 'defect_code', type: 'string', maxLength: 50, example: 'LOI-0001'),
                    new OA\Property(property: 'defect_name', type: 'string', maxLength: 255, example: 'Chập cháy động cơ'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền defect_catalog.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: defect_code đã tồn tại)'),
        ]
    )]
    public function store(StoreDefectCatalogRequest $request)
    {
        $defect = $this->defects->create($request->validated() + ['status' => 'ACTIVE']);

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'defect_catalog',
            objectId: $defect->defect_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $defect->toArray(),
        );

        return $this->respondCreated($defect, 'Tạo danh mục lỗi thành công');
    }

    #[OA\Get(
        path: '/api/v1/defect-catalogs/{id}',
        operationId: 'defect_catalog_detail',
        summary: 'Chi tiết lỗi/bệnh máy',
        security: [['bearerAuth' => []]],
        tags: ['17. Defect Catalog'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết lỗi/bệnh máy'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy'),
        ]
    )]
    public function show(string $id)
    {
        $defect = $this->defects->find($id);

        return $defect ? $this->respondSuccess($defect) : $this->respondNotFound('Không tìm thấy danh mục lỗi.');
    }

    #[OA\Patch(
        path: '/api/v1/defect-catalogs/{id}',
        operationId: 'defect_catalog_update',
        summary: 'Sửa lỗi/bệnh máy',
        security: [['bearerAuth' => []]],
        tags: ['17. Defect Catalog'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'defect_code', type: 'string', maxLength: 50),
                new OA\Property(property: 'defect_name', type: 'string', maxLength: 255),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền defect_catalog.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateDefectCatalogRequest $request, string $id)
    {
        $before = $this->defects->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy danh mục lỗi.');
        }

        $beforeData = $before->toArray();
        $defect = $this->defects->update($id, $request->validated());

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'defect_catalog',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $defect->toArray(),
        );

        return $this->respondSuccess($defect, 'Cập nhật danh mục lỗi thành công');
    }

    #[OA\Delete(
        path: '/api/v1/defect-catalogs/{id}',
        operationId: 'defect_catalog_delete',
        summary: 'Xoá lỗi/bệnh máy (bị chặn nếu đang được tham chiếu)',
        security: [['bearerAuth' => []]],
        tags: ['17. Defect Catalog'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Xoá thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền defect_catalog.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy'),
            new OA\Response(response: 409, description: 'Đang được tham chiếu, không thể xoá'),
        ]
    )]
    public function destroy(Request $request, string $id)
    {
        $defect = $this->defects->find($id);

        if (! $defect) {
            return $this->respondNotFound('Không tìm thấy danh mục lỗi.');
        }

        $beforeData = $defect->toArray();

        try {
            $this->defects->delete($id);
        } catch (QueryException) {
            return $this->respondConflict('Danh mục lỗi đang được tham chiếu, không thể xoá.', [], 'REFERENCE_EXISTS');
        }

        AuditLogger::record(
            action: 'DELETE',
            objectType: 'defect_catalog',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
        );

        return $this->respondSuccess(null, 'Xoá danh mục lỗi thành công');
    }
}
