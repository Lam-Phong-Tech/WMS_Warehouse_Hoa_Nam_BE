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

        $this->containerGuards();
        $this->putawayGuards();
        $this->warrantyGuards();
        $this->postingGuards();
        $this->componentIssueGuards();
        $this->labelSnapshotGuards();
    }

    public function down(): void
    {
        // Fail-forward signed ERD business invariants.
    }

    private function containerGuards(): void
    {
        DB::statement('ALTER TABLE container_items DROP CONSTRAINT IF EXISTS chk_erd_container_item_shape');
        DB::statement(<<<'SQL'
            ALTER TABLE container_items
            ADD CONSTRAINT chk_erd_container_item_shape CHECK (
                (item_id IS NOT NULL AND sku_id IS NULL AND quantity IS NULL)
                OR
                (item_id IS NULL AND sku_id IS NOT NULL AND quantity > 0)
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ux_erd_container_active_item ON container_items(item_id) WHERE is_active=TRUE AND item_id IS NOT NULL');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_container_hierarchy()
            RETURNS trigger AS $$
            DECLARE
                parent_type varchar(20);
                cycle_found boolean;
            BEGIN
                IF NEW.parent_container_id IS NULL THEN
                    RETURN NEW;
                END IF;
                IF NEW.parent_container_id = NEW.id THEN
                    RAISE EXCEPTION 'CONTAINER_CYCLE: container cannot parent itself';
                END IF;
                IF NEW.container_type <> 'BOX' THEN
                    RAISE EXCEPTION 'PUTAWAY_BOX_REQUIRED: only BOX may have a TRAY parent in MVP';
                END IF;

                SELECT container_type INTO parent_type FROM containers WHERE id=NEW.parent_container_id;
                IF parent_type IS DISTINCT FROM 'TRAY' THEN
                    RAISE EXCEPTION 'PUTAWAY_TRAY_REQUIRED: BOX parent must be a TRAY';
                END IF;

                WITH RECURSIVE ancestry AS (
                    SELECT id, parent_container_id FROM containers WHERE id=NEW.parent_container_id
                    UNION ALL
                    SELECT c.id, c.parent_container_id
                      FROM containers c
                      JOIN ancestry a ON c.id=a.parent_container_id
                )
                SELECT EXISTS(SELECT 1 FROM ancestry WHERE id=NEW.id) INTO cycle_found;
                IF cycle_found THEN
                    RAISE EXCEPTION 'CONTAINER_CYCLE: parent chain would create a cycle';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_container_hierarchy ON containers');
        DB::statement('CREATE TRIGGER trg_erd_container_hierarchy BEFORE INSERT OR UPDATE OF parent_container_id, container_type ON containers FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_container_hierarchy()');
    }

    private function putawayGuards(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_putaway_types()
            RETURNS trigger AS $$
            DECLARE
                box_type varchar(20);
                to_type varchar(20);
                from_type varchar(20);
            BEGIN
                SELECT container_type INTO box_type FROM containers WHERE id=NEW.box_container_id;
                SELECT container_type INTO to_type FROM containers WHERE id=NEW.to_tray_id;
                IF NEW.from_tray_id IS NOT NULL THEN
                    SELECT container_type INTO from_type FROM containers WHERE id=NEW.from_tray_id;
                END IF;

                IF box_type IS DISTINCT FROM 'BOX' THEN
                    RAISE EXCEPTION 'PUTAWAY_BOX_REQUIRED: box_container_id must reference BOX';
                END IF;
                IF to_type IS DISTINCT FROM 'TRAY' THEN
                    RAISE EXCEPTION 'PUTAWAY_TRAY_REQUIRED: to_tray_id must reference TRAY';
                END IF;
                IF NEW.from_tray_id IS NOT NULL AND from_type IS DISTINCT FROM 'TRAY' THEN
                    RAISE EXCEPTION 'PUTAWAY_TRAY_REQUIRED: from_tray_id must reference TRAY';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_putaway_types ON putaway_events');
        DB::statement('CREATE TRIGGER trg_erd_putaway_types BEFORE INSERT OR UPDATE OF box_container_id, from_tray_id, to_tray_id ON putaway_events FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_putaway_types()');
    }

    private function warrantyGuards(): void
    {
        DB::statement('ALTER TABLE warranty_cases DROP CONSTRAINT IF EXISTS chk_erd_warranty_purchase_date');
        DB::statement('ALTER TABLE warranty_cases ADD CONSTRAINT chk_erd_warranty_purchase_date CHECK (purchase_date IS NULL OR purchase_date <= CURRENT_DATE)');
        DB::statement('ALTER TABLE warranty_cases DROP CONSTRAINT IF EXISTS chk_erd_warranty_identification_shape');
        DB::statement(<<<'SQL'
            ALTER TABLE warranty_cases
            ADD CONSTRAINT chk_erd_warranty_identification_shape CHECK (
                (identification_status='IDENTIFIED' AND item_id IS NOT NULL)
                OR
                (
                    identification_status='TEMP_ONLY'
                    AND item_id IS NULL
                    AND temporary_item_code IS NOT NULL
                    AND missing_code_reason IS NOT NULL
                    AND manual_product_description IS NOT NULL
                )
            )
        SQL);
    }

    private function postingGuards(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_inbound_full_post()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.status='POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
                    IF EXISTS (
                        SELECT 1 FROM inbound_lines l
                         WHERE l.inbound_document_id=NEW.id
                           AND l.scanned_qty <> l.expected_qty
                    ) THEN
                        RAISE EXCEPTION 'FULL_POST_REQUIRED: every inbound line scanned_qty must equal expected_qty';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM inbound_lines l WHERE l.inbound_document_id=NEW.id) THEN
                        RAISE EXCEPTION 'FULL_POST_REQUIRED: inbound document has no lines';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_inbound_full_post ON inbound_documents');
        DB::statement('CREATE TRIGGER trg_erd_inbound_full_post BEFORE UPDATE OF status ON inbound_documents FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_inbound_full_post()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_outbound_full_post()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.status='POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
                    IF EXISTS (
                        SELECT 1 FROM outbound_lines l
                         WHERE l.outbound_document_id=NEW.id
                           AND l.scanned_qty <> l.required_qty
                    ) THEN
                        RAISE EXCEPTION 'FULL_POST_REQUIRED: every outbound line scanned_qty must equal required_qty';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM outbound_lines l WHERE l.outbound_document_id=NEW.id) THEN
                        RAISE EXCEPTION 'FULL_POST_REQUIRED: outbound document has no lines';
                    END IF;
                    IF EXISTS (
                        SELECT 1
                          FROM outbound_item_matches m
                          JOIN outbound_lines l ON l.id=m.outbound_line_id
                          JOIN skus s ON s.id=l.sku_id
                         WHERE l.outbound_document_id=NEW.id
                           AND m.status IN ('ACTIVE','POSTED')
                           AND s.sku_type='PRODUCT'
                           AND m.item_id IS NOT NULL
                           AND NOT EXISTS (
                               SELECT 1 FROM physical_codes pc
                                WHERE pc.item_id=m.item_id
                                  AND pc.carrier_type='RFID'
                                  AND pc.hardware_uid IS NOT NULL
                                  AND pc.status='ACTIVE'
                           )
                    ) THEN
                        RAISE EXCEPTION 'RFID_REQUIRED: every matched PRODUCT item requires active RFID/hardware_uid';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_outbound_full_post ON outbound_documents');
        DB::statement('CREATE TRIGGER trg_erd_outbound_full_post BEFORE UPDATE OF status ON outbound_documents FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_outbound_full_post()');
    }

    private function componentIssueGuards(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_component_issue_line()
            RETURNS trigger AS $$
            DECLARE
                type_value varchar(20);
            BEGIN
                SELECT sku_type INTO type_value FROM skus WHERE id=NEW.sku_id;
                IF type_value IS DISTINCT FROM 'COMPONENT' THEN
                    RAISE EXCEPTION 'COMPONENT_SKU_REQUIRED: component issue lines may reference COMPONENT only';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_component_issue_line_sku ON component_issue_lines');
        DB::statement('CREATE TRIGGER trg_erd_component_issue_line_sku BEFORE INSERT OR UPDATE OF sku_id ON component_issue_lines FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_component_issue_line()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_guard_component_issue_post()
            RETURNS trigger AS $$
            DECLARE
                warranty_status varchar(20);
            BEGIN
                IF NEW.purpose <> 'WARRANTY' THEN
                    RAISE EXCEPTION 'WARRANTY_PURPOSE_REQUIRED: component issue purpose must be WARRANTY';
                END IF;

                SELECT status INTO warranty_status FROM warranty_cases WHERE id=NEW.warranty_case_id;
                IF warranty_status IS NULL OR warranty_status IN ('RETURNED','CANCELLED') THEN
                    RAISE EXCEPTION 'ACTIVE_WARRANTY_REQUIRED: component issue requires an active warranty case';
                END IF;

                IF NEW.status='POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
                    IF NOT EXISTS (SELECT 1 FROM component_issue_lines l WHERE l.component_issue_document_id=NEW.id) THEN
                        RAISE EXCEPTION 'FULL_POST_REQUIRED: component issue has no lines';
                    END IF;
                    IF EXISTS (
                        SELECT 1 FROM component_issue_lines l
                         WHERE l.component_issue_document_id=NEW.id
                           AND l.scanned_qty <> l.required_qty
                    ) THEN
                        RAISE EXCEPTION 'FULL_POST_REQUIRED: component issue scanned_qty must equal required_qty';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_component_issue_warranty ON component_issue_documents');
        DB::statement('CREATE TRIGGER trg_erd_component_issue_warranty BEFORE INSERT OR UPDATE OF warranty_case_id, purpose, status ON component_issue_documents FOR EACH ROW EXECUTE FUNCTION hn_erd_guard_component_issue_post()');
    }

    private function labelSnapshotGuards(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_lock_label_snapshot_payload()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.label_code IS DISTINCT FROM NEW.label_code
                   OR OLD.outbound_item_match_id IS DISTINCT FROM NEW.outbound_item_match_id
                   OR OLD.outbound_document_id IS DISTINCT FROM NEW.outbound_document_id
                   OR OLD.customer_name IS DISTINCT FROM NEW.customer_name
                   OR OLD.customer_address IS DISTINCT FROM NEW.customer_address
                   OR OLD.product_name IS DISTINCT FROM NEW.product_name
                   OR OLD.sku_code IS DISTINCT FROM NEW.sku_code
                   OR OLD.item_code_value IS DISTINCT FROM NEW.item_code_value
                   OR OLD.outbound_no IS DISTINCT FROM NEW.outbound_no
                   OR OLD.template_version IS DISTINCT FROM NEW.template_version
                   OR OLD.payload IS DISTINCT FROM NEW.payload
                   OR OLD.sku_id IS DISTINCT FROM NEW.sku_id
                   OR OLD.item_id IS DISTINCT FROM NEW.item_id THEN
                    RAISE EXCEPTION 'LABEL_SNAPSHOT_IMMUTABLE: business payload cannot be changed after capture';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('DROP TRIGGER IF EXISTS trg_label_snapshot_payload_immutable ON label_snapshots');
        DB::statement('CREATE TRIGGER trg_label_snapshot_payload_immutable BEFORE UPDATE ON label_snapshots FOR EACH ROW EXECUTE FUNCTION hn_erd_lock_label_snapshot_payload()');
    }
};
