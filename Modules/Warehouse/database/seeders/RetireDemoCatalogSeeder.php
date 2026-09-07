<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Support\PvRelations;
use Throwable;

/**
 * Dọn DANH MỤC DEMO cũ khỏi CSDL đã seed từ trước.
 *
 * Bộ seeder đời trước bịa ~818 mã hàng ("Máy bơm nước Hoa Nam C68", "Máy phun
 * thuốc Honda", "Máy kéo Sumo (ngừng phân phối)"...) cùng hãng/dòng máy/nhóm
 * hàng ảo. Sau khi danh mục thật DongCheng vào hệ, những dòng đó là rác: chúng
 * nằm lẫn với hàng thật ở màn Danh sách SKU, App PV và mọi báo cáo.
 *
 * Định nghĩa "demo" ở đây rất hẹp và không đoán mò: mã hàng KHÔNG có trong
 * `dongcheng_catalog.json`. Sau thay đổi này mọi mã do seeder sinh ra đều lấy từ
 * file đó, nên phần bù chính là dữ liệu của bộ seeder cũ.
 *
 * Thứ tự dọn:
 *  1. Gỡ khỏi mặt công khai (ảnh/nhãn/ngành nghề/gợi ý/hồ sơ PV) — luôn làm được.
 *  2. Xoá hẳn SKU nếu KHÔNG còn chứng từ/tồn kho nào trỏ tới.
 *  3. Mã còn bị chứng từ cũ tham chiếu thì hạ về INACTIVE và báo số lượng — xoá
 *     cưỡng bức sẽ phá sổ cái. Muốn sạch tuyệt đối thì `migrate:fresh --seed`.
 *
 * Rồi dọn nốt hãng / dòng máy / nhóm hàng không còn SKU nào (Kubota, Yanmar,
 * Honda, Husqvarna, "Sumo (ngừng phân phối)"... — Hoa Nam chỉ phân phối DongCheng).
 */
class RetireDemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalogCodes = $this->catalogCodes();
        if ($catalogCodes === []) {
            return;
        }

        $demoSkus = DB::table('sku')
            ->whereNotIn('sku_code', $catalogCodes)
            ->pluck('sku_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($demoSkus !== []) {
            $this->unpublishFromPublicApp($demoSkus);
            [$deleted, $kept] = $this->deleteUnreferenced($demoSkus);
            $this->command?->info(sprintf(
                'RetireDemoCatalogSeeder: xoá %d SKU demo, giữ %d mã còn bị chứng từ cũ tham chiếu (đã hạ INACTIVE).',
                $deleted,
                $kept,
            ));
        }

        $this->retireOrphanMasters();
    }

    /** @return list<string> */
    private function catalogCodes(): array
    {
        $path = __DIR__.'/../data/dongcheng_catalog.json';
        if (! is_file($path)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $codes = [];
        foreach (array_merge($data['products'] ?? [], $data['components'] ?? []) as $row) {
            $codes[] = (string) $row['sku_code'];
        }

        return $codes;
    }

    /** @param  list<string>  $skuIds */
    private function unpublishFromPublicApp(array $skuIds): void
    {
        $profile = PvRelations::profile();
        $rule = PvRelations::rule();
        $tagTable = PvRelations::productTagTable();

        foreach (array_chunk($skuIds, 500) as $chunk) {
            DB::table($rule['table'])->whereIn($rule['sku'], $chunk)->delete();

            $profileIds = DB::table($profile['table'])
                ->whereIn($profile['sku'], $chunk)
                ->pluck($profile['key'])
                ->all();
            if ($profileIds === []) {
                continue;
            }

            DB::table('pv_product_images')->whereIn('profile_id', $profileIds)->delete();
            DB::table('pv_product_industry')->whereIn('profile_id', $profileIds)->delete();
            DB::table($tagTable)->whereIn('profile_id', $profileIds)->delete();
            DB::table($profile['table'])->whereIn($profile['key'], $profileIds)->delete();
        }
    }

    /**
     * @param  list<string>  $skuIds
     * @return array{0:int,1:int} [đã xoá, giữ lại]
     */
    private function deleteUnreferenced(array $skuIds): array
    {
        // Mọi bảng có khoá ngoại tới skus.id (xem \d skus). Thiếu một bảng ở đây
        // thì DELETE bên dưới ném FK và cả seeder đổ — nên duyệt theo danh sách
        // tường minh và bỏ qua bảng không tồn tại trên driver hiện tại.
        $referencing = [
            'inbound_lines' => 'sku_id',
            'outbound_lines' => 'sku_id',
            'outbound_request_lines' => 'sku_id',
            'outbound_request_import_rows' => 'resolved_sku_id',
            'component_issue_lines' => 'sku_id',
            'container_items' => 'sku_id',
            'containers' => 'primary_sku_id',
            'inventory_balances' => 'sku_id',
            'inventory_movements' => 'sku_id',
            'item_instances' => 'sku_id',
            'label_snapshots' => 'sku_id',
            'stock_document_lines' => 'sku_id',
            'stock_movements' => 'sku_id',
            'inventory_serials' => 'sku_id',
            'inventory_reservations' => 'sku_id',
            'sku_aliases' => 'sku_id',
            'sku_component' => 'sku_id',
            'sku_compatible_accessories' => 'sku_id',
            'sku_import_claims' => 'sku_id',
            'physical_codes' => 'sku_id',
            'reversal_lines' => 'sku_id',
            'safe_stock_configs' => 'sku_id',
        ];

        $deleted = 0;
        $kept = 0;
        foreach ($skuIds as $skuId) {
            $referenced = false;
            foreach ($referencing as $table => $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }
                if (DB::table($table)->where($column, $skuId)->exists()) {
                    $referenced = true;
                    break;
                }
            }

            if ($referenced) {
                DB::table('sku')->where('sku_id', $skuId)->update(['status' => 'INACTIVE']);
                $kept++;

                continue;
            }

            try {
                DB::table('sku')->where('sku_id', $skuId)->delete();
                $deleted++;
            } catch (Throwable) {
                // Khoá ngoại lạ (bảng chưa liệt kê) — không đánh đổi cả lần seed
                // vì một dòng: hạ INACTIVE rồi đi tiếp.
                DB::table('sku')->where('sku_id', $skuId)->update(['status' => 'INACTIVE']);
                $kept++;
            }
        }

        return [$deleted, $kept];
    }

    /**
     * Hãng / dòng máy / nhóm hàng của bộ demo cũ mà nay KHÔNG còn SKU nào trỏ tới.
     * Hoa Nam là nhà phân phối DongCheng — Kubota/Yanmar/Honda/Husqvarna/Makita/
     * Stihl/Mitsubishi/Total/Robin/Sumo và các nhóm hàng nông nghiệp đi kèm chưa
     * bao giờ là hàng thật của kho này.
     */
    private function retireOrphanMasters(): void
    {
        $orphanModels = DB::table('product_models')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('skus')->whereColumn('skus.model_id', 'product_models.id'))
            ->pluck('id')->all();
        $this->deleteMasters('product_models', $orphanModels, 'MODEL');

        $orphanBrands = DB::table('brands')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('skus')->whereColumn('skus.brand_id', 'brands.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('product_models')->whereColumn('product_models.brand_id', 'brands.id'))
            ->pluck('id')->all();
        $this->deleteMasters('brands', $orphanBrands, 'BRAND');

        $orphanCategories = DB::table('product_categories')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('skus')->whereColumn('skus.category_id', 'product_categories.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('pv_recommendation_rules')->whereColumn('pv_recommendation_rules.category_id', 'product_categories.id'))
            ->pluck('id')->all();
        $this->deleteMasters('product_categories', $orphanCategories, 'CATEGORY');
    }

    /** @param  list<mixed>  $ids */
    private function deleteMasters(string $table, array $ids, string $masterType): void
    {
        foreach ($ids as $id) {
            try {
                DB::table($table)->where('id', $id)->delete();
                if (Schema::hasTable('product_masters')) {
                    DB::table('product_masters')
                        ->where('master_id', $id)->where('master_type', $masterType)->delete();
                }
            } catch (Throwable) {
                // Còn tham chiếu ở bảng chưa lường trước — để nguyên, không phá seed.
            }
        }
    }
}
