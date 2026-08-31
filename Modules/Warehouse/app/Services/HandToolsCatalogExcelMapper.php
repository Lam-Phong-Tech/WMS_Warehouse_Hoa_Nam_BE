<?php

namespace Modules\Warehouse\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Đọc "Phan_loai_du_lieu_Handtool_DongCheng_2026_Cloudflare.xlsx" và dựng payload
 * đúng hợp đồng `POST /api/v1/pv-admin/catalog-import` (P0 Hand Tools DongCheng).
 *
 * KHÔNG tự suy đoán mã SKU/family/category ngoài những gì cột nguồn ghi rõ —
 * đây là nguyên tắc cứng của BA (không tự thêm suffix, không tự chọn một trong
 * hai dòng trùng mã). Hai mã trùng nguồn (D094101/D156601) vẫn được map đầy đủ
 * VÀO CẢ HAI dòng — importer đã có sẵn DUPLICATE_CODE_IN_BATCH để tự phát hiện
 * khi dry-run 541 dòng; mapper chỉ có nhiệm vụ tách riêng một bản đã LOẠI hai
 * mã đó ra để có batch 537 dòng "sạch" dùng cho dry-run/commit thật.
 *
 * KHÔNG đọc "Trang PDF"/"Trang catalog" vào payload — đó là dữ liệu audit nội
 * bộ, importer cũng hard-reject nếu ai đó cố gửi lên (xem FORBIDDEN_KEYS của
 * PvCatalogImportService).
 *
 * "Mã vạch: ..." trong cột "Phụ kiện và gói hàng" CỐ Ý không bị mapper lọc bỏ ở
 * đây — nó được truyền nguyên văn vào specifications.package để chứng minh
 * CatalogTextSanitizer lọc mã vạch ở tầng importer (P0 #5: không tin caller đã
 * tự lọc), không phải ở tầng ETL.
 */
final class HandToolsCatalogExcelMapper
{
    public const BRAND_CODE = 'DONGCHENG';

    public const DOMAIN = 'HAND_TOOLS';

    private const MAIN_SHEET = 'Dữ liệu sản phẩm';

    private const OVERVIEW_SHEET = 'Ảnh tổng quan';

    /**
     * "Phân loại theo chức năng chung" (12 giá trị) -> category_code UPPER_SNAKE_CASE.
     * Phải khớp đúng WarehouseDatabaseSeeder::PRODUCT_CATEGORIES +
     * config/public_api.php (domains.HAND_TOOLS.categories).
     *
     * @var array<string, string>
     */
    private const CATEGORY_CODE_MAP = [
        'Dụng cụ kẹp giữ' => 'CLAMPING_TOOLS',
        'Cờ lê' => 'WRENCHES',
        'Đầu tuýp' => 'SOCKETS',
        'Dụng cụ siết/vặn' => 'FASTENING_TOOLS',
        'Dụng cụ cắt' => 'CUTTING_TOOLS',
        'Dụng cụ đo lường' => 'MEASURING_TOOLS',
        'Dụng cụ điện' => 'ELECTRICAL_TOOLS',
        'Dụng cụ sơn & làm tường' => 'PAINTING_WALL_TOOLS',
        'Dụng cụ cơ khí' => 'MECHANICAL_TOOLS',
        'Dụng cụ thủy lực' => 'HYDRAULIC_TOOLS',
        'Dụng cụ lưu trữ' => 'STORAGE_TOOLS',
        'Dụng cụ làm vườn cầm tay' => 'GARDEN_HAND_TOOLS',
    ];

