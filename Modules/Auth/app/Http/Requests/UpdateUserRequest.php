<?php

namespace Modules\Auth\Http\Requests;

use App\Support\Validation\InputLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
            'username' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('app_user', 'username')->ignore($this->route('id'), 'user_id'),
            ],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('app_user', 'email')->ignore($this->route('id'), 'user_id'),
            ],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^0\d{9}$/'],
            'department_id' => ['nullable', 'uuid', 'exists:department,department_id'],
            'password' => ['sometimes', 'string', 'min:8', 'max:128'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'LOCKED'])],
            'version' => ['nullable', 'integer', 'min:1', 'max:'.InputLimits::VERSION_MAX],
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
