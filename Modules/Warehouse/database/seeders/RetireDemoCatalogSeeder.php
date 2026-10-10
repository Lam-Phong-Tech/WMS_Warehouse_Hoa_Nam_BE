<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Deprecated compatibility entrypoint. Catalog absence is NOT seed provenance.
 *
 * The former complement-of-DongCheng cleanup could delete user-created SKUs,
 * images and public profiles, deactivate referenced SKUs and remove masters.
 * Keep this entrypoint non-mutating even when explicitly invoked. Any future
 * cleanup requires a separately reviewed, provenance-backed plan and approval;
 * neither a code prefix nor an environment flag is proof that a row is demo.
 */
class RetireDemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn('RetireDemoCatalogSeeder DISABLED: không có bằng chứng nguồn demo; giữ nguyên SKU, ảnh, hồ sơ, trạng thái và danh mục.');
    }
}