    /**
     * Toàn bộ dòng nguồn (bao gồm cả các mã trùng) + siêu dữ liệu đối soát.
     *
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     excel_rows: array<int, int>,
     *     clean_items: array<int, array<string, mixed>>,
     *     conflicted_codes: array<string, array<int, int>>,
     *     unmapped_categories: array<int, string>,
     * }
     */
    public function map(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $overviewImages = $this->overviewImagesBySku($spreadsheet);

        $sheet = $spreadsheet->getSheetByName(self::MAIN_SHEET);
        if ($sheet === null) {
            throw new \RuntimeException('Sheet "'.self::MAIN_SHEET.'" không tồn tại trong file nguồn.');
        }

        $items = [];
        $excelRows = [];
        $codeToRows = [];
        $unmappedCategories = [];

        $highestRow = $sheet->getHighestDataRow();
        for ($r = 2; $r <= $highestRow; $r++) {
            $values = $sheet->rangeToArray("A{$r}:T{$r}", null, true, false)[0];
            $values = array_pad($values, 20, null);
            [
                $name, $skuCode, , $family, $features, $packageText, , $power,
                $capacity, $noLoadSpeed, $impactRate, $maxTorque, $weight, $image,
                $categoryLabel, $usage, , ,
            ] = $values;

            $skuCode = trim((string) $skuCode);
            if ($skuCode === '') {
                continue;
            }

            $categoryLabel = trim((string) $categoryLabel);
            $categoryCode = self::CATEGORY_CODE_MAP[$categoryLabel] ?? null;
            if ($categoryCode === null) {
                $unmappedCategories[] = $categoryLabel !== '' ? $categoryLabel : '(rỗng)';

                continue;
            }

            $familyName = trim((string) $family);
            $name = trim((string) $name);

            $specifications = array_filter([
                'capacity' => $this->nz($capacity),
                'no_load_speed' => $this->nz($noLoadSpeed),
                'impact_rate' => $this->nz($impactRate),
                'max_torque' => $this->nz($maxTorque),
                'weight' => $this->nz($weight),
                'power' => $this->nz($power),
                // Cố ý KHÔNG lọc "Mã vạch: ..." ở đây — xem docblock lớp.
                'package' => $this->nz($packageText),
            ], static fn ($v): bool => $v !== null);

            $media = [];
            $imageUrl = $this->nz($image);
            if ($imageUrl !== null) {
                $media[] = ['url' => $imageUrl, 'role' => 'PRIMARY'];
            }
            $overviewUrl = $overviewImages[$skuCode] ?? null;
            if ($overviewUrl !== null && $overviewUrl !== $imageUrl) {
                $media[] = ['url' => $overviewUrl, 'role' => 'GALLERY'];
            }

            $items[] = [
                'product_code' => $skuCode,
                'name' => $name,
                'brand_code' => self::BRAND_CODE,
                'domain' => self::DOMAIN,
                'category_code' => $categoryCode,
                'family_name' => $familyName !== '' ? $familyName : null,
                'variant_label' => $this->variantLabelOf($name, $familyName),
                'unit' => 'Cái',
                'power_source' => null,
                'description' => $this->nz($features),
                'short_description' => $this->nz($usage),
                'specifications' => $specifications === [] ? null : $specifications,
                'media' => $media,
            ];
            $excelRows[] = $r;
            $codeToRows[$skuCode][] = $r;
        }

        $conflictedCodes = array_filter($codeToRows, static fn (array $rows): bool => count($rows) > 1);
        $conflictedCodeSet = array_flip(array_keys($conflictedCodes));

        $cleanItems = array_values(array_filter(
            $items,
            static fn (array $item): bool => ! isset($conflictedCodeSet[$item['product_code']])
        ));

        return [
            'items' => $items,
            'excel_rows' => $excelRows,
            'clean_items' => $cleanItems,
            'conflicted_codes' => $conflictedCodes,
            'unmapped_categories' => array_values(array_unique($unmappedCategories)),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function overviewImagesBySku(Spreadsheet $spreadsheet): array
    {
        $sheet = $spreadsheet->getSheetByName(self::OVERVIEW_SHEET);
        if ($sheet === null) {
            return [];
        }

        $map = [];
        $highestRow = $sheet->getHighestDataRow();
        for ($r = 2; $r <= $highestRow; $r++) {
            $skuList = trim((string) $sheet->getCell("A{$r}")->getValue());
            $overviewUrl = $this->nz($sheet->getCell('D'.$r)->getValue());
            if ($skuList === '' || $overviewUrl === null) {
                continue;
            }

            foreach (explode(',', $skuList) as $sku) {
                $sku = trim($sku);
                if ($sku !== '') {
                    $map[$sku] = $overviewUrl;
                }
            }
        }

        return $map;
    }

    /**
     * "Kìm điện đa năng - 6"" trừ family "Kìm điện đa năng" -> "6"". Family
     * trùng khít tên (không có hậu tố riêng) hoặc rỗng -> không có variant_label
     * (chỉ có một biến thể trong nhóm, hoặc sản phẩm đứng một mình).
     */
    private function variantLabelOf(string $name, string $familyName): ?string
    {
        if ($familyName === '' || $name === $familyName || ! str_starts_with($name, $familyName)) {
            return null;
        }

        $rest = trim(mb_substr($name, mb_strlen($familyName)));
        $rest = ltrim($rest, "-–— \t");
        $rest = trim($rest);

        return $rest !== '' ? $rest : null;
    }

    private function nz(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
