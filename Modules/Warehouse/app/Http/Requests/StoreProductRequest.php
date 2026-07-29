<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('code') && $this->filled('sku_code')) {
            $this->merge(['code' => $this->input('sku_code')]);
        }

        if (! $this->filled('sku_code') && $this->filled('code')) {
            $this->merge(['sku_code' => $this->input('code')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required_without:sku_code', 'nullable', 'string', 'max:100', 'unique:products,code'],
            'sku_code' => ['required_without:code', 'nullable', 'string', 'max:100', 'unique:products,sku_code'],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
            'sku_type' => ['nullable', Rule::in(['PRODUCT', 'COMPONENT'])],
            'brand_code' => ['nullable', 'string', 'max:100'],
            'category_code' => ['nullable', 'string', 'max:100'],
            'model_code' => ['nullable', 'string', 'max:100'],
            'power_or_voltage' => ['nullable', 'string', 'max:100'],
            'package_variant' => ['nullable', 'string', 'max:100'],
            'version' => ['nullable', 'integer', 'min:1'],
            'published_to_app' => ['nullable', 'boolean'],
            'is_serial_tracked' => ['nullable', 'boolean'],
        ];
    }
}
