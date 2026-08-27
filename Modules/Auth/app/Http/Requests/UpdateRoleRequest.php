<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // notIn: EnsureMutableRole chặn SỬA chính role SUPER_ADMIN, nhưng không
            // chặn ĐỔI TÊN một role thường thành SUPER_ADMIN. Xem StoreRoleRequest
            // để biết vì sao không được dựa vào mỗi ràng buộc unique.
            'role_code' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('role', 'role_code')->ignore($this->route('id'), 'role_id'),
                Rule::notIn(StoreRoleRequest::RESERVED_ROLE_CODES),
            ],
            'role_name' => ['sometimes', 'string', 'max:255'],
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
