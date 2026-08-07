<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Models\PhysicalCode;

class StoreContainerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'container_code' => ['required', 'string', 'max:100', 'unique:containers,container_code'],
            'container_type' => ['required', Rule::in(['CARTON', 'BOX', 'TRAY'])],
            'physical_code_id' => ['required', 'uuid', 'exists:physical_codes,physical_code_id', 'unique:containers,physical_code_id'],
            'primary_sku_id' => ['nullable', 'uuid', 'exists:sku,sku_id'],
            'parent_container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'zone_code' => ['nullable', 'string', 'max:50'],
            'location_hint' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:20'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            // CARTON/BOX luôn chỉ chứa 1 primary SKU; TRAY có thể chứa hỗn hợp nên không bắt buộc.
            if (in_array($this->input('container_type'), ['CARTON', 'BOX'], true) && ! $this->filled('primary_sku_id')) {
                $validator->errors()->add('primary_sku_id', 'CARTON/BOX bắt buộc phải có primary_sku_id.');
            }

            if ($this->filled('physical_code_id')) {
                $physicalCode = PhysicalCode::find($this->input('physical_code_id'));
                if ($physicalCode && $physicalCode->status !== 'ACTIVE') {
                    $validator->errors()->add('physical_code_id', 'Mã vật lý phải ở trạng thái ACTIVE để gán cho container.');
                }
            }
        });
    }
}
