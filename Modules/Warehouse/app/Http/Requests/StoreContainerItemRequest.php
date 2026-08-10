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
            'quantity' => ['nullable', 'integer', 'min:1'],
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

            if ($hasItem && $this->filled('quantity') && (int) $this->input('quantity') !== 1) {
                $validator->errors()->add('quantity', 'item_id đại diện một item cụ thể nên quantity phải bằng 1 hoặc bỏ trống.');
            }
            if ($hasSku && ! $this->filled('quantity')) {
                $validator->errors()->add('quantity', 'quantity bắt buộc khi dùng sku_id (không theo serial).');
            }
        });
    }
}
