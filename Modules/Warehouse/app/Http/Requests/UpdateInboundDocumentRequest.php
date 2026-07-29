<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInboundDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_no' => ['sometimes', 'string', 'max:80', Rule::unique('stock_documents', 'doc_no')->ignore($this->route('id'))],
            'doc_date' => ['sometimes', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.product_id' => ['required_with:lines', 'uuid', 'exists:products,id'],
            'lines.*.qty_planned' => ['required_with:lines', 'numeric', 'gt:0'],
        ];
    }
}
