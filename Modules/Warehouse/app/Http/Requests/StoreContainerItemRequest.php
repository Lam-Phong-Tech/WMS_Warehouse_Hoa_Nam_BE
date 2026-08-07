<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

class StoreContainerItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
            'sku_id' => ['nullable', 'uuid', 'exists:sku,sku_id'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $hasItem = $this->filled('item_id');
            $hasSku = $this->filled('sku_id');

            if ($hasItem === $hasSku) {
                $validator->errors()->add('item_id', 'Phải chọn đúng 1 trong 2: item_id (serial) hoặc sku_id+quantity (số lượng), không được cả hai hoặc không có gì.');

                return;
            }

            if ($hasSku && ! $this->filled('quantity')) {
                $validator->errors()->add('quantity', 'quantity bắt buộc khi dùng sku_id (không theo serial).');
            }
        });
    }
}
