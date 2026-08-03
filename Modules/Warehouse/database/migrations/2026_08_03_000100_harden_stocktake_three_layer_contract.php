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
        if (Schema::hasColumn('stock_movements', 'stocktake_variance_id')) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('stocktake_variance_id');
            });
        }

        if (Schema::hasTable('stocktake_variance')) {
            Schema::table('stocktake_variance', function (Blueprint $table): void {
                foreach ([
                    'grain_type', 'grain_id', 'grain_key', 'stock_status', 'inventory_serial_id',
                    'container_id', 'container_item_id', 'inventory_effect_qty',
                    'balance_qty_at_review', 'balance_version_at_review', 'detected_movement_ids',
                    'acknowledged_by', 'acknowledged_at',
                ] as $column) {
                    if (Schema::hasColumn('stocktake_variance', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('stocktake_count')) {
            Schema::table('stocktake_count', function (Blueprint $table): void {
                foreach (['grain_type', 'grain_id', 'grain_key', 'stock_status', 'container_item_id'] as $column) {
                    if (Schema::hasColumn('stocktake_count', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('stocktake_snapshot')) {
            Schema::table('stocktake_snapshot', function (Blueprint $table): void {
                foreach (['grain_type', 'grain_id', 'grain_key', 'inventory_serial_id', 'container_id', 'container_item_id'] as $column) {
                    if (Schema::hasColumn('stocktake_snapshot', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('stocktake_session')) {
            Schema::table('stocktake_session', function (Blueprint $table): void {
                foreach (['reviewed_at', 'reviewed_by'] as $column) {
                    if (Schema::hasColumn('stocktake_session', $column)) {
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
                $table->foreignUuid('warehouse_id')->after('session_no')->constrained('warehouses')->restrictOnDelete();
            }
            if (! Schema::hasColumn('stocktake_session', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('count_cutoff_at');
            }
            if (! Schema::hasColumn('stocktake_session', 'reviewed_by')) {
                $table->foreignUuid('reviewed_by')->nullable()->after('reviewed_at')->constrained('app_user', 'user_id')->nullOnDelete();
            }
        });

        Schema::table('stocktake_session', function (Blueprint $table): void {
            $table->timestamp('stocktake_started_at')->nullable()->change();
            $table->index(['warehouse_id', 'status'], 'idx_stocktake_session_warehouse_status');
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

        DB::table('stocktake_snapshot')->whereNull('grain_key')->orderBy('snapshot_id')->get()->each(function (object $row): void {
            $status = strtoupper((string) ($row->stock_status ?? 'AVAILABLE'));
            DB::table('stocktake_snapshot')->where('snapshot_id', $row->snapshot_id)->update([
                'grain_type' => 'SKU',
                'grain_id' => $row->product_id,
                'grain_key' => 'SKU|'.$row->product_id.'|'.$status,
                'stock_status' => $status,
            ]);
        });

        $this->assertNoDuplicateGrain('stocktake_snapshot', 'snapshot_id');
        if ($this->hasIndex('stocktake_snapshot', 'uk_stocktake_snapshot_session_product_status')) {
            Schema::table('stocktake_snapshot', fn (Blueprint $table) => $table->dropUnique('uk_stocktake_snapshot_session_product_status'));
        }
        Schema::table('stocktake_snapshot', function (Blueprint $table): void {
            $table->string('grain_type', 20)->nullable(false)->change();
            $table->uuid('grain_id')->nullable(false)->change();
            $table->string('grain_key', 180)->nullable(false)->change();
            $table->unique(['session_id', 'grain_key'], 'uk_stocktake_snapshot_session_grain');
            $table->index(['session_id', 'product_id', 'stock_status'], 'idx_stocktake_snapshot_dimension');
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

        DB::table('stocktake_count')->whereNull('grain_key')->orderBy('count_id')->get()->each(function (object $row): void {
            $status = strtoupper((string) ($row->stock_status ?? 'AVAILABLE'));
            $grainType = $row->inventory_serial_id ? 'ITEM' : ($row->container_id ? 'CONTAINER' : 'SKU');
            $grainId = $row->inventory_serial_id ?: ($row->container_id ?: $row->product_id);
            DB::table('stocktake_count')->where('count_id', $row->count_id)->update([
                'grain_type' => $grainType,
                'grain_id' => $grainId,
                'grain_key' => $grainType.'|'.$grainId.'|'.$status,
                'stock_status' => $status,
            ]);
        });

        $this->assertNoDuplicateGrain('stocktake_count', 'count_id');
        Schema::table('stocktake_count', function (Blueprint $table): void {
            $table->string('grain_type', 20)->nullable(false)->change();
            $table->uuid('grain_id')->nullable(false)->change();
            $table->string('grain_key', 180)->nullable(false)->change();
            $table->unique(['session_id', 'grain_key'], 'uk_stocktake_count_session_grain');
            $table->index(['session_id', 'product_id', 'stock_status'], 'idx_stocktake_count_dimension');
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

        DB::table('stocktake_variance')->whereNull('grain_key')->orderBy('variance_id')->get()->each(function (object $row): void {
            $status = strtoupper((string) ($row->stock_status ?? 'AVAILABLE'));
            DB::table('stocktake_variance')->where('variance_id', $row->variance_id)->update([
                'grain_type' => 'SKU',
                'grain_id' => $row->product_id,
                'grain_key' => 'SKU|'.$row->product_id.'|'.$status,
                'stock_status' => $status,
                'inventory_effect_qty' => $row->variance_qty,
            ]);
        });

        $this->assertNoDuplicateGrain('stocktake_variance', 'variance_id');
        Schema::table('stocktake_variance', function (Blueprint $table): void {
            $table->string('grain_type', 20)->nullable(false)->change();
            $table->uuid('grain_id')->nullable(false)->change();
            $table->string('grain_key', 180)->nullable(false)->change();
            $table->unique(['session_id', 'grain_key'], 'uk_stocktake_variance_session_grain');
            $table->index(['session_id', 'status'], 'idx_stocktake_variance_session_status');
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

    private function assertNoDuplicateGrain(string $table, string $primaryKey): void
    {
        $duplicate = DB::table($table)
            ->select('session_id', 'grain_key')
            ->whereNotNull('grain_key')
            ->groupBy('session_id', 'grain_key')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicate) {
            throw new RuntimeException("{$table} có duplicate grain; phải reconcile dữ liệu trước khi áp constraint ({$primaryKey}).");
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $indexName
        );
    }
};
