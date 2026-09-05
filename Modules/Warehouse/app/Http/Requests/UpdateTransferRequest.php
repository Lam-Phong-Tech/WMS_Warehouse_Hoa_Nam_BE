<?php

namespace Modules\Warehouse\Http\Requests;

use App\Support\Validation\InputLimits;
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
            // Ngày chứng từ là ngày ĐÃ XẢY RA — không nhận ngày tương lai.
            'doc_date' => array_merge(['nullable'], InputLimits::pastDate()),
            'note' => ['nullable', 'string', 'max:1000'],
            // max:500 dòng một phiếu: mỗi dòng còn phải chạy 'exists' riêng.
            'lines' => ['nullable', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required_with:lines', 'uuid', 'exists:sku,sku_id'],
            'lines.*.qty_planned' => ['required_with:lines', 'numeric', 'gt:0', 'max:'.InputLimits::QUANTITY_MAX, 'decimal:0,'.InputLimits::QUANTITY_DECIMALS],
        ];
    }
}
