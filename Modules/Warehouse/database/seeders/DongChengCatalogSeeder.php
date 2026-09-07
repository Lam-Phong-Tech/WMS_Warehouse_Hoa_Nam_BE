<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\PvRelations;
use RuntimeException;

/**
 * DANH MỤC HÀNG HOÁ THẬT của kho Hoa Nam — nhà phân phối DongCheng.
 *
 * Thay thế toàn bộ SKU demo bịa tên ("Máy bơm nước Hoa Nam C68", "Máy phun thuốc
 * Honda...") bằng 972 mã hàng THẬT lấy từ hai catalog DongCheng 2026 (539 dụng cụ
 * cầm tay + 433 máy) cộng 20 mã linh kiện/phụ kiện rút ra từ chính cột cấu hình
 * của catalog (pin 20V MAX 2.0–8.0Ah, sạc 1.3–6A, chổi than, mũi vít...).
 *
 * Nguồn dữ liệu: `Modules/Warehouse/database/data/dongcheng_catalog.json`, commit
 * trong repo nên `git clone` + `php artisan db:seed` là có dữ liệu ngay, KHÔNG
 * cần file Excel gốc (~136 MB). Sinh lại bằng:
 *   node scripts/build-dongcheng-catalog.mjs <handtools.xlsx> <powertools.xlsx>
 *
 * Seeder này ghi:
 *  - product_categories: bổ sung 15 nhóm hàng máy (PT_*); 12 nhóm dụng cụ cầm tay
 *    đã có sẵn nên chỉ tái sử dụng.
 *  - product_models: 113 sê-ri DongCheng thật (DCPB, DCSM, D07...).
 *  - skus: 992 mã, đủ PRODUCT/COMPONENT × SERIAL/QUANTITY × ACTIVE/INACTIVE, kèm
 *    family_name/variant_label để nhóm biến thể (WWHN hand-tool family) và
 *    specifications thật (công suất, tốc độ, momen, khối lượng, mã vạch...).
 *  - pv_product_profiles + pv_product_images: MỖI sản phẩm một ảnh thật trên
 *    Cloudflare (images.bigk.click) → App PV, thẻ sản phẩm, màn SKU đều có ảnh.
 *  - pv_industry / pv_filter_tag + bảng nối: ngành nghề & nhãn lọc lấy từ cột
 *    "Lĩnh vực" và "Phân loại theo chức năng chung" của catalog.
 *  - pv_recommendation_rules: mỗi ngành nghề 6 sản phẩm gợi ý.
 *
 * Idempotent theo sku_code/mã danh mục. PHẢI chạy TRƯỚC mọi seeder nghiệp vụ:
 * các seeder đó dùng `ensureSku()` kiểu "có rồi thì dùng lại" nên khi mã thật đã
 * nằm sẵn trong bảng, mọi chứng từ nhập/xuất/bảo hành/báo cáo tự gắn vào hàng thật.
 *
 * Khối lượng trên sqlite (test): mặc định chỉ nạp một lát cắt phủ đủ mọi nhóm
 * hàng/nguồn điện/quy cách/trạng thái (xem SQLITE_SAMPLE) để bộ test không chậm
 * đi vì 992 dòng; đặt HN_CATALOG_LIMIT=0 để nạp đủ trên mọi driver.
 */
class DongChengCatalogSeeder extends Seeder
{
    /** Số sản phẩm nạp trên sqlite khi không đặt HN_CATALOG_LIMIT. */
    private const SQLITE_SAMPLE = 120;

    /**
     * Mã hàng mà các seeder nghiệp vụ khác neo vào (xem DongChengAnchorSkus).
     * Luôn được nạp kể cả khi cắt bớt danh mục trên sqlite.
     */
    private const ALWAYS_INCLUDE = DongChengAnchorSkus::ALL;

    /** @var array<string,string> mã danh mục → uuid */
    private array $refs = [];

    /** @var array<string,list<string>> tên bảng → danh sách cột thật của driver */
    private array $tableColumns = [];

    private ?string $brandId = null;

