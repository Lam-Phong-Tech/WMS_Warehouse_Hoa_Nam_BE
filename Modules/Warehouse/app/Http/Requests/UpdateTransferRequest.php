<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_no' => ['nullable', 'string', 'max:80'],
            'doc_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.product_id' => ['required_with:lines', 'uuid', 'exists:sku,sku_id'],
            'lines.*.qty_planned' => ['required_with:lines', 'numeric', 'gt:0'],
        ];
    }
}
