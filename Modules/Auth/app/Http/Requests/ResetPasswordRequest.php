<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Cùng rule "mật khẩu mạnh" mà FE đã áp dụng cho đổi mật khẩu
     * (src/modules/auth/schemas/change-password.schema.ts: tối thiểu 8 ký tự,
     * có hoa/thường/ký tự đặc biệt, không khoảng trắng đầu/cuối) — mật khẩu đặt
     * qua quên-mật-khẩu không được phép yếu hơn.
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'reset_token' => ['required', 'string'],
            'new_password' => [
                'required', 'string', 'min:8', 'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,}$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && $value !== trim($value)) {
                        $fail('Mật khẩu không được có khoảng trắng đầu/cuối.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'new_password.regex' => 'Mật khẩu phải có ít nhất 1 chữ hoa, 1 chữ thường và 1 ký tự đặc biệt.',
        ];
    }
}
