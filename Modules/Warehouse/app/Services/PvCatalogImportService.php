<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 */
class PvCatalogImportService
{
    private const FORBIDDEN_KEYS = [
        'price', 'unit_price', 'sale_price', 'list_price', 'cost', 'cost_price',
        'quantity', 'qty', 'stock', 'available_qty', 'on_hand_qty',
        'barcode', 'ean', 'gtin', 'upc',
        'published', 'published_to_app', 'is_published',
    ];

    private const MAX_ITEMS = 500;

    private const CODE_PATTERN = '/^[A-Z0-9](?:[A-Z0-9._-]*[A-Z0-9])?$/';

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
                    'specification' => $record['specifications'],
                    'published_to_app' => false,
                    'status' => 'ACTIVE',
                ]);

                $profileId = $this->createProfile((string) $sku->sku_id, $record);
                $this->createImages($profileId, $record['media_url']);

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
                        'profile_id' => $profileId,
                        'image_count' => count($record['media_url']),
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
        if (Schema::hasColumn($relation['table'], 'short_description')) {
            $fields['short_description'] = $record['short_description'];
        }
        if (Schema::hasColumn($relation['table'], 'public_specifications') && $record['specifications'] !== null) {
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
     * @param  array<int, string>  $mediaUrls
     */
    private function createImages(string $profileId, array $mediaUrls): void
    {
        foreach ($mediaUrls as $index => $url) {
            DB::table('pv_product_images')->insert([
                'id' => (string) Str::uuid(),
                'profile_id' => $profileId,
                'image_url' => $url,
                'storage_url' => $url,
                'is_primary' => $index === 0,
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

            $mediaUrls = $row['media_url'] ?? [];
            if (! is_array($mediaUrls)) {
                $mediaUrls = [$mediaUrls];
            }
            $mediaUrls = array_values(array_filter(array_map(static fn ($u): string => trim((string) $u), $mediaUrls)));
            foreach ($mediaUrls as $url) {
                if (! preg_match('/^https:\/\//i', $url)) {
                    $rowErrors[] = $this->rowError($rowNumber, 'INVALID_MEDIA_URL', 'media_url', 'media_url phải là https:// (CDN đã xác minh).', $url);
                }
            }

            $specifications = $row['specifications'] ?? null;
            if ($specifications !== null && ! is_array($specifications)) {
                $rowErrors[] = $this->rowError($rowNumber, 'INVALID_SPECIFICATIONS', 'specifications', 'specifications phải là object.', $specifications);
                $specifications = null;
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
                'description' => isset($row['description']) ? trim((string) $row['description']) : null,
                'short_description' => isset($row['short_description']) ? trim((string) $row['short_description']) : null,
                'specifications' => $specifications,
                'brand_code' => $brandCode,
                'brand_id' => $brandId,
                'category_code' => $categoryCode,
                'category_id' => $categoryId,
                'domain' => $domain,
                'media_url' => $mediaUrls,
            ];
        }

        return [$records, $errors];
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
