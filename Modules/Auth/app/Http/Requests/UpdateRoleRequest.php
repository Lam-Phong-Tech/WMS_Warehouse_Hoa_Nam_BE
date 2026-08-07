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
            'role_code' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('role', 'role_code')->ignore($this->route('id'), 'role_id'),
            ],
            'role_name' => ['sometimes', 'string', 'max:255'],
            'scope_type' => ['nullable', Rule::in(['SYSTEM', 'WAREHOUSE', 'FUNCTION'])],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }
}
