<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignRolePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'required' coi mảng rỗng [] là "thiếu trường" và fail — chặn đúng thao
            // tác hợp lệ "gỡ hết quyền của role về 0" (vd role "No Permission Test").
            // 'present' chỉ đòi field có mặt trong request, cho phép giá trị là [].
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['uuid', 'exists:permission,permission_id'],
        ];
    }
}
