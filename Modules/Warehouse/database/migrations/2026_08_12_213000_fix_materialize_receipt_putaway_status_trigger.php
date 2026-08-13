<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes 2026_08_11_161500_materialize_receipt_putaway_status.php.
 *
 * That migration guarded creation of trg_materialize_receipt_putaway_status
 * with Schema::hasTable('sku'). On PostgreSQL, 2026_08_10_000790 renamed the
 * real SKU table to `skus` and replaced `sku` with a compatibility VIEW.
 * Laravel's PostgresGrammar::compileTableExists() only matches relkind
 * 'r'/'p' (ordinary/partitioned tables), so Schema::hasTable('sku') is false
 * for that view — the guard silently short-circuited `up()`, so the trigger
 * and function were never created even though the migration recorded "Ran".
 *
 * Net effect: COMPONENT BOX containers never materialized to
 * RECEIVED_PENDING_PUTAWAY after Full Post Receipt, so GET
 * /api/v1/putaway/tasks stayed empty for every receipt posted on Postgres.
 *
 * This migration recreates the same trigger/function using a driver-correct
 * existence check, and backfills any BOX already stuck EMPTY from receipts
 * that posted while the trigger was missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_documents')
            || ! Schema::hasTable('stock_document_lines')
            || ! Schema::hasTable('inbound_scan_entries')
            || ! Schema::hasTable('containers')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            // 'sku' can be a compatibility VIEW here (see 2026_08_10_000790);
            // Schema::hasTable() only matches base/partitioned tables and
            // misses it, so resolve existence via pg_catalog directly.
            $hasSku = (bool) DB::selectOne("select to_regclass('public.sku') is not null as ok")->ok;
            if (! $hasSku) {
                return;
            }

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION hn_materialize_receipt_putaway_status()
                RETURNS trigger AS $$
                BEGIN
                    IF NEW.doc_type = 'receipt'
                       AND NEW.status = 'POSTED'
                       AND OLD.status IS DISTINCT FROM NEW.status THEN
                        UPDATE containers AS c
                           SET status = 'RECEIVED_PENDING_PUTAWAY',
                               version = COALESCE(c.version, 0) + 1,
                               updated_at = CURRENT_TIMESTAMP
                         WHERE c.container_type = 'BOX'
                           AND c.parent_container_id IS NULL
                           AND c.status NOT IN ('CLOSED', 'ISSUED', 'STORED')
                           AND c.container_id IN (
                               SELECT DISTINCT e.container_id
                                 FROM inbound_scan_entries AS e
                                 JOIN stock_document_lines AS l
                                   ON l.id = e.inbound_line_id
                                 JOIN sku AS s
                                   ON s.sku_id = l.product_id
                                WHERE l.stock_document_id = NEW.id
                                  AND e.container_id IS NOT NULL
                                  AND UPPER(s.sku_type) = 'COMPONENT'
                           );
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS trg_materialize_receipt_putaway_status ON stock_documents;
                CREATE TRIGGER trg_materialize_receipt_putaway_status
                AFTER UPDATE OF status ON stock_documents
                FOR EACH ROW
                EXECUTE FUNCTION hn_materialize_receipt_putaway_status();
            SQL);

            // Backfill: BOX containers left EMPTY by receipts that already
            // posted while the trigger was missing.
            DB::unprepared(<<<'SQL'
                UPDATE containers AS c
                   SET status = 'RECEIVED_PENDING_PUTAWAY',
                       version = COALESCE(c.version, 0) + 1,
                       updated_at = CURRENT_TIMESTAMP
                 WHERE c.container_type = 'BOX'
                   AND c.parent_container_id IS NULL
                   AND c.status NOT IN ('CLOSED', 'ISSUED', 'STORED')
                   AND c.container_id IN (
                       SELECT DISTINCT e.container_id
                         FROM inbound_scan_entries AS e
                         JOIN stock_document_lines AS l
                           ON l.id = e.inbound_line_id
                         JOIN stock_documents AS d
                           ON d.id = l.stock_document_id
                         JOIN sku AS s
                           ON s.sku_id = l.product_id
                        WHERE d.doc_type = 'receipt'
                          AND d.status = 'POSTED'
                          AND e.container_id IS NOT NULL
                          AND UPPER(s.sku_type) = 'COMPONENT'
                   );
            SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            if (! Schema::hasTable('sku')) {
                return;
            }

            // SQLite branch of the original migration works correctly
            // (no view aliasing issue there); recreate it idempotently so
            // this migration is a safe no-op-if-already-correct on that
            // driver, and backfill any stragglers for parity.
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS trg_materialize_receipt_putaway_status;
                CREATE TRIGGER trg_materialize_receipt_putaway_status
                AFTER UPDATE OF status ON stock_documents
                FOR EACH ROW
                WHEN NEW.doc_type = 'receipt'
                     AND NEW.status = 'POSTED'
                     AND OLD.status <> NEW.status
                BEGIN
                    UPDATE containers
                       SET status = 'RECEIVED_PENDING_PUTAWAY',
                           version = COALESCE(version, 0) + 1,
                           updated_at = CURRENT_TIMESTAMP
                     WHERE container_type = 'BOX'
                       AND parent_container_id IS NULL
                       AND status NOT IN ('CLOSED', 'ISSUED', 'STORED')
                       AND container_id IN (
                           SELECT DISTINCT e.container_id
                             FROM inbound_scan_entries AS e
                             JOIN stock_document_lines AS l
                               ON l.id = e.inbound_line_id
                             JOIN sku AS s
                               ON s.sku_id = l.product_id
                            WHERE l.stock_document_id = NEW.id
                              AND e.container_id IS NOT NULL
                              AND UPPER(s.sku_type) = 'COMPONENT'
                       );
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                UPDATE containers
                   SET status = 'RECEIVED_PENDING_PUTAWAY',
                       version = COALESCE(version, 0) + 1,
                       updated_at = CURRENT_TIMESTAMP
                 WHERE container_type = 'BOX'
                   AND parent_container_id IS NULL
                   AND status NOT IN ('CLOSED', 'ISSUED', 'STORED')
                   AND container_id IN (
                       SELECT DISTINCT e.container_id
                         FROM inbound_scan_entries AS e
                         JOIN stock_document_lines AS l
                           ON l.id = e.inbound_line_id
                         JOIN stock_documents AS d
                           ON d.id = l.stock_document_id
                         JOIN sku AS s
                           ON s.sku_id = l.product_id
                        WHERE d.doc_type = 'receipt'
                          AND d.status = 'POSTED'
                          AND e.container_id IS NOT NULL
                          AND UPPER(s.sku_type) = 'COMPONENT'
                   );
            SQL);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: this migration only repairs the trigger
        // that 2026_08_11_161500 already owns dropping, and reverting the
        // backfilled container statuses is not safely invertible (we can't
        // tell which rows were EMPTY-by-bug vs. legitimately EMPTY since).
    }
};
