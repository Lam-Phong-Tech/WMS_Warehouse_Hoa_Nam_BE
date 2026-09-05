<?php

namespace Modules\Warehouse\Http\Requests;

use App\Support\Validation\InputLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Models\StockRequest;

class StoreStockRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->input('request_type');

        return [
            'request_type' => ['required', 'string', Rule::in([StockRequest::TYPE_RECEIPT, StockRequest::TYPE_ISSUE, StockRequest::TYPE_TRANSFER])],
            'request_no' => ['nullable', 'string', 'max:80', 'unique:stock_requests,request_no'],
            // Ngày lập yêu cầu là ngày ĐÃ XẢY RA — không nhận ngày tương lai.
            'request_date' => array_merge(['required'], InputLimits::pastDate()),
            'src_warehouse_id' => [
                in_array($type, [StockRequest::TYPE_ISSUE, StockRequest::TYPE_TRANSFER], true) ? 'required' : 'nullable',
                'uuid', 'exists:warehouses,id',
            ],
            'dst_warehouse_id' => [
                in_array($type, [StockRequest::TYPE_RECEIPT, StockRequest::TYPE_TRANSFER], true) ? 'required' : 'nullable',
                'uuid', 'exists:warehouses,id',
            ],
            'note' => ['nullable', 'string', 'max:1000'],
            // max:500 dòng một phiếu: mỗi dòng còn phải chạy 'exists' riêng.
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'lines.*.qty_planned' => ['required', 'numeric', 'gt:0', 'max:'.InputLimits::QUANTITY_MAX, 'decimal:0,'.InputLimits::QUANTITY_DECIMALS],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'gte:0', 'max:'.InputLimits::MONEY_MAX, 'decimal:0,'.InputLimits::MONEY_DECIMALS],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
