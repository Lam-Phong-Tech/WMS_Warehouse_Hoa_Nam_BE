<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class Wwhn172ContainerItemInvariantSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('container_item') || ! Schema::hasColumn('container_item', 'item_key')) {
            return;
        }

        DB::transaction(function (): void {
            $rows = DB::table('container_item')
                ->select([
                    'container_item_id',
                    'container_id',
                    'item_key',
                    'inventory_serial_id',
                    'product_id',
                    'quantity',
                ])
                ->orderBy('container_item_id')
                ->lockForUpdate()
                ->get();

            $seen = [];
            $updates = [];

            foreach ($rows as $row) {
                $containerId = strtolower(trim((string) $row->container_id));
                if ($containerId === '') {
                    throw new RuntimeException(sprintf(
                        'WWHN-172 seed blocked: container_item %s thiếu container_id.',
                        (string) $row->container_item_id,
                    ));
                }

                if ($row->quantity === null || (float) $row->quantity <= 0) {
                    throw new RuntimeException(sprintf(
                        'WWHN-172 seed blocked: container_item %s có quantity không hợp lệ.',
                        (string) $row->container_item_id,
                    ));
                }

                $expectedKey = $this->expectedItemKey($row);
                $compoundKey = $containerId.'|'.$expectedKey;
                if (isset($seen[$compoundKey])) {
                    throw new RuntimeException(sprintf(
                        'WWHN-172 seed blocked: duplicate item_key %s trong container %s (%s, %s).',
                        $expectedKey,
                        (string) $row->container_id,
                        $seen[$compoundKey],
                        (string) $row->container_item_id,
                    ));
                }
                $seen[$compoundKey] = (string) $row->container_item_id;

                $currentKey = trim((string) $row->item_key);
                if ($currentKey !== '' && $currentKey !== $expectedKey) {
                    throw new RuntimeException(sprintf(
                        'WWHN-172 seed blocked: item_key %s của container_item %s không khớp %s.',
                        $currentKey,
                        (string) $row->container_item_id,
                        $expectedKey,
                    ));
                }

                if ($currentKey === '') {
                    $updates[(string) $row->container_item_id] = $expectedKey;
                }
            }

            foreach ($updates as $containerItemId => $itemKey) {
                DB::table('container_item')
                    ->where('container_item_id', $containerItemId)
                    ->update(['item_key' => $itemKey]);
            }

            $remaining = DB::table('container_item')
                ->where(fn ($query) => $query->whereNull('item_key')->orWhere('item_key', ''))
                ->count();
            if ($remaining !== 0) {
                throw new RuntimeException(sprintf(
                    'WWHN-172 seed blocked: còn %d container_item thiếu item_key sau reconciliation.',
                    $remaining,
                ));
            }
        }, 3);
    }

    private function expectedItemKey(object $row): string
    {
        $serialId = strtolower(trim((string) $row->inventory_serial_id));
        if ($serialId !== '') {
            return 'SERIAL:'.$serialId;
        }

        $productId = strtolower(trim((string) $row->product_id));
        if ($productId !== '') {
            return 'PRODUCT:'.$productId;
        }

        throw new RuntimeException(sprintf(
            'WWHN-172 seed blocked: container_item %s phải có inventory_serial_id hoặc product_id.',
            (string) $row->container_item_id,
        ));
    }
}
