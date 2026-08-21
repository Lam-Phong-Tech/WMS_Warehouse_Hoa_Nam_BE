<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreDefectCatalogRequest;
use Modules\Warehouse\Http\Requests\UpdateDefectCatalogRequest;
use Modules\Warehouse\Repositories\Contracts\DefectCatalogRepositoryInterface;
use OpenApi\Attributes as OA;

/**
 * Danh mục bệnh/lỗi máy dùng cho bảo hành và báo cáo.
 *
 * Không có thao tác xoá: BR-DEF-01..04 yêu cầu "history không bị đổi" và Annex C
 * không công bố deleteDefect. Ngừng dùng bằng PATCH status=INACTIVE.
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
                    new OA\Property(property: 'defect_code', type: 'string', maxLength: 80, example: 'LOI-0001'),
                    new OA\Property(property: 'defect_name', type: 'string', maxLength: 200, example: 'Chập cháy động cơ'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Màn hình bị nứt góc trái'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
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
    #[OA\Post(
        path: '/api/v1/defects',
        operationId: 'defect_create',
        summary: 'Tạo bệnh/lỗi bảo hành (Annex C createDefect — cùng handler với POST /defect-catalogs)',
        security: [['bearerAuth' => []]],
        tags: ['17. Defect Catalog'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['defect_code', 'defect_name'],
                properties: [
                    new OA\Property(property: 'defect_code', type: 'string', maxLength: 80, example: 'LOI-0001'),
                    new OA\Property(property: 'defect_name', type: 'string', maxLength: 200, example: 'Chập cháy động cơ'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Màn hình bị nứt góc trái'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tạo danh mục lỗi thành công'),
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
                new OA\Property(property: 'defect_code', type: 'string', maxLength: 80, example: 'LOI-0001'),
                new OA\Property(property: 'defect_name', type: 'string', maxLength: 200, example: 'Chập cháy động cơ'),
                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Màn hình bị nứt góc trái'),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
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
    #[OA\Patch(
        path: '/api/v1/defects/{id}',
        operationId: 'defect_update',
        summary: 'Cập nhật bệnh/lỗi bảo hành (Annex C updateDefect — cùng handler với PATCH /defect-catalogs/{id})',
        security: [['bearerAuth' => []]],
        tags: ['17. Defect Catalog'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'defect_code', type: 'string', maxLength: 80, example: 'LOI-0001'),
                new OA\Property(property: 'defect_name', type: 'string', maxLength: 200, example: 'Chập cháy động cơ'),
                new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Màn hình bị nứt góc trái'),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, example: 'ACTIVE'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật danh mục lỗi thành công'),
        ]
    )]
    public function update(UpdateDefectCatalogRequest $request, string $id)
    {
        $before = $this->defects->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy danh mục lỗi.');
        }

        $validated = $request->validated();
        $this->assertVersionIfProvided($request, $before);
        $this->assertCodeImmutableAfterUse($id, $validated, $before);

        $beforeData = $before->toArray();
        $defect = $this->defects->update($id, $validated);

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

    /**
     * Annex C updateDefect + ERD defect_catalog.version: If-Match là optimistic
     * locking. Chỉ enforce khi client gửi header.
     */
    private function assertVersionIfProvided(Request $request, Model $current): void
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            return;
        }
        $header = trim(preg_replace('/^W\//', '', $header) ?? $header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên.');
        }
        if ((int) $header !== (int) ($current->version ?? 0)) {
            ApiError::abort(412, 'STALE_VERSION', 'Danh mục lỗi đã được cập nhật bởi phiên khác; tải lại trước khi lưu.', [
                'expected_version' => (int) $header,
                'current_version' => (int) ($current->version ?? 0),
            ]);
        }
    }

    /**
     * BR-DEF-01..04: "history không bị đổi" — lỗi đã gắn vào hồ sơ bảo hành thì
     * defect_code là dữ liệu lịch sử, chỉ được deactivate chứ không đổi mã.
     */
    private function assertCodeImmutableAfterUse(string $id, array $payload, Model $current): void
    {
        if (! array_key_exists('defect_code', $payload)) {
            return;
        }
        if (strtoupper(trim((string) $payload['defect_code'])) === strtoupper(trim((string) $current->defect_code))) {
            return;
        }

        if (DB::table('warranty_case')->where('defect_id', $id)->exists()) {
            ApiError::abort(409, 'DEFECT_CODE_IMMUTABLE_AFTER_USE', 'Lỗi đã được gán vào hồ sơ bảo hành nên không đổi được defect_code.');
        }
    }
}
