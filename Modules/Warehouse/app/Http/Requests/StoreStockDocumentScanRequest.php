<?php

namespace Modules\Warehouse\Http\Requests;

use App\Support\Validation\InputLimits;
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
            'qty' => ['required', 'numeric', 'gt:0', 'max:'.InputLimits::QUANTITY_MAX, 'decimal:0,'.InputLimits::QUANTITY_DECIMALS],
            'raw_code' => ['nullable', 'string', 'max:100'],
        ];
    }
}
