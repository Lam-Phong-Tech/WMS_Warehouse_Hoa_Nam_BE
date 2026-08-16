<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IdempotencyStore xoá lease `idempotency_keys` khi request lỗi (contract: "request lỗi
 * không bị cache để client có thể sửa dữ liệu và thử lại"), nhưng mirror
 * hn_erd_mirror_idempotency_records chỉ upsert theo id — row cũ trong
 * `idempotency_records` không bị xoá, retry cùng key tạo id mới và nổ
 * ux (scope, idempotency_key) → 500 + abort transaction (25P02). Đồng bộ cả DELETE:
 * mirror = full projection của idempotency_records__legacy_projection.
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

        $hash = "md5('hoa-nam-legacy-id:' || p.id::text)";
        $uuidExpr = "((substr({$hash}, 1, 8) || '-' || substr({$hash}, 9, 4) || '-' || substr({$hash}, 13, 4) || '-' || substr({$hash}, 17, 4) || '-' || substr({$hash}, 21, 12))::uuid)";

        DB::statement('CREATE OR REPLACE FUNCTION hn_erd_mirror_idempotency_records() RETURNS trigger AS $$ BEGIN
            DELETE FROM idempotency_records r
             WHERE NOT EXISTS (
                SELECT 1 FROM idempotency_records__legacy_projection p
                 WHERE '.$uuidExpr.' = r.id
             );
            INSERT INTO idempotency_records (
                id, scope, idempotency_key, request_hash, response_status, response_body,
                resource_type, resource_id, expires_at, created_at, created_by,
                user_id, operation, business_key, status, response_code, locked_until, completed_at, updated_at
            )
            SELECT '.$uuidExpr.' AS id,
                COALESCE(p.operation, \'LEGACY\') AS scope,
                p.idempotency_key::varchar(200),
                CASE WHEN p.request_hash::text ~ \'^v2:[a-f0-9]{64}\' THEN substring(p.request_hash::text from 4 for 64) ELSE substring(p.request_hash::text from 1 for 64) END::char(64),
                p.response_code::integer,
                p.response_body::jsonb,
                p.operation::varchar(80),
                NULL::uuid,
                p.locked_until,
                p.created_at,
                CASE WHEN p.user_id ~* \'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$\' THEN p.user_id::uuid ELSE NULL END,
                p.user_id, p.operation, p.business_key, p.status, p.response_code, p.locked_until, p.completed_at, p.updated_at
            FROM idempotency_records__legacy_projection p
            ON CONFLICT (id) DO UPDATE SET
                scope = EXCLUDED.scope,
                idempotency_key = EXCLUDED.idempotency_key,
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
        END; $$ LANGUAGE plpgsql');
    }

    public function down(): void
    {
        // Fail-forward signed ERD business constraints.
    }
};
