<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Warehouse\Models\ItemInstance;

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
            'lines.*.item_instance_id' => ['nullable', 'uuid', 'distinct', 'exists:item_instances,item_id'],
            'lines.*.qty_planned' => ['required', 'numeric', 'gt:0'],
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
                if (isset($line['qty_planned']) && (float) $line['qty_planned'] !== 1.0) {
                    $validator->errors()->add("lines.{$index}.qty_planned", 'Dòng item serial phải có qty_planned bằng 1.');
                }
            }
        });
    }
}
