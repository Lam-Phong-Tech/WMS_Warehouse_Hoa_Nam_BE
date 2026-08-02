<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Models\Sku;

class UpdateItemInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku_id' => ['sometimes', 'uuid', 'exists:sku,sku_id'],
            'physical_code_id' => ['nullable', 'uuid', 'exists:physical_codes,physical_code_id', Rule::unique('item_instances', 'physical_code_id')->ignore($this->route('id'), 'item_id')],
            'serial_number' => ['nullable', 'string', 'max:100', Rule::unique('item_instances', 'serial_number')->ignore($this->route('id'), 'item_id')],
            'container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'item_status' => ['nullable', Rule::in(['NEW', 'IN_STOCK', 'ISSUED', 'IN_WARRANTY', 'RETURNED_TO_CUSTOMER', 'LOST', 'ADJUSTED_OUT'])],
            'item_condition' => ['nullable', Rule::in(['NORMAL', 'DEMO', 'DEFECTIVE', 'WARRANTY_RETURN', 'NON_SELLABLE'])],
            'import_batch_id' => ['nullable', 'uuid', 'exists:import_batches,import_batch_id'],
            'version' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $item = ItemInstance::find($this->route('id'));
            $skuId = $this->input('sku_id') ?? $item?->sku_id;
            $sku = $skuId ? Sku::find($skuId) : null;

            $hasPhysicalCode = $this->filled('physical_code_id') || (! $this->exists('physical_code_id') && $item?->physical_code_id);

            if ($sku && $sku->sku_type === 'PRODUCT' && $sku->is_serial_tracked && ! $hasPhysicalCode) {
                $validator->errors()->add('physical_code_id', 'SKU loại PRODUCT có is_serial_tracked=true bắt buộc phải có physical_code_id.');
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
