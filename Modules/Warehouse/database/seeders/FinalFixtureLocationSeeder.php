<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Support\SeedRunContext;
use RuntimeException;

/** Late fixture display hints only; never a putaway event or authoritative location. */
final class FinalFixtureLocationSeeder extends Seeder
{
    public function run(): void
    {
        if ((bool) config('deployment_safety.protect_database', true)) {
            throw new RuntimeException('Fixture location enrichment rejects a protected database.');
        }
        $scope = app(SeedRunContext::class);
        if (! $scope->active()) {
            throw new RuntimeException('FIXTURE_LOCATION_PROVENANCE_REQUIRED: run inside the isolated default fixture chain.');
        }
        $ids = $scope->freshIds('item_instances');
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::transaction(function () use ($chunk): void {
                $items = DB::table('item_instances')->whereIn('item_id', $chunk)
                    ->whereNull('location_hint')->orderBy('item_id')->lockForUpdate()->pluck('item_id');
                foreach ($items as $id) {
                    $hash = crc32((string) $id);
                    $location = sprintf(
                        'Kệ %s-%02d · Tầng %d · Ô %02d',
                        chr(65 + ($hash % 6)),
                        ($hash >> 3) % 12 + 1,
                        ($hash >> 7) % 4 + 1,
                        ($hash >> 11) % 20 + 1,
                    );
                    DB::table('item_instances')->where('item_id', $id)->whereNull('location_hint')
                        ->update(['location_hint' => $location]);
                }
            });
        }
        $this->command?->info('FinalFixtureLocationSeeder: enriched only exact new fixture IDs after daily coverage; existing locations retained.');
    }
}
