<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WWHN-219 evidence bug: `hn_erd_normalize_canonical_posting_events()` (định nghĩa ở
 * 2026_08_10_000765_keep_legacy_runtime_and_erd_physical_schema_in_sync.php) derive
 * `posting_action` bằng `COALESCE(NEW.posting_action, NEW.operation, 'POST')`.
 *
 * `operation` (posting_event.operation, varchar(160), business key ghép idempotency)
 * luôn có dạng "<verb>_<document/reversal id UUID 36 ký tự>", ví dụ:
 *   - post_receipt_<uuid>          (13+36=49, vừa vặn varchar(50))
 *   - post_issue_<uuid>            (11+36=47, vừa vặn)
 *   - reverse_stock_document_<uuid> (23+36=59, VƯỢT varchar(50))
 *   - post_component_issue_<uuid>   (21+36=57, VƯỢT varchar(50))
 * (xem CurrentBaselineStockService::recordPostingEvent call sites).
 *
 * `posting_events.posting_action` là varchar(50) theo Data Dictionary bảng 31
 * (docs/HoaNam_ERD_Moi_Nhat.md — "POST_RECEIPT/POST_ISSUE/...", và unique constraint
 * khuyến nghị UNIQUE(resource_type,resource_id,posting_action)) — nghĩa là cột này CHỦ Ý
 * chỉ chứa action-code ngắn, UUID của resource đã có cột resource_id riêng rồi. Vậy root
 * cause không phải cột quá hẹp mà trigger đang nhét nhầm cả operation key (có UUID) vào
 * một cột chỉ dành cho action-code — khi UUID đẩy chuỗi qua 50 ký tự thì Postgres ném
 * "value too long for type character varying(50)" (SQLite không enforce length nên không
 * lộ ra). Sửa: derive posting_action bằng cách cắt bỏ hậu tố "_<uuid>" khỏi operation
 * trước khi coalesce, giữ nguyên toàn bộ logic khác của trigger không đổi. Không cần đổi
 * độ rộng cột / không cần sửa docs/HoaNam_ERD_Moi_Nhat.md — bám đúng contract đã ghi.
 *
 * KHÔNG sửa lại migration 000765 cũ (đã chạy) — replace function bằng CREATE OR REPLACE
 * là an toàn vì trigger definition chỉ tham chiếu function theo tên.
 */
return new class extends Migration
{
    public function up(): void
    {
        // DEV195-P2-02R: PostgreSQL là canonical/production DB (docs/HN-BE-001_BACKEND_STANDARD.md
        // §3) — bug varchar(50) overflow chỉ lộ trên pgsql (constraint có enforce), nên fix trigger
        // này chỉ cần áp dụng ở đây. SQLite (test/dev only) không enforce length nên không cần vá.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE OR REPLACE FUNCTION '.HoaNamErdSchema::quote('hn_erd_normalize_canonical_posting_events').'() RETURNS trigger AS $$ BEGIN '.$this->body().' RETURN NEW; END; $$ LANGUAGE plpgsql');

        if (DB::getSchemaBuilder()->hasTable('posting_events') && DB::getSchemaBuilder()->hasColumn('posting_events', 'posting_action')) {
            DB::statement(<<<'SQL'
                UPDATE posting_events
                   SET posting_action = LEFT(
                       regexp_replace(posting_action, '_[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$', ''),
                       50
                   )
                 WHERE posting_action ~* '_[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
            SQL);
        }
    }

    public function down(): void
    {
        // Fail-forward: cắt UUID khỏi posting_action là fix đúng theo Data Dictionary,
        // không có bản "cũ đúng hơn" để quay lại.
    }

    private function body(): string
    {
        return <<<'SQL'
            NEW.resource_type := COALESCE(NEW.resource_type, CASE WHEN NEW.document_id IS NOT NULL THEN 'STOCK_DOCUMENT' WHEN NEW.movement_id IS NOT NULL THEN 'MOVEMENT' ELSE 'LEGACY' END);
            NEW.resource_id := COALESCE(NEW.resource_id, NEW.document_id, NEW.movement_id, NEW.id);
            NEW.posting_action := COALESCE(NEW.posting_action, LEFT(regexp_replace(NEW.operation, '_[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$', ''), 50), 'POST');
            NEW.status := CASE WHEN NEW.status IN ('STARTED','COMMITTED','FAILED') THEN NEW.status WHEN NEW.status IN ('SUCCESS','SUCCEEDED','DONE') THEN 'COMMITTED' ELSE COALESCE(NEW.status, 'STARTED') END;
            NEW.movement_count := COALESCE(NEW.movement_count, CASE WHEN NEW.movement_id IS NULL THEN 0 ELSE 1 END);
            NEW.started_at := COALESCE(NEW.started_at, NEW.created_at, CURRENT_TIMESTAMP);
            NEW.committed_at := COALESCE(NEW.committed_at, CASE WHEN NEW.status='COMMITTED' THEN NEW.created_at END);
            NEW.actor_id := COALESCE(NEW.actor_id, NEW.created_by);
        SQL;
    }
};
