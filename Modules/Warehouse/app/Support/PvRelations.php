<?php

namespace Modules\Warehouse\Support;

use Illuminate\Support\Facades\DB;

/**
 * WWHN-252 — nguồn sự thật duy nhất về "bảng nào ghi được" của cụm App PV.
 *
 * Schema App PV tồn tại ở hai hình dạng tuỳ driver (xem
 * 2026_08_09_000400_align_latest_erd_baseline + 2026_08_10_000791_retire_remaining_singular_legacy_tables
 * + 2026_08_16_121000_project_publish_state_through_sku_compat_views):
 *
 * | Thực thể            | sqlite (bảng vật lý)     | PostgreSQL (bảng vật lý)  |
 * |---------------------|--------------------------|---------------------------|
 * | Product profile     | pv_product_profile       | pv_product_profiles       |
 * | Recommendation rule | pv_recommendation_rule   | pv_recommendation_rules   |
 * | Product-tag mapping | pv_product_tag           | pv_product_tags           |
 * | Industry            | pv_industry              | pv_industry (+mirror)     |
 * | Filter tag          | pv_filter_tag            | pv_filter_tag (+mirror)   |
 * | Product-industry    | pv_product_industry      | pv_product_industry (+mirror) |
 *
 * Tên SỐ ÍT đọc được trên cả hai driver (bảng ở driver này, view ở driver kia)
 * và có cùng danh sách cột → mọi truy vấn ĐỌC dùng số ít. Nhưng trên PostgreSQL
 * ba view số ít đầu tiên gắn trigger reject-write, nên mọi truy vấn GHI phải
 * hỏi lớp này để lấy đúng bảng vật lý + tên cột khoá của driver hiện tại.
 */
final class PvRelations
{
    private function __construct() {}

    public static function isPgsql(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    // ---------- Read relations (portable, singular) ----------

    public const READ_PROFILE = 'pv_product_profile';

    public const READ_INDUSTRY = 'pv_industry';

    public const READ_TAG = 'pv_filter_tag';

    public const READ_PRODUCT_INDUSTRY = 'pv_product_industry';

    public const READ_PRODUCT_TAG = 'pv_product_tag';

    public const READ_RULE = 'pv_recommendation_rule';

    // ---------- Write relations (driver aware) ----------

    /**
     * @return array{table: string, key: string, sku: string}
     */
    public static function profile(): array
    {
        return self::isPgsql()
            ? ['table' => 'pv_product_profiles', 'key' => 'id', 'sku' => 'sku_id']
            : ['table' => 'pv_product_profile', 'key' => 'profile_id', 'sku' => 'product_id'];
    }

    /**
     * @return array{table: string, key: string, sku: string}
     */
    public static function rule(): array
    {
        return self::isPgsql()
            ? ['table' => 'pv_recommendation_rules', 'key' => 'id', 'sku' => 'sku_id']
            : ['table' => 'pv_recommendation_rule', 'key' => 'rule_id', 'sku' => 'product_id'];
    }

    /**
     * @return array{table: string, key: string}
     */
    public static function industry(): array
    {
        return ['table' => 'pv_industry', 'key' => 'industry_id'];
    }

    /**
     * @return array{table: string, key: string}
     */
    public static function tag(): array
    {
        return ['table' => 'pv_filter_tag', 'key' => 'tag_id'];
    }

    public static function productIndustryTable(): string
    {
        return 'pv_product_industry';
    }

    /**
     * pv_product_tag là view reject-write trên PostgreSQL; bảng vật lý canonical
     * ở đó là pv_product_tags và KHÔNG có cột priority (khác sqlite), nên caller
     * chỉ được ghi profile_id/tag_id/created_at để payload chạy trên cả hai.
     */
    public static function productTagTable(): string
    {
        return self::isPgsql() ? 'pv_product_tags' : 'pv_product_tag';
    }
}
