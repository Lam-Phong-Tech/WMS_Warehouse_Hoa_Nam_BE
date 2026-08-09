<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
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
            'email' => ['nullable', 'required_without:username', 'email'],
            'username' => ['nullable', 'required_without:email', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'email' => 'email',
            'username' => 'tên đăng nhập',
            'password' => 'mật khẩu',
        ];
    }
}