    public function run(): void
    {
        $catalog = $this->loadCatalog();

        $this->seedBrand($catalog['brand']);
        $this->seedPowerSourcesAndPackages();
        $this->seedCategories($catalog['categories']);
        $this->seedModels($catalog['models']);
        $this->seedPvMasters($catalog['industries'], $catalog['tags']);

        $rows = $this->selectRows($catalog);
        $this->command?->info(sprintf('DongChengCatalogSeeder: nạp %d mã hàng thật.', count($rows)));

        $skuIds = $this->seedSkus($rows);
        $this->seedPvProfiles($rows, $skuIds);
        $this->seedRecommendationRules($rows, $skuIds);
    }

    /** @return array<string,mixed> */
    private function loadCatalog(): array
    {
        $path = __DIR__.'/../data/dongcheng_catalog.json';
        if (! is_file($path)) {
            throw new RuntimeException(
                'Thiếu Modules/Warehouse/database/data/dongcheng_catalog.json. '
                .'Sinh lại bằng: node scripts/build-dongcheng-catalog.mjs <handtools.xlsx> <powertools.xlsx>'
            );
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! isset($decoded['products'])) {
            throw new RuntimeException('dongcheng_catalog.json sai định dạng.');
        }

        return $decoded;
    }

    /**
     * Lát cắt danh mục cần nạp. Trên PostgreSQL (CSDL thật của app) luôn nạp đủ;
     * trên sqlite cắt bớt để bộ test không cõng 992 dòng mỗi lần seed, nhưng vẫn
     * giữ ĐỦ mọi tổ hợp nhóm hàng × nguồn điện × quy cách × cách theo dõi ×
     * trạng thái để test lọc/báo cáo vẫn có dữ liệu ở mọi nhánh.
     *
     * @param  array<string,mixed>  $catalog
     * @return list<array<string,mixed>>
     */
    private function selectRows(array $catalog): array
    {
        /** @var list<array<string,mixed>> $all */
        $all = array_merge($catalog['products'], $catalog['components']);

        $limitEnv = getenv('HN_CATALOG_LIMIT');
        $limit = $limitEnv === false
            ? (DB::getDriverName() === 'pgsql' ? 0 : self::SQLITE_SAMPLE)
            : (int) $limitEnv;

        if ($limit <= 0 || count($all) <= $limit) {
            return $all;
        }

        $picked = [];
        $seenCombos = [];
        foreach ($all as $row) {
            $code = (string) $row['sku_code'];
            $combo = implode('|', [
                $row['category_code'], $row['power_source_code'], $row['package_code'],
                $row['tracking_mode'], $row['status'], $row['segment'],
            ]);
            if (in_array($code, self::ALWAYS_INCLUDE, true) || ! isset($seenCombos[$combo])) {
                $seenCombos[$combo] = true;
                $picked[$code] = $row;
            }
        }
        // Nếu vẫn thiếu so với hạn mức thì bù thêm theo thứ tự danh mục.
        foreach ($all as $row) {
            if (count($picked) >= $limit) {
                break;
            }
            $picked[(string) $row['sku_code']] ??= $row;
        }

        return array_values($picked);
    }

    // ------------------------------------------------------------------
    //  Danh mục gốc
    // ------------------------------------------------------------------

    /** @param  array{code:string,name:string}  $brand */
    private function seedBrand(array $brand): void
    {
        $existing = DB::table('brands')->where('brand_code', $brand['code'])->first();
        if ($existing !== null) {
            $this->brandId = (string) $existing->id;
            DB::table('brands')->where('id', $this->brandId)->update([
                'brand_name' => $brand['name'],
                'description' => 'Hãng dụng cụ điện & cầm tay DongCheng — hàng do Hoa Nam phân phối.',
                'status' => 'ACTIVE',
                'sort_order' => 1,
                'updated_at' => now(),
            ]);
        } else {
            $this->brandId = (string) Str::uuid();
            DB::table('brands')->insert([
                'id' => $this->brandId,
                'brand_code' => $brand['code'],
                'brand_name' => $brand['name'],
                'description' => 'Hãng dụng cụ điện & cầm tay DongCheng — hàng do Hoa Nam phân phối.',
                'status' => 'ACTIVE',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 0,
            ]);
        }
        $this->syncProductMaster('BRAND', $this->brandId, $brand['code'], $brand['name']);
    }

