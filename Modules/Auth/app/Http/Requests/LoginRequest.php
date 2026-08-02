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

    /**
     * username lưu xuống app_user đã chuẩn hoá lowercase/trim (ERD) — chuẩn hoá tại đây
     * để đăng nhập không phân biệt hoa/thường/khoảng trắng thừa.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('username')) {
            $this->merge(['username' => Str::lower(trim((string) $this->input('username')))]);
        }
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'username' => 'tên đăng nhập',
            'password' => 'mật khẩu',
        ];
    }
}
