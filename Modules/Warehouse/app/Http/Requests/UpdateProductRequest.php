<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Warehouse\Http\Requests\Concerns\ValidatesSkuStockThresholds;

class UpdateProductRequest extends FormRequest
{
    use ValidatesSkuStockThresholds;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $mapped = [];
        if (! $this->has('sku_code') && $this->has('code')) {
            $mapped['sku_code'] = $this->input('code');
        }
        if (! $this->has('sku_name') && $this->has('name')) {
            $mapped['sku_name'] = $this->input('name');
        }
        if ($mapped !== []) {
            $this->merge($mapped);
        }
    }

    public function rules(): array
    {
        return [
            'sku_code' => ['sometimes', 'string', 'max:100', Rule::unique('sku', 'sku_code')->ignore($this->route('id'), 'sku_id')],
            'sku_name' => ['sometimes', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
            'sku_type' => ['nullable', Rule::in(['PRODUCT', 'COMPONENT'])],
            'is_serial_tracked' => ['nullable', 'boolean'],
            'brand_id' => ['nullable', 'uuid', 'exists:product_masters,master_id'],
            'category_id' => ['nullable', 'uuid', 'exists:product_masters,master_id'],
            'model_id' => ['nullable', 'uuid', 'exists:product_masters,master_id'],
            // ERD bảng 16: nguồn điện và quy cách đóng gói là FK tới master, không
            // phải enum cứng (AC-SRS-029 "thêm loại không cần code change").
            'power_source_id' => ['nullable', 'uuid', 'exists:power_sources,id'],
            'power_source_code' => ['nullable', 'string', 'max:80'],
            'power_or_voltage' => ['nullable', 'string', 'max:50'],
            'package_variant_id' => ['nullable', 'uuid', 'exists:package_variants,id'],
            'package_variant_code' => ['nullable', 'string', 'max:80'],
            'package_variant' => ['nullable', 'string', 'max:50'],
            'version_code' => ['nullable', 'string', 'max:30'],
            'warranty_applicable' => ['nullable', 'boolean'],
            // max:500 khoá: specification là map thuộc tính mở của SKU, không
            // phải chỗ chứa dữ liệu — trần chặn payload JSON phình vô hạn.
            'specification' => ['nullable', 'array', 'max:500'],
            'published_to_app' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            // Định mức tồn theo SKU, toàn hệ thống (không theo kho) — phục vụ báo
            // cáo định mức tồn. NULL = chưa đặt, không tính cảnh báo.
            // min >= 0, max >= 1, max > min: xem ValidatesSkuStockThresholds.
            ...$this->skuStockThresholdRules(),
        ];
    }

    /**
     * PATCH có thể chỉ gửi một vế định mức; vế còn lại đọc từ bản ghi đang sửa để
     * ràng buộc "max > min" so trên GIÁ TRỊ SAU KHI LƯU, không phải trên payload.
     *
     * @return array{min_stock?: float|int|string|null, max_stock?: float|int|string|null}
     */
    protected function currentSkuStockThresholds(): array
    {
        $id = $this->route('id');
        if (! is_string($id) || $id === '') {
            return [];
        }

        $row = DB::table('sku')->where('sku_id', $id)->first(['min_stock', 'max_stock']);

        return $row === null ? [] : (array) $row;
    }
}
