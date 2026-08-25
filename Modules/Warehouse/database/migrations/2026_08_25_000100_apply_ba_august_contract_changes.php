<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BA decisions confirmed 2026-08-25.
 *
 * This migration is deliberately additive: historic documents retain their
 * agency and single-defect columns while the runtime moves to recipient_type
 * and warranty_case_defects.  Keeping defect_id during the transition also
 * preserves compatibility for existing exports and integrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agencies') && ! Schema::hasColumn('agencies', 'recipient_type')) {
            Schema::table('agencies', function (Blueprint $table): void {
                $table->string('recipient_type', 30)->default('AGENCY')->index();
            });
        }

        // All legacy records in this master were agencies before recipient type
        // existed.  New records are restricted by request validation.
        if (Schema::hasTable('agencies') && Schema::hasColumn('agencies', 'recipient_type')) {
            DB::table('agencies')->whereNull('recipient_type')->update(['recipient_type' => 'AGENCY']);
        }

        if (Schema::hasTable('warranty_case') && ! Schema::hasColumn('warranty_case', 'estimated_completion_date')) {
            Schema::table('warranty_case', function (Blueprint $table): void {
                $table->date('estimated_completion_date')->nullable()->index();
            });
        }

        if (Schema::hasTable('warranty_case') && ! Schema::hasTable('warranty_case_defects')) {
            Schema::create('warranty_case_defects', function (Blueprint $table): void {
                $table->uuid('warranty_case_defect_id')->primary();
                $table->uuid('warranty_case_id')->index();
                $table->uuid('defect_id')->index();
                $table->timestamps();
                $table->unique(['warranty_case_id', 'defect_id'], 'uq_warranty_case_defect');
            });
        }

        // Backfill the original single value exactly once, making all existing
        // reports semantically equivalent after the many-to-many cut-over.
        if (Schema::hasTable('warranty_case') && Schema::hasTable('warranty_case_defects')) {
            DB::table('warranty_case')
                ->whereNotNull('defect_id')
                ->orderBy('warranty_case_id')
                ->each(function (object $case): void {
                    $exists = DB::table('warranty_case_defects')
                        ->where('warranty_case_id', $case->warranty_case_id)
                        ->where('defect_id', $case->defect_id)
                        ->exists();
                    if (! $exists) {
                        DB::table('warranty_case_defects')->insert([
                            'warranty_case_defect_id' => (string) \Illuminate\Support\Str::uuid(),
                            'warranty_case_id' => $case->warranty_case_id,
                            'defect_id' => $case->defect_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_case_defects');
        if (Schema::hasTable('warranty_case') && Schema::hasColumn('warranty_case', 'estimated_completion_date')) {
            Schema::table('warranty_case', fn (Blueprint $table) => $table->dropColumn('estimated_completion_date'));
        }
        if (Schema::hasTable('agencies') && Schema::hasColumn('agencies', 'recipient_type')) {
            Schema::table('agencies', fn (Blueprint $table) => $table->dropColumn('recipient_type'));
        }
    }
};
