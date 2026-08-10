<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->alignOutboundImportRows();
        $this->alignLabelSnapshots();
        $this->alignPrinterConfigs();
    }

    public function down(): void
    {
        // Fail-forward canonical data projection.
    }

    private function alignOutboundImportRows(): void
    {
        if (! Schema::hasTable('outbound_request_import_rows')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE outbound_request_import_rows
               SET request_no = COALESCE(request_no, normalized_payload::jsonb->>'request_no'),
                   customer_name = COALESCE(customer_name, normalized_payload::jsonb->>'customer_name', normalized_payload::jsonb->>'recipient_name'),
                   customer_address = COALESCE(customer_address, normalized_payload::jsonb->>'customer_address', normalized_payload::jsonb->>'recipient_address'),
                   sku_code = COALESCE(sku_code, normalized_payload::jsonb->>'sku_code'),
                   required_qty = COALESCE(required_qty, NULLIF(normalized_payload::jsonb->>'required_qty', '')::bigint),
                   product_name_raw = COALESCE(product_name_raw, normalized_payload::jsonb->>'product_name', normalized_payload::jsonb->>'product_name_raw'),
                   note = COALESCE(note, normalized_payload::jsonb->>'note'),
                   resolved_sku_id = COALESCE(resolved_sku_id, NULLIF(normalized_payload::jsonb->>'sku_id', '')::uuid),
                   raw_data = COALESCE(raw_data, raw_payload::jsonb),
                   errors = COALESCE(errors, validation_errors::jsonb),
                   validation_status = CASE
                       WHEN validation_status IN ('VALID','ERROR','WARNING') THEN validation_status
                       WHEN validation_status IN ('PENDING','INVALID') THEN 'WARNING'
                       ELSE 'ERROR'
                   END
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_normalize_runtime_outbound_request_import_rows()
            RETURNS trigger AS $$
            BEGIN
                NEW.raw_data := COALESCE(NEW.raw_data, NEW.raw_payload::jsonb);
                NEW.raw_payload := COALESCE(NEW.raw_payload, NEW.raw_data);
                NEW.errors := COALESCE(NEW.errors, NEW.validation_errors::jsonb);
                NEW.validation_errors := COALESCE(NEW.validation_errors, NEW.errors);
                NEW.request_no := COALESCE(NEW.request_no, NEW.normalized_payload::jsonb->>'request_no');
                NEW.customer_name := COALESCE(NEW.customer_name, NEW.normalized_payload::jsonb->>'customer_name', NEW.normalized_payload::jsonb->>'recipient_name');
                NEW.customer_address := COALESCE(NEW.customer_address, NEW.normalized_payload::jsonb->>'customer_address', NEW.normalized_payload::jsonb->>'recipient_address');
                NEW.sku_code := COALESCE(NEW.sku_code, NEW.normalized_payload::jsonb->>'sku_code');
                NEW.required_qty := COALESCE(NEW.required_qty, NULLIF(NEW.normalized_payload::jsonb->>'required_qty', '')::bigint);
                NEW.product_name_raw := COALESCE(NEW.product_name_raw, NEW.normalized_payload::jsonb->>'product_name', NEW.normalized_payload::jsonb->>'product_name_raw');
                NEW.note := COALESCE(NEW.note, NEW.normalized_payload::jsonb->>'note');
                NEW.resolved_sku_id := COALESCE(NEW.resolved_sku_id, NULLIF(NEW.normalized_payload::jsonb->>'sku_id', '')::uuid);
                NEW.validation_status := CASE
                    WHEN NEW.validation_status IN ('VALID','ERROR','WARNING') THEN NEW.validation_status
                    WHEN NEW.validation_status IN ('PENDING','INVALID') THEN 'WARNING'
                    ELSE 'ERROR'
                END;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    private function alignLabelSnapshots(): void
    {
        if (! Schema::hasTable('label_snapshots')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE label_snapshots ls
               SET payload = COALESCE(ls.payload, ls.label_payload::jsonb, '{}'::jsonb),
                   label_code = COALESCE(ls.label_code, ls.label_payload::jsonb->>'label_code', 'LBL-' || ls.id::text),
                   outbound_document_id = COALESCE(ls.outbound_document_id, l.stock_document_id),
                   customer_name = COALESCE(ls.customer_name, d.recipient_name, ls.label_payload::jsonb->>'customer_name'),
                   customer_address = COALESCE(ls.customer_address, d.recipient_address, ls.label_payload::jsonb->>'customer_address'),
                   product_name = COALESCE(ls.product_name, s.sku_name, ls.label_payload::jsonb->>'product_name'),
                   sku_code = COALESCE(ls.sku_code, s.sku_code, ls.label_payload::jsonb->>'sku_code'),
                   item_code_value = COALESCE(
                       ls.item_code_value,
                       (
                           SELECT p.code_value
                             FROM physical_codes p
                            WHERE p.item_id = ls.item_id AND p.status='ACTIVE'
                            ORDER BY p.is_primary DESC NULLS LAST, p.assigned_at DESC NULLS LAST
                            LIMIT 1
                       ),
                       (
                           SELECT ii.item_code
                             FROM item_instances ii
                            WHERE ii.item_id = ls.item_id
                            LIMIT 1
                       ),
                       ls.label_payload::jsonb->>'item_code_value'
                   ),
                   outbound_no = COALESCE(ls.outbound_no, d.doc_no, ls.label_payload::jsonb->>'outbound_no'),
                   template_version = COALESCE(ls.template_version, ls.label_payload::jsonb->>'template_version', 'v1')
              FROM outbound_item_matches m
              JOIN stock_document_lines l ON l.id = m.outbound_line_id
              JOIN stock_documents d ON d.id = l.stock_document_id
              JOIN sku s ON s.sku_id = l.product_id
             WHERE m.id = ls.outbound_item_match_id
        SQL);
    }

    private function alignPrinterConfigs(): void
    {
        if (! Schema::hasTable('printer_configs')) {
            return;
        }

        $this->installPrinterConfigNormalizer();

        $printerNameFallback = Schema::hasColumn('printer_configs', 'name')
            ? 'name, printer_code'
            : 'printer_code';
        $agentEndpoint = Schema::hasColumn('printer_configs', 'agent_code')
            ? 'COALESCE(agent_endpoint, agent_code)'
            : 'agent_endpoint';
        $labelWidth = Schema::hasColumn('printer_configs', 'label_size')
            ? "COALESCE(label_width_mm, CASE WHEN label_size ~ '^[0-9]+(\\.[0-9]+)?[xX][0-9]+(\\.[0-9]+)?$' THEN split_part(lower(label_size), 'x', 1)::numeric END)"
            : 'label_width_mm';
        $labelHeight = Schema::hasColumn('printer_configs', 'label_size')
            ? "COALESCE(label_height_mm, CASE WHEN label_size ~ '^[0-9]+(\\.[0-9]+)?[xX][0-9]+(\\.[0-9]+)?$' THEN split_part(lower(label_size), 'x', 2)::numeric END)"
            : 'label_height_mm';

        DB::statement(sprintf(
            'UPDATE printer_configs SET printer_name = COALESCE(printer_name, %s), agent_endpoint = %s, dpi = COALESCE(dpi, 203), label_width_mm = %s, label_height_mm = %s',
            $printerNameFallback,
            $agentEndpoint,
            $labelWidth,
            $labelHeight,
        ));
    }

    private function installPrinterConfigNormalizer(): void
    {
        $body = [
            'NEW.printer_name := COALESCE(NEW.printer_name, NEW.printer_code);',
            'NEW.dpi := COALESCE(NEW.dpi, 203);',
        ];

        if (Schema::hasColumn('printer_configs', 'agent_code')) {
            $body[] = 'NEW.agent_endpoint := COALESCE(NEW.agent_endpoint, NEW.agent_code);';
            $body[] = 'NEW.agent_code := COALESCE(NEW.agent_code, NEW.agent_endpoint);';
        }

        if (Schema::hasColumn('printer_configs', 'label_size')) {
            $body[] = <<<'SQL'
                IF NEW.label_width_mm IS NULL OR NEW.label_height_mm IS NULL THEN
                    IF NEW.label_size ~ '^[0-9]+(\.[0-9]+)?[xX][0-9]+(\.[0-9]+)?$' THEN
                        NEW.label_width_mm := COALESCE(NEW.label_width_mm, split_part(lower(NEW.label_size), 'x', 1)::numeric);
                        NEW.label_height_mm := COALESCE(NEW.label_height_mm, split_part(lower(NEW.label_size), 'x', 2)::numeric);
                    END IF;
                END IF;
            SQL;
        }

        DB::statement('CREATE OR REPLACE FUNCTION hn_erd_normalize_runtime_printer_configs() RETURNS trigger AS $$ BEGIN '.implode("\n", $body).' RETURN NEW; END; $$ LANGUAGE plpgsql');
        DB::statement('DROP TRIGGER IF EXISTS trg_erd_normalize_runtime_printer_configs ON printer_configs');
        DB::statement('CREATE TRIGGER trg_erd_normalize_runtime_printer_configs BEFORE INSERT OR UPDATE ON printer_configs FOR EACH ROW EXECUTE FUNCTION hn_erd_normalize_runtime_printer_configs()');
    }
};
