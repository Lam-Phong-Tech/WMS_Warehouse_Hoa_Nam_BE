<?php

namespace Modules\Warehouse\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Support\SeedEnvironment;
use RuntimeException;
use Throwable;

/**
 * Current-invocation ID boundary for an EXCLUSIVE, isolated fixture database.
 * Not a durable manifest or an ownership claim over historical/import/user rows.
 * The default-chain lock excludes another default seed, not application writers:
 * DevOps must isolate the database, services, filesystem and network beforehand.
 */
final class SeedRunContext
{
    private const LOCK = 'hoa-nam:isolated-full-fixture-chain:v2';

    private const TABLES = [
        'item_instances' => 'item_id',
        'containers' => 'container_id',
        'inventory_balances' => 'id',
        'import_reports' => 'import_report_id',
        'outbound_request_lines' => 'id',
    ];

    private bool $active = false;

    private bool $locked = false;

    private array $initial = [];

    private array $initialStockSkus = [];

    public function begin(): void
    {
        if ($this->active || $this->locked) {
            throw new RuntimeException('SEED_SCOPE_ALREADY_ACTIVE');
        }
        if ((bool) config('deployment_safety.protect_database', true)) {
            throw new RuntimeException('FULL_FIXTURE_SEED_PROTECTED_DATABASE: no master or fixture writes authorized.');
        }
        SeedEnvironment::assertFixtureAccountsAllowed(self::class);
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $lock = DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS acquired', [self::LOCK]);
        if (! in_array($lock->acquired, [true, 1, '1', 't'], true)) {
            throw new RuntimeException('FULL_FIXTURE_SEED_BUSY: no seed phase started.');
        }
        $this->locked = true;
        try {
            foreach (self::TABLES as $table => $key) {
                $this->initial[$table] = Schema::hasTable($table)
                    ? array_fill_keys(DB::table($table)->pluck($key)->map(static fn ($id) => (string) $id)->all(), true)
                    : [];
            }
            $this->initialStockSkus = Schema::hasTable('inventory_balances')
                ? array_fill_keys(DB::table('inventory_balances')->selectRaw('COALESCE(sku_id, product_id) AS sku')
                    ->get()->pluck('sku')->filter()->map(static fn ($id) => (string) $id)->all(), true)
                : [];
            $this->active = true;
        } catch (Throwable $error) {
            $this->end();

            throw $error;
        }
    }

    public function active(): bool
    {
        return $this->active;
    }

    /** No prefix, missing catalog entry, or zero balance establishes historical ownership. */
    public function freshIds(string $table): array
    {
        if (! $this->active || ! isset(self::TABLES[$table]) || ! Schema::hasTable($table)) {
            return [];
        }
        $ids = DB::table($table)->pluck(self::TABLES[$table])
            ->map(static fn ($id) => (string) $id)->all();

        return array_values(array_filter($ids, fn ($id) => ! isset($this->initial[$table][$id])));
    }

    /** Never derive a sample storage mapping from pre-existing operational stock. */
    public function freshUnmixedBalanceIds(): array
    {
        $fresh = $this->freshIds('inventory_balances');
        if ($fresh === []) {
            return [];
        }

        return DB::table('inventory_balances')->whereIn('id', $fresh)
            ->selectRaw('id, COALESCE(sku_id, product_id) AS sku')->get()
            ->filter(fn ($row) => ! isset($this->initialStockSkus[(string) $row->sku]))
            ->pluck('id')->map(static fn ($id) => (string) $id)->all();
    }

    public function end(): void
    {
        try {
            if ($this->locked) {
                DB::selectOne('SELECT pg_advisory_unlock(hashtext(?)) AS released', [self::LOCK]);
            }
        } finally {
            $this->locked = $this->active = false;
            $this->initial = $this->initialStockSkus = [];
        }
    }
}
