<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Models\Sku;

class StoreItemInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'physical_code_id' => ['nullable', 'uuid', 'exists:physical_codes,physical_code_id', 'unique:item_instances,physical_code_id'],
            'serial_number' => ['nullable', 'string', 'max:100', 'unique:item_instances,serial_number'],
            'container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'item_status' => ['nullable', Rule::in(['NEW', 'IN_STOCK', 'ISSUED', 'IN_WARRANTY', 'RETURNED_TO_CUSTOMER', 'LOST', 'ADJUSTED_OUT'])],
            'item_condition' => ['nullable', Rule::in(['NORMAL', 'DEMO', 'DEFECTIVE', 'WARRANTY_RETURN', 'NON_SELLABLE'])],
            'import_batch_id' => ['nullable', 'uuid', 'exists:import_batches,import_batch_id'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $sku = $this->filled('sku_id') ? Sku::find($this->input('sku_id')) : null;

            if ($sku && $sku->sku_type === 'PRODUCT' && $sku->is_serial_tracked && ! $this->filled('physical_code_id')) {
                $validator->errors()->add('physical_code_id', 'SKU loại PRODUCT có is_serial_tracked=true bắt buộc phải có physical_code_id.');
            }

            // ERD bảng 20 (serial subledger) + DB guard SERIAL_REQUIRED: SKU
            // tracking SERIAL bắt buộc serial_number — validate 422 thay vì để 500.
            if ($sku && $sku->is_serial_tracked && ! $this->filled('serial_number')) {
                $validator->errors()->add('serial_number', 'SKU theo dõi serial bắt buộc serial_number.');
            }

            if ($this->filled('physical_code_id')) {
                $physicalCode = PhysicalCode::find($this->input('physical_code_id'));
                if ($physicalCode && $physicalCode->status !== 'ACTIVE') {
                    $validator->errors()->add('physical_code_id', 'Mã vật lý phải ở trạng thái ACTIVE để gán cho item.');
                }
            }
        });
    }
}
