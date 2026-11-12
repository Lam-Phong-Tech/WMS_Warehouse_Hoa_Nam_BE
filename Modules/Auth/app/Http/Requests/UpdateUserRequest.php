<?php

namespace Modules\Auth\Http\Requests;

use App\Support\Validation\InputLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Auth\Support\PasswordPolicy;

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
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
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
                'sometimes', 'required', 'email', 'max:200', 'regex:/\A[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}\z/',
                Rule::unique('app_user', 'email')->ignore($this->route('id'), 'user_id'),
            ],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^0\d{9}$/'],
            'department_id' => ['nullable', 'uuid', 'exists:department,department_id'],
            'password' => [
                'sometimes', 'string', 'min:'.PasswordPolicy::MIN_LENGTH, 'max:'.PasswordPolicy::MAX_LENGTH,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }
                    if (PasswordPolicy::hasWhitespace($value)) {
                        $fail(PasswordPolicy::MSG_WHITESPACE);

                        return;
                    }
                    if (! PasswordPolicy::hasAllCharacterClasses($value)) {
                        $fail(PasswordPolicy::MSG_COMPLEXITY);
                    }
                },
            ],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'LOCKED'])],
            'version' => ['nullable', 'integer', 'min:1', 'max:'.InputLimits::VERSION_MAX],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Số điện thoại phải gồm đúng 10 chữ số và bắt đầu bằng số 0.',
            'department_id.exists' => 'Phòng ban không tồn tại.',
            'password.min' => 'Mật khẩu tối thiểu 8 ký tự',
            'password.max' => 'Mật khẩu không được vượt quá 64 ký tự',
        ];
    }
}
