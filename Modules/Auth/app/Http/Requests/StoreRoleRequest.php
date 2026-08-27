<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `SUPER_ADMIN` là mã hệ thống: User::isSuperAdmin() nhận diện siêu quản trị
     * bằng đúng chuỗi này, nên ai tạo được một role mang mã đó là tự cấp cho mình
     * quyền bỏ qua toàn bộ CheckPermission. Trước đây chỉ có `unique` chặn — tức
     * an toàn phụ thuộc vào việc dòng SUPER_ADMIN gốc còn tồn tại, một điều kiện
     * DỮ LIỆU chứ không phải bất biến mã nguồn.
     */
    public const RESERVED_ROLE_CODES = ['SUPER_ADMIN'];

    public function rules(): array
    {
        return [
            'role_code' => [
                'required', 'string', 'max:100', 'unique:role,role_code',
                Rule::notIn(self::RESERVED_ROLE_CODES),
            ],
            'role_name' => ['required', 'string', 'max:255'],
            'scope_type' => ['nullable', Rule::in(['SYSTEM', 'WAREHOUSE', 'FUNCTION'])],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }

    public function messages(): array
    {
        return [
            'role_code.not_in' => 'Mã vai trò này được hệ thống giữ riêng, vui lòng chọn mã khác.',
        ];
    }
}
