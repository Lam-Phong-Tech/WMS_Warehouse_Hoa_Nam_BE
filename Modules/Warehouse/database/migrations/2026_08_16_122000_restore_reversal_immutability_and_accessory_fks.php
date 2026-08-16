<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Khôi phục hai guard DB-level bị mất khi retire bảng legacy (000790/000791):
 *
 * 1. BR-REV-08 (SRS v1.12 §5.5): Reversal POSTED là immutable. Trigger
 *    trg_reversal_document_posted_immutable trước đây nằm trên bảng vật lý
 *    reversal_document (số ít); bảng đó nay là view nên trigger biến mất.
 *    Tạo lại guard trên bảng canonical reversal_documents.
 *
 * 2. ERD bảng 18: sku_compatible_accessories.sku_id/accessory_sku_id FK skus.id.
 *    FK cũ trỏ bảng sku vật lý đã bị DROP CASCADE cuốn mất. Tạo lại về skus(id),
 *    ON DELETE RESTRICT theo ERD §5 (không cascade delete master data).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_reject_posted_reversal_documents_change()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'POSTED' THEN
                    RAISE EXCEPTION 'POSTED reversal_documents is immutable; create a new correction chain';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_reversal_document_posted_immutable ON reversal_documents');
        DB::statement('CREATE TRIGGER trg_reversal_document_posted_immutable BEFORE UPDATE OR DELETE ON reversal_documents FOR EACH ROW EXECUTE FUNCTION hn_reject_posted_reversal_documents_change()');

        DB::statement('ALTER TABLE sku_compatible_accessories DROP CONSTRAINT IF EXISTS fk_sku_accessories_sku');
        DB::statement('ALTER TABLE sku_compatible_accessories ADD CONSTRAINT fk_sku_accessories_sku FOREIGN KEY (sku_id) REFERENCES skus(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE sku_compatible_accessories DROP CONSTRAINT IF EXISTS fk_sku_accessories_accessory');
        DB::statement('ALTER TABLE sku_compatible_accessories ADD CONSTRAINT fk_sku_accessories_accessory FOREIGN KEY (accessory_sku_id) REFERENCES skus(id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        // Fail-forward: các guard này là baseline BR-REV-08 / ERD bảng 18.
    }
};
