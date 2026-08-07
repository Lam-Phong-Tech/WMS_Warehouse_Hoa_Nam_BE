<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\PhysicalCode;

class UpdateContainerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'container_code' => ['sometimes', 'string', 'max:100', Rule::unique('containers', 'container_code')->ignore($this->route('id'), 'container_id')],
            'container_type' => ['sometimes', Rule::in(['CARTON', 'BOX', 'TRAY'])],
            'physical_code_id' => ['sometimes', 'uuid', 'exists:physical_codes,physical_code_id', Rule::unique('containers', 'physical_code_id')->ignore($this->route('id'), 'container_id')],
            'primary_sku_id' => ['nullable', 'uuid', 'exists:sku,sku_id'],
            'parent_container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'zone_code' => ['nullable', 'string', 'max:50'],
            'location_hint' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:20'],
            'version' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            $container = Container::find($this->route('id'));
            $effectiveType = $this->input('container_type') ?? $container?->container_type;
            $hasPrimarySku = $this->filled('primary_sku_id') || ($container?->primary_sku_id && ! $this->exists('primary_sku_id'));

            if (in_array($effectiveType, ['CARTON', 'BOX'], true) && ! $hasPrimarySku) {
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