    /**
     * Nguồn điện + quy cách đóng gói mà catalog thật tham chiếu. WarehouseDatabaseSeeder
     * chỉ khai 5 nguồn điện / 5 quy cách baseline; MANUAL, PNEUMATIC, DUAL_BATTERY,
     * PRO_CASE do MasterCatalogCoverageSeeder tạo nhưng seeder ĐÓ chạy SAU bước này.
     * Không tự bảo đảm ở đây thì ~170 SKU sẽ mất nguồn điện/quy cách (cột "Chưa có"
     * ở màn Danh sách SKU và bộ lọc trả rỗng). Idempotent, an toàn khi chạy chồng.
     */
    private function seedPowerSourcesAndPackages(): void
    {
        $powerSources = [
            ['MANUAL', 'Cơ tay (không động cơ)', 'Dụng cụ cầm tay vận hành bằng sức người, không có động cơ.'],
            ['PNEUMATIC', 'Khí nén', 'Dụng cụ chạy bằng khí nén từ máy nén khí.'],
        ];
        foreach ($powerSources as [$code, $name, $description]) {
            $existing = DB::table('power_sources')->where('power_source_code', $code)->value('id');
            if ($existing !== null) {
                $this->syncProductMaster('POWER', (string) $existing, $code, $name);

                continue;
            }
            $id = (string) Str::uuid();
            DB::table('power_sources')->insert([
                'id' => $id,
                'power_source_code' => $code,
                'power_source_name' => $name,
                'description' => $description,
                'sort_order' => 0,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 0,
            ]);
            $this->syncProductMaster('POWER', $id, $code, $name);
        }

        $packages = [
            ['DUAL_BATTERY', 'Dual battery — kèm 2 pin + sạc nhanh', 'Cấu hình bán kèm 2 pin trượt và bộ sạc nhanh.'],
            ['PRO_CASE', 'Pro case — kèm vali nhựa', 'Máy kèm hộp/vali nhựa đựng máy và phụ kiện.'],
        ];
        foreach ($packages as [$code, $name, $description]) {
            $exists = DB::table('package_variants')->where('package_code', $code)->exists();
            if ($exists) {
                continue;
            }
            DB::table('package_variants')->insert([
                'id' => (string) Str::uuid(),
                // Bảng có CẢ cặp cột cũ (variant_code/variant_name) lẫn cặp canonical
                // (package_code/package_name), đều NOT NULL. Trên PostgreSQL trigger
                // sync_package_variant_canonical_columns tự bù, nhưng sqlite thì không
                // → ghi cả 4 cột để chạy được trên mọi driver.
                'variant_code' => $code,
                'variant_name' => $name,
                'package_code' => $code,
                'package_name' => $name,
                'description' => $description,
                'sort_order' => 0,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 0,
            ]);
        }
    }

    /** @param  list<array{code:string,name:string,sort_order:int}>  $categories */
    private function seedCategories(array $categories): void
    {
        foreach ($categories as $row) {
            $existing = DB::table('product_categories')->where('category_code', $row['code'])->first();
            $description = 'Nhóm hàng theo mục lục catalog DongCheng 2026.';
            if ($existing !== null) {
                DB::table('product_categories')->where('id', $existing->id)->update([
                    'category_name' => $row['name'],
                    'description' => $description,
                    'status' => 'ACTIVE',
                    'sort_order' => $row['sort_order'],
                    'updated_at' => now(),
                ]);
                $this->syncProductMaster('CATEGORY', (string) $existing->id, $row['code'], $row['name']);

                continue;
            }

            $id = (string) Str::uuid();
            DB::table('product_categories')->insert([
                'id' => $id,
                'category_code' => $row['code'],
                'category_name' => $row['name'],
                'description' => $description,
                'status' => 'ACTIVE',
                'sort_order' => $row['sort_order'],
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 0,
            ]);
            $this->syncProductMaster('CATEGORY', $id, $row['code'], $row['name']);
        }
    }

