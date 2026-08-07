<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku_code' => ['sometimes', 'string', 'max:100', Rule::unique('sku', 'sku_code')->ignore($this->route('id'), 'sku_id')],
            'sku_name' => ['sometimes', 'string', 'max:255'],
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
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }
}
