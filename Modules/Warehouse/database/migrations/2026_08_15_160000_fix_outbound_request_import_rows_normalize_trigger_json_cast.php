<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix: hn_erd_normalize_runtime_outbound_request_import_rows() (created by
 * 2026_08_10_000766_backfill_structured_erd_payload_columns) reassigns
 * NEW.raw_payload := COALESCE(NEW.raw_payload, NEW.raw_data) and
 * NEW.validation_errors := COALESCE(NEW.validation_errors, NEW.errors).
 * raw_payload/validation_errors are `json` columns while raw_data/errors are
 * `jsonb` columns; Postgres has no implicit json/jsonb cast inside COALESCE,
 * so both assignments fail to compile at INSERT time regardless of the
 * row's actual values (SQLSTATE 42846 "Cannot coerce: could not convert type
 * jsonb to json").
 *
 * Effect: every INSERT into outbound_request_import_rows fails, breaking
 * POST /api/v1/outbound-request-imports (and therefore the whole outbound
 * import -> consume -> outbound-document flow) for every caller, not just
 * seed data.
 *
 * Fix: recreate the trigger function identically, only adding the missing
 * ::json casts on the two reassignments above. Same pattern as
 * 2026_08_15_150000_fix_label_snapshots_normalize_trigger_payload_column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('outbound_request_import_rows')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_normalize_runtime_outbound_request_import_rows()
            RETURNS trigger AS $$
            BEGIN
                NEW.raw_data := COALESCE(NEW.raw_data, NEW.raw_payload::jsonb);
                NEW.raw_payload := COALESCE(NEW.raw_payload, NEW.raw_data::json);
                NEW.errors := COALESCE(NEW.errors, NEW.validation_errors::jsonb);
                NEW.validation_errors := COALESCE(NEW.validation_errors, NEW.errors::json);
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

    public function down(): void
    {
        // Fail-forward: restoring the broken (missing cast) body would
        // reintroduce the outage this migration fixes.
    }
};