    /** @param  list<array{code:string,name:string,sort_order:int}>  $models */
    private function seedModels(array $models): void
    {
        foreach ($models as $row) {
            $existing = DB::table('product_models')->where('model_code', $row['code'])->first();
            $description = 'Sê-ri máy DongCheng theo mã catalog.';
            if ($existing !== null) {
                DB::table('product_models')->where('id', $existing->id)->update([
                    'model_name' => $row['name'],
                    'brand_id' => $this->brandId,
                    'description' => $description,
                    'status' => 'ACTIVE',
                    'sort_order' => $row['sort_order'],
                    'updated_at' => now(),
                ]);
                $this->syncProductMaster('MODEL', (string) $existing->id, $row['code'], $row['name'], $this->brandId);

                continue;
            }

            $id = (string) Str::uuid();
            DB::table('product_models')->insert([
                'id' => $id,
                'model_code' => $row['code'],
                'model_name' => $row['name'],
                'brand_id' => $this->brandId,
                'description' => $description,
                'status' => 'ACTIVE',
                'sort_order' => $row['sort_order'],
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 0,
            ]);
            $this->syncProductMaster('MODEL', $id, $row['code'], $row['name'], $this->brandId);
        }
    }

    /**
     * Ngành nghề & nhãn lọc của App PV. Ghi vào bảng SỐ ÍT (pv_industry /
     * pv_filter_tag) — trên PostgreSQL bảng số nhiều là mirror một chiều do
     * trigger đồng bộ, ghi thẳng vào đó sẽ bị trôi.
     *
     * @param  list<array{code:string,name:string,sort_order:int}>  $industries
     * @param  list<array{code:string,name:string,sort_order:int}>  $tags
     */
    private function seedPvMasters(array $industries, array $tags): void
    {
        foreach ($industries as $row) {
            $existing = DB::table('pv_industry')->where('industry_code', $row['code'])->first();
            if ($existing !== null) {
                continue;
            }
            DB::table('pv_industry')->insert([
                'industry_id' => (string) Str::uuid(),
                'industry_code' => $row['code'],
                'industry_name' => $row['name'],
                'status' => 'ACTIVE',
                'sort_order' => $row['sort_order'],
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 1,
            ]);
        }

        foreach ($tags as $row) {
            $existing = DB::table('pv_filter_tag')->where('tag_code', $row['code'])->first();
            if ($existing !== null) {
                continue;
            }
            DB::table('pv_filter_tag')->insert([
                'tag_id' => (string) Str::uuid(),
                'tag_code' => $row['code'],
                'tag_name' => $row['name'],
                // Nhãn rút từ cột "Phân loại theo chức năng chung" mô tả ĐẶC TÍNH
                // kỹ thuật của máy (không chổi than, giảm rung, laser...) nên
                // thuộc nhóm SPEC, khác CATEGORY/MARKETING đã có sẵn.
                'tag_type' => 'SPEC',
                'status' => 'ACTIVE',
                'sort_order' => $row['sort_order'],
                'created_at' => now(),
                'updated_at' => now(),
                'version' => 1,
            ]);
        }
    }

    // ------------------------------------------------------------------
    //  SKU
    // ------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,string> sku_code → sku_id
     */
    private function seedSkus(array $rows): array
    {
        $ids = [];
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::transaction(function () use ($chunk, &$ids): void {
                foreach ($chunk as $row) {
                    $ids[(string) $row['sku_code']] = $this->upsertSku($row);
                }
            });
        }

