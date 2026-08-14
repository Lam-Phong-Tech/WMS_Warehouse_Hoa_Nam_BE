<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockDocumentScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'line_id' => ['required', 'uuid'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'raw_code' => ['nullable', 'string', 'max:100'],
        ];
    }
}
