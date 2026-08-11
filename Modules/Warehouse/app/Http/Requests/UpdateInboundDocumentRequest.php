<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Warehouse\Models\ItemInstance;

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
            'lines.*.product_id' => ['required_with:lines', 'uuid', 'exists:sku,sku_id'],
            'lines.*.item_instance_id' => ['nullable', 'uuid', 'distinct', 'exists:item_instances,item_id'],
            'lines.*.qty_planned' => ['required_with:lines', 'numeric', 'gt:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->input('lines', []) as $index => $line) {
                if (empty($line['item_instance_id']) || empty($line['product_id'])) {
                    continue;
                }

                $item = ItemInstance::find($line['item_instance_id']);
                if ($item && $item->sku_id !== $line['product_id']) {
                    $validator->errors()->add("lines.{$index}.item_instance_id", 'Item instance phải thuộc SKU của dòng chứng từ.');
                }
            }
        });
    }
}
