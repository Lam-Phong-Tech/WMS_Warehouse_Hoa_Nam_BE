<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production-safe repair for the legacy idempotency_keys -> idempotency_records
 * statement mirror.
 *
 * The canonical ERD key is UNIQUE(scope, idempotency_key), while the runtime
 * source is unique by (idem_key, operation, user_id). Therefore more than one
 * source row may legitimately project to the same canonical key. The previous
 * mirror upserted only by id and could fail with SQLSTATE 23505 on the canonical
 * scope/key unique index (for example stock.receipt.confirm / seed-idem-001).
 *
 * Keep the canonical row identity stable, collapse duplicate source projections
 * deterministically to the newest row, and upsert by the documented logical key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! Schema::hasTable('idempotency_records')
            || ! Schema::hasTable('idempotency_keys')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_mirror_idempotency_records()
            RETURNS trigger AS $$
            BEGIN
                DELETE FROM idempotency_records AS r
                 WHERE NOT EXISTS (
                    SELECT 1
                      FROM idempotency_records__legacy_projection AS p
                     WHERE COALESCE(p.operation, 'LEGACY')::varchar(80) = r.scope
                       AND p.idempotency_key::varchar(200) = r.idempotency_key
                 );

                INSERT INTO idempotency_records (
                    id, scope, idempotency_key, request_hash, response_status, response_body,
                    resource_type, resource_id, expires_at, created_at, created_by,
                    user_id, operation, business_key, status, response_code, locked_until, completed_at, updated_at
                )
                SELECT
                    projected.id,
                    projected.scope,
                    projected.idempotency_key,
                    projected.request_hash,
                    projected.response_status,
                    projected.response_body,
                    projected.resource_type,
                    projected.resource_id,
                    projected.expires_at,
                    projected.created_at,
                    projected.created_by,
                    projected.user_id,
                    projected.operation,
                    projected.business_key,
                    projected.status,
                    projected.response_code,
                    projected.locked_until,
                    projected.completed_at,
                    projected.updated_at
                  FROM (
                    SELECT DISTINCT ON (
                        COALESCE(p.operation, 'LEGACY')::varchar(80),
                        p.idempotency_key::varchar(200)
                    )
                        (
                            substr(md5('hoa-nam-legacy-id:' || p.id::text), 1, 8) || '-' ||
                            substr(md5('hoa-nam-legacy-id:' || p.id::text), 9, 4) || '-' ||
                            substr(md5('hoa-nam-legacy-id:' || p.id::text), 13, 4) || '-' ||
                            substr(md5('hoa-nam-legacy-id:' || p.id::text), 17, 4) || '-' ||
                            substr(md5('hoa-nam-legacy-id:' || p.id::text), 21, 12)
                        )::uuid AS id,
                        COALESCE(p.operation, 'LEGACY')::varchar(80) AS scope,
                        p.idempotency_key::varchar(200) AS idempotency_key,
                        CASE
                            WHEN p.request_hash::text ~ '^v2:[a-f0-9]{64}'
                                THEN substring(p.request_hash::text from 4 for 64)
                            ELSE substring(p.request_hash::text from 1 for 64)
                        END::char(64) AS request_hash,
                        p.response_code::integer AS response_status,
                        p.response_body::jsonb AS response_body,
                        p.operation::varchar(80) AS resource_type,
                        NULL::uuid AS resource_id,
                        p.locked_until AS expires_at,
                        p.created_at,
                        CASE
                            WHEN p.user_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                                THEN p.user_id::uuid
                            ELSE NULL
                        END AS created_by,
                        p.user_id,
                        p.operation,
                        p.business_key,
                        p.status,
                        p.response_code,
                        p.locked_until,
                        p.completed_at,
                        p.updated_at
                      FROM idempotency_records__legacy_projection AS p
                     ORDER BY
                        COALESCE(p.operation, 'LEGACY')::varchar(80),
                        p.idempotency_key::varchar(200),
                        p.updated_at DESC NULLS LAST,
                        p.id DESC
                  ) AS projected
                ON CONFLICT (scope, idempotency_key) DO UPDATE SET
                    request_hash = EXCLUDED.request_hash,
                    response_status = EXCLUDED.response_status,
                    response_body = EXCLUDED.response_body,
                    resource_type = EXCLUDED.resource_type,
                    expires_at = EXCLUDED.expires_at,
                    created_by = EXCLUDED.created_by,
                    user_id = EXCLUDED.user_id,
                    operation = EXCLUDED.operation,
                    business_key = EXCLUDED.business_key,
                    status = EXCLUDED.status,
                    response_code = EXCLUDED.response_code,
                    locked_until = EXCLUDED.locked_until,
                    completed_at = EXCLUDED.completed_at,
                    updated_at = EXCLUDED.updated_at;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS trg_erd_mirror_idempotency_records_from_idempotency_keys ON idempotency_keys');
        DB::statement(
            'CREATE TRIGGER trg_erd_mirror_idempotency_records_from_idempotency_keys '.
            'AFTER INSERT OR UPDATE OR DELETE ON idempotency_keys '.
            'FOR EACH STATEMENT EXECUTE FUNCTION hn_erd_mirror_idempotency_records()'
        );
    }

    public function down(): void
    {
        // Fail-forward repair: restoring the id-only upsert reintroduces a known
        // production seed failure against UNIQUE(scope, idempotency_key).
    }
};
