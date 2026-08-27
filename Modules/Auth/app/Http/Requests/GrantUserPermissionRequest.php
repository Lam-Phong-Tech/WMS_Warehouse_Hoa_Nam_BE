<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantUserPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // status=ACTIVE: quyền đã ngừng hiệu lực vẫn còn dòng trong bảng để giữ
            // vẹn khoá ngoại/audit, nhưng không được cấp lại cho ai (xem
            // AssignRolePermissionRequest cho lý do đầy đủ).
            'permission_id' => [
                'required',
                'uuid',
                Rule::exists('permission', 'permission_id')->where('status', 'ACTIVE'),
            ],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'permission_id.exists' => 'Quyền không tồn tại hoặc đã ngừng hiệu lực, không thể cấp.',
        ];
    }
}
