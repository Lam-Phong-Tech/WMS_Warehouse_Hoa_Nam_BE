<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $masterType = $this->input('master_type');

        return [
            'master_type' => ['sometimes', Rule::in(['BRAND', 'CATEGORY', 'MODEL', 'PACKAGE', 'POWER'])],
            'code' => [
                'sometimes', 'string', 'max:80',
                Rule::unique('product_masters', 'code')
                    ->where(fn ($q) => $masterType ? $q->where('master_type', $masterType) : $q)
                    ->ignore($this->route('id'), 'master_id'),
            ],
            'name' => ['sometimes', 'string', 'max:200'],
            'parent_master_id' => ['nullable', 'uuid', 'exists:product_masters,master_id'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }
}
