<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Final GĐ1 contract normalization.
 *
 * This migration intentionally repairs the two BA migrations from 2026-08-25
 * without rewriting migration history that may already exist on DEV/UAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Canonical warranty date = estimated_completion_date. The historical
        // expected_completion_date alias is copied once and no runtime code should
        // write it after this migration.
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

        // Make both previously-created variants of warranty_case_defects usable by
        // the same runtime contract. We do not replace an existing primary key;
        // instead every row gets a stable surrogate id when that column was absent.
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
                DB::table('warranty_case as wc')
                    ->join('warranty_case_defects as wcd', function ($join): void {
                        $join->on('wcd.warranty_case_id', '=', 'wc.warranty_case_id')
                            ->on('wcd.defect_id', '=', 'wc.defect_id');
                    })
                    ->whereNotNull('wc.defect_id')
                    ->update(['is_primary' => true]);
            }
        }

        if (Schema::hasTable('agencies')) {
            Schema::table('agencies', function (Blueprint $table): void {
                if (! Schema::hasColumn('agencies', 'market')) {
                    $table->string('market', 120)->nullable()->index();
                }
                if (! Schema::hasColumn('agencies', 'region')) {
                    $table->string('region', 120)->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        // Corrective migration is intentionally conservative: do not remove
        // normalized warranty data on rollback. Only optional reporting dimensions
        // introduced here are reversible.
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
