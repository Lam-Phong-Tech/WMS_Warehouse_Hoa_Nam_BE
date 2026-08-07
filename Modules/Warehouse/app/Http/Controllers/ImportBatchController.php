<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreImportBatchRequest;
use Modules\Warehouse\Http\Requests\UpdateImportBatchRequest;
use Modules\Warehouse\Repositories\Contracts\ImportBatchRepositoryInterface;
use OpenApi\Attributes as OA;

/**
 * Lô/đợt nhập khẩu nghiệp vụ (ERD §5.3 import_batch) — dùng cho traceability của item_instance.
 */
class ImportBatchController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ImportBatchRepositoryInterface $importBatches) {}

    #[OA\Get(
        path: '/api/v1/import-batches',
        operationId: 'import_batch_list',
        summary: 'Danh sách lô nhập khẩu (phân trang, filter theo keyword/status)',
        security: [['bearerAuth' => []]],
        tags: ['22. Import Batch'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách lô nhập khẩu'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền import_batch.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->importBatches->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    #[OA\Post(
        path: '/api/v1/import-batches',
        operationId: 'import_batch_create',
        summary: 'Tạo lô nhập khẩu mới',
        security: [['bearerAuth' => []]],
        tags: ['22. Import Batch'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['import_batch_code'],
                properties: [
                    new OA\Property(property: 'import_batch_code', type: 'string', maxLength: 80),
                    new OA\Property(property: 'shipment_container_code', type: 'string', maxLength: 100, nullable: true),
                    new OA\Property(property: 'supplier_batch_no', type: 'string', maxLength: 100, nullable: true),
                    new OA\Property(property: 'import_period', type: 'string', maxLength: 30, nullable: true),
                    new OA\Property(property: 'supplier_name', type: 'string', maxLength: 200, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo lô nhập khẩu thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền import_batch.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: import_batch_code đã tồn tại)'),
        ]
    )]
    public function store(StoreImportBatchRequest $request)
    {
        $importBatch = $this->importBatches->create($request->validated() + ['status' => 'ACTIVE']);

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'import_batch',
            objectId: $importBatch->import_batch_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $importBatch->toArray(),
        );

        return $this->respondCreated($importBatch, 'Tạo lô nhập khẩu thành công');
    }

    #[OA\Get(
        path: '/api/v1/import-batches/{id}',
        operationId: 'import_batch_detail',
        summary: 'Chi tiết lô nhập khẩu',
        security: [['bearerAuth' => []]],
        tags: ['22. Import Batch'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết lô nhập khẩu'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy lô nhập khẩu'),
        ]
    )]
    public function show(string $id)
    {
        $importBatch = $this->importBatches->find($id);

        return $importBatch ? $this->respondSuccess($importBatch) : $this->respondNotFound('Không tìm thấy lô nhập khẩu.');
    }

    #[OA\Patch(
        path: '/api/v1/import-batches/{id}',
        operationId: 'import_batch_update',
        summary: 'Sửa lô nhập khẩu',
        security: [['bearerAuth' => []]],
        tags: ['22. Import Batch'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'import_batch_code', type: 'string', maxLength: 80),
                new OA\Property(property: 'shipment_container_code', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'supplier_batch_no', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'import_period', type: 'string', maxLength: 30, nullable: true),
                new OA\Property(property: 'supplier_name', type: 'string', maxLength: 200, nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền import_batch.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy lô nhập khẩu'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateImportBatchRequest $request, string $id)
    {
        $before = $this->importBatches->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy lô nhập khẩu.');
        }

        $beforeData = $before->toArray();
        $importBatch = $this->importBatches->update($id, $request->validated());

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'import_batch',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $importBatch->toArray(),
        );

        return $this->respondSuccess($importBatch, 'Cập nhật lô nhập khẩu thành công');
    }
}
