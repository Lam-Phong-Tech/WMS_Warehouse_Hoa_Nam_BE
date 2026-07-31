<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStockRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_date' => ['required', 'date'],
            'src_warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'dst_warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'lines.*.qty_planned' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
