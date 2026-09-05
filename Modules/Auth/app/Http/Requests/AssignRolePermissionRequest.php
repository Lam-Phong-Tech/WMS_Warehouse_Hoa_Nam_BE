<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // max:500 — ma trận quyền thật chỉ vài chục dòng; trần chặn payload
            // hàng trăm nghìn phần tử phải chạy 'exists' từng cái ở dưới.
            'permission_ids' => ['present', 'array', 'max:500'],
            // Ràng buộc status=ACTIVE, không chỉ 'exists': quyền đã ngừng hiệu lực
            // (PermissionEnforcementMap + migration retire_unenforced_legacy_permissions)
            // vẫn giữ dòng trong bảng `permission` để không vỡ khoá ngoại và lịch sử
            // audit. Nếu chỉ kiểm 'exists', một client cũ hoặc request thủ công vẫn
            // gán lại được đúng những mã quyền ma vừa được dọn.
            'permission_ids.*' => [
                'uuid',
                Rule::exists('permission', 'permission_id')->where('status', 'ACTIVE'),
            ],
            // Yêu cầu nghiệp vụ: mọi thay đổi ma trận quyền phải ghi lại lý do để truy vết.
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'permission_ids.*.exists' => 'Có quyền không tồn tại hoặc đã ngừng hiệu lực, không thể gán.',
        ];
    }
}
