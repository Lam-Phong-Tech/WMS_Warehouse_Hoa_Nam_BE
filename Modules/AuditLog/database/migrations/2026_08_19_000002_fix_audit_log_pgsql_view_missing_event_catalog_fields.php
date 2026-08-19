<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DEV184-P1-03R3 (QA1 retest độc lập) — `2026_08_19_000001_add_event_catalog_fields_to_audit_log`
 * dùng `Schema::hasTable('audit_log')`/`Schema::table('audit_log', ...)` để thêm
 * `result`/`error_code`/`idempotency_key`. Trên sqlite `audit_log` là bảng vật lý nên chạy
 * đúng; trên PostgreSQL `audit_log` chỉ là VIEW tương thích ngược (bảng vật lý thật là
 * `audit_logs`, tạo ở `2026_08_10_000790_retire_legacy_runtime_tables.php`) — Laravin's
 * Postgres grammar không coi VIEW là "table" nên `hasTable()` trả về false và migration
 * kia lặng lẽ no-op. Hệ quả: mọi lời gọi `AuditLogger::record()` trên PostgreSQL insert
 * `idempotency_key`/`result`/`error_code` vào view -> `42703 undefined column` -> bị nuốt
 * bởi policy mặc định `outbox_required` (rơi vào `audit_outbox`, không throw) -> TOÀN BỘ
 * audit_log trên PostgreSQL rỗng cho mọi action, không riêng WWHN-184 (phát hiện khi chạy
 * lại `PostReceiptAuditLogTest` trên PostgreSQL cho checklist QA1 mục 5.1).
 *
 * Bản vá: thêm 2 cột còn thiếu (`result` đã có sẵn trên `audit_logs`) vào bảng vật lý,
 * dựng lại view + trigger `INSTEAD OF INSERT` để đi qua đủ 3 cột, và sửa luôn lỗi liền kề
 * — trigger cũ hard-code `result = 'SUCCESS'`, bỏ qua giá trị `result` thật ứng dụng gửi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $alreadyFixed = DB::selectOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_log' AND column_name = 'idempotency_key'"
        );
        if ($alreadyFixed) {
            return;
        }

        $auditLogsIsTable = DB::selectOne(
            "SELECT 1 FROM pg_class WHERE relname = 'audit_logs' AND relkind IN ('r', 'p')"
        );
        if (! $auditLogsIsTable) {
            return;
        }

        DB::statement('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS idempotency_key varchar(100)');
        DB::statement('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS error_code varchar(64)');

        DB::statement('DROP VIEW IF EXISTS audit_log CASCADE');
        DB::statement(<<<'SQL'
            CREATE VIEW audit_log AS
            SELECT COALESCE(
                       NULLIF(metadata->>'legacy_id', '')::uuid,
                       ((substr(md5('audit_log:' || id::text), 1, 8) || '-' ||
                         substr(md5('audit_log:' || id::text), 9, 4) || '-' ||
                         substr(md5('audit_log:' || id::text), 13, 4) || '-' ||
                         substr(md5('audit_log:' || id::text), 17, 4) || '-' ||
                         substr(md5('audit_log:' || id::text), 21, 12))::uuid)
                   ) AS id,
                   actor_id, action, entity_type AS object_type,
                   COALESCE(metadata->>'legacy_object_id', entity_id::text) AS object_id,
                   metadata->>'legacy_reason' AS reason, request_id,
                   idempotency_key, result, error_code,
                   before_data, after_data, created_at
              FROM audit_logs
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_audit_log_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'audit_log compatibility view is append-only';
                END IF;
                INSERT INTO audit_logs (
                    request_id, actor_id, action, entity_type, entity_id, result,
                    idempotency_key, error_code,
                    before_data, after_data, metadata, created_at
                )
                VALUES (
                    NEW.request_id, NEW.actor_id, NEW.action, COALESCE(NEW.object_type, 'LEGACY'),
                    CASE WHEN NEW.object_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
                         THEN NEW.object_id::uuid ELSE NULL END,
                    COALESCE(NEW.result, 'SUCCESS'), NEW.idempotency_key, NEW.error_code,
                    NEW.before_data::jsonb, NEW.after_data::jsonb,
                    jsonb_strip_nulls(jsonb_build_object(
                        'legacy_id', COALESCE(NEW.id::text, gen_random_uuid()::text),
                        'legacy_object_id', NEW.object_id,
                        'legacy_reason', NEW.reason
                    )),
                    COALESCE(NEW.created_at, CURRENT_TIMESTAMP)
                );
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('CREATE TRIGGER trg_hn_legacy_audit_log_write INSTEAD OF INSERT ON audit_log FOR EACH ROW EXECUTE FUNCTION hn_legacy_audit_log_write()');
    }

    public function down(): void
    {
        // Fail-forward, cùng quy ước với migration gốc dựng view này
        // (2026_08_10_000790_retire_legacy_runtime_tables.php::createAuditView).
    }
};
