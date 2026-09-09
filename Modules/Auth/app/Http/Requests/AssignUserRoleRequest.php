<?php

namespace Modules\Auth\Http\Requests;

use App\Support\Validation\InputLimits;
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
            // max:500 cho cả hai danh sách: một người dùng thật chỉ mang vài
            // vai trò, trần chặn payload rác hàng trăm nghìn dòng.
            'role_ids' => ['required_without:roles', 'array', 'max:500'],
            'role_ids.*' => ['uuid', 'exists:role,role_id'],
            'roles' => ['required_without:role_ids', 'array', 'max:500'],
            'roles.*.role_id' => ['required_with:roles', 'uuid', 'exists:role,role_id'],
            // WWHN-SEC-09: scope_ref phải là kho CÓ THẬT. Trước đây nhận UUID bất
            // kỳ — gõ nhầm sẽ khoá người dùng khỏi mọi kho (fail-closed) mà không
            // ai phát hiện, còn bỏ trống thì âm thầm thành quyền toàn hệ thống;
            // cả hai đều không phân biệt được với chủ đích trong audit.
            'roles.*.scope_ref' => ['nullable', 'uuid', 'exists:warehouses,id'],
            // Cửa sổ hiệu lực được phép đặt trước cho tương lai nên dùng date()
            // chứ không phải pastDate(); ràng buộc to >= from giữ nguyên.
            'roles.*.effective_from' => array_merge(['nullable'], InputLimits::date()),
            'roles.*.effective_to' => array_merge(['nullable'], InputLimits::date(), ['after_or_equal:roles.*.effective_from']),
            // Yêu cầu nghiệp vụ: mọi thay đổi role của người dùng phải ghi lại lý do để truy vết.
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
