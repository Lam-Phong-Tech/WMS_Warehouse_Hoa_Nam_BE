<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInboundDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_no' => ['nullable', 'string', 'max:80', 'unique:stock_documents,doc_no'],
            'doc_date' => ['nullable', 'date'],
            'dst_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'lines.*.qty_planned' => ['required', 'integer', 'min:1'],
        ];
    }
}
