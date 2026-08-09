<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Legacy /products clients used code/name before the SKU rename. */
    protected function prepareForValidation(): void
    {
        $mapped = [];
        if (! $this->has('sku_code') && $this->has('code')) {
            $mapped['sku_code'] = $this->input('code');
        }
        if (! $this->has('sku_name') && $this->has('name')) {
            $mapped['sku_name'] = $this->input('name');
        }
        if ($mapped !== []) {
            $this->merge($mapped);
        }
    }

    public function rules(): array
    {
        return [
            'sku_code' => ['required', 'string', 'max:100', 'unique:sku,sku_code'],
            'sku_name' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
            'sku_type' => ['nullable', Rule::in(['PRODUCT', 'COMPONENT'])],
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
