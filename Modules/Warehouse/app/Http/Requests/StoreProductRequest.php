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

        if (! $this->filled('name') && $this->filled('sku_name')) {
            $this->merge(['name' => $this->input('sku_name')]);
        }

        if (! $this->filled('sku_name') && $this->filled('name')) {
            $this->merge(['sku_name' => $this->input('name')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku_code' => ['required', 'string', 'max:100', 'unique:sku,sku_code'],
            'sku_name' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
            'sku_type' => ['nullable', Rule::in(['PRODUCT', 'COMPONENT'])],
            'brand_code' => ['nullable', 'string', 'max:100'],
            'category_code' => ['nullable', 'string', 'max:100'],
            'model_code' => ['nullable', 'string', 'max:100'],
            'power_or_voltage' => ['nullable', 'string', 'max:100'],
            'package_variant' => ['nullable', Rule::in(['STANDARD', 'TRAY', 'BOX', 'CARTON', 'PALLET', 'KIT'])],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'DRAFT'])],
            'version' => ['nullable', 'integer', 'min:1'],
            'published_to_app' => ['nullable', 'boolean'],
            'is_serial_tracked' => ['nullable', 'boolean'],
            'brand_id' => ['nullable', 'uuid', 'exists:product_masters,master_id'],
            'category_id' => ['nullable', 'uuid', 'exists:product_masters,master_id'],
            'model_id' => ['nullable', 'uuid', 'exists:product_masters,master_id'],
            'power_or_voltage' => ['nullable', 'string', 'max:50'],
            'package_variant' => ['nullable', Rule::in(['BARE', 'KIT', 'SET', 'BODY_ONLY'])],
            'version_code' => ['nullable', 'string', 'max:30'],
            'warranty_applicable' => ['nullable', 'boolean'],
            'specification' => ['nullable', 'array'],
            'published_to_app' => ['nullable', 'boolean'],
        ];
    }
}
