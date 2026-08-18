<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WWHN-252 (FE252-P1-07) — xoá lệch hành vi publish giữa hai driver.
 *
 * REQ-SRS-020 lưu trạng thái publish tại pv_product_profiles.published (ERD 51).
 * Trên PostgreSQL, trigger `hn_legacy_sku_write` (migration 2026_08_16_121000)
 * upsert hồ sơ PV mỗi khi `sku.published_to_app` được ghi, nên publish một SKU
 * là tự có hồ sơ PV để gắn ngành nghề/tag/ảnh.
 *
 * Trên sqlite — driver mà CI chạy full suite — `sku` là BẢNG THẬT, không có
 * INSTEAD OF trigger nào, nên `published_to_app` được ghi thẳng vào cột còn
 * `pv_product_profile` không bao giờ có dòng. Hệ quả: cùng một payload publish
 * cho hai kết quả khác nhau (PG có hồ sơ, sqlite không), và mọi truy vấn PV
 * dựa vào hồ sơ (ảnh, ngành nghề, tag, display_name) im lặng trả rỗng trên CI.
 *
 * Migration này dựng đúng hành vi đó cho sqlite bằng AFTER INSERT/UPDATE trigger
 * trên `sku`, cộng backfill cho dữ liệu đã publish từ trước. ERD 51 khai
 * UX(sku_id) nên cũng bổ sung unique index còn thiếu — đó vừa là điều kiện của
 * ON CONFLICT, vừa là ràng buộc "một hồ sơ / một SKU" mà ERD yêu cầu.
 */
return new class extends Migration
{
    private const UUID_EXPR = "lower(hex(randomblob(4))||'-'||hex(randomblob(2))||'-4'||substr(hex(randomblob(2)),2)||'-'||substr('89ab',abs(random())%4+1,1)||substr(hex(randomblob(2)),2)||'-'||hex(randomblob(6)))";

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        if (! $this->isPhysicalTable('sku') || ! $this->isPhysicalTable('pv_product_profile')) {
            return;
        }

        $this->dropDuplicateProfiles();
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_pv_product_profile_product_id ON pv_product_profile (product_id)');

        $this->backfillProfiles();

        DB::statement('DROP TRIGGER IF EXISTS hn_pv_profile_sync_on_sku_insert');
        DB::statement('DROP TRIGGER IF EXISTS hn_pv_profile_sync_on_sku_update');

        $uuid = self::UUID_EXPR;
        DB::statement(<<<SQL
            CREATE TRIGGER hn_pv_profile_sync_on_sku_insert
            AFTER INSERT ON sku
            WHEN NEW.published_to_app = 1
            BEGIN
                INSERT INTO pv_product_profile (profile_id, product_id, display_name, published, published_at, sort_order, version, created_at, updated_at)
                VALUES ({$uuid}, NEW.sku_id, NEW.sku_name, NEW.published_to_app,
                        CASE WHEN NEW.published_to_app THEN CURRENT_TIMESTAMP END,
                        0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON CONFLICT(product_id) DO UPDATE SET
                    published = excluded.published,
                    published_at = CASE WHEN excluded.published THEN COALESCE(pv_product_profile.published_at, CURRENT_TIMESTAMP) END,
                    updated_at = CURRENT_TIMESTAMP;
            END
        SQL);

        DB::statement(<<<SQL
            CREATE TRIGGER hn_pv_profile_sync_on_sku_update
            AFTER UPDATE OF published_to_app ON sku
            WHEN NEW.published_to_app IS NOT NULL
             AND (OLD.published_to_app IS NULL OR OLD.published_to_app <> NEW.published_to_app)
            BEGIN
                INSERT INTO pv_product_profile (profile_id, product_id, display_name, published, published_at, sort_order, version, created_at, updated_at)
                VALUES ({$uuid}, NEW.sku_id, NEW.sku_name, NEW.published_to_app,
                        CASE WHEN NEW.published_to_app THEN CURRENT_TIMESTAMP END,
                        0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON CONFLICT(product_id) DO UPDATE SET
                    published = excluded.published,
                    published_at = CASE WHEN excluded.published THEN COALESCE(pv_product_profile.published_at, CURRENT_TIMESTAMP) END,
                    updated_at = CURRENT_TIMESTAMP;
            END
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS hn_pv_profile_sync_on_sku_insert');
        DB::statement('DROP TRIGGER IF EXISTS hn_pv_profile_sync_on_sku_update');
        DB::statement('DROP INDEX IF EXISTS ux_pv_product_profile_product_id');
    }

    /**
     * ERD 51 chỉ cho một hồ sơ / SKU. Nếu dữ liệu cũ lỡ có nhiều dòng thì giữ
     * dòng tạo sớm nhất để không mất published_at gốc, rồi mới tạo unique index.
     */
    private function dropDuplicateProfiles(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM pv_product_profile
             WHERE profile_id NOT IN (
                 SELECT profile_id FROM (
                     SELECT profile_id,
                            ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY created_at, profile_id) AS rn
                       FROM pv_product_profile
                 ) ranked WHERE rn = 1
             )
        SQL);
    }

    /**
     * Chỉ SKU ĐANG publish mới được backfill hồ sơ. `sku.published_to_app` trên
     * sqlite có DEFAULT false nên "IS NOT NULL" sẽ đúng với MỌI SKU và biến mọi
     * SKU thành có hồ sơ PV — khác hẳn PostgreSQL, nơi trigger chỉ chạy khi
     * client thực sự gửi giá trị publish. Điều kiện `= true` là điểm chung duy
     * nhất cho ra cùng một trạng thái trên hai driver.
     */
    private function backfillProfiles(): void
    {
        $rows = DB::table('sku')
            ->where('published_to_app', true)
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('pv_product_profile')
                    ->whereColumn('pv_product_profile.product_id', 'sku.sku_id');
            })
            ->get(['sku_id', 'sku_name', 'published_to_app']);

        foreach ($rows as $row) {
            $published = (bool) $row->published_to_app;
            DB::table('pv_product_profile')->insert([
                'profile_id' => (string) Str::uuid(),
                'product_id' => $row->sku_id,
                'display_name' => $row->sku_name,
                'published' => $published,
                'published_at' => $published ? now() : null,
                'sort_order' => 0,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Hồ sơ đã tồn tại nhưng lệch cờ publish so với SKU (dữ liệu trước khi có
        // trigger) phải được kéo về đúng nguồn sự thật.
        DB::statement(<<<'SQL'
            UPDATE pv_product_profile
               SET published = (
                       SELECT CASE WHEN s.published_to_app THEN 1 ELSE 0 END
                         FROM sku s WHERE s.sku_id = pv_product_profile.product_id
                   ),
                   updated_at = CURRENT_TIMESTAMP
             WHERE EXISTS (
                       SELECT 1 FROM sku s
                        WHERE s.sku_id = pv_product_profile.product_id
                          AND s.published_to_app IS NOT NULL
                          AND (CASE WHEN s.published_to_app THEN 1 ELSE 0 END) <> (CASE WHEN pv_product_profile.published THEN 1 ELSE 0 END)
                   )
        SQL);
    }

    private function isPhysicalTable(string $name): bool
    {
        $relation = DB::selectOne('SELECT type FROM sqlite_master WHERE name = ? LIMIT 1', [$name]);

        return $relation !== null && $relation->type === 'table';
    }
};
