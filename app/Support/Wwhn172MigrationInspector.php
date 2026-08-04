<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class Wwhn172MigrationInspector
{
    /** @return array<string, mixed> */
    public function inspect(): array
    {
        $checks = [];

        $this->check($checks, 'sku_component_self_reference', function (): array {
            if (! Schema::hasTable('sku_component')) {
                return $this->skipped('sku_component chưa tồn tại');
            }
            $count = DB::table('sku_component')->whereColumn('parent_product_id', 'component_product_id')->count();
            return $this->result($count, 'Không được có parent_product_id = component_product_id.');
        });

        $this->check($checks, 'sku_component_non_positive_quantity', function (): array {
            if (! Schema::hasTable('sku_component')) {
                return $this->skipped('sku_component chưa tồn tại');
            }
            return $this->result(DB::table('sku_component')->where('quantity', '<=', 0)->count(), 'quantity của component phải > 0.');
        });

        $this->check($checks, 'sku_component_duplicate_pair', function (): array {
            if (! Schema::hasTable('sku_component')) {
                return $this->skipped('sku_component chưa tồn tại');
            }
            $sample = DB::table('sku_component')
                ->select('parent_product_id', 'component_product_id', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('parent_product_id', 'component_product_id')
                ->havingRaw('COUNT(*) > 1')->limit(20)->get()
                ->map(fn (object $row): array => (array) $row)->all();
            return $this->result(count($sample), 'Mỗi parent/component pair chỉ có một row.', $sample);
        });

        $this->check($checks, 'container_item_missing_identity', function (): array {
            if (! Schema::hasTable('container_item')) {
                return $this->skipped('container_item chưa tồn tại');
            }
            $count = DB::table('container_item')->whereNull('inventory_serial_id')->whereNull('product_id')->count();
            return $this->result($count, 'Container item phải có serial hoặc product.');
        });

        $this->check($checks, 'container_item_invalid_quantity', function (): array {
            if (! Schema::hasTable('container_item')) {
                return $this->skipped('container_item chưa tồn tại');
            }
            $count = DB::table('container_item')->where(fn ($query) => $query->whereNull('quantity')->orWhere('quantity', '<=', 0))->count();
            return $this->result($count, 'Container item quantity phải > 0.');
        });

        $this->check($checks, 'container_item_missing_item_key', function (): array {
            if (! Schema::hasTable('container_item') || ! Schema::hasColumn('container_item', 'item_key')) {
                return $this->skipped('item_key chưa tồn tại');
            }
            $count = DB::table('container_item')->where(fn ($query) => $query->whereNull('item_key')->orWhere('item_key', ''))->count();
            return $this->result($count, 'item_key phải được backfill trước khi tạo unique index.');
        });

        $this->check($checks, 'container_item_duplicate_key', function (): array {
            if (! Schema::hasTable('container_item') || ! Schema::hasColumn('container_item', 'item_key')) {
                return $this->skipped('item_key chưa tồn tại');
            }
            $sample = DB::table('container_item')
                ->select('container_id', 'item_key', DB::raw('COUNT(*) AS duplicate_count'))
                ->whereNotNull('item_key')->groupBy('container_id', 'item_key')
                ->havingRaw('COUNT(*) > 1')->limit(20)->get()
                ->map(fn (object $row): array => (array) $row)->all();
            return $this->result(count($sample), 'item_key phải unique trong một container.', $sample);
        });

        $this->check($checks, 'active_serial_claimed_by_multiple_containers', function (): array {
            if (! Schema::hasTable('container_item')) {
                return $this->skipped('container_item chưa tồn tại');
            }
            $sample = DB::table('container_item')
                ->select('inventory_serial_id', DB::raw('COUNT(DISTINCT container_id) AS container_count'))
                ->whereNotNull('inventory_serial_id')->groupBy('inventory_serial_id')
                ->havingRaw('COUNT(DISTINCT container_id) > 1')->limit(20)->get()
                ->map(fn (object $row): array => (array) $row)->all();
            return $this->result(count($sample), 'Một serial chỉ được có một container-item owner.', $sample);
        });

        $this->check($checks, 'physical_code_used_by_multiple_containers', function (): array {
            if (! Schema::hasTable('container') || ! Schema::hasColumn('container', 'physical_code_id')) {
                return $this->skipped('container.physical_code_id chưa tồn tại');
            }
            $query = DB::table('container')->select('physical_code_id', DB::raw('COUNT(*) AS container_count'))->whereNotNull('physical_code_id');
            if (Schema::hasColumn('container', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            $sample = $query->groupBy('physical_code_id')->havingRaw('COUNT(*) > 1')->limit(20)->get()
                ->map(fn (object $row): array => (array) $row)->all();
            return $this->result(count($sample), 'Một physical code chỉ thuộc một container còn hiệu lực.', $sample);
        });

        $failed = collect($checks)->where('status', 'BLOCKED')->count();
        return [
            'schema_version' => '1.0.0',
            'task' => 'WWHN-172',
            'database_driver' => DB::getDriverName(),
            'database_name' => (string) DB::connection()->getDatabaseName(),
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'status' => $failed === 0 ? 'PASS' : 'BLOCKED',
                'total' => count($checks),
                'failed' => $failed,
                'passed' => collect($checks)->where('status', 'PASS')->count(),
                'skipped' => collect($checks)->where('status', 'SKIPPED')->count(),
            ],
            'checks' => $checks,
        ];
    }

    /** @param array<int, array<string, mixed>> $checks */
    private function check(array &$checks, string $name, callable $callback): void
    {
        try {
            $checks[] = array_merge(['name' => $name], $callback());
        } catch (\Throwable $e) {
            $checks[] = [
                'name' => $name,
                'status' => 'BLOCKED',
                'violation_count' => null,
                'message' => 'Không thể chạy precheck: '.$e->getMessage(),
                'samples' => [],
            ];
        }
    }

    /** @return array<string, mixed> */
    private function result(int $violations, string $message, array $samples = []): array
    {
        return [
            'status' => $violations === 0 ? 'PASS' : 'BLOCKED',
            'violation_count' => $violations,
            'message' => $message,
            'samples' => $samples,
        ];
    }

    /** @return array<string, mixed> */
    private function skipped(string $message): array
    {
        return [
            'status' => 'SKIPPED',
            'violation_count' => 0,
            'message' => $message,
            'samples' => [],
        ];
    }
}
