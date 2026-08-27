<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // §8.1 IAM + BR-IAM-08: "explicit role/scope assignment". Ngoài role_ids
        // (giữ nguyên cho client cũ), cho phép gán kèm scope_ref và cửa sổ hiệu
        // lực theo ERD bảng user_roles.
        return [
            'role_ids' => ['required_without:roles', 'array'],
            'role_ids.*' => ['uuid', 'exists:role,role_id'],
            'roles' => ['required_without:role_ids', 'array'],
            'roles.*.role_id' => ['required_with:roles', 'uuid', 'exists:role,role_id'],
            'roles.*.scope_ref' => ['nullable', 'uuid'],
            'roles.*.effective_from' => ['nullable', 'date'],
            'roles.*.effective_to' => ['nullable', 'date', 'after_or_equal:roles.*.effective_from'],
            // Yêu cầu nghiệp vụ: mọi thay đổi role của người dùng phải ghi lại lý do để truy vết.
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
