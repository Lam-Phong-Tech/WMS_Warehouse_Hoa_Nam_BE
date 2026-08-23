<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => [
                'required_without:username',
                'prohibits:username',
                'nullable',
                'string',
                'max:200',
                'email',
                'regex:/\A[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}\z/',
            ],
            'username' => [
                'required_without:email',
                'prohibits:email',
                'nullable',
                'string',
                'max:100',
                'regex:/\A[^\s[:cntrl:]]+\z/u',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:128',
                'regex:/\A[^\s[:cntrl:]]+\z/u',
            ],
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
            'email.prohibits' => 'Chỉ được gửi một trường đăng nhập: email hoặc tài khoản.',
            'username.regex' => 'Tài khoản không được chứa khoảng trắng hoặc ký tự điều khiển.',
            'username.prohibits' => 'Chỉ được gửi một trường đăng nhập: email hoặc tài khoản.',
            'password.regex' => 'Mật khẩu không được chứa khoảng trắng hoặc ký tự điều khiển.',
        ];
    }
}
