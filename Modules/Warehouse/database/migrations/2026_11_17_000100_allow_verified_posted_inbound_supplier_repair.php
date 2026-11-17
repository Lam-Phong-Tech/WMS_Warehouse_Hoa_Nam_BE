<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_reject_posted_document_change()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'POSTED' THEN
                    IF TG_OP = 'UPDATE' THEN
                        IF current_setting('hoanam.inbound_supplier_sync', true) = 'on'
                            AND OLD.doc_type = 'receipt'
                            AND OLD.supplier_id IS NULL
                            AND NEW.supplier_id IS NOT NULL
                            AND (to_jsonb(NEW) - 'supplier_id') = (to_jsonb(OLD) - 'supplier_id')
                            AND EXISTS (
                                SELECT 1 FROM mini_app_inbound_record_metadata m
                                JOIN inbound_suppliers s ON s.id = m.supplier_id
                                WHERE m.document_id = OLD.id AND m.supplier_id = NEW.supplier_id
                            ) THEN
                            RETURN NEW;
                        END IF;
                    END IF;
                    RAISE EXCEPTION 'POSTED stock document % is immutable; use reversal/correction', OLD.id;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_reject_posted_document_change()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'POSTED' THEN
                    RAISE EXCEPTION 'POSTED stock document % is immutable; use reversal/correction', OLD.id;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }
};
