<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WWHN-222 R2 — ERD §7 quy định `audit_logs.result varchar(30) NOT NULL CHECK
 * SUCCESS/FAILED/DENIED`, nhưng bảng vật lý trên PostgreSQL chưa từng có CHECK
 * này và code Auth ghi giá trị `FAILURE` ngoài enum. Drift doc-vs-code đúng
 * loại đã gây P1 lặp lại ở các flow audit trước.
 *
 * Bản vá (chỉ pgsql — sqlite là DB dev, `audit_log.result` nullable theo
 * migration 2026_08_19_000001, thêm CHECK đòi rebuild bảng, không đáng rủi ro):
 *
 * 1. Trigger `hn_legacy_audit_log_write` (view `audit_log`) chuẩn hoá giá trị
 *    legacy `FAILURE` -> `FAILED` tại điểm ghi, để payload cũ còn nằm trong
 *    `audit_outbox` (ghi trước bản vá này) vẫn replay được qua `audit:reconcile`
 *    sau khi CHECK có hiệu lực.
 * 2. `ADD CONSTRAINT ... NOT VALID`: enforce enum ERD cho bản ghi MỚI, không
 *    validate bản ghi lịch sử — audit_logs là append-only (trigger
 *    `trg_audit_logs_append_only` chặn UPDATE/DELETE) nên không được phép và
 *    không cần sửa dòng cũ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $auditLogsIsTable = DB::selectOne(
            "SELECT 1 FROM pg_class WHERE relname = 'audit_logs' AND relkind IN ('r', 'p')"
        );
        $viewTriggerExists = DB::selectOne(
            "SELECT 1 FROM pg_trigger WHERE tgname = 'trg_hn_legacy_audit_log_write'"
        );
        if (! $auditLogsIsTable || ! $viewTriggerExists) {
            // Chuỗi migration chưa dựng view+trigger (2026_08_19_000002) thì
            // không có gì để chuẩn hoá — giữ no-op thay vì đoán trạng thái.
            return;
        }

        // Cùng thân hàm với 2026_08_19_000002, chỉ đổi biểu thức result:
        // FAILURE (legacy, ngoài enum ERD) được chuẩn hoá thành FAILED.
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
                    CASE WHEN NEW.result = 'FAILURE' THEN 'FAILED' ELSE COALESCE(NEW.result, 'SUCCESS') END,
                    NEW.idempotency_key, NEW.error_code,
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

        $checkExists = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = 'chk_erd_audit_logs_result'"
        );
        if (! $checkExists) {
            DB::statement(
                "ALTER TABLE audit_logs ADD CONSTRAINT chk_erd_audit_logs_result "
                ."CHECK (result IN ('SUCCESS', 'FAILED', 'DENIED')) NOT VALID"
            );
        }
    }

    public function down(): void
    {
        // Fail-forward, cùng quy ước với 2026_08_19_000002 và migration gốc
        // dựng view (2026_08_10_000790): down() không hạ trigger/constraint
        // để tránh brick DB phụ thuộc (tiền lệ WWHN-300).
    }
};
