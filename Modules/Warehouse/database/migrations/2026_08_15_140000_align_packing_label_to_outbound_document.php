<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('label_snapshots', 'outbound_document_id')) {
            Schema::table('label_snapshots', function (Blueprint $table): void {
                $table->uuid('outbound_document_id')->nullable()->after('id');
            });
        }
        if (! Schema::hasColumn('label_snapshots', 'applied_at')) {
            Schema::table('label_snapshots', function (Blueprint $table): void {
                $table->timestamp('applied_at')->nullable()->after('status');
                $table->uuid('applied_by')->nullable()->after('applied_at');
            });
        }

        // New canonical Packing Label is document-level. Legacy item-level columns stay
        // for immutable historical snapshots, but are nullable for every new record.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE label_snapshots ALTER COLUMN outbound_item_match_id DROP NOT NULL');
            DB::statement('ALTER TABLE label_snapshots ALTER COLUMN sku_id DROP NOT NULL');
        } else {
            Schema::table('label_snapshots', function (Blueprint $table): void {
                $table->uuid('outbound_item_match_id')->nullable()->change();
                $table->uuid('sku_id')->nullable()->change();
            });
        }

        // Preserve legacy snapshots without deleting audit history. For each outbound
        // document, assign the earliest legacy snapshot as the canonical compatibility
        // representative. New writes create exactly one document-level snapshot.
        $assigned = [];
        DB::table('label_snapshots')
            ->whereNull('outbound_document_id')
            ->whereNotNull('outbound_item_match_id')
            ->orderBy('created_at')
            ->get(['id', 'outbound_item_match_id'])
            ->each(function (object $snapshot) use (&$assigned): void {
                $documentId = DB::table('outbound_item_matches as m')
                    ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
                    ->where('m.id', $snapshot->outbound_item_match_id)
                    ->value('l.stock_document_id');
                if ($documentId === null || isset($assigned[(string) $documentId])) {
                    return;
                }
                DB::table('label_snapshots')->where('id', $snapshot->id)->update([
                    'outbound_document_id' => $documentId,
                    'updated_at' => now(),
                ]);
                $assigned[(string) $documentId] = true;
            });

        Schema::table('label_snapshots', function (Blueprint $table): void {
            $table->unique('outbound_document_id', 'uk_label_snapshots_outbound_document');
            $table->index(['outbound_document_id', 'status'], 'idx_label_snapshots_outbound_status');
            $table->index(['applied_at'], 'idx_label_snapshots_applied_at');
        });

        // reprint_count was a legacy snapshot projection. The canonical source is the
        // append-only label_print_events ledger, so remove the temporary compatibility
        // column (and its legacy CHECK constraint) after all old ERD migrations pass.
        if (Schema::hasColumn('label_snapshots', 'reprint_count')) {
            Schema::table('label_snapshots', function (Blueprint $table): void {
                $table->dropColumn('reprint_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('label_snapshots', 'outbound_document_id')) {
            Schema::table('label_snapshots', function (Blueprint $table): void {
                $table->dropUnique('uk_label_snapshots_outbound_document');
                $table->dropIndex('idx_label_snapshots_outbound_status');
                if (Schema::hasColumn('label_snapshots', 'applied_at')) {
                    $table->dropIndex('idx_label_snapshots_applied_at');
                }
                $table->dropColumn(['outbound_document_id', 'applied_at', 'applied_by']);
            });
        }
    }
};
