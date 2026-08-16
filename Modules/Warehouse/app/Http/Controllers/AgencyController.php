<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Requests\StoreAgencyRequest;
use Modules\Warehouse\Http\Requests\UpdateAgencyRequest;
use Modules\Warehouse\Repositories\Contracts\AgencyRepositoryInterface;
use OpenApi\Attributes as OA;

/**
 * Danh mục đại lý — chỉ dùng để tham chiếu/gán, KHÔNG có inventory_balance riêng
 * (BR-AGY-01/DEC-WM-001).
 */
class AgencyController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AgencyRepositoryInterface $agencies) {}

    #[OA\Get(
        path: '/api/v1/agencies',
        operationId: 'agency_list',
        summary: 'Danh sách đại lý (phân trang, filter theo keyword/status)',
        security: [['bearerAuth' => []]],
        tags: ['16. Agency'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách đại lý'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền agency.view'),
        ]
    )]
    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->agencies->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }

    #[OA\Post(
        path: '/api/v1/agencies',
        operationId: 'agency_create',
        summary: 'Tạo đại lý mới',
        security: [['bearerAuth' => []]],
        tags: ['16. Agency'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['agency_code', 'agency_name'],
                properties: [
                    new OA\Property(property: 'agency_code', type: 'string', maxLength: 50, example: 'DL-0001'),
                    new OA\Property(property: 'agency_name', type: 'string', maxLength: 255, example: 'Đại lý Hoà Nam Q1'),
                    new OA\Property(property: 'contact_name', type: 'string', maxLength: 200, nullable: true),
                    new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true),
                    new OA\Property(property: 'address', type: 'string', maxLength: 500, nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo đại lý thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền agency.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: agency_code đã tồn tại)'),
        ]
    )]
    public function store(StoreAgencyRequest $request)
    {
        $agency = $this->agencies->create($request->validated() + ['status' => 'ACTIVE']);

        AuditLogger::record(
            action: 'CREATE',
            objectType: 'agency',
            objectId: $agency->agency_id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: $agency->toArray(),
        );

        return $this->respondCreated($agency, 'Tạo đại lý thành công');
    }

    #[OA\Get(
        path: '/api/v1/agencies/{id}',
        operationId: 'agency_detail',
        summary: 'Chi tiết đại lý',
        security: [['bearerAuth' => []]],
        tags: ['16. Agency'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết đại lý'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy đại lý'),
        ]
    )]
    public function show(string $id)
    {
        $agency = $this->agencies->find($id);

        return $agency ? $this->respondSuccess($agency) : $this->respondNotFound('Không tìm thấy đại lý.');
    }

    #[OA\Patch(
        path: '/api/v1/agencies/{id}',
        operationId: 'agency_update',
        summary: 'Sửa đại lý',
        security: [['bearerAuth' => []]],
        tags: ['16. Agency'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'agency_code', type: 'string', maxLength: 50),
                new OA\Property(property: 'agency_name', type: 'string', maxLength: 255),
                new OA\Property(property: 'contact_name', type: 'string', maxLength: 200, nullable: true),
                new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true),
                new OA\Property(property: 'address', type: 'string', maxLength: 500, nullable: true),
                new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền agency.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy đại lý'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function update(UpdateAgencyRequest $request, string $id)
    {
        $before = $this->agencies->find($id);

        if (! $before) {
            return $this->respondNotFound('Không tìm thấy đại lý.');
        }

        $validated = $request->validated();
        $this->assertVersionIfProvided($request, $before);
        $this->assertCodeImmutableAfterUse($id, $validated, $before);

        $beforeData = $before->toArray();
        $agency = $this->agencies->update($id, $validated);

        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'agency',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
            afterData: $agency->toArray(),
        );

        return $this->respondSuccess($agency, 'Cập nhật đại lý thành công');
    }

    #[OA\Delete(
        path: '/api/v1/agencies/{id}',
        operationId: 'agency_delete',
        summary: 'Xoá đại lý (bị chặn nếu đang được tham chiếu)',
        security: [['bearerAuth' => []]],
        tags: ['16. Agency'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Xoá thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền agency.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy đại lý'),
            new OA\Response(response: 409, description: 'Đại lý đang được tham chiếu, không thể xoá'),
        ]
    )]
    public function destroy(Request $request, string $id)
    {
        $agency = $this->agencies->find($id);

        if (! $agency) {
            return $this->respondNotFound('Không tìm thấy đại lý.');
        }

        $beforeData = $agency->toArray();

        try {
            $this->agencies->delete($id);
        } catch (QueryException) {
            return $this->respondConflict('Đại lý đang được tham chiếu, không thể xoá.', [], 'REFERENCE_EXISTS');
        }

        AuditLogger::record(
            action: 'DELETE',
            objectType: 'agency',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: $beforeData,
        );

        return $this->respondSuccess(null, 'Xoá đại lý thành công');
    }

    /**
     * Annex C updateAgency + ERD agencies.version: If-Match là optimistic locking.
     * Chỉ enforce khi client gửi header, để client cũ không bị vỡ.
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
            ApiError::abort(412, 'STALE_VERSION', 'Đại lý đã được cập nhật bởi phiên khác; tải lại trước khi lưu.', [
                'expected_version' => (int) $header,
                'current_version' => (int) ($current->version ?? 0),
            ]);
        }
    }

    /**
     * §8.1 Master: "immutable code after use" + BR-AGY-01. Đại lý đã xuất hiện trên
     * chứng từ/toa thì agency_code là dữ liệu lịch sử, không được đổi.
     */
    private function assertCodeImmutableAfterUse(string $id, array $payload, Model $current): void
    {
        if (! array_key_exists('agency_code', $payload)) {
            return;
        }
        if (strtoupper(trim((string) $payload['agency_code'])) === strtoupper(trim((string) $current->agency_code))) {
            return;
        }

        $referenced = DB::table('stock_documents')->where('agency_id', $id)->exists()
            || (Schema::hasTable('outbound_requests') && DB::table('outbound_requests')->where('agency_id', $id)->exists());

        if ($referenced) {
            ApiError::abort(409, 'AGENCY_CODE_IMMUTABLE_AFTER_USE', 'Đại lý đã được dùng trên chứng từ nên không đổi được agency_code.');
        }
    }
}
