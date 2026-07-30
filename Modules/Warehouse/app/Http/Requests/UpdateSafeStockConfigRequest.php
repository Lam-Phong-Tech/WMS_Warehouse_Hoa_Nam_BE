<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSafeStockConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['sometimes', 'uuid', 'exists:products,id'],
            'warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'min_qty' => ['nullable', 'numeric', 'gte:0'],
            'max_qty' => ['nullable', 'numeric', 'gte:0'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