        return $ids;
    }

    /** @param  array<string,mixed>  $row */
    private function upsertSku(array $row): string
    {
        $isComponent = $row['segment'] === 'COMPONENT';
        $specs = is_array($row['specifications']) ? $row['specifications'] : [];

        $attributes = [
            'sku_name' => mb_substr((string) $row['sku_name'], 0, 200),
            'sku_type' => $isComponent ? 'COMPONENT' : 'PRODUCT',
            'unit' => (string) $row['unit'],
            'is_serial_tracked' => $row['tracking_mode'] === 'SERIAL',
            'brand_id' => $this->brandId,
            'category_id' => $this->refId('product_categories', 'category_code', (string) $row['category_code']),
            'model_id' => $this->refId('product_models', 'model_code', (string) $row['model_code']),
            'power_source_id' => $this->refId('power_sources', 'power_source_code', (string) $row['power_source_code']),
            'package_variant_id' => $this->refId('package_variants', 'package_code', (string) $row['package_code']),
            'family_name' => mb_substr((string) $row['family_name'], 0, 200),
            'variant_label' => $row['variant_label'] !== null ? mb_substr((string) $row['variant_label'], 0, 100) : null,
            'power_or_voltage' => isset($specs['rated_power']) ? mb_substr((string) $specs['rated_power'], 0, 50) : null,
            'package_variant' => mb_substr((string) $row['package_code'], 0, 50),
            'version_code' => 'V1',
            'warranty_applicable' => (bool) $row['warranty_applicable'],
            'specification' => $specs,
            // Guard hn_erd_guard_pv_publish_active_sku: chỉ SKU ACTIVE mới được
            // publish ra mặt công khai — mã đã ngừng kinh doanh phải để false.
            // Linh kiện (pin/sạc/chổi than) KHÔNG lên App PV: đó là vật tư phục vụ
            // hồ sơ bảo hành nội bộ, catalog gốc cũng không có ảnh cho chúng.
            'published_to_app' => $row['status'] === 'ACTIVE' && $row['segment'] !== 'COMPONENT',
            'status' => (string) $row['status'],
        ];

        $sku = Sku::updateOrCreate(['sku_code' => (string) $row['sku_code']], $attributes);

        return (string) $sku->sku_id;
    }

    // ------------------------------------------------------------------
    //  App PV: hồ sơ, ảnh, ngành nghề, nhãn lọc
    // ------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,string>  $skuIds
     */
    private function seedPvProfiles(array $rows, array $skuIds): void
    {
        $profile = PvRelations::profile();
        $industryIds = DB::table('pv_industry')->pluck('industry_id', 'industry_code');
        $tagIds = DB::table('pv_filter_tag')->pluck('tag_id', 'tag_code');
        $productTagTable = PvRelations::productTagTable();

        // Gom TOÀN BỘ trạng thái hiện có trong 3 truy vấn rồi ghi theo lô. Bản
        // đầu tiên hỏi CSDL ~12 lần cho mỗi sản phẩm (hồ sơ + ảnh + n ngành nghề
        // + n nhãn), tức ~12.000 lượt round-trip — trên Docker Desktop (bind mount,
        // 150-500 ms/request) riêng bước này đã mất hơn 20 phút, quá lâu cho một
        // lệnh `db:seed` mà người mới clone repo phải chạy.
        $existingProfiles = DB::table($profile['table'])
            ->whereIn($profile['sku'], array_values($skuIds))
            ->pluck($profile['key'], $profile['sku']);

        $insertProfiles = [];
        $updateProfiles = [];
        $profileIdOf = [];
        $sort = 0;

        foreach ($rows as $row) {
            $skuId = $skuIds[(string) $row['sku_code']] ?? null;
            if ($skuId === null) {
                continue;
            }
            $sort++;
            $payload = $this->onlyExistingColumns($profile['table'], $this->profilePayload($row, $sort));
            $existingId = $existingProfiles[$skuId] ?? null;

            if ($existingId !== null) {
                $profileIdOf[$skuId] = (string) $existingId;
                $updateProfiles[(string) $existingId] = $payload;

                continue;
            }

            $id = (string) Str::uuid();
            $profileIdOf[$skuId] = $id;
            $insertProfiles[] = $payload + $this->onlyExistingColumns($profile['table'], [
                $profile['key'] => $id,
                $profile['sku'] => $skuId,
                'created_at' => now(),
                'version' => 0,
            ]);
        }

        foreach (array_chunk($insertProfiles, 200) as $chunk) {
            DB::table($profile['table'])->insert($chunk);
        }
        // Nhánh cập nhật chỉ chạy khi seed lại trên CSDL đã có dữ liệu; CSDL mới
        // (`migrate:fresh --seed`) đi hết bằng nhánh insert theo lô ở trên.
        foreach ($updateProfiles as $profileId => $payload) {
            DB::table($profile['table'])->where($profile['key'], $profileId)->update($payload);
        }

        $this->seedPvImages($rows, $skuIds, $profileIdOf);
        $this->seedPvLinks($rows, $skuIds, $profileIdOf, $industryIds, $tagIds, $productTagTable);
    }

    /**
     * Ảnh chính của từng hồ sơ — một truy vấn đọc, phần còn lại ghi theo lô.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,string>  $skuIds
     * @param  array<string,string>  $profileIdOf
     */
    private function seedPvImages(array $rows, array $skuIds, array $profileIdOf): void
    {
        $profileIds = array_values($profileIdOf);
        if ($profileIds === []) {
            return;
        }

        $existing = [];
        foreach (array_chunk($profileIds, 500) as $chunk) {
            foreach (DB::table('pv_product_images')->whereIn('profile_id', $chunk)->get(['id', 'profile_id', 'image_url']) as $img) {
                $existing[$img->profile_id.'|'.$img->image_url] = (string) $img->id;
            }
        }

        $inserts = [];
        $updates = [];
        foreach ($rows as $row) {
            $url = $row['image_url'] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }
            $skuId = $skuIds[(string) $row['sku_code']] ?? null;
            $profileId = $skuId !== null ? ($profileIdOf[$skuId] ?? null) : null;
            if ($profileId === null) {
                continue;
            }

            $payload = [
                'image_url' => mb_substr($url, 0, 500),
                'storage_url' => mb_substr($url, 0, 1000),
                'is_primary' => true,
                'sort_order' => 0,
                'alt_text' => mb_substr('Ảnh sản phẩm '.$row['sku_name'], 0, 255),
                'status' => 'ACTIVE',
                'updated_at' => now(),
            ];

            $key = $profileId.'|'.$payload['image_url'];
            if (isset($existing[$key])) {
                $updates[$existing[$key]] = $payload;

                continue;
            }
            $inserts[] = $payload + [
                'id' => (string) Str::uuid(),
                'profile_id' => $profileId,
                'created_at' => now(),
                'version' => 0,
            ];
        }

        foreach (array_chunk($inserts, 200) as $chunk) {
            DB::table('pv_product_images')->insert(array_map(
                fn (array $item): array => $this->onlyExistingColumns('pv_product_images', $item),
                $chunk,
            ));
        }
        foreach ($updates as $id => $payload) {
            DB::table('pv_product_images')->where('id', $id)->update($payload);
        }
    }

    /**
     * Bảng nối ngành nghề + nhãn lọc. Khoá chính là (profile_id, industry_id) /
     * (profile_id, tag_id) nên `insertOrIgnore` lo luôn tính idempotent, khỏi phải
     * hỏi `exists()` từng dòng.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,string>  $skuIds
     * @param  array<string,string>  $profileIdOf
     * @param  \Illuminate\Support\Collection<string,string>  $industryIds
     * @param  \Illuminate\Support\Collection<string,string>  $tagIds
     */
    private function seedPvLinks(
        array $rows,
        array $skuIds,
        array $profileIdOf,
        $industryIds,
        $tagIds,
        string $productTagTable,
    ): void {
        $industryLinks = [];
        $tagLinks = [];

        foreach ($rows as $row) {
            $skuId = $skuIds[(string) $row['sku_code']] ?? null;
            $profileId = $skuId !== null ? ($profileIdOf[$skuId] ?? null) : null;
            if ($profileId === null) {
                continue;
            }

            $priority = 1;
            foreach ((array) $row['industries'] as $code) {
                $industryId = $industryIds[$code] ?? null;
                if ($industryId === null) {
                    continue;
                }
                $industryLinks[$profileId.'|'.$industryId] = [
                    'profile_id' => $profileId,
                    'industry_id' => $industryId,
                    'priority' => $priority++,
                    'created_at' => now(),
                ];
            }

            foreach ($this->tagCodesOf($row) as $code) {
                $tagId = $tagIds[$code] ?? null;
                if ($tagId === null) {
                    continue;
                }
                $tagLinks[$profileId.'|'.$tagId] = [
                    'profile_id' => $profileId,
                    'tag_id' => $tagId,
                    'created_at' => now(),
                ];
            }
        }

        foreach (array_chunk(array_values($industryLinks), 500) as $chunk) {
            DB::table('pv_product_industry')->insertOrIgnore(array_map(
                fn (array $item): array => $this->onlyExistingColumns('pv_product_industry', $item),
                $chunk,
            ));
        }
        foreach (array_chunk(array_values($tagLinks), 500) as $chunk) {
            DB::table($productTagTable)->insertOrIgnore(array_map(
                fn (array $item): array => $this->onlyExistingColumns($productTagTable, $item),
                $chunk,
            ));
        }
    }

    /**
     * Nội dung hồ sơ công khai của một mã hàng (không gồm khoá/ngày tạo).
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function profilePayload(array $row, int $sort): array
    {
        $published = $row['status'] === 'ACTIVE' && $row['segment'] !== 'COMPONENT';
        $specs = is_array($row['specifications']) ? $row['specifications'] : [];

        return [
            'display_name' => mb_substr((string) $row['sku_name'], 0, 200),
            // Mô tả đầy đủ = "Tính năng", mô tả ngắn = "Công dụng" của chính catalog.
            // 6 mã trong catalog gốc bỏ trống "Tính năng" — lấy "Công dụng" thay
            // vào để không có sản phẩm nào lên App PV mà phần mô tả trống trơn.
            'description' => $row['features'] ?? $row['usage'],
            'short_description' => $row['usage'] !== null ? mb_substr((string) $row['usage'], 0, 500) : null,
            'public_specifications' => json_encode($this->publicSpecifications($specs), JSON_UNESCAPED_UNICODE),
            'published' => $published,
            'is_published' => $published,
            'published_at' => $published ? now() : null,
            // Hàng nổi bật = máy pin không chổi than (dòng chủ lực catalog 2026).
            'featured' => $row['segment'] === 'POWER_TOOL' && in_array('KHONG_CHOI_THAN', (array) $row['tags'], true),
            'seo_slug' => $this->seoSlug((string) $row['sku_name'], (string) $row['sku_code']),
            'sort_order' => $sort,
            'updated_at' => now(),
        ];
    }

    /**
     * Thông số công khai của App PV — chỉ giữ trường người mua cần đọc, bỏ trường
     * nội bộ (trang catalog/PDF) để mặt công khai không lộ dữ liệu vận hành.
     *
     * @param  array<string,mixed>  $specs
     * @return array<string,mixed>
     */
    private function publicSpecifications(array $specs): array
    {
        $labels = [
            'power_input' => 'Nguồn điện',
            'rated_power' => 'Công suất',
            'capability' => 'Khả năng',
            'no_load_speed' => 'Tốc độ không tải',
            'impact_rate' => 'Tần suất va đập',
            'max_torque' => 'Momen xoắn tối đa',
            'weight' => 'Trọng lượng',
            'packaging' => 'Phụ kiện & đóng gói',
            'target_users' => 'Đối tượng sử dụng',
            'barcode' => 'Mã vạch',
        ];

        $out = [];
        foreach ($labels as $key => $label) {
            if (isset($specs[$key]) && $specs[$key] !== '') {
                $out[$label] = $specs[$key];
            }
        }

        return $out;
    }

    /**
     * Nhãn lọc của một mã hàng: nhãn ĐẶC TÍNH lấy từ catalog, cộng thêm nhãn phân
     * loại/marketing đã có sẵn trong hệ (HAND_TOOL / POWER_TOOL / ENERGY_SAVING /
     * PROMOTION / HEAVY_DUTY) — trước đây 5 nhãn này không sản phẩm nào dùng nên
     * bộ lọc App PV bấm vào là rỗng.
     *
     * @param  array<string,mixed>  $row
     * @return list<string>
     */
    private function tagCodesOf(array $row): array
    {
        $codes = (array) $row['tags'];

        if ($row['segment'] === 'HAND_TOOL') {
            $codes[] = 'HAND_TOOL';
        } elseif ($row['segment'] === 'POWER_TOOL') {
            $codes[] = 'POWER_TOOL';
        }
        if (in_array('KHONG_CHOI_THAN', (array) $row['tags'], true)) {
            $codes[] = 'ENERGY_SAVING';
        }
        if ($row['package_code'] === 'COMBO') {
            $codes[] = 'PROMOTION';
        }
        $ratedPower = (string) ($row['specifications']['rated_power'] ?? '');
        if (preg_match('/(\d{3,5})\s*W/i', $ratedPower, $m) && (int) $m[1] >= 1000) {
            $codes[] = 'HEAVY_DUTY';
        }

        return array_values(array_unique($codes));
    }

    /**
     * Gợi ý theo ngành nghề (SCR-PV-02): mỗi ngành lấy 6 mã bán chạy nhất theo thứ
     * tự catalog. Không có bảng doanh số nên "bán chạy" = thứ tự trong catalog —
     * đúng ý đồ của nhà sản xuất khi xếp trang.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,string>  $skuIds
     */
    private function seedRecommendationRules(array $rows, array $skuIds): void
    {
        $rule = PvRelations::rule();
        $industryIds = DB::table('pv_industry')->pluck('industry_id', 'industry_code');

        /** @var array<string,list<string>> $byIndustry */
        $byIndustry = [];
        foreach ($rows as $row) {
            if ($row['status'] !== 'ACTIVE' || $row['segment'] === 'COMPONENT') {
                continue;
            }
            foreach ((array) $row['industries'] as $code) {
                $byIndustry[$code] ??= [];
                if (count($byIndustry[$code]) < 6) {
                    $byIndustry[$code][] = (string) $row['sku_code'];
                }
            }
        }

        foreach ($byIndustry as $industryCode => $codes) {
            $industryId = $industryIds[$industryCode] ?? null;
            if ($industryId === null) {
                continue;
            }
            foreach (array_values($codes) as $index => $skuCode) {
                $skuId = $skuIds[$skuCode] ?? null;
                if ($skuId === null) {
                    continue;
                }
                $exists = DB::table($rule['table'])
                    ->where('industry_id', $industryId)
                    ->where($rule['sku'], $skuId)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table($rule['table'])->insert($this->onlyExistingColumns($rule['table'], [
                    $rule['key'] => (string) Str::uuid(),
                    'industry_id' => $industryId,
                    $rule['sku'] => $skuId,
                    'priority' => $index + 1,
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'version' => 0,
                ]));
            }
        }
    }

    // ------------------------------------------------------------------
    //  Tiện ích
    // ------------------------------------------------------------------

    private function seoSlug(string $name, string $code): string
    {
        return mb_substr(Str::slug($name.' '.$code), 0, 180);
    }

    /**
     * Giữ lại đúng những khoá là CỘT THẬT của bảng đích trên driver hiện tại.
     *
     * Hồ sơ App PV có hai hình dạng: PostgreSQL ghi vào `pv_product_profiles`
     * (đủ short_description / public_specifications / featured / seo_slug /
     * is_published), còn sqlite ghi vào `pv_product_profile` — bảng gọn hơn,
     * KHÔNG có mấy cột đó. Không lọc thì seed trên sqlite (bộ test) vỡ ngay ở
     * câu INSERT đầu tiên.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function onlyExistingColumns(string $table, array $payload): array
    {
        if (! isset($this->tableColumns[$table])) {
            $this->tableColumns[$table] = Schema::getColumnListing($table);
        }
        $columns = $this->tableColumns[$table];

        return array_filter(
            $payload,
            fn (string $key): bool => in_array($key, $columns, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function refId(string $table, string $column, ?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $key = $table.':'.$code;
        if (! array_key_exists($key, $this->refs)) {
            $value = DB::table($table)->where($column, $code)->value('id');
            $this->refs[$key] = $value !== null ? (string) $value : null;
        }

        return $this->refs[$key];
    }

    /**
     * Trên SQLite sku.brand_id/category_id/model_id vẫn FK vào product_masters —
     * cùng lý do đã ghi ở WarehouseDatabaseSeeder::syncProductMaster().
     */
    private function syncProductMaster(string $masterType, string $id, string $code, string $name, ?string $parentMasterId = null): void
    {
        if (! Schema::hasTable('product_masters')) {
            return;
        }

        $exists = DB::table('product_masters')->where('master_id', $id)->exists();
        if ($exists) {
            DB::table('product_masters')->where('master_id', $id)->update([
                'master_type' => $masterType,
                'code' => $code,
                'name' => $name,
                'parent_master_id' => $parentMasterId,
                'status' => 'ACTIVE',
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('product_masters')->insert([
            'master_id' => $id,
            'master_type' => $masterType,
            'code' => $code,
            'name' => $name,
            'parent_master_id' => $parentMasterId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
            'version' => 0,
        ]);
    }
}
