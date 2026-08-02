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

    /**
     * ERD app_user.username: "Chuẩn hóa lowercase/trim" — chuẩn hoá trước khi validate
     * để rule unique và giá trị lưu xuống DB luôn nhất quán bất kể client gửi hoa/thường/khoảng trắng.
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
            'username' => ['required', 'string', 'max:100', 'unique:app_user,username'],
            'display_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:app_user,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'LOCKED'])],
        ];
    }
}
