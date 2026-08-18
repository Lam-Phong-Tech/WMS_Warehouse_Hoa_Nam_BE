<?php

namespace App\Console\Commands;

use App\Support\Cache\CacheService;
use Illuminate\Console\Command;

/**
 * Clear application cache theo SCOPE — không bao giờ FLUSHALL/FLUSHDB (mục 27),
 * không bao giờ KEYS/SCAN wildcard xoá hàng loạt (mục 13/27). Chỉ bump các version
 * namespace đã biết trước (list/report cache) — cache chi tiết theo id (RBAC per-user,
 * SKU/Role/Agency/... detail) không nằm trong scope này, tự hết hạn theo TTL riêng
 * (tối đa vài chục phút, xem config/wms_cache.php) — đây là lựa chọn có chủ đích để
 * tránh phải liệt kê/scan toàn bộ key trong Valkey.
 *
 * Session/queue KHÔNG bị ảnh hưởng: lệnh này chỉ gọi CacheService::bumpVersion(),
 * không đụng tới Cache::store('redis') ở DB khác (session/queue dùng DB 0, cache
 * dùng DB 1 riêng — config/database.php redis.cache.database).
 */
class ClearWmsCache extends Command
{
    protected $signature = 'wms:cache:clear
        {--scope=all : rbac|master|sku|stock|reports|all}';

    protected $description = 'Bump version namespace của application cache theo scope an toàn (không FLUSHALL/FLUSHDB/KEYS *)';

    /** @var array<string, array<int, string>> */
    private const SCOPE_NAMESPACES = [
        'rbac' => [
            'auth.role:list',
            'auth.permission:list',
        ],
        'master' => [
            'warehouse.warehouse:list',
            'warehouse.agency:list',
            'warehouse.defect_catalog:list',
            'warehouse.product_master:list',
            'warehouse.catalog_master.brands:list',
            'warehouse.catalog_master.product_categories:list',
            'warehouse.catalog_master.power_sources:list',
            'warehouse.catalog_master.product_models:list',
            'warehouse.catalog_master.package_variants:list',
        ],
        'sku' => [
            'warehouse.sku:list',
        ],
        // Report cache (dashboard-quick, inventory-balance, inventory-reconciliation)
        // dùng chung namespace stock:version — bump 1 lần invalidate cả tồn kho lẫn
        // report phái sinh từ tồn kho.
        'stock' => ['stock:version'],
        'reports' => ['stock:version'],
    ];

    public function handle(CacheService $cache): int
    {
        $scope = (string) $this->option('scope');

        $namespaces = $scope === 'all'
            ? collect(self::SCOPE_NAMESPACES)->flatten()->unique()->values()->all()
            : self::SCOPE_NAMESPACES[$scope] ?? null;

        if ($namespaces === null) {
            $this->error("Scope không hợp lệ: {$scope}. Chọn: ".implode('|', [...array_keys(self::SCOPE_NAMESPACES), 'all']));

            return self::FAILURE;
        }

        foreach ($namespaces as $namespace) {
            $version = $cache->bumpVersion($namespace);
            $this->line("  bumped {$namespace} -> v{$version}");
        }

        $this->info(sprintf('Đã clear application cache scope=%s (%d namespace).', $scope, count($namespaces)));

        return self::SUCCESS;
    }
}
