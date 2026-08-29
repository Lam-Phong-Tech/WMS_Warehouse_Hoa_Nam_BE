<?php

namespace Modules\Warehouse\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * Rule định mức tồn theo SKU (phục vụ báo cáo định mức tồn):
 *   - min_stock >= 0   — 0 là một định mức HỢP LỆ, không phải "chưa đặt".
 *   - max_stock >= 1
 *   - max_stock >  min_stock   (lớn hơn hẳn, không phải >=)
 * Bỏ trống / null = chưa đặt định mức, không tính cảnh báo — xem CHECK
 * `chk_skus_stock_thresholds` (migration 2026_08_29_100000), là chốt chặn cuối.
 *
 * Không dùng rule `gt:min_stock` của Laravel vì PATCH có thể chỉ gửi MỘT vế:
 * khi đó `gt` so với giá trị vắng mặt (quy về 0) và cặp min/max chéo nhau vẫn
 * lọt xuống DB, đổi 422 đọc được thành 500 vi phạm CHECK.
 */
trait ValidatesSkuStockThresholds
{
    /** @var array<string, mixed>|null */
    private ?array $resolvedSkuStockThresholds = null;

    /**
     * Rule cho từng vế; ràng buộc bắc cầu giữa hai vế nằm ở withValidator().
     *
     * @return array<string, array<int, string>>
     */
    protected function skuStockThresholdRules(): array
    {
        return [
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Vế nào đã sai kiểu/biên thì báo lỗi đó trước, đừng chồng thêm lỗi cặp.
            if ($validator->errors()->hasAny(['min_stock', 'max_stock'])) {
                return;
            }

            $min = $this->effectiveStockThreshold('min_stock');
            $max = $this->effectiveStockThreshold('max_stock');

            if ($min === null || $max === null) {
                return;
            }

            if ($max > $min) {
                return;
            }

            // Gắn lỗi vào ĐÚNG ô người dùng vừa nhập: PATCH chỉ gửi min_stock thì
            // báo ở min_stock (kèm giá trị max đang lưu), nếu không thì báo ở
            // max_stock — báo nhầm ô khiến người dùng sửa ô họ không hề đụng tới.
            if ($this->has('min_stock') && ! $this->has('max_stock')) {
                $validator->errors()->add(
                    'min_stock',
                    'Định mức tồn tối thiểu phải nhỏ hơn định mức tồn tối đa đang đặt ('
                        .$this->formatStockThreshold($max).').'
                );

                return;
            }

            $validator->errors()->add(
                'max_stock',
                'Định mức tồn tối đa phải lớn hơn định mức tồn tối thiểu'
                    .($this->has('min_stock') ? '' : ' đang đặt ('.$this->formatStockThreshold($min).')')
                    .'.'
            );
        });
    }

    /** 20.000 -> "20", 12.500 -> "12.5" — thông báo lỗi đọc như người dùng nhập. */
    private function formatStockThreshold(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Giá trị SAU khi request được áp: field có trong payload thì lấy theo payload
     * (kể cả null = xoá định mức), không có thì giữ giá trị đang lưu.
     */
    private function effectiveStockThreshold(string $field): ?float
    {
        if ($this->has($field)) {
            $value = $this->input($field);

            return ($value === null || $value === '') ? null : (float) $value;
        }

        // Nhớ lại kết quả: cả hai vế cùng đọc một bản ghi, không cần 2 lượt query.
        $this->resolvedSkuStockThresholds ??= $this->currentSkuStockThresholds();
        $current = $this->resolvedSkuStockThresholds[$field] ?? null;

        return $current === null ? null : (float) $current;
    }

    /**
     * Giá trị đang lưu của SKU đang sửa. Store request tạo bản ghi mới nên không
     * có gì để kế thừa — mảng rỗng.
     *
     * @return array{min_stock?: float|int|string|null, max_stock?: float|int|string|null}
     */
    protected function currentSkuStockThresholds(): array
    {
        return [];
    }
}
