<?php

namespace Modules\Warehouse\Services;

use App\Support\Database\SchemaCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\ProductMaster;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\PvRelations;

/**
 * FE App PV báo cáo cần một importer JSON cho catalogue DongCheng (61 sản phẩm ban
 * đầu) — KHÔNG dùng chung với `POST /api/v1/skus/import-excel` (WWHN-172) vì mục
 * tiêu khác nhau: import Excel chỉ tạo `sku`, không đụng tới trình bày PV
 * (display_name/description/ảnh). Batch này phải tạo CẢ HAI trong một giao dịch
 * atomic — SKU (`sku`) và hồ sơ App PV (`pv_product_profile(s)` + `pv_product_images`)
 * — nên cần một service riêng, không tái dùng ImportService (vốn gắn chặt với
 * semantics file Excel/checksum/idempotency của WWHN-330/331).
 *
 * Ba bất biến theo đúng yêu cầu FE:
 * 1. published_to_app LUÔN false khi tạo — publish là thao tác RIÊNG, quyền RIÊNG
 *    (`pv.publish` tại PATCH /pv/products/{sku_id}/publish), khớp đúng ranh giới đã
 *    có ở PvAdminResourceController::validateProfile().
 * 2. Không nhận giá/số lượng/barcode — FORBIDDEN_KEYS chặn ở tầng validate, trước
 *    khi động tới DB.
 * 3. dry_run=true không ghi bất kỳ dòng nào; dry_run=false là all-or-nothing (một
 *    dòng lỗi thì toàn batch rollback).
 *
 * Mở rộng cho Hand Tools DongCheng (P0):
 * 4. `family_name`/`variant_label` — một family (vd. "Kìm điện đa năng") có nhiều
 *    SKU variant (vd. "6 inch"). Không có bảng family riêng, chỉ là hai cột mô tả
 *    trên `sku`; PublicCatalogReadModel nhóm lại bằng (brand_id, category_id,
 *    family_name) ở tầng đọc.
 * 5. `power_source` — nullable cho mọi domain; domain=HAND_TOOLS thì BẮT BUỘC
 *    null/absent (không có nguồn điện thật, không được bịa UNKNOWN).
 * 6. `media` (mảng {url, role}) thay cho `media_url` phẳng — role PRIMARY/GALLERY,
 *    validate qua CatalogMediaValidator (host allowlist, chặn redirect ra ngoài,
 *    content-type ảnh, giới hạn kích thước). `media_url` cũ vẫn nhận để tương
 *    thích ngược (URL đầu = PRIMARY, còn lại = GALLERY).
 * 7. Sanitize description/short_description/specifications qua CatalogTextSanitizer
 *    trước khi ghi — không tin caller đã tự lọc mã vạch khỏi text tự do.
 */
class PvCatalogImportService
{
    private const FORBIDDEN_KEYS = [
        'price', 'unit_price', 'sale_price', 'list_price', 'cost', 'cost_price',
        'quantity', 'qty', 'stock', 'available_qty', 'on_hand_qty',
        'barcode', 'ean', 'gtin', 'upc',
        'published', 'published_to_app', 'is_published',
        // Dữ liệu audit nội bộ của catalog nguồn (Trang PDF/Trang catalog/OCR) —
        // tuyệt đối không được lọt qua importer dưới bất kỳ tên trường nào, kể cả
        // khi một caller nào đó cố tình gửi lên (P0 #5, #8).
        'page_pdf', 'catalog_page', 'source_url', 'source_path',
        'ocr_confidence', 'bounding_box', 'import_audit',
    ];

    /**
     * Khoá cùng loại có thể lọt vào bên trong `specifications` (object tự do) —
     * chặn đệ quy ở đó nữa, không chỉ ở top-level của item.
     */
    private const FORBIDDEN_SPEC_KEYS = [
        'price', 'quantity', 'stock', 'barcode', 'ean', 'gtin', 'upc',
        'page_pdf', 'catalog_page', 'source_url', 'source_path',
        'ocr_confidence', 'bounding_box',
    ];

