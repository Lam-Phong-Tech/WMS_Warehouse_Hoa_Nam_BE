<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->hardenSession();
        $this->hardenSnapshot();
        $this->hardenCount();
        $this->hardenVariance();
        $this->linkMovementToVariance();
        $this->ensurePostingEventIdempotency();
    }

    public function down(): void
    {
        // MySQL may use the legacy composite unique index as the supporting
        // index for stocktake_snapshot.session_id. Restore it before dropping
        // the replacement dimension index, otherwise rollback can fail with
        // ER_DROP_INDEX_FK (1553). Duplicate legacy grains block rollback.
        $this->restoreLegacySnapshotUniqueIndex();

        $this->dropIndexIfExists('stocktake_session', 'idx_stocktake_session_warehouse_status');
        $this->dropIndexIfExists('stocktake_snapshot', 'uk_stocktake_snapshot_session_grain', true);
        $this->dropIndexIfExists('stocktake_snapshot', 'idx_stocktake_snapshot_dimension');
        $this->dropIndexIfExists('stocktake_count', 'uk_stocktake_count_session_grain', true);
        $this->dropIndexIfExists('stocktake_count', 'idx_stocktake_count_dimension');
        $this->dropIndexIfExists('stocktake_variance', 'uk_stocktake_variance_session_grain', true);
        $this->dropIndexIfExists('stocktake_variance', 'idx_stocktake_variance_session_status');
        $this->dropIndexIfExists('stock_movements', 'idx_movement_stocktake_variance');

        $this->dropForeignIfColumnExists('stocktake_session', 'reviewed_by');
        foreach (['inventory_serial_id', 'container_id', 'container_item_id'] as $column) {
            $this->dropForeignIfColumnExists('stocktake_snapshot', $column);
        }
        $this->dropForeignIfColumnExists('stocktake_count', 'container_item_id');
        foreach (['inventory_serial_id', 'container_id', 'container_item_id', 'acknowledged_by'] as $column) {
            $this->dropForeignIfColumnExists('stocktake_variance', $column);
        }

        if (Schema::hasColumn('stock_movements', 'stocktake_variance_id')) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('stocktake_variance_id');
            });
        }

        foreach ([
            'stocktake_variance' => [
                'grain_type', 'grain_id', 'grain_key', 'stock_status', 'inventory_serial_id',
                'container_id', 'container_item_id', 'inventory_effect_qty',
                'balance_qty_at_review', 'balance_version_at_review', 'detected_movement_ids',
                'acknowledged_by', 'acknowledged_at',
            ],
            'stocktake_count' => ['grain_type', 'grain_id', 'grain_key', 'stock_status', 'container_item_id'],
            'stocktake_snapshot' => ['grain_type', 'grain_id', 'grain_key', 'inventory_serial_id', 'container_id', 'container_item_id'],
            'stocktake_session' => ['reviewed_at', 'reviewed_by'],
        ] as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function hardenSession(): void
    {
        Schema::table('stocktake_session', function (Blueprint $table): void {
            if (! Schema::hasColumn('stocktake_session', 'warehouse_id')) {
                $table->foreignUuid('warehouse_id')->nullable()->after('session_no')->constrained('warehouses')->restrictOnDelete();
            }
            if (! Schema::hasColumn('stocktake_session', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('count_cutoff_at');
            }
            if (! Schema::hasColumn('stocktake_session', 'reviewed_by')) {
                $table->foreignUuid('reviewed_by')->nullable()->after('reviewed_at')->constrained('app_user', 'user_id')->nullOnDelete();
            }
        });

        if (DB::table('stocktake_session')->whereNull('warehouse_id')->exists()) {
            throw new RuntimeException(
                'stocktake_session.warehouse_id còn NULL; DBA phải backfill kho đúng cho từng session trước khi áp NOT NULL/data scope.'
            );
        }

        Schema::table('stocktake_session', function (Blueprint $table): void {
            $table->uuid('warehouse_id')->nullable(false)->change();
            $table->timestamp('stocktake_started_at')->nullable()->change();
            if (! $this->hasIndex('stocktake_session', 'idx_stocktake_session_warehouse_status')) {
                $table->index(['warehouse_id', 'status'], 'idx_stocktake_session_warehouse_status');
            }
        });
    }

    private function hardenSnapshot(): void
    {
        Schema::table('stocktake_snapshot', function (Blueprint $table): void {
            if (! Schema::hasColumn('stocktake_snapshot', 'grain_type')) {
                $table->string('grain_type', 20)->nullable()->after('session_id');
            }
            if (! Schema::hasColumn('stocktake_snapshot', 'grain_id')) {
                $table->uuid('grain_id')->nullable()->after('grain_type');
            }
            if (! Schema::hasColumn('stocktake_snapshot', 'grain_key')) {
                $table->string('grain_key', 180)->nullable()->after('grain_id');
            }
            if (! Schema::hasColumn('stocktake_snapshot', 'inventory_serial_id')) {
                $table->foreignUuid('inventory_serial_id')->nullable()->after('stock_status')->constrained('inventory_serials')->nullOnDelete();
            }
            if (! Schema::hasColumn('stocktake_snapshot', 'container_id')) {
                $table->foreignUuid('container_id')->nullable()->after('inventory_serial_id')->constrained('container', 'container_id')->nullOnDelete();
            }
            if (! Schema::hasColumn('stocktake_snapshot', 'container_item_id')) {
                $table->foreignUuid('container_item_id')->nullable()->after('container_id')->constrained('container_item', 'container_item_id')->nullOnDelete();
            }
        });

        $this->backfillSkuGrain('stocktake_snapshot', 'snapshot_id');
        $this->assertRequiredGrain('stocktake_snapshot');
        $this->assertNoDuplicateGrain('stocktake_snapshot');

        // Create a leading session_id index before removing the legacy unique.
        // InnoDB may use that unique as the supporting index for the session FK.
        $this->ensureSnapshotDimensionIndex();
        if ($this->hasIndex('stocktake_snapshot', 'uk_stocktake_snapshot_session_product_status')) {
            Schema::table('stocktake_snapshot', fn (Blueprint $table) => $table->dropUnique('uk_stocktake_snapshot_session_product_status'));
        }
        Schema::table('stocktake_snapshot', function (Blueprint $table): void {
            $table->string('grain_type', 20)->nullable(false)->change();
            $table->uuid('grain_id')->nullable(false)->change();
            $table->string('grain_key', 180)->nullable(false)->change();
            if (! $this->hasIndex('stocktake_snapshot', 'uk_stocktake_snapshot_session_grain')) {
                $table->unique(['session_id', 'grain_key'], 'uk_stocktake_snapshot_session_grain');
            }
        });
    }

    private function hardenCount(): void
    {
        Schema::table('stocktake_count', function (Blueprint $table): void {
            if (! Schema::hasColumn('stocktake_count', 'grain_type')) {
                $table->string('grain_type', 20)->nullable()->after('session_id');
            }
            if (! Schema::hasColumn('stocktake_count', 'grain_id')) {
                $table->uuid('grain_id')->nullable()->after('grain_type');
            }
            if (! Schema::hasColumn('stocktake_count', 'grain_key')) {
                $table->string('grain_key', 180)->nullable()->after('grain_id');
            }
            if (! Schema::hasColumn('stocktake_count', 'stock_status')) {
                $table->string('stock_status', 30)->default('AVAILABLE')->after('product_id');
            }
            if (! Schema::hasColumn('stocktake_count', 'container_item_id')) {
                $table->foreignUuid('container_item_id')->nullable()->after('container_id')->constrained('container_item', 'container_item_id')->nullOnDelete();
            }
        });

        $this->backfillCountGrain();
        $this->assertRequiredGrain('stocktake_count');
        $this->assertNoDuplicateGrain('stocktake_count');
        Schema::table('stocktake_count', function (Blueprint $table): void {
            $table->string('grain_type', 20)->nullable(false)->change();
            $table->uuid('grain_id')->nullable(false)->change();
            $table->string('grain_key', 180)->nullable(false)->change();
            if (! $this->hasIndex('stocktake_count', 'uk_stocktake_count_session_grain')) {
                $table->unique(['session_id', 'grain_key'], 'uk_stocktake_count_session_grain');
            }
            if (! $this->hasIndex('stocktake_count', 'idx_stocktake_count_dimension')) {
                $table->index(['session_id', 'product_id', 'stock_status'], 'idx_stocktake_count_dimension');
            }
        });
    }

    private function hardenVariance(): void
    {
        Schema::table('stocktake_variance', function (Blueprint $table): void {
            if (! Schema::hasColumn('stocktake_variance', 'grain_type')) {
                $table->string('grain_type', 20)->nullable()->after('session_id');
            }
            if (! Schema::hasColumn('stocktake_variance', 'grain_id')) {
                $table->uuid('grain_id')->nullable()->after('grain_type');
            }
            if (! Schema::hasColumn('stocktake_variance', 'grain_key')) {
                $table->string('grain_key', 180)->nullable()->after('grain_id');
            }
            if (! Schema::hasColumn('stocktake_variance', 'stock_status')) {
                $table->string('stock_status', 30)->default('AVAILABLE')->after('product_id');
            }
            if (! Schema::hasColumn('stocktake_variance', 'inventory_serial_id')) {
                $table->foreignUuid('inventory_serial_id')->nullable()->after('stock_status')->constrained('inventory_serials')->nullOnDelete();
            }
            if (! Schema::hasColumn('stocktake_variance', 'container_id')) {
                $table->foreignUuid('container_id')->nullable()->after('inventory_serial_id')->constrained('container', 'container_id')->nullOnDelete();
            }
            if (! Schema::hasColumn('stocktake_variance', 'container_item_id')) {
                $table->foreignUuid('container_item_id')->nullable()->after('container_id')->constrained('container_item', 'container_item_id')->nullOnDelete();
            }
            if (! Schema::hasColumn('stocktake_variance', 'inventory_effect_qty')) {
                $table->decimal('inventory_effect_qty', 18, 3)->default(0)->after('variance_qty');
            }
            if (! Schema::hasColumn('stocktake_variance', 'balance_qty_at_review')) {
                $table->decimal('balance_qty_at_review', 18, 3)->default(0)->after('inventory_effect_qty');
            }
            if (! Schema::hasColumn('stocktake_variance', 'balance_version_at_review')) {
                $table->unsignedInteger('balance_version_at_review')->default(1)->after('balance_qty_at_review');
            }
            if (! Schema::hasColumn('stocktake_variance', 'detected_movement_ids')) {
                $table->json('detected_movement_ids')->nullable()->after('balance_version_at_review');
            }
            if (! Schema::hasColumn('stocktake_variance', 'acknowledged_by')) {
                $table->foreignUuid('acknowledged_by')->nullable()->after('acknowledged_movement_ids')->constrained('app_user', 'user_id')->nullOnDelete();
            }
            if (! Schema::hasColumn('stocktake_variance', 'acknowledged_at')) {
                $table->timestamp('acknowledged_at')->nullable()->after('acknowledged_by');
            }
        });

        $this->backfillSkuGrain('stocktake_variance', 'variance_id', true);
        $this->assertRequiredGrain('stocktake_variance');
        $this->assertNoDuplicateGrain('stocktake_variance');
        Schema::table('stocktake_variance', function (Blueprint $table): void {
            $table->string('grain_type', 20)->nullable(false)->change();
            $table->uuid('grain_id')->nullable(false)->change();
            $table->string('grain_key', 180)->nullable(false)->change();
            if (! $this->hasIndex('stocktake_variance', 'uk_stocktake_variance_session_grain')) {
                $table->unique(['session_id', 'grain_key'], 'uk_stocktake_variance_session_grain');
            }
            if (! $this->hasIndex('stocktake_variance', 'idx_stocktake_variance_session_status')) {
                $table->index(['session_id', 'status'], 'idx_stocktake_variance_session_status');
            }
        });
    }

    private function linkMovementToVariance(): void
    {
        if (! Schema::hasColumn('stock_movements', 'stocktake_variance_id')) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->foreignUuid('stocktake_variance_id')
                    ->nullable()
                    ->after('stock_document_line_id')
                    ->constrained('stocktake_variance', 'variance_id')
                    ->nullOnDelete();
                $table->index(['stocktake_variance_id', 'movement_type'], 'idx_movement_stocktake_variance');
            });
        }
    }

    private function ensurePostingEventIdempotency(): void
    {
        $duplicates = DB::table('posting_event')
            ->select('operation', 'idempotency_key')
            ->groupBy('operation', 'idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('posting_event có duplicate operation/idempotency_key; phải reconcile trước khi migrate.');
        }
        if (! $this->hasIndex('posting_event', 'uk_posting_event_operation_idem')) {
            Schema::table('posting_event', function (Blueprint $table): void {
                $table->unique(['operation', 'idempotency_key'], 'uk_posting_event_operation_idem');
            });
        }
    }

    private function ensureSnapshotDimensionIndex(): void
    {
        if (! Schema::hasTable('stocktake_snapshot')
            || $this->hasIndex('stocktake_snapshot', 'idx_stocktake_snapshot_dimension')) {
            return;
        }

        Schema::table('stocktake_snapshot', function (Blueprint $table): void {
            $table->index(
                ['session_id', 'product_id', 'stock_status'],
                'idx_stocktake_snapshot_dimension'
            );
        });
    }

    private function restoreLegacySnapshotUniqueIndex(): void
    {
        if (! Schema::hasTable('stocktake_snapshot')
            || $this->hasIndex('stocktake_snapshot', 'uk_stocktake_snapshot_session_product_status')) {
            return;
        }

        if (! DB::connection()->pretending()) {
            $duplicate = DB::table('stocktake_snapshot')
                ->select('session_id', 'product_id', 'stock_status')
                ->groupBy('session_id', 'product_id', 'stock_status')
                ->havingRaw('COUNT(*) > 1')
                ->first();
            if ($duplicate) {
                throw new RuntimeException(
                    'Rollback stocktake bị chặn: dữ liệu hiện có trùng session/product/status; '
                    .'phải reconcile về legacy grain trước khi khôi phục unique constraint.'
                );
            }
        }

        Schema::table('stocktake_snapshot', function (Blueprint $table): void {
            $table->unique(
                ['session_id', 'product_id', 'stock_status'],
                'uk_stocktake_snapshot_session_product_status'
            );
        });
    }

    private function backfillSkuGrain(string $table, string $primaryKey, bool $copyVariance = false): void
    {
        DB::table($table)
            ->where(function ($query): void {
                $query->whereNull('grain_type')->orWhereNull('grain_id')->orWhereNull('grain_key');
            })
            ->orderBy($primaryKey)
            ->get()
            ->each(function (object $row) use ($table, $primaryKey, $copyVariance): void {
                $status = strtoupper((string) ($row->stock_status ?? 'AVAILABLE'));
                $updates = [
                    'grain_type' => 'SKU',
                    'grain_id' => $row->product_id,
                    'grain_key' => 'SKU|'.$row->product_id.'|'.$status,
                    'stock_status' => $status,
                ];
                if ($copyVariance) {
                    $updates['inventory_effect_qty'] = $row->variance_qty;
                }
                DB::table($table)->where($primaryKey, $row->{$primaryKey})->update($updates);
            });
    }

    private function backfillCountGrain(): void
    {
        DB::table('stocktake_count')
            ->where(function ($query): void {
                $query->whereNull('grain_type')->orWhereNull('grain_id')->orWhereNull('grain_key');
            })
            ->orderBy('count_id')
            ->get()
            ->each(function (object $row): void {
                $status = strtoupper((string) ($row->stock_status ?? 'AVAILABLE'));
                $containerGrainId = $row->container_item_id ?: $row->container_id;
                $grainType = $row->inventory_serial_id ? 'ITEM' : ($containerGrainId ? 'CONTAINER' : 'SKU');
                $grainId = $row->inventory_serial_id ?: ($containerGrainId ?: $row->product_id);
                DB::table('stocktake_count')->where('count_id', $row->count_id)->update([
                    'grain_type' => $grainType,
                    'grain_id' => $grainId,
                    'grain_key' => $grainType.'|'.$grainId.'|'.$status,
                    'stock_status' => $status,
                ]);
            });
    }

    private function assertRequiredGrain(string $table): void
    {
        // During `artisan migrate --pretend`, schema SQL is logged but not applied.
        // Runtime data assertions must remain fail-closed for real migrations only;
        // otherwise Schema::hasColumn() observes the pre-migration schema and creates
        // a false blocker on every supported database engine.
        if (DB::connection()->pretending()) {
            return;
        }

        foreach (['grain_type', 'grain_id', 'grain_key'] as $column) {
            if (! Schema::hasColumn($table, $column) || DB::table($table)->whereNull($column)->exists()) {
                throw new RuntimeException("{$table}.{$column} còn NULL; phải backfill/reconcile trước khi đặt NOT NULL.");
            }
        }
    }

    private function assertNoDuplicateGrain(string $table): void
    {
        $duplicate = DB::table($table)
            ->select('session_id', 'grain_key')
            ->whereNotNull('grain_key')
            ->groupBy('session_id', 'grain_key')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicate) {
            throw new RuntimeException("{$table} có duplicate session/grain_key; phải reconcile dữ liệu trước khi đặt unique constraint.");
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $indexName
        );
    }

    private function dropIndexIfExists(string $table, string $indexName, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || ! $this->hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName, $unique): void {
            if ($unique) {
                $blueprint->dropUnique($indexName);

                return;
            }

            $blueprint->dropIndex($indexName);
        });
    }

    private function dropForeignIfColumnExists(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropForeign([$column]);
        });
    }
};
