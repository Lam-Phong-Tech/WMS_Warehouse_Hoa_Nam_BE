<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScanClassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'raw_code' => ['required', 'string', 'max:100'],
            'context' => ['required', 'string', 'in:INBOUND,OUTBOUND,STOCKTAKE,WARRANTY_TEMP_ONLY'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
        ];
    }
}
