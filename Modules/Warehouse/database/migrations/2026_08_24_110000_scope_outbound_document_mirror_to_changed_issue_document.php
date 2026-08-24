<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mini App inbound / canonical inbound create must not depend on outbound mirror health.
 *
 * The generic ERD mirror installed by 2026_08_10_000765 creates
 * trg_erd_mirror_outbound_documents_from_stock_documents as a statement-level
 * trigger. Any stock_documents INSERT/UPDATE/DELETE refreshes the complete
 * outbound_documents projection. A receipt insert can therefore touch old issue
 * rows and trip outbound-only guards such as AGENCY_NOT_ACTIVE.
 *
 * Replace that one mirror with a row-scoped bridge: receipt rows are ignored,
 * issue rows upsert only their own projection row, and issue->receipt changes
 * remove only that stale outbound projection. Dirty historical outbound rows
 * remain visible for reconciliation, but they no longer block inbound writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! Schema::hasTable('stock_documents')
            || ! Schema::hasTable('outbound_documents')
            || HoaNamErdSchema::relationKind('outbound_documents__legacy_projection') !== 'v') {
            return;
        }

        $columns = $this->projectionColumns();
        if ($columns === [] || ! in_array('id', $columns, true)) {
            return;
        }

        $quotedColumns = implode(', ', array_map(HoaNamErdSchema::quote(...), $columns));
        $updates = collect($columns)
            ->reject(fn (string $column): bool => $column === 'id')
            ->map(fn (string $column): string => HoaNamErdSchema::quote($column).' = EXCLUDED.'.HoaNamErdSchema::quote($column))
            ->implode(', ');

        $upsert = $updates === ''
            ? 'ON CONFLICT ("id") DO NOTHING'
            : 'ON CONFLICT ("id") DO UPDATE SET '.$updates;

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS "ux_erd_mirror_outbound_documents_id" ON "outbound_documents" ("id")');

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION "hn_erd_mirror_outbound_documents"()
            RETURNS trigger AS \$\$
            DECLARE
                v_document_id uuid;
            BEGIN
                v_document_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END;

                IF TG_OP = 'DELETE' THEN
                    IF OLD.doc_type IS DISTINCT FROM 'issue' THEN
                        RETURN NULL;
                    END IF;

                    DELETE FROM "outbound_documents" WHERE "id" = v_document_id;
                    RETURN NULL;
                END IF;

                IF NEW.doc_type IS DISTINCT FROM 'issue' THEN
                    IF TG_OP = 'UPDATE' AND OLD.doc_type IS NOT DISTINCT FROM 'issue' THEN
                        DELETE FROM "outbound_documents" WHERE "id" = v_document_id;
                    END IF;

                    RETURN NULL;
                END IF;

                INSERT INTO "outbound_documents" ({$quotedColumns})
                SELECT {$quotedColumns}
                  FROM "outbound_documents__legacy_projection"
                 WHERE "id" = v_document_id
                {$upsert};

                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS "trg_erd_mirror_outbound_documents_from_stock_documents" ON "stock_documents"');
        DB::statement(<<<'SQL'
            CREATE TRIGGER "trg_erd_mirror_outbound_documents_from_stock_documents"
            AFTER INSERT OR UPDATE OR DELETE ON "stock_documents"
            FOR EACH ROW
            EXECUTE FUNCTION "hn_erd_mirror_outbound_documents"()
        SQL);

        $this->hardenAgencyGuardLookup();
    }

    public function down(): void
    {
        // Fail-forward repair. Reinstalling the old statement-level mirror would
        // reintroduce receipt writes depending on historical outbound data.
    }

    /**
     * @return list<string>
     */
    private function projectionColumns(): array
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::raw('current_schema()'))
            ->where('table_name', 'outbound_documents__legacy_projection')
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->filter(fn (string $column): bool => Schema::hasColumn('outbound_documents', $column))
            ->values()
            ->all();
    }

    private function hardenAgencyGuardLookup(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_outbound_agency_active()
            RETURNS trigger AS $$
            DECLARE
                agency_status varchar(20);
            BEGIN
                IF NEW.agency_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT status
                  INTO agency_status
                  FROM agencies
                 WHERE id = NEW.agency_id
                    OR agency_id = NEW.agency_id
                 LIMIT 1;

                IF agency_status IS DISTINCT FROM 'ACTIVE' THEN
                    RAISE EXCEPTION 'AGENCY_NOT_ACTIVE: outbound document requires an ACTIVE agency';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }
};
