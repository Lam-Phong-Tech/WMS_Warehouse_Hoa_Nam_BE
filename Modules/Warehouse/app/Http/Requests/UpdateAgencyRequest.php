<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agency_code' => [
                'sometimes', 'string', 'max:50',
                Rule::unique('agencies', 'agency_code')->ignore($this->route('id'), 'agency_id'),
            ],
            'agency_name' => ['sometimes', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^0\d{9}$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Số điện thoại phải gồm đúng 10 chữ số và bắt đầu bằng số 0.',
        ];
    }
}
