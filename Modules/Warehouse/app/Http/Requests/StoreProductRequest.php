<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $code = $this->filled('code') ? strtoupper(trim((string) $this->input('code'))) : null;
        $skuCode = $this->filled('sku_code') ? strtoupper(trim((string) $this->input('sku_code'))) : null;

        if ($code === null && $skuCode !== null) {
            $code = $skuCode;
        }
        if ($skuCode === null && $code !== null) {
            $skuCode = $code;
        }

        $physicalCodes = collect((array) $this->input('physical_codes', []))
            ->map(function (mixed $row): mixed {
                if (! is_array($row)) {
                    return $row;
                }

                if (array_key_exists('code_value', $row)) {
                    $row['code_value'] = strtoupper(trim((string) $row['code_value']));
                }
                foreach (['code_type', 'carrier_type', 'status'] as $field) {
                    if (array_key_exists($field, $row) && $row[$field] !== null) {
                        $row[$field] = strtoupper(trim((string) $row[$field]));
                    }
                }

                return $row;
            })
            ->all();

        $this->merge([
            'code' => $code,
            'sku_code' => $skuCode,
            'physical_codes' => $physicalCodes,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100', 'unique:products,code'],
            'sku_code' => ['required', 'string', 'max:100', 'unique:products,sku_code'],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
            'sku_type' => ['nullable', Rule::in(['PRODUCT', 'COMPONENT'])],
            'brand_code' => ['nullable', 'string', 'max:100'],
            'category_code' => ['nullable', 'string', 'max:100'],
            'model_code' => ['nullable', 'string', 'max:100'],
            'power_or_voltage' => ['nullable', 'string', 'max:100'],
            'package_variant' => ['nullable', Rule::in(['STANDARD', 'TRAY', 'BOX', 'CARTON', 'PALLET', 'KIT'])],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'DRAFT'])],
            'version' => ['prohibited'],
            'published_to_app' => ['nullable', 'boolean'],
            'is_serial_tracked' => ['nullable', 'boolean'],
            'components' => ['sometimes', 'array', 'max:100'],
            'components.*.component_id' => ['required', 'uuid', 'exists:products,id'],
            'components.*.quantity' => ['required', 'numeric', 'gt:0'],
            'physical_codes' => ['sometimes', 'array', 'max:100'],
            'physical_codes.*.code_value' => ['required', 'string', 'max:150'],
            'physical_codes.*.code_type' => ['sometimes', Rule::in(['BARCODE', 'QR', 'RFID', 'SERIAL'])],
            'physical_codes.*.carrier_type' => ['sometimes', Rule::in(['UNIT', 'PACKAGE', 'LABEL', 'TAG'])],
            'physical_codes.*.status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $componentIds = collect((array) $this->input('components', []))
                    ->pluck('component_id')
                    ->filter()
                    ->map(fn (mixed $id): string => strtolower((string) $id));
                if ($componentIds->count() !== $componentIds->unique()->count()) {
                    $validator->errors()->add('components', 'Danh sách component có phần tử trùng.');
                }

                $codes = collect((array) $this->input('physical_codes', []))
                    ->pluck('code_value')
                    ->filter()
                    ->map(fn (mixed $code): string => strtoupper(trim((string) $code)));
                if ($codes->count() !== $codes->unique()->count()) {
                    $validator->errors()->add('physical_codes', 'Danh sách physical code có phần tử trùng.');
                }

                if (strtoupper((string) $this->input('sku_type', 'PRODUCT')) === 'COMPONENT'
                    && count((array) $this->input('components', [])) > 0) {
                    $validator->errors()->add('components', 'SKU loại COMPONENT không được có component con.');
                }
            },
        ];
    }
}
