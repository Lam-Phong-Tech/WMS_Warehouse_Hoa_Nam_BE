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

    public function rules(): array
    {
        return [
            'role_code' => ['required', 'string', 'max:100', 'unique:role,role_code'],
            'role_name' => ['required', 'string', 'max:255'],
            'scope_type' => ['nullable', Rule::in(['SYSTEM', 'WAREHOUSE', 'FUNCTION'])],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }
}
