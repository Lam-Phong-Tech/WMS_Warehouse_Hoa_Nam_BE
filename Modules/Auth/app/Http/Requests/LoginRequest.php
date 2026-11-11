<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Định danh đăng nhập DUY NHẤT là email. Trước đây endpoint nhận thêm
     * `username`, nghĩa là một tài khoản có hai cách vào (`admin` và
     * `admin@gmail.com`) — gây nhầm lẫn cho người dùng và mở rộng bề mặt dò
     * tài khoản. Cột `username` vẫn tồn tại và vẫn là khoá nghiệp vụ của IAM,
     * chỉ không còn dùng để đăng nhập.
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'max:200',
                'email',
                'regex:/\A[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}\z/',
            ],
            'username' => ['prohibited'],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:128',
                'regex:/\A[^\s[:cntrl:]]+\z/u',
            ],
            'step_up_token' => ['nullable', 'string', 'max:128'],
            'pow' => ['nullable', 'array'],
            'pow.challenge_id' => ['required_with:pow', 'string', 'max:128'],
            'pow.solution' => ['required_with:pow', 'integer', 'min:0'],
            'pow_supported' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'email' => 'email',
            'username' => 'tài khoản',
            'password' => 'mật khẩu',
        ];
    }

    public function messages(): array
    {
        return [
            'email.regex' => 'Email phải đúng định dạng chữ thường, không có khoảng trắng hoặc chữ hoa.',
            'username.prohibited' => 'Đăng nhập chỉ nhận email đầy đủ; không dùng tên đăng nhập.',
            'password.regex' => 'Mật khẩu không được chứa khoảng trắng hoặc ký tự điều khiển.',
        ];
    }
}
