<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDefectCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'defect_code' => ['required', 'string', 'max:80', 'unique:defect_catalogs,defect_code'],
            'defect_name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }
}
