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
        return [
            'role_ids' => ['required', 'array'],
            'role_ids.*' => ['uuid', 'exists:role,role_id'],
            'scope_ref' => ['nullable', 'string', 'max:100'],
        ];
    }
}
