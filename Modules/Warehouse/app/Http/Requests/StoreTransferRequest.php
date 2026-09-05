<?php

namespace Modules\Warehouse\Http\Requests;

use App\Support\Validation\InputLimits;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_no' => ['nullable', 'string', 'max:80', 'unique:stock_documents,doc_no'],
            // Ngày chứng từ là ngày ĐÃ XẢY RA — không nhận ngày tương lai.
            'doc_date' => array_merge(['nullable'], InputLimits::pastDate()),
            'src_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'dst_warehouse_id' => ['required', 'uuid', 'different:src_warehouse_id', 'exists:warehouses,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            // max:500 dòng một phiếu: mỗi dòng còn phải chạy 'exists' riêng.
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'lines.*.qty_planned' => ['required', 'numeric', 'gt:0', 'max:'.InputLimits::QUANTITY_MAX, 'decimal:0,'.InputLimits::QUANTITY_DECIMALS],
        ];
    }
}
