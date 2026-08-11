<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BP-WMS-03 invariant:
 * - registering/scanning a COMPONENT BOX is stock-neutral and leaves it EMPTY;
 * - the same transaction that changes a receipt to POSTED materializes the BOX
 *   state RECEIVED_PENDING_PUTAWAY;
 * - Putaway confirmation later changes only location/container state, never stock.
 *
 * The invariant is implemented at DB level so every current/future Post Receipt
 * entry point gets identical behavior. Both production PostgreSQL and CI SQLite
 * are covered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_documents')
            || ! Schema::hasTable('stock_document_lines')
            || ! Schema::hasTable('inbound_scan_entries')
            || ! Schema::hasTable('containers')
            || ! Schema::hasTable('sku')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
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

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
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
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS trg_materialize_receipt_putaway_status ON stock_documents;
                DROP FUNCTION IF EXISTS hn_materialize_receipt_putaway_status();
            SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_materialize_receipt_putaway_status;');
        }
    }
};
