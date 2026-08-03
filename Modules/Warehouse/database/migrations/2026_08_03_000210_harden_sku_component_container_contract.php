<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sku_component')) {
            Schema::create('sku_component', function (Blueprint $table) {
                $table->uuid('sku_component_id')->primary();
                $table->foreignUuid('parent_product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignUuid('component_product_id')->constrained('products')->restrictOnDelete();
                $table->decimal('quantity', 18, 3);
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
                $table->unique(['parent_product_id', 'component_product_id'], 'uk_sku_component_pair');
                $table->index(['component_product_id', 'status'], 'idx_sku_component_reverse');
            });
        }

        if (Schema::hasTable('physical_code')) {
            Schema::table('physical_code', function (Blueprint $table) {
                if (! Schema::hasColumn('physical_code', 'product_id')) {
                    $table->foreignUuid('product_id')
                        ->nullable()
                        ->after('physical_code_id')
                        ->constrained('products')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('physical_code', 'version')) {
                    $table->unsignedInteger('version')->default(1)->after('status');
                }
            });

            if (! $this->indexExists('physical_code', 'idx_physical_code_product_status')) {
                Schema::table('physical_code', function (Blueprint $table) {
                    $table->index(['product_id', 'status'], 'idx_physical_code_product_status');
                });
            }
        }

        if (Schema::hasTable('container')) {
            Schema::table('container', function (Blueprint $table) {
                if (! Schema::hasColumn('container', 'sealed_at')) {
                    $table->timestamp('sealed_at')->nullable()->after('version');
                }
            });
        }

        if (Schema::hasTable('container_item')) {
            Schema::table('container_item', function (Blueprint $table) {
                if (! Schema::hasColumn('container_item', 'item_key')) {
                    $table->string('item_key', 191)->nullable()->after('container_id');
                }
                if (! Schema::hasColumn('container_item', 'version')) {
                    $table->unsignedInteger('version')->default(1)->after('status');
                }
            });

            DB::table('container_item')
                ->orderBy('container_item_id')
                ->each(function (object $row): void {
                    if ($row->item_key !== null && $row->item_key !== '') {
                        return;
                    }

                    $key = $row->inventory_serial_id
                        ? 'SERIAL:'.strtolower((string) $row->inventory_serial_id)
                        : 'PRODUCT:'.strtolower((string) $row->product_id);

                    DB::table('container_item')
                        ->where('container_item_id', $row->container_item_id)
                        ->update(['item_key' => $key]);
                });

            if (! $this->indexExists('container_item', 'uk_container_item_key')) {
                Schema::table('container_item', function (Blueprint $table) {
                    $table->unique(['container_id', 'item_key'], 'uk_container_item_key');
                });
            }
        }

        $this->addDatabaseChecks();
    }

    public function down(): void
    {
        if (Schema::hasTable('container_item')) {
            Schema::table('container_item', function (Blueprint $table) {
                if ($this->indexExists('container_item', 'uk_container_item_key')) {
                    $table->dropUnique('uk_container_item_key');
                }
                if (Schema::hasColumn('container_item', 'version')) {
                    $table->dropColumn('version');
                }
                if (Schema::hasColumn('container_item', 'item_key')) {
                    $table->dropColumn('item_key');
                }
            });
        }

        if (Schema::hasTable('container') && Schema::hasColumn('container', 'sealed_at')) {
            Schema::table('container', fn (Blueprint $table) => $table->dropColumn('sealed_at'));
        }

        if (Schema::hasTable('physical_code')) {
            Schema::table('physical_code', function (Blueprint $table) {
                if ($this->indexExists('physical_code', 'idx_physical_code_product_status')) {
                    $table->dropIndex('idx_physical_code_product_status');
                }
                if (Schema::hasColumn('physical_code', 'version')) {
                    $table->dropColumn('version');
                }
                if (Schema::hasColumn('physical_code', 'product_id')) {
                    $table->dropConstrainedForeignId('product_id');
                }
            });
        }

        Schema::dropIfExists('sku_component');
    }

    private function addDatabaseChecks(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            return;
        }

        $statements = [
            'ALTER TABLE sku_component ADD CONSTRAINT chk_sku_component_not_self CHECK (parent_product_id <> component_product_id)',
            'ALTER TABLE sku_component ADD CONSTRAINT chk_sku_component_quantity CHECK (quantity > 0)',
            'ALTER TABLE container_item ADD CONSTRAINT chk_container_item_quantity CHECK (quantity IS NULL OR quantity > 0)',
            "ALTER TABLE physical_code ADD CONSTRAINT chk_physical_code_status CHECK (status IN ('ACTIVE','INACTIVE','REVOKED'))",
            "ALTER TABLE container ADD CONSTRAINT chk_container_status CHECK (status IN ('ACTIVE','SEALED','CLOSED','INACTIVE'))",
        ];

        foreach ($statements as $statement) {
            try {
                DB::statement($statement);
            } catch (Throwable) {
                // Migration remains repeatable across engines/versions that already own the constraint.
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return collect(Schema::getIndexes($table))
                ->contains(fn (array $candidate): bool => ($candidate['name'] ?? null) === $index);
        } catch (Throwable) {
            return false;
        }
    }
};
