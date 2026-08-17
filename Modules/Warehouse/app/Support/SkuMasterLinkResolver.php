<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;

/**
 * ERD bảng 16: `skus.power_source_id` -> power_sources.id và
 * `skus.package_variant_id` -> package_variants.id là quan hệ chuẩn; hai cột text
 * `power_or_voltage` / `package_variant` chỉ là dữ liệu legacy nằm trong
 * specifications jsonb.
 *
 * REQ-SRS-029 / AC-SRS-029 yêu cầu "thêm loại không cần code change", nên nguồn
 * điện và quy cách đóng gói phải đọc từ master chứ không phải enum cứng trong code.
 * Resolver này quy đổi mọi dạng client gửi (id / code / text legacy) về đúng cặp
 * FK + text, dùng chung cho cả /products (ProductController) lẫn /skus
 * (CurrentBaselineSkuController) để hai đường vào không lệch nhau.
 */
class SkuMasterLinkResolver
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> các cột cần ghi xuống SKU
     */
    public static function resolve(array $payload): array
    {
        return static::resolvePowerSource($payload) + static::resolvePackageVariant($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function resolvePowerSource(array $payload): array
    {
        $explicitId = array_key_exists('power_source_id', $payload);
        $explicitCode = array_key_exists('power_source_code', $payload);

        if ($explicitId || $explicitCode) {
            $id = $payload['power_source_id'] ?? null;
            $code = trim((string) ($payload['power_source_code'] ?? ''));

            if ($id === null && $code === '') {
                return ['power_source_id' => null];
            }

            $master = $id
                ? DB::table('power_sources')->where('id', $id)->first()
                : DB::table('power_sources')->where('power_source_code', $code)->first();

            if ($master === null || strtoupper((string) $master->status) !== 'ACTIVE') {
                ApiError::abort(422, 'MASTER_NOT_ACTIVE', 'power_source_id/power_source_code không tồn tại hoặc không ACTIVE.');
            }

            return ['power_source_id' => $master->id, 'power_or_voltage' => $master->power_source_code];
        }

        // Text legacy: cố gắng bắc cầu sang master để FK không bị bỏ trống, nhưng
        // giá trị không khớp master nào vẫn được giữ nguyên (mô tả kỹ thuật tự do
        // như "220V" tồn tại từ trước khi có bảng power_sources).
        if (! array_key_exists('power_or_voltage', $payload)) {
            return [];
        }

        $value = trim((string) $payload['power_or_voltage']);
        if ($value === '') {
            return [];
        }

        $master = DB::table('power_sources')
            ->where(fn ($query) => $query->where('power_source_code', $value)->orWhere('power_source_name', $value))
            ->where('status', 'ACTIVE')
            ->first();

        return $master === null
            ? []
            : ['power_source_id' => $master->id, 'power_or_voltage' => $master->power_source_code];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function resolvePackageVariant(array $payload): array
    {
        $explicitId = array_key_exists('package_variant_id', $payload);
        $explicitCode = array_key_exists('package_variant_code', $payload);

        if ($explicitId || $explicitCode) {
            $id = $payload['package_variant_id'] ?? null;
            $code = trim((string) ($payload['package_variant_code'] ?? ''));

            if ($id === null && $code === '') {
                return ['package_variant_id' => null];
            }

            $master = $id
                ? DB::table('package_variants')->where('id', $id)->first()
                : DB::table('package_variants')->where('package_code', $code)->first();

            if ($master === null || strtoupper((string) $master->status) !== 'ACTIVE') {
                ApiError::abort(422, 'PACKAGE_VARIANT_NOT_ACTIVE', 'Package Variant không tồn tại hoặc không ACTIVE.');
            }

            return ['package_variant_id' => $master->id, 'package_variant' => $master->package_code];
        }

        if (! array_key_exists('package_variant', $payload)) {
            return [];
        }

        $value = trim((string) $payload['package_variant']);
        if ($value === '') {
            return [];
        }

        $master = DB::table('package_variants')
            ->where('package_code', $value)
            ->where('status', 'ACTIVE')
            ->first();

        return $master === null
            ? []
            : ['package_variant_id' => $master->id, 'package_variant' => $master->package_code];
    }
}
