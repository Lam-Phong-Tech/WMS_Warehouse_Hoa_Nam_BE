<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-SRS-029 chiều ngược lại của 2026_08_16_124000.
 *
 * Master tạo qua endpoint canonical (POST /brands, /product-categories,
 * /product-models, /power-sources, /package-variants) chỉ nằm ở bảng canonical.
 * StoreProductRequest/UpdateProductRequest lại validate `exists:product_masters`,
 * nên brand vừa tạo bị 422 khi gán vào SKU và FE (resolveMasterId đọc
 * /product-masters) cũng không thấy. Sync ngược để hai đường vào cùng một
 * nguồn sự thật; master_id dùng chung id canonical nên FK khớp trực tiếp.
 */
return new class extends Migration
{
    /** @var array<string, array{0:string,1:string,2:string}> table => [type, codeColumn, nameColumn] */
    private const CANONICAL_MASTERS = [
        'brands' => ['BRAND', 'brand_code', 'brand_name'],
        'product_categories' => ['CATEGORY', 'category_code', 'category_name'],
        'product_models' => ['MODEL', 'model_code', 'model_name'],
        'power_sources' => ['POWER', 'power_source_code', 'power_source_name'],
        'package_variants' => ['PACKAGE', 'variant_code', 'variant_name'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::CANONICAL_MASTERS as $table => [$type, $codeColumn, $nameColumn]) {
            $parentValue = $table === 'product_models' ? 'NEW.brand_id' : 'NULL::uuid';
            $parentColumn = $table === 'product_models' ? 'brand_id' : 'NULL::uuid';
            $function = 'hn_sync_'.$table.'_to_product_masters';

            // uk_product_masters_type_code: nếu code đã thuộc master_id khác thì bỏ qua
            // thay vì nổ 23505 — dữ liệu trùng đó phải được dọn thủ công, không được
            // làm hỏng thao tác tạo master canonical.
            DB::statement(<<<SQL
                CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger AS \$\$
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM product_masters pm
                         WHERE pm.master_type = '{$type}' AND pm.code = NEW.{$codeColumn} AND pm.master_id <> NEW.id
                    ) THEN
                        RETURN NEW;
                    END IF;

                    INSERT INTO product_masters (master_id, master_type, code, name, parent_master_id, status, created_at, updated_at, version)
                    VALUES (NEW.id, '{$type}', NEW.{$codeColumn}, NEW.{$nameColumn}, {$parentValue},
                            COALESCE(NEW.status, 'ACTIVE'), COALESCE(NEW.created_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP, 0)
                    ON CONFLICT (master_id) DO UPDATE SET
                        code = EXCLUDED.code,
                        name = EXCLUDED.name,
                        parent_master_id = EXCLUDED.parent_master_id,
                        status = EXCLUDED.status,
                        updated_at = CURRENT_TIMESTAMP;
                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql
            SQL);

            // Sync hai chiều dễ tạo vòng lặp. WHEN được đánh giá TRƯỚC khi vào trigger
            // nên depth=0 nghĩa là ứng dụng ghi thẳng bảng canonical; khi
            // trg_hn_sync_product_master_to_canonical vừa ghi xuống thì depth đã là 1
            // và trigger này không chạy lại.
            DB::statement('DROP TRIGGER IF EXISTS trg_'.$function.' ON '.$table);
            DB::statement(
                'CREATE TRIGGER trg_'.$function.' AFTER INSERT OR UPDATE ON '.$table.
                ' FOR EACH ROW WHEN (pg_trigger_depth() = 0) EXECUTE FUNCTION '.$function.'()'
            );

            DB::statement(<<<SQL
                INSERT INTO product_masters (master_id, master_type, code, name, parent_master_id, status, created_at, updated_at, version)
                SELECT c.id, '{$type}', c.{$codeColumn}, c.{$nameColumn}, {$parentColumn},
                       COALESCE(c.status, 'ACTIVE'), COALESCE(c.created_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP, 0
                  FROM {$table} c
                 WHERE NOT EXISTS (
                    SELECT 1 FROM product_masters pm
                     WHERE pm.master_type = '{$type}' AND pm.code = c.{$codeColumn}
                 )
                ON CONFLICT (master_id) DO NOTHING
            SQL);
        }
    }

    public function down(): void
    {
        // Fail-forward.
    }
};
