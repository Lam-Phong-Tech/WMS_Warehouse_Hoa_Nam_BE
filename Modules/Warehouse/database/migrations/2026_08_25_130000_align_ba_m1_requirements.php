<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warranty_case') && ! Schema::hasColumn('warranty_case', 'expected_completion_date')) {
            Schema::table('warranty_case', function (Blueprint $table): void {
                $table->date('expected_completion_date')->nullable();
            });
        }

        if (! Schema::hasTable('warranty_case_defects')) {
            Schema::create('warranty_case_defects', function (Blueprint $table): void {
                $table->uuid('warranty_case_id');
                $table->uuid('defect_id');
                $table->boolean('is_primary')->default(false);
                $table->timestamps();

                $table->primary(['warranty_case_id', 'defect_id'], 'pk_warranty_case_defects');
                $table->index('defect_id', 'idx_warranty_case_defects_defect');
            });

            // Backward-compatible migration: every legacy single defect becomes the
            // primary member of the new N:N set. warranty_case.defect_id is kept as
            // the primary-defect compatibility field for old clients/reports.
            if (Schema::hasTable('warranty_case') && Schema::hasColumn('warranty_case', 'defect_id')) {
                DB::table('warranty_case')
                    ->whereNotNull('defect_id')
                    ->orderBy('warranty_case_id')
                    ->get(['warranty_case_id', 'defect_id'])
                    ->each(function (object $row): void {
                        DB::table('warranty_case_defects')->insertOrIgnore([
                            'warranty_case_id' => $row->warranty_case_id,
                            'defect_id' => $row->defect_id,
                            'is_primary' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    });
            }
        }

        if (Schema::hasTable('stock_documents')) {
            Schema::table('stock_documents', function (Blueprint $table): void {
                if (! Schema::hasColumn('stock_documents', 'recipient_type')) {
                    $table->string('recipient_type', 40)->nullable();
                }
                if (! Schema::hasColumn('stock_documents', 'recipient_reference_id')) {
                    $table->string('recipient_reference_id', 100)->nullable();
                }
                if (! Schema::hasColumn('stock_documents', 'recipient_name_snapshot')) {
                    $table->string('recipient_name_snapshot', 200)->nullable();
                }
                if (! Schema::hasColumn('stock_documents', 'recipient_address_snapshot')) {
                    $table->string('recipient_address_snapshot', 500)->nullable();
                }
                if (! Schema::hasColumn('stock_documents', 'recipient_phone_snapshot')) {
                    $table->string('recipient_phone_snapshot', 30)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('warranty_case_defects')) {
            Schema::drop('warranty_case_defects');
        }

        if (Schema::hasTable('warranty_case') && Schema::hasColumn('warranty_case', 'expected_completion_date')) {
            Schema::table('warranty_case', function (Blueprint $table): void {
                $table->dropColumn('expected_completion_date');
            });
        }

        if (Schema::hasTable('stock_documents')) {
            $columns = array_values(array_filter([
                'recipient_type',
                'recipient_reference_id',
                'recipient_name_snapshot',
                'recipient_address_snapshot',
                'recipient_phone_snapshot',
            ], fn (string $column): bool => Schema::hasColumn('stock_documents', $column)));

            if ($columns !== []) {
                Schema::table('stock_documents', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
    }
};
