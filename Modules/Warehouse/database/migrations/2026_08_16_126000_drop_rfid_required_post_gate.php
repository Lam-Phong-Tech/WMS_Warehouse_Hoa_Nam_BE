<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SRS v1.12 REQ-SRS-011 + DEC-RFID-FALLBACK-001 + wireframe ALIGNED v3.20:
 * "RFID không phải gate; thiếu RFID vẫn dùng Barcode/QR fallback" và
 * "Post Issue chỉ gate bởi full match + đủ tồn + label PRINTED + label-applied".
 * Trigger hn_erd_guard_outbound_full_post (từ ERD proposal cho SRS v1.6) đang
 * RAISE 'RFID_REQUIRED' khi PRODUCT item matched thiếu RFID ACTIVE — sai baseline
 * hiện hành. Định nghĩa lại function, giữ nguyên các guard full-post/has-lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_outbound_full_post() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'POSTED' AND COALESCE(OLD.status, '') <> 'POSTED' THEN
                    IF EXISTS (
                        SELECT 1 FROM outbound_lines l
                         WHERE l.outbound_document_id = NEW.id
                           AND l.scanned_qty <> l.required_qty
                    ) THEN
                        RAISE EXCEPTION 'FULL_POST_REQUIRED: every outbound line scanned_qty must equal required_qty';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM outbound_lines l WHERE l.outbound_document_id = NEW.id) THEN
                        RAISE EXCEPTION 'FULL_POST_REQUIRED: outbound document has no lines';
                    END IF;
                    -- SRS v1.12: RFID optional — không còn guard RFID_REQUIRED tại đây.
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        // Fail-forward: RFID-optional là baseline SRS v1.12.
    }
};
