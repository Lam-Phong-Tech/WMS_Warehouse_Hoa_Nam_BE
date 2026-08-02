<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateImportBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'import_batch_code' => ['sometimes', 'string', 'max:80', Rule::unique('import_batches', 'import_batch_code')->ignore($this->route('id'), 'import_batch_id')],
            'shipment_container_code' => ['nullable', 'string', 'max:100'],
            'supplier_batch_no' => ['nullable', 'string', 'max:100'],
            'import_period' => ['nullable', 'string', 'max:30'],
            'supplier_name' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }
}