    // Hand Tools DongCheng có 541 dòng nguồn (537 sau khi loại mã trùng) — 500
    // (giới hạn cũ, chỉ tính cho batch Power Tools khởi đầu 61 SKU) sẽ cắt cụt
    // batch trước khi validate từng dòng chạy tới, khiến DUPLICATE_CODE_IN_BATCH
    // không bao giờ có cơ hội báo lỗi cho D094101/D156601. Nâng lên 600 kèm dư
    // cho catalogue phình thêm, không đổi ngữ nghĩa BATCH_TOO_LARGE.
    private const MAX_ITEMS = 600;

    private const CODE_PATTERN = '/^[A-Z0-9](?:[A-Z0-9._-]*[A-Z0-9])?$/';

    private const MEDIA_ROLES = ['PRIMARY', 'GALLERY'];

    /**
     * Cache trong đúng một lần gọi import()/dry-run: nhiều SKU (các variant
     * cùng family) thường trỏ chung một ảnh — P0 #6 yêu cầu "không nhân bản
     * kiểm tra CDN không cần thiết" cho đúng một URL lặp lại hàng trăm lần
     * trong một batch 541 dòng.
     *
     * @var array<string, array{ok: bool, code: ?string, message: ?string}>
     */
    private array $mediaValidationCache = [];

    public function __construct(private readonly CatalogMediaValidator $mediaValidator = new CatalogMediaValidator) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function import(array $items, bool $dryRun, ?string $actorId, ?string $requestId): array
    {
        if ($items === []) {
            return $this->error(422, 'EMPTY_BATCH', 'items rỗng — không có gì để import.');
        }
        if (count($items) > self::MAX_ITEMS) {
            return $this->error(422, 'BATCH_TOO_LARGE', 'Tối đa '.self::MAX_ITEMS.' dòng mỗi lần gọi.');
        }

        [$records, $rowErrors] = $this->validateRows($items);
        if ($rowErrors !== []) {
            return [
                'committed' => false,
                'dry_run' => $dryRun,
                'total_rows' => count($items),
                'would_insert' => 0,
                'inserted' => 0,
                'row_errors' => $rowErrors,
            ];
        }

        return DB::transaction(function () use ($records, $items, $dryRun, $actorId, $requestId): array {
            $codes = collect($records)->pluck('sku_code')->all();
            $existing = Sku::query()->whereIn('sku_code', $codes)->lockForUpdate()->pluck('sku_code');
            $existingUpper = $existing->map(fn ($code): string => strtoupper((string) $code))->all();

            $dbErrors = [];
            foreach ($records as $index => $record) {
                if (in_array($record['sku_code'], $existingUpper, true)) {
                    $dbErrors[] = $this->rowError($index + 1, 'DUPLICATE_CODE', 'product_code', 'SKU đã tồn tại — import này chỉ tạo mới, không cập nhật.', $record['sku_code']);
                }
            }

            if ($dbErrors !== []) {
                return [
                    'committed' => false,
                    'dry_run' => $dryRun,
                    'total_rows' => count($items),
                    'would_insert' => 0,
                    'inserted' => 0,
                    'row_errors' => $dbErrors,
                ];
            }

            if ($dryRun) {
                AuditLogger::record(
                    action: 'PV_CATALOG_IMPORT_DRY_RUN',
                    objectType: 'pv_catalog_import',
                    objectId: null,
                    actorId: $actorId,
                    requestId: $requestId,
                    afterData: ['would_insert_rows' => count($records), 'sku_codes' => $codes],
                    reason: 'App PV catalogue import — kiểm tra thử, không ghi dữ liệu',
                    module: AuditLogger::MODULE_SYSTEM,
                );

                return [
                    'committed' => false,
                    'dry_run' => true,
                    'total_rows' => count($items),
                    'would_insert' => count($records),
                    'inserted' => 0,
                    'row_errors' => [],
                ];
            }

            $insertedSkuIds = [];
            foreach ($records as $record) {
                $sku = Sku::query()->create([
                    'sku_code' => $record['sku_code'],
                    'sku_name' => $record['name'],
                    'sku_type' => 'PRODUCT',
                    'unit' => $record['unit'],
                    'is_serial_tracked' => false,
                    'brand_id' => $record['brand_id'],
                    'category_id' => $record['category_id'],
                    'power_source_id' => $record['power_source_id'],
                    'family_name' => $record['family_name'],
                    'variant_label' => $record['variant_label'],
                    'specification' => $record['specifications'],
                    'published_to_app' => false,
                    'status' => 'ACTIVE',
                ]);

                $profileId = $this->createProfile((string) $sku->sku_id, $record);
                $this->createImages($profileId, $record['media']);

                AuditLogger::record(
                    action: 'PV_CATALOG_IMPORT_CREATE',
                    objectType: 'sku',
                    objectId: (string) $sku->sku_id,
                    actorId: $actorId,
                    requestId: $requestId,
                    afterData: [
                        'sku_code' => $sku->sku_code,
                        'brand_code' => $record['brand_code'],
                        'category_code' => $record['category_code'],
                        'domain' => $record['domain'],
                        'family_name' => $record['family_name'],
                        'variant_label' => $record['variant_label'],
                        'profile_id' => $profileId,
                        'image_count' => count($record['media']),
                    ],
                    reason: 'App PV catalogue import (DongCheng) — tạo SKU + hồ sơ PV, published_to_app=false',
                    module: AuditLogger::MODULE_SYSTEM,
                );

                $insertedSkuIds[] = (string) $sku->sku_id;
            }

            return [
                'committed' => true,
                'dry_run' => false,
                'total_rows' => count($items),
                'would_insert' => count($records),
                'inserted' => count($insertedSkuIds),
                'sku_ids' => $insertedSkuIds,
                'row_errors' => [],
            ];
        });
    }

