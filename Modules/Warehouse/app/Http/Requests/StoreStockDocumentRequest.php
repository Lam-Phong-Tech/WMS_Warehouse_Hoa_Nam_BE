<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Warehouse\Models\StockDocument;

class StoreStockDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_type' => ['required', Rule::in([StockDocument::TYPE_RECEIPT, StockDocument::TYPE_ISSUE, StockDocument::TYPE_TRANSFER])],
            'doc_no' => ['required', 'string', 'max:80', 'unique:stock_documents,doc_no'],
            'doc_date' => ['required', 'date'],
            'src_warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'dst_warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'lines.*.qty_planned' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $type = $this->input('doc_type');
            $src = $this->input('src_warehouse_id');
            $dst = $this->input('dst_warehouse_id');

            if ($type === StockDocument::TYPE_RECEIPT && ! $dst) {
                $v->errors()->add('dst_warehouse_id', 'Phiếu nhập kho bắt buộc phải có kho đích.');
            }
            if ($type === StockDocument::TYPE_ISSUE && ! $src) {
                $v->errors()->add('src_warehouse_id', 'Phiếu xuất kho bắt buộc phải có kho nguồn.');
            }
            if ($type === StockDocument::TYPE_TRANSFER && (! $src || ! $dst)) {
                $v->errors()->add('src_warehouse_id', 'Phiếu điều chuyển bắt buộc phải có cả kho nguồn và kho đích.');
            }
            if ($type === StockDocument::TYPE_TRANSFER && $src && $dst && $src === $dst) {
                $v->errors()->add('dst_warehouse_id', 'Kho nguồn và kho đích không được trùng nhau.');
            }
        });
    }
}
