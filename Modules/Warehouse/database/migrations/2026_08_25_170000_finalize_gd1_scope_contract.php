<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Repair schema drift between the two 2026-08-25 BA migrations. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warranty_case')) {
            if (! Schema::hasColumn('warranty_case', 'estimated_completion_date')) {
                Schema::table('warranty_case', function (Blueprint $table): void {
                    $table->date('estimated_completion_date')->nullable()->index();
                });
            }
            if (Schema::hasColumn('warranty_case', 'expected_completion_date')) {
                DB::table('warranty_case')
                    ->whereNull('estimated_completion_date')
                    ->whereNotNull('expected_completion_date')
                    ->update(['estimated_completion_date' => DB::raw('expected_completion_date')]);
            }
        }

        if (Schema::hasTable('warranty_case_defects')) {
            if (! Schema::hasColumn('warranty_case_defects', 'warranty_case_defect_id')) {
                Schema::table('warranty_case_defects', function (Blueprint $table): void {
                    $table->uuid('warranty_case_defect_id')->nullable()->index();
                });
            }
            if (! Schema::hasColumn('warranty_case_defects', 'is_primary')) {
                Schema::table('warranty_case_defects', function (Blueprint $table): void {
                    $table->boolean('is_primary')->default(false)->index();
                });
            }

            DB::table('warranty_case_defects')
                ->whereNull('warranty_case_defect_id')
                ->orderBy('warranty_case_id')
                ->get(['warranty_case_id', 'defect_id'])
                ->each(function (object $row): void {
                    DB::table('warranty_case_defects')
                        ->where('warranty_case_id', $row->warranty_case_id)
                        ->where('defect_id', $row->defect_id)
                        ->whereNull('warranty_case_defect_id')
                        ->update(['warranty_case_defect_id' => (string) Str::uuid()]);
                });

            if (Schema::hasTable('warranty_case') && Schema::hasColumn('warranty_case', 'defect_id')) {
                // Avoid DB-specific joined UPDATE syntax: the project runs both
                // PostgreSQL and SQLite in its gates.
                DB::table('warranty_case')
                    ->whereNotNull('defect_id')
                    ->orderBy('warranty_case_id')
                    ->get(['warranty_case_id', 'defect_id'])
                    ->each(function (object $case): void {
                        DB::table('warranty_case_defects')
                            ->where('warranty_case_id', $case->warranty_case_id)
                            ->where('defect_id', $case->defect_id)
                            ->update(['is_primary' => true]);
                    });
            }
        }

        if (Schema::hasTable('agencies')) {
            $addMarket = ! Schema::hasColumn('agencies', 'market');
            $addRegion = ! Schema::hasColumn('agencies', 'region');
            if ($addMarket || $addRegion) {
                Schema::table('agencies', function (Blueprint $table) use ($addMarket, $addRegion): void {
                    if ($addMarket) {
                        $table->string('market', 120)->nullable()->index();
                    }
                    if ($addRegion) {
                        $table->string('region', 120)->nullable()->index();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agencies')) {
            $columns = [];
            foreach (['market', 'region'] as $column) {
                if (Schema::hasColumn('agencies', $column)) {
                    $columns[] = $column;
                }
            }
            if ($columns !== []) {
                Schema::table('agencies', fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }
};