    /**
     * WWHN legacy sku write trigger (`hn_legacy_sku_write`, Postgres) đã tự tạo sẵn một
     * dòng `pv_product_profiles` (display_name=sku_name, published=false) làm side
     * effect của `Sku::create([..., 'published_to_app' => false])` — vì trigger đồng bộ
     * publish state vào bảng này bất cứ khi nào `published_to_app` khác null. Vì vậy đây
     * PHẢI là update-or-insert (không phải insert thuần), nếu không sẽ đụng unique
     * constraint `ux_erd_pv_product_profiles_sku_id` ngay trong cùng transaction.
     *
     * @param  array<string, mixed>  $record
     */
    private function createProfile(string $skuId, array $record): string
    {
        $relation = PvRelations::profile();

        $fields = [
            'display_name' => $record['name'],
            'description' => $record['description'],
            'sort_order' => 0,
            'updated_at' => now(),
        ];
        if (SchemaCache::hasColumn($relation['table'], 'short_description')) {
            $fields['short_description'] = $record['short_description'];
        }
        if (SchemaCache::hasColumn($relation['table'], 'public_specifications') && $record['specifications'] !== null) {
            $fields['public_specifications'] = json_encode($record['specifications']);
        }

        $existingId = DB::table($relation['table'])->where($relation['sku'], $skuId)->value($relation['key']);
        if ($existingId !== null) {
            DB::table($relation['table'])->where($relation['key'], $existingId)->update($fields);

            return (string) $existingId;
        }

        $id = (string) Str::uuid();
        DB::table($relation['table'])->insert($fields + [
            $relation['key'] => $id,
            $relation['sku'] => $skuId,
            'version' => 1,
            'created_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array<int, array{url: string, role: string}>  $media
     */
    private function createImages(string $profileId, array $media): void
    {
        foreach ($media as $index => $entry) {
            DB::table('pv_product_images')->insert([
                'id' => (string) Str::uuid(),
                'profile_id' => $profileId,
                'image_url' => $entry['url'],
                'storage_url' => $entry['url'],
                'is_primary' => $entry['role'] === 'PRIMARY',
                'sort_order' => $index,
                'status' => 'ACTIVE',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function validateRows(array $items): array
    {
        $records = [];
        $errors = [];
        $seen = [];
        $domains = (array) config('public_api.domains', []);

        foreach ($items as $index => $row) {
            $rowNumber = $index + 1;
            if (! is_array($row)) {
                $errors[] = $this->rowError($rowNumber, 'INVALID_ROW', 'row', 'Mỗi phần tử items phải là object.', $row);

                continue;
            }

            $forbidden = array_intersect(array_map('strtolower', array_keys($row)), self::FORBIDDEN_KEYS);
            if ($forbidden !== []) {
                foreach ($forbidden as $field) {
                    $errors[] = $this->rowError($rowNumber, 'FORBIDDEN_FIELD', $field, "Trường '{$field}' không được chấp nhận ở importer này.", $row[$field] ?? null);
                }

                continue;
            }

            $rowErrors = [];

            $code = strtoupper(trim((string) ($row['product_code'] ?? '')));
            if ($code === '' || mb_strlen($code) > 100 || ! preg_match(self::CODE_PATTERN, $code)) {
                $rowErrors[] = $this->rowError($rowNumber, 'INVALID_PRODUCT_CODE', 'product_code', 'product_code bắt buộc, tối đa 100 ký tự, chỉ chữ/số/._-.', $row['product_code'] ?? null);
            } elseif (isset($seen[$code])) {
                $rowErrors[] = $this->rowError($rowNumber, 'DUPLICATE_CODE_IN_BATCH', 'product_code', "Trùng dòng {$seen[$code]}.", $code);
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 255) {
                $rowErrors[] = $this->rowError($rowNumber, 'INVALID_NAME', 'name', 'name bắt buộc, tối đa 255 ký tự.', $name);
            }

            $brandCode = strtoupper(trim((string) ($row['brand_code'] ?? '')));
            $brandId = $this->resolveMaster($brandCode, 'BRAND', $rowNumber, 'brand_code', $rowErrors);

            $categoryCode = strtoupper(trim((string) ($row['category_code'] ?? '')));
            $categoryId = $this->resolveMaster($categoryCode, 'CATEGORY', $rowNumber, 'category_code', $rowErrors);

            $domain = $row['domain'] ?? null;
            if ($domain !== null) {
                $domain = strtoupper(trim((string) $domain));
                if (! array_key_exists($domain, $domains)) {
                    $rowErrors[] = $this->rowError($rowNumber, 'UNKNOWN_DOMAIN', 'domain', "domain '{$domain}' không có trong config/public_api.php.", $domain);
                } elseif ($categoryCode !== '' && ! in_array($categoryCode, (array) ($domains[$domain]['categories'] ?? []), true)) {
                    $rowErrors[] = $this->rowError($rowNumber, 'CATEGORY_DOMAIN_MISMATCH', 'domain', "category_code '{$categoryCode}' chưa được ánh xạ vào domain '{$domain}' (config/public_api.php).", $domain);
                }
            }

            // power_source: nullable cho mọi domain; HAND_TOOLS thì BẮT BUỘC null —
            // dụng cụ cầm tay không có nguồn điện thật, không được bịa UNKNOWN (P0 #4).
            $powerSourceId = null;
            $rawPowerSource = $row['power_source'] ?? null;
            $powerSourceCode = $rawPowerSource !== null ? strtoupper(trim((string) $rawPowerSource)) : '';
            if ($powerSourceCode !== '') {
                if ($domain === 'HAND_TOOLS') {
                    $rowErrors[] = $this->rowError($rowNumber, 'POWER_SOURCE_NOT_APPLICABLE', 'power_source', 'Hand Tools không có nguồn điện — power_source phải là null.', $rawPowerSource);
                } else {
                    $powerSourceId = $this->resolveMaster($powerSourceCode, 'POWER', $rowNumber, 'power_source', $rowErrors);
                }
            }

            $familyName = isset($row['family_name']) ? trim((string) $row['family_name']) : '';
            $familyName = $familyName !== '' ? $familyName : null;
            if ($familyName !== null && mb_strlen($familyName) > 200) {
                $rowErrors[] = $this->rowError($rowNumber, 'INVALID_FAMILY_NAME', 'family_name', 'family_name tối đa 200 ký tự.', $familyName);
            }

            $variantLabel = isset($row['variant_label']) ? trim((string) $row['variant_label']) : '';
            $variantLabel = $variantLabel !== '' ? $variantLabel : null;
            if ($variantLabel !== null && mb_strlen($variantLabel) > 100) {
                $rowErrors[] = $this->rowError($rowNumber, 'INVALID_VARIANT_LABEL', 'variant_label', 'variant_label tối đa 100 ký tự.', $variantLabel);
            }
            if ($variantLabel !== null && $familyName === null) {
                $rowErrors[] = $this->rowError($rowNumber, 'VARIANT_WITHOUT_FAMILY', 'variant_label', 'variant_label chỉ có nghĩa khi đã có family_name.', $variantLabel);
            }

            $media = $this->normalizeMedia($row, $rowNumber, $domain, $rowErrors);

            $specifications = $row['specifications'] ?? null;
            if ($specifications !== null && ! is_array($specifications)) {
                $rowErrors[] = $this->rowError($rowNumber, 'INVALID_SPECIFICATIONS', 'specifications', 'specifications phải là object.', $specifications);
                $specifications = null;
            } elseif (is_array($specifications)) {
                $specForbidden = array_intersect(array_map('strtolower', array_keys($specifications)), self::FORBIDDEN_SPEC_KEYS);
                if ($specForbidden !== []) {
                    foreach ($specForbidden as $field) {
                        $rowErrors[] = $this->rowError($rowNumber, 'FORBIDDEN_FIELD', "specifications.{$field}", "Trường 'specifications.{$field}' không được chấp nhận ở importer này.", $specifications[$field] ?? null);
                    }
                }
                $specifications = CatalogTextSanitizer::sanitizeArray($specifications);
            }

            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);

                continue;
            }

            $seen[$code] = $rowNumber;
            $records[] = [
                'sku_code' => $code,
                'name' => $name,
                'unit' => trim((string) ($row['unit'] ?? '')) !== '' ? trim((string) $row['unit']) : 'Cái',
                'description' => CatalogTextSanitizer::sanitize(isset($row['description']) ? trim((string) $row['description']) : null),
                'short_description' => CatalogTextSanitizer::sanitize(isset($row['short_description']) ? trim((string) $row['short_description']) : null),
                'specifications' => $specifications,
                'brand_code' => $brandCode,
                'brand_id' => $brandId,
                'category_code' => $categoryCode,
                'category_id' => $categoryId,
                'domain' => $domain,
                'power_source_id' => $powerSourceId,
                'family_name' => $familyName,
                'variant_label' => $variantLabel,
                'media' => $media,
            ];
        }

        return [$records, $errors];
    }

    /**
     * Nhận cả hai hình dạng payload:
     *   - `media`: [{url, role}]  (role PRIMARY/GALLERY, mới — ưu tiên nếu có)
     *   - `media_url`: [url, ...] (cũ — URL đầu = PRIMARY, còn lại = GALLERY)
     *
     * CatalogMediaValidator (https + host allowlist + không redirect ra ngoài +
     * content-type ảnh + giới hạn kích thước) CHỈ áp dụng cho domain=HAND_TOOLS
     * — đây là ràng buộc mới của catalogue DongCheng, cố ý không siết ngược lại
     * lên các domain đã có từ trước (Power Tools) để không phá vỡ luồng hiện
     * hữu của họ (CDN khác, chưa qua allowlist). Domain khác/không có domain
     * vẫn giữ đúng hành vi cũ: chỉ kiểm tra scheme https://.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $rowErrors
     * @return array<int, array{url: string, role: string}>
     */
    private function normalizeMedia(array $row, int $rowNumber, ?string $domain, array &$rowErrors): array
    {
        $entries = [];

        if (array_key_exists('media', $row)) {
            $raw = is_array($row['media']) ? $row['media'] : [];
            foreach ($raw as $item) {
                if (! is_array($item)) {
                    $rowErrors[] = $this->rowError($rowNumber, 'INVALID_MEDIA_URL', 'media', 'Mỗi phần tử media phải là object {url, role}.', $item);

                    continue;
                }
                $url = trim((string) ($item['url'] ?? ''));
                $role = strtoupper(trim((string) ($item['role'] ?? '')));
                if ($role === '') {
                    $role = $entries === [] ? 'PRIMARY' : 'GALLERY';
                }
                if (! in_array($role, self::MEDIA_ROLES, true)) {
                    $rowErrors[] = $this->rowError($rowNumber, 'INVALID_MEDIA_ROLE', 'media.role', 'role phải là PRIMARY hoặc GALLERY.', $role);

                    continue;
                }
                if ($url === '') {
                    continue;
                }
                $entries[] = ['url' => $url, 'role' => $role];
            }
        } elseif (array_key_exists('media_url', $row)) {
            $urls = is_array($row['media_url']) ? $row['media_url'] : [$row['media_url']];
            $urls = array_values(array_filter(array_map(static fn ($u): string => trim((string) $u), $urls)));
            foreach ($urls as $i => $url) {
                $entries[] = ['url' => $url, 'role' => $i === 0 ? 'PRIMARY' : 'GALLERY'];
            }
        }

        $primaryCount = count(array_filter($entries, static fn (array $e): bool => $e['role'] === 'PRIMARY'));
        if ($primaryCount > 1) {
            $rowErrors[] = $this->rowError($rowNumber, 'DUPLICATE_PRIMARY_MEDIA', 'media', 'Chỉ được đúng một media role=PRIMARY.', $primaryCount);

            return $entries;
        }

        foreach ($entries as $entry) {
            if ($domain === 'HAND_TOOLS') {
                $result = $this->mediaValidationCache[$entry['url']]
                    ??= $this->mediaValidator->validate($entry['url']);
                if (! $result['ok']) {
                    $rowErrors[] = $this->rowError($rowNumber, (string) $result['code'], 'media', (string) $result['message'], $entry['url']);
                }

                continue;
            }

            if (! preg_match('/^https:\/\//i', $entry['url'])) {
                $rowErrors[] = $this->rowError($rowNumber, 'INVALID_MEDIA_URL', 'media', 'media phải là https:// (CDN đã xác minh).', $entry['url']);
            }
        }

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function resolveMaster(string $code, string $type, int $row, string $field, array &$errors): ?string
    {
        if ($code === '') {
            $errors[] = $this->rowError($row, 'MISSING_'.$type, $field, "{$field} là bắt buộc.", null);

            return null;
        }

        $master = ProductMaster::query()
            ->where('master_type', $type)
            ->where('code', $code)
            ->where('status', 'ACTIVE')
            ->first();

        if (! $master) {
            $errors[] = $this->rowError($row, 'UNKNOWN_MASTER_CODE', $field, "Không tìm thấy {$type} ACTIVE với code {$code}. Master data phải được tạo trước qua POST /api/v1/brands hoặc /api/v1/product-categories.", $code);

            return null;
        }

        return (string) $master->master_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowError(int $row, string $code, string $field, string $message, mixed $value): array
    {
        return compact('row', 'code', 'field', 'message', 'value');
    }

    /**
     * @return array<string, mixed>
     */
    private function error(int $status, string $code, string $message): array
    {
        return [
            '_http_status' => $status,
            '_error_code' => $code,
            '_message' => $message,
        ];
    }
}
