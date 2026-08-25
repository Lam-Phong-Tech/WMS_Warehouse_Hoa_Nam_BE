<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('username')) {
            $this->merge(['username' => Str::lower(trim((string) $this->input('username')))]);
        }
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:100', 'unique:app_user,username'],
            'display_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:app_user,email'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^0\d{9}$/'],
            'department_id' => ['nullable', 'uuid', 'exists:department,department_id'],
            'password' => ['required', 'string', 'min:8'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'LOCKED'])],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Số điện thoại phải gồm đúng 10 chữ số và bắt đầu bằng số 0.',
            'department_id.exists' => 'Phòng ban không tồn tại.',
        ];
    }
}
