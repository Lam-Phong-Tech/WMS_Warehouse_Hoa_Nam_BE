<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Yêu cầu vận hành: "Audit log tổng nên chia theo module/luồng: hệ thống & danh
 * mục, nhập kho, xuất kho, bảo hành, kiểm kê/đối soát" + hiển thị được actor
 * qua quan hệ (không cần IP riêng nhưng UI cần audit đăng nhập/bảo mật nên vẫn
 * bổ sung `ip_address`). `module` do AuditLogger tự phân loại từ object_type
 * (và fallback theo class gọi) tại thời điểm ghi — KHÔNG cần sửa 130+ call site
 * `AuditLogger::record()` hiện có (xem AuditLogger::classifyModule()).
 *
 * Additive, cùng quy ước dual-driver với 2026_08_19_000001/000002: sqlite —
 * `audit_log` là bảng vật lý thật, ALTER TABLE trực tiếp. PostgreSQL —
 * `audit_log` chỉ là VIEW tương thích ngược trên bảng vật lý `audit_logs`; phải
 * ALTER bảng vật lý rồi dựng lại view + trigger `INSTEAD OF INSERT` để đi qua
 * đủ cột (nếu không, đúng bug 42703 đã ghi lại ở 2026_08_19_000002 sẽ tái diễn
 * cho 2 cột mới).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            if (Schema::hasTable('audit_log') && ! Schema::hasColumn('audit_log', 'module')) {
                Schema::table('audit_log', function (Blueprint $table) {
                    $table->string('module', 20)->nullable()->after('object_id');
                    $table->string('ip_address', 64)->nullable()->after('request_id');
                });
            }

            return;
        }

        $alreadyDone = DB::selectOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_logs' AND column_name = 'module'"
        );
        if ($alreadyDone) {
            return;
        }

        $auditLogsIsTable = DB::selectOne(
            "SELECT 1 FROM pg_class WHERE relname = 'audit_logs' AND relkind IN ('r', 'p')"
        );
        if (! $auditLogsIsTable) {
            return;
        }

        DB::statement('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS module varchar(20)');
        DB::statement('ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS ip_address varchar(64)');

        $viewExists = DB::selectOne(
            "SELECT 1 FROM pg_class WHERE relname = 'audit_log' AND relkind = 'v'"
        );
        if (! $viewExists) {
            // Chuỗi migration trước (2026_08_19_000002) chưa dựng view trên môi
            // trường này — không có view/trigger nào để nối thêm cột.
            return;
        }

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
                   idempotency_key, result, error_code, module, ip_address,
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
                    idempotency_key, error_code, module, ip_address,
                    before_data, after_data, metadata, created_at
                )
                VALUES (
                    NEW.request_id, NEW.actor_id, NEW.action, COALESCE(NEW.object_type, 'LEGACY'),
                    CASE WHEN NEW.object_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
                         THEN NEW.object_id::uuid ELSE NULL END,
                    CASE WHEN NEW.result = 'FAILURE' THEN 'FAILED' ELSE COALESCE(NEW.result, 'SUCCESS') END,
                    NEW.idempotency_key, NEW.error_code, NEW.module, NEW.ip_address,
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

        // Backfill best-effort cho dữ liệu lịch sử — cùng bảng phân loại với
        // AuditLogger::classifyModule() (xem PHP) để danh sách audit log cũ vẫn
        // lọc được theo module thay vì rơi hết vào NULL sau khi UI thêm bộ lọc.
        DB::statement(<<<'SQL'
            UPDATE audit_logs SET module = CASE
                WHEN entity_type ILIKE '%warranty%' OR entity_type ILIKE '%component_issue%' THEN 'WARRANTY'
                WHEN entity_type ILIKE '%reversal%' OR entity_type ILIKE '%stocktake%' OR entity_type ILIKE '%variance%' OR entity_type ILIKE '%reconciliation%' THEN 'STOCKTAKE'
                WHEN entity_type ILIKE '%inbound%' OR entity_type ILIKE '%putaway%' OR entity_type ILIKE '%receipt%' THEN 'INBOUND'
                WHEN entity_type ILIKE '%outbound%' OR entity_type ILIKE '%stock_request%' THEN 'OUTBOUND'
                ELSE 'SYSTEM'
            END
            WHERE module IS NULL
        SQL);
    }

    public function down(): void
    {
        // Fail-forward, cùng quy ước với các migration dựng/sửa view audit_log
        // trước đó (2026_08_10_000790, 2026_08_19_000002/150000).
    }
};
