<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Auth\Support\PasswordPolicy;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Cùng rule "mật khẩu mạnh" với đổi mật khẩu — mật khẩu đặt qua
     * quên-mật-khẩu không được yếu hơn.
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            // Token do server sinh bằng Str::random(64); trần 255 chặn chuỗi
            // khổng lồ phải đi qua Hash::check.
            'reset_token' => ['required', 'string', 'max:255'],
            'new_password' => [
                'required', 'string', 'min:'.PasswordPolicy::MIN_LENGTH, 'max:'.PasswordPolicy::MAX_LENGTH,
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
                'confirmed',
            ],
            'new_password_confirmation' => ['required', 'string', 'max:'.PasswordPolicy::MAX_LENGTH],
        ];
    }

    public function messages(): array
    {
        return [
            'new_password.required' => PasswordPolicy::MSG_NEW_REQUIRED,
            'new_password.min' => PasswordPolicy::MSG_MIN,
            'new_password.max' => PasswordPolicy::MSG_MAX,
            'new_password.confirmed' => PasswordPolicy::MSG_CONFIRM_MISMATCH,
            'new_password_confirmation.required' => PasswordPolicy::MSG_CONFIRM_REQUIRED,
            'new_password_confirmation.max' => PasswordPolicy::MSG_MAX,
        ];
    }
}
