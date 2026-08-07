<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'master_type' => ['required', Rule::in(['BRAND', 'CATEGORY', 'MODEL', 'PACKAGE', 'POWER'])],
            'code' => [
                'required', 'string', 'max:80',
                Rule::unique('product_masters', 'code')->where(fn ($q) => $q->where('master_type', $this->input('master_type'))),
            ],
            'name' => ['required', 'string', 'max:200'],
            'parent_master_id' => ['nullable', 'uuid', 'exists:product_masters,master_id'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }
}
