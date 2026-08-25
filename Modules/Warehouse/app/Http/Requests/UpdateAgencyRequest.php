<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Models\Agency;

class StoreAgencyRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (! $this->has('recipient_type')) {
            $this->merge(['recipient_type' => 'AGENCY']);
        }
    }

    public function rules(): array
    {
        return [
            'agency_code' => ['required', 'string', 'max:50', 'unique:agencies,agency_code'],
            'agency_name' => ['required', 'string', 'max:255'],
            'recipient_type' => ['required', Rule::in(Agency::RECIPIENT_TYPES)],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^0\d{9}$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Số điện thoại phải gồm đúng 10 chữ số và bắt đầu bằng số 0.'];
    }
}
