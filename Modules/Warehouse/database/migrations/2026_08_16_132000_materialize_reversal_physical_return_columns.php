<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BR-REV-06/BR-REV-12 (SRS §5.5): Reverse Issue chỉ cộng tồn khi hàng vật lý đã về
 * (physical_return_status) kèm condition; retry theo idempotency_key. Service ghi 3 cột
 * này qua view `reversal_document`, nhưng view cũ chiếu NULL literal và trigger
 * write-through không map về bảng canonical — mọi Reverse Issue trên PG bị
 * 409 PHYSICAL_RETURN_REQUIRED dù client đã xác nhận. Materialize cột thật trên
 * `reversal_documents`, chiếu qua view và map trong trigger. ERD bảng 33 amend kèm.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('reversal_documents')) {
            return;
        }

        DB::statement('ALTER TABLE reversal_documents ADD COLUMN IF NOT EXISTS condition_status varchar(30)');
        DB::statement('ALTER TABLE reversal_documents ADD COLUMN IF NOT EXISTS physical_return_status varchar(30)');
        DB::statement('ALTER TABLE reversal_documents ADD COLUMN IF NOT EXISTS idempotency_key varchar(200)');
        DB::statement('ALTER TABLE reversal_documents DROP CONSTRAINT IF EXISTS chk_reversal_documents_physical_return_status');
        DB::statement("ALTER TABLE reversal_documents ADD CONSTRAINT chk_reversal_documents_physical_return_status CHECK (physical_return_status IS NULL OR physical_return_status IN ('PENDING_RETURN','CONFIRMED'))");

        DB::statement('DROP VIEW IF EXISTS reversal_document');
        DB::statement(<<<'SQL'
            CREATE VIEW reversal_document AS
            SELECT id AS reversal_document_id, reversal_no AS document_no, original_document_id AS stock_document_id,
                   status, reason, created_by, posted_at, posted_by, version, created_at, updated_at,
                   correction_chain_id, condition_status, idempotency_key,
                   warehouse_id, original_document_type, original_document_id, reason_code, reason_text,
                   physical_return_status, cancelled_at, cancel_reason, updated_by
              FROM reversal_documents
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_reversal_document_write() RETURNS trigger AS $$ BEGIN
            IF TG_OP = 'DELETE' THEN DELETE FROM "reversal_documents" WHERE id=OLD.reversal_document_id; RETURN OLD; END IF;
            INSERT INTO "reversal_documents" ("id", "reversal_no", "original_document_id", "status", "reason", "posted_at", "posted_by", "created_at", "created_by", "updated_at", "updated_by", "version", "warehouse_id", "original_document_type", "correction_chain_id", "reason_code", "reason_text", "cancelled_at", "cancel_reason", "condition_status", "physical_return_status", "idempotency_key")
            VALUES (COALESCE(NEW.reversal_document_id, gen_random_uuid()), NEW.document_no, COALESCE(NEW.original_document_id, NEW.stock_document_id), COALESCE(NEW.status, 'DRAFT'), NEW.reason, NEW.posted_at, NEW.posted_by, COALESCE(NEW.created_at, CURRENT_TIMESTAMP), NEW.created_by, COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), NEW.updated_by, COALESCE(NEW.version, 1), NEW.warehouse_id, NEW.original_document_type, NEW.correction_chain_id, NEW.reason_code, COALESCE(NEW.reason_text, NEW.reason), NEW.cancelled_at, NEW.cancel_reason, NEW.condition_status, NEW.physical_return_status, NEW.idempotency_key)
            ON CONFLICT ("id") DO UPDATE SET "reversal_no"=EXCLUDED."reversal_no", "original_document_id"=EXCLUDED."original_document_id", "status"=EXCLUDED."status", "reason"=EXCLUDED."reason", "posted_at"=EXCLUDED."posted_at", "posted_by"=EXCLUDED."posted_by", "created_at"=EXCLUDED."created_at", "created_by"=EXCLUDED."created_by", "updated_at"=EXCLUDED."updated_at", "updated_by"=EXCLUDED."updated_by", "version"=EXCLUDED."version", "warehouse_id"=EXCLUDED."warehouse_id", "original_document_type"=EXCLUDED."original_document_type", "correction_chain_id"=EXCLUDED."correction_chain_id", "reason_code"=EXCLUDED."reason_code", "reason_text"=EXCLUDED."reason_text", "cancelled_at"=EXCLUDED."cancelled_at", "cancel_reason"=EXCLUDED."cancel_reason", "condition_status"=EXCLUDED."condition_status", "physical_return_status"=EXCLUDED."physical_return_status", "idempotency_key"=EXCLUDED."idempotency_key";
            RETURN NEW; END; $$ LANGUAGE plpgsql
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS trg_hn_legacy_reversal_document_write ON reversal_document');
        DB::statement('CREATE TRIGGER trg_hn_legacy_reversal_document_write INSTEAD OF INSERT OR UPDATE OR DELETE ON reversal_document FOR EACH ROW EXECUTE FUNCTION hn_legacy_reversal_document_write()');
    }

    public function down(): void
    {
        // Fail-forward signed ERD business constraints.
    }
};
