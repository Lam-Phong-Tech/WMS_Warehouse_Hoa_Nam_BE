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

class DepartmentController extends Controller
{
    use ApiResponse;

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
