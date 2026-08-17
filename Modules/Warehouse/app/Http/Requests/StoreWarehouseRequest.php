<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** ERD bảng warehouses: code chuẩn hoá UPPER/trim để unique không phụ thuộc hoa thường. */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => Str::upper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        // REQ-SRS-001 / AC-SRS-001 + Annex C createWarehouse: cho phép tạo thông tin
        // kho, nhưng kho ACTIVE thứ hai bị WarehouseRepository chặn bằng 409
        // STATE_CONFLICT (ux_erd_warehouses_one_active là chốt chặn ở tầng DB).
        return [
            'code' => ['required', 'string', 'max:50', 'unique:warehouses,code'],
            'name' => ['required', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_person' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
