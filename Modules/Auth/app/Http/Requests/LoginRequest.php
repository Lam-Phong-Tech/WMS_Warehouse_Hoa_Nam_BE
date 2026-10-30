<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required_without:email', Rule::prohibitedIf($this->filled('email')), 'string', 'max:100'],
            'email' => ['required_without:username', Rule::prohibitedIf($this->filled('username')), 'email', 'max:200'],
            'password' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'username' => 'tên đăng nhập',
            'email' => 'email',
            'password' => 'mật khẩu',
        ];
    }
}
