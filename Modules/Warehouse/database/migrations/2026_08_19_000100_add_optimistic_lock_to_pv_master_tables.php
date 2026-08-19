<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Database\Migrations\Concerns\DropsBaselineViewsAroundAlter;

/**
 * WWHN-252 (FE252-P1-02) — Annex C khai `updatePvAdminResource` dùng header
 * If-Match và có 412 STALE_VERSION trong error set. Điều đó đòi mỗi resource PV
 * master phải có một số version tăng dần.
 *
 * Trạng thái trước migration:
 * - `pv_product_profile(s)` và `pv_recommendation_rules` (PostgreSQL) đã có cột
 *   `version` theo ERD bảng 51/57.
 * - `pv_industry`, `pv_filter_tag` (bảng vật lý ở CẢ hai driver) và
 *   `pv_recommendation_rule` (bảng vật lý trên sqlite) thì KHÔNG có, nên
 *   If-Match không thể thực thi được và 412 là mã chết.
 *
 * Migration bổ sung cột `version` cho đúng ba bảng đó, rồi cập nhật các
 * projection sang bảng ERD số nhiều để chúng chiếu version THẬT thay vì hằng 0
 * — nếu bỏ bước này thì `pv_industries.version` sẽ vĩnh viễn là 0 trong khi
 * `pv_industry.version` tăng, tức là mirror nói dối về optimistic lock.
 *
 * ERD bảng 53/54 mô tả `version` cho các thực thể PV master ở dạng số nguyên
 * DEFAULT 0/1; ở đây chọn DEFAULT 1 cho khớp cách ProductController đọc
 * If-Match (`version >= 1`) trên toàn hệ thống.
 */
return new class extends Migration
{
    use DropsBaselineViewsAroundAlter;

    public function up(): void
    {
        foreach (['pv_industry', 'pv_filter_tag', 'pv_recommendation_rule'] as $table) {
            $this->addVersionColumn($table);
        }

        $this->refreshVersionProjections();
    }

    public function down(): void
    {
        // Fail-forward: gỡ cột version sẽ làm If-Match/412 của
        // /pv-admin/resources chết trở lại. Projection giữ nguyên vì nó chỉ đọc
        // cột đã có. Chỉ hoàn tác nếu bảng thật vẫn còn cột (idempotent).
        foreach (['pv_industry', 'pv_filter_tag', 'pv_recommendation_rule'] as $table) {
            if (! $this->isPhysicalTable($table) || ! Schema::hasColumn($table, 'version')) {
                continue;
            }

            $this->alterTableAroundBaselineViews($table, function () use ($table): void {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('version');
                });
            });
        }

        $this->restoreConstantVersionProjections();
    }

    private function addVersionColumn(string $table): void
    {
        // Trên PostgreSQL `pv_recommendation_rule` là VIEW reject-write (bảng
        // canonical là pv_recommendation_rules, đã có version) nên bỏ qua.
        if (! $this->isPhysicalTable($table) || Schema::hasColumn($table, 'version')) {
            return;
        }

        $this->alterTableAroundBaselineViews($table, function () use ($table): void {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedInteger('version')->default(1);
            });
        });

        DB::table($table)->whereNull('version')->update(['version' => 1]);
    }

    private function refreshVersionProjections(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // CREATE OR REPLACE giữ nguyên tên/thứ tự/kiểu cột nên trigger mirror
            // trg_erd_mirror_* đang SELECT từ view này không phải dựng lại.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE VIEW pv_industries__legacy_projection AS
                SELECT industry_id AS id, industry_code, industry_name, status, sort_order,
                       created_at, NULL::text AS created_by, updated_at, NULL::text AS updated_by,
                       version
                  FROM pv_industry
            SQL);
            DB::statement(<<<'SQL'
                CREATE OR REPLACE VIEW pv_tags__legacy_projection AS
                SELECT tag_id AS id, tag_code, tag_name, status, sort_order,
                       created_at, NULL::text AS created_by, updated_at, NULL::text AS updated_by,
                       version
                  FROM pv_filter_tag
            SQL);

            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $this->replaceSqliteView('pv_industries', 'SELECT industry_id AS id, industry_code, industry_name, status, sort_order, created_at, NULL AS created_by, updated_at, NULL AS updated_by, version FROM pv_industry');
        $this->replaceSqliteView('pv_tags', 'SELECT tag_id AS id, tag_code, tag_name, status, sort_order, created_at, NULL AS created_by, updated_at, NULL AS updated_by, version FROM pv_filter_tag');
        $this->replaceSqliteView('pv_recommendation_rules', 'SELECT rule_id AS id, industry_id, product_id AS sku_id, category_id, tag_id, priority, status, NULL AS effective_from, NULL AS effective_to, created_at, NULL AS created_by, updated_at, NULL AS updated_by, version FROM pv_recommendation_rule');
    }

    private function restoreConstantVersionProjections(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE VIEW pv_industries__legacy_projection AS
                SELECT industry_id AS id, industry_code, industry_name, status, sort_order,
                       created_at, NULL::text AS created_by, updated_at, NULL::text AS updated_by,
                       0 AS version
                  FROM pv_industry
            SQL);
            DB::statement(<<<'SQL'
                CREATE OR REPLACE VIEW pv_tags__legacy_projection AS
                SELECT tag_id AS id, tag_code, tag_name, status, sort_order,
                       created_at, NULL::text AS created_by, updated_at, NULL::text AS updated_by,
                       0 AS version
                  FROM pv_filter_tag
            SQL);

            return;
        }

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $this->replaceSqliteView('pv_industries', 'SELECT industry_id AS id, industry_code, industry_name, status, sort_order, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM pv_industry');
        $this->replaceSqliteView('pv_tags', 'SELECT tag_id AS id, tag_code, tag_name, status, sort_order, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM pv_filter_tag');
        $this->replaceSqliteView('pv_recommendation_rules', 'SELECT rule_id AS id, industry_id, product_id AS sku_id, category_id, tag_id, priority, status, NULL AS effective_from, NULL AS effective_to, created_at, NULL AS created_by, updated_at, NULL AS updated_by, 0 AS version FROM pv_recommendation_rule');
    }

    private function replaceSqliteView(string $view, string $selectSql): void
    {
        $existing = DB::selectOne('SELECT type FROM sqlite_master WHERE name = ? LIMIT 1', [$view]);
        if ($existing !== null && $existing->type !== 'view') {
            return;
        }

        DB::statement("DROP VIEW IF EXISTS {$view}");
        DB::statement("CREATE VIEW {$view} AS {$selectSql}");
    }

    private function isPhysicalTable(string $name): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            $relation = DB::selectOne(<<<'SQL'
                SELECT c.relkind
                  FROM pg_class c
                  JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = current_schema() AND c.relname = ?
                 LIMIT 1
            SQL, [$name]);

            return $relation !== null && in_array($relation->relkind, ['r', 'p'], true);
        }

        if (DB::getDriverName() === 'sqlite') {
            $relation = DB::selectOne('SELECT type FROM sqlite_master WHERE name = ? LIMIT 1', [$name]);

            return $relation !== null && $relation->type === 'table';
        }

        return Schema::hasTable($name);
    }
};
