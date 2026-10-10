<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Warehouse\Database\Seeders\RetireDemoCatalogSeeder;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\PvRelations;
use Tests\TestCase;

/** Synthetic sentinels on the isolated test database/disk; never a live seed. */
class CatalogRetirementSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_retirement_preserves_two_non_catalog_skus_and_two_images_exactly(): void
    {
        Storage::fake('public');
        $files = [];
        $profileRelation = PvRelations::profile();
        for ($i = 0; $i < 2; $i++) {
            $sku = Sku::create([
                'sku_code' => 'USER-OWNED-'.Str::upper(Str::random(12)),
                'sku_name' => 'Isolated customer catalog sentinel '.$i,
                'sku_type' => 'COMPONENT', 'unit' => 'cai',
                'is_serial_tracked' => false, 'status' => 'ACTIVE', 'version' => 1,
            ]);
            $profile = (string) Str::uuid();
            $path = 'catalog-sentinels/'.$sku->sku_id.'.png';
            $bytes = 'isolated-image-sentinel-'.$i;
            Storage::disk('public')->put($path, $bytes);
            $files[$path] = hash('sha256', $bytes);
            // Use the owner's physical write relation on each driver, not a
            // read-only compatibility view or a test-created substitute table.
            DB::table($profileRelation['table'])->insert([
                $profileRelation['key'] => $profile, $profileRelation['sku'] => $sku->sku_id,
                'display_name' => 'Owner supplied profile '.$i, 'published' => true,
                'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('pv_product_images')->insert([
                'id' => (string) Str::uuid(), 'profile_id' => $profile,
                'image_url' => '/storage/'.$path, 'storage_url' => $path,
                'is_primary' => true, 'sort_order' => 0, 'version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->assertDatabaseCount('sku', 2);
        $this->assertDatabaseCount('pv_product_images', 2);
        $before = $this->catalogSnapshot();
        for ($pass = 0; $pass < 2; $pass++) {
            (new RetireDemoCatalogSeeder)->run();
            $this->assertDatabaseCount('sku', 2);
            $this->assertDatabaseCount('pv_product_images', 2);
            $this->assertSame($before, $this->catalogSnapshot(), 'A seed must not unpublish, deactivate or delete existing catalog data.');
            foreach ($files as $path => $sha) {
                Storage::disk('public')->assertExists($path);
                $this->assertSame($sha, hash('sha256', Storage::disk('public')->get($path)));
            }
        }
    }

    public function test_retirement_has_no_queries_and_is_not_in_the_default_seed_chain(): void
    {
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        try {
            (new RetireDemoCatalogSeeder)->run();
            $this->assertSame([], $connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }
        $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));
        $this->assertStringNotContainsString('RetireDemoCatalogSeeder::class', $source);
    }

    private function catalogSnapshot(): array
    {
        $snapshot = [];
        $profile = PvRelations::profile();
        foreach (['sku' => 'sku_id', $profile['table'] => $profile['key'], 'pv_product_images' => 'id',
            'brands' => 'id', 'product_models' => 'id', 'product_categories' => 'id'] as $table => $key) {
            $snapshot[$table] = DB::table($table)->orderBy($key)->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }
}
