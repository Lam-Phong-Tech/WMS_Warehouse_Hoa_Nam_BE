<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime repair for the outbound final-scan path.
 *
 * The last scan of a fully matched outbound document immediately creates one
 * document-level Packing Label. Production databases upgraded through older ERD
 * revisions can still carry one (or more) of these stale objects:
 *
 *  - label_snapshots.status CHECK limited to PENDING_PRINT/PRINTED/PRINT_FAILED;
 *  - item-level NOT NULL columns although new snapshots are document-level;
 *  - hn_erd_normalize_runtime_label_snapshots() referencing columns removed from
 *    the canonical table.
 *
 * Any of the above turns an otherwise valid final scan into SQLSTATE 23xxx/42xxx
 * and the API returns 500. Re-assert the current runtime contract idempotently so
 * an upgrade deployment repairs an already-drifted PostgreSQL database instead of
 * relying on a fresh migration chain.
 */
return new class extends Migration
{
    private const SNAPSHOT_STATUSES = "'DRAFT','PREVIEWED','READY_TO_PRINT','PENDING_PRINT','PRINTED','PRINT_FAILED'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('label_snapshots')) {
            return;
        }

        $this->ensureDocumentLevelColumns();
        $this->relaxLegacyItemLevelNullability();
        $this->repairNormalizeTriggerFunction();
        $this->repairImmutabilityTriggerFunction();
        $this->repairStatusConstraints();
        $this->ensureOneDocumentLevelSnapshotIndex();
        $this->verifyRuntimeContract();
    }

    private function ensureDocumentLevelColumns(): void
    {
        if (! Schema::hasColumn('label_snapshots', 'outbound_document_id')) {
            Schema::table('label_snapshots', function (Blueprint $table): void {
                $table->uuid('outbound_document_id')->nullable();
            });
        }

        if (! Schema::hasColumn('label_snapshots', 'applied_at')) {
            Schema::table('label_snapshots', function (Blueprint $table): void {
                $table->timestamp('applied_at')->nullable();
            });
        }

        if (! Schema::hasColumn('label_snapshots', 'applied_by')) {
            Schema::table('label_snapshots', function (Blueprint $table): void {
                $table->uuid('applied_by')->nullable();
            });
        }
    }

    private function relaxLegacyItemLevelNullability(): void
    {
        // New Packing Labels are document-level. The controller intentionally writes
        // NULL to these historical item-level references.
        foreach (['outbound_item_match_id', 'sku_id', 'item_id'] as $column) {
            if (Schema::hasColumn('label_snapshots', $column)) {
                DB::statement("ALTER TABLE label_snapshots ALTER COLUMN {$column} DROP NOT NULL");
            }
        }
    }

    private function repairNormalizeTriggerFunction(): void
    {
        $canBackfillDocument = Schema::hasColumn('label_snapshots', 'outbound_item_match_id')
            && Schema::hasColumn('label_snapshots', 'outbound_document_id');

        $body = $canBackfillDocument
            ? <<<'SQL'
                IF NEW.outbound_document_id IS NULL AND NEW.outbound_item_match_id IS NOT NULL THEN
                    SELECT l.stock_document_id
                      INTO NEW.outbound_document_id
                      FROM outbound_item_matches m
                      JOIN stock_document_lines l ON l.id = m.outbound_line_id
                     WHERE m.id = NEW.outbound_item_match_id;
                END IF;
SQL
            : '';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION hn_erd_normalize_runtime_label_snapshots()
            RETURNS trigger AS \$\$
            BEGIN
                {$body}
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL);
    }

    private function repairImmutabilityTriggerFunction(): void
    {
        $immutable = array_values(array_filter(
            ['label_payload', 'outbound_document_id', 'outbound_item_match_id', 'sku_id', 'item_id'],
            fn (string $column): bool => Schema::hasColumn('label_snapshots', $column)
        ));

        if ($immutable === []) {
            return;
        }

        $conditions = implode("\n                   OR ", array_map(
            fn (string $column): string => "OLD.{$column} IS DISTINCT FROM NEW.{$column}",
            $immutable
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION hn_erd_lock_label_snapshot_payload()
            RETURNS trigger AS \$\$
            BEGIN
                IF {$conditions} THEN
                    RAISE EXCEPTION 'LABEL_SNAPSHOT_IMMUTABLE: business payload cannot be changed after capture';
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL);
    }

    private function repairStatusConstraints(): void
    {
        // Different historical migrations created the same business CHECK under
        // different names. Remove every status CHECK on this table, then establish
        // one canonical source of truth.
        $constraints = DB::select(<<<'SQL'
            SELECT conname
              FROM pg_constraint
             WHERE contype = 'c'
               AND conrelid = 'label_snapshots'::regclass
               AND pg_get_constraintdef(oid) ILIKE '%status%'
        SQL);

        foreach ($constraints as $constraint) {
            $name = str_replace('"', '""', (string) $constraint->conname);
            DB::statement('ALTER TABLE label_snapshots DROP CONSTRAINT IF EXISTS "'.$name.'"');
        }

        DB::statement(
            'ALTER TABLE label_snapshots ADD CONSTRAINT chk_label_snapshot_current_status '.
            'CHECK (status IN ('.self::SNAPSHOT_STATUSES.'))'
        );
    }

    private function ensureOneDocumentLevelSnapshotIndex(): void
    {
        if (! Schema::hasColumn('label_snapshots', 'outbound_document_id')) {
            return;
        }

        // NULL legacy rows remain allowed; new document-level rows are unique.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uk_label_snapshots_outbound_document
                ON label_snapshots(outbound_document_id)
             WHERE outbound_document_id IS NOT NULL
        SQL);
    }

    private function verifyRuntimeContract(): void
    {
        foreach (['outbound_document_id', 'applied_at', 'applied_by'] as $column) {
            if (! Schema::hasColumn('label_snapshots', $column)) {
                throw new RuntimeException("Packing Label runtime contract missing label_snapshots.{$column}");
            }
        }

        $definition = (string) (DB::table('pg_constraint')
            ->where('conname', 'chk_label_snapshot_current_status')
            ->selectRaw('pg_get_constraintdef(oid) AS definition')
            ->value('definition') ?? '');

        foreach (['DRAFT', 'PREVIEWED', 'READY_TO_PRINT', 'PRINTED', 'PRINT_FAILED'] as $status) {
            if (! str_contains($definition, $status)) {
                throw new RuntimeException("Packing Label status constraint does not allow {$status}");
            }
        }
    }

    public function down(): void
    {
        // Fail-forward: rolling this repair back would reintroduce the production
        // final-scan outage on databases that still contain legacy ERD constraints.
    }
};
