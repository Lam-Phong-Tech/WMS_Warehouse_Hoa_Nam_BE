<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('app_user', 'username')->ignore($this->route('id'), 'user_id'),
            ],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('app_user', 'email')->ignore($this->route('id'), 'user_id'),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['sometimes', 'string', 'min:8'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'LOCKED'])],
        ];
    }
}
