<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;
use OpenApi\Attributes as OA;

class DepartmentController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/departments',
        operationId: 'department_list',
        summary: 'Danh sách phòng ban (phân trang, filter theo keyword/status)',
        security: [['bearerAuth' => []]],
        tags: ['08. Departments'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách phòng ban'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền iam.user.view'),
        ]
    )]
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'keyword' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = DB::table('department');
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower(trim((string) $validated['keyword'])).'%';
            $query->where(function ($nested) use ($keyword): void {
                $nested->whereRaw('LOWER(department_code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(department_name) LIKE ?', [$keyword]);
            });
        }

        return $this->respondPaginated(
            $query->orderBy('department_name')->paginate((int) ($validated['per_page'] ?? 10))
        );
    }

    #[OA\Post(
        path: '/api/v1/departments',
        operationId: 'department_create',
        summary: 'Tạo phòng ban mới',
        security: [['bearerAuth' => []]],
        tags: ['08. Departments'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['department_code', 'department_name'],
                properties: [
                    new OA\Property(property: 'department_code', type: 'string', maxLength: 80, example: 'HN-DEPT-KHO'),
                    new OA\Property(property: 'department_name', type: 'string', maxLength: 200, example: 'Phòng Kho vận'),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true, default: 'ACTIVE'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tạo phòng ban thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền iam.user.manage'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: department_code đã tồn tại)'),
        ]
    )]
    public function store(Request $request)
    {
        $validated = $request->validate([
            'department_code' => ['required', 'string', 'max:80', 'unique:department,department_code'],
            'department_name' => ['required', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $id = (string) Str::uuid();
        DB::table('department')->insert([
            'department_id' => $id,
            'department_code' => strtoupper(trim((string) $validated['department_code'])),
            'department_name' => trim((string) $validated['department_name']),
            'status' => $validated['status'] ?? 'ACTIVE',
            'description' => $validated['description'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = DB::table('department')->where('department_id', $id)->first();
        AuditLogger::record(
            action: 'CREATE',
            objectType: 'department',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: (array) $row,
        );

        return $this->respondCreated($row, 'Tạo phòng ban thành công.');
    }

    #[OA\Patch(
        path: '/api/v1/departments/{id}',
        operationId: 'department_update',
        summary: 'Cập nhật phòng ban',
        security: [['bearerAuth' => []]],
        tags: ['08. Departments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'department_code', type: 'string', maxLength: 80),
                    new OA\Property(property: 'department_name', type: 'string', maxLength: 200),
                    new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], nullable: true),
                    new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cập nhật phòng ban thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền iam.user.manage'),
            new OA\Response(response: 404, description: 'Không tìm thấy phòng ban'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ (vd: department_code đã tồn tại)'),
        ]
    )]
    public function update(Request $request, string $id)
    {
        $before = DB::table('department')->where('department_id', $id)->first();
        if ($before === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phòng ban.');
        }
        $validated = $request->validate([
            'department_code' => [
                'sometimes', 'string', 'max:80',
                Rule::unique('department', 'department_code')->ignore($id, 'department_id'),
            ],
            'department_name' => ['sometimes', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        if (array_key_exists('department_code', $validated)) {
            $validated['department_code'] = strtoupper(trim((string) $validated['department_code']));
        }
        if (array_key_exists('department_name', $validated)) {
            $validated['department_name'] = trim((string) $validated['department_name']);
        }
        $validated['updated_at'] = now();
        DB::table('department')->where('department_id', $id)->update($validated);
        $after = DB::table('department')->where('department_id', $id)->first();
        AuditLogger::record(
            action: 'UPDATE',
            objectType: 'department',
            objectId: $id,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            beforeData: (array) $before,
            afterData: (array) $after,
        );

        return $this->respondSuccess($after, 'Cập nhật phòng ban thành công.');
    }
}
