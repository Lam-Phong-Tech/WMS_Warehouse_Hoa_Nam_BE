<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSafeStockConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'min_qty' => ['nullable', 'numeric', 'gte:0'],
            'max_qty' => ['nullable', 'numeric', 'gte:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
