<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWarrantyTempOnlyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid', 'exists:inventory_serials,id'],
            'defect_id' => ['nullable', 'uuid', 'exists:defect_catalogs,defect_id'],
            'condition_status' => ['required', 'string', 'in:WAITING_CHECK,INSPECTING'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
