<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // REQ-SRS-001 + §8.1 Warehouse: "create/edit name, address, active status".
        // Ràng buộc một kho ACTIVE do unique index ux_erd_warehouses_one_active giữ.
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_person' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
