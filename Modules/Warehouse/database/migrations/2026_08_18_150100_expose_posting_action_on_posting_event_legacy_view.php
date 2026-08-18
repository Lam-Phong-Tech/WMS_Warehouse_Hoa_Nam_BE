<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-219 posting_action overflow (xem 2026_08_18_150000_fix_posting_events_posting_action_operation_overflow.php).
 *
 * Đang có 1 fix song song ở tầng application (CurrentBaselineStockService,
 * AlignedOutboundStockService, CurrentBaselineReversalService — working tree, chưa
 * commit): mỗi call site `recordPostingEvent()` giờ truyền thêm 1 mã posting_action
 * ngắn cố định (POST_RECEIPT/POST_ISSUE/REVERSE_ISSUE/...) và ghi thẳng vào
 * `DB::table('posting_event')->insert([..., 'posting_action' => $postingAction, ...])`.
 *
 * Trên PostgreSQL, `posting_event` (số ít) là COMPATIBILITY VIEW trên bảng vật lý
 * `posting_events` (số nhiều) — xem 2026_08_10_000791_retire_remaining_singular_legacy_tables.php
 * (`createGenericWriteTrigger`). View đó KHÔNG có cột `posting_action`, và INSTEAD OF
 * INSERT trigger `hn_legacy_posting_event_write()` cũng không map cột này — nên insert
 * app-layer nêu trên sẽ FAIL ngay với "column posting_action of relation posting_event
 * does not exist" một khi code kia hoàn thiện, TRƯỚC CẢ KHI chạm tới trigger derive ở
 * migration 2026_08_18_150000.
 *
 * Vá: thêm cột `posting_action` vào view (chỉ append cuối — CREATE OR REPLACE VIEW an
 * toàn khi chỉ thêm cột) và vào column map của INSTEAD OF trigger để giá trị app cung
 * cấp đi thẳng vào `posting_events.posting_action`; nếu app không cung cấp
 * (NEW.posting_action NULL — code cũ/route khác chưa cập nhật), BEFORE INSERT trigger
 * canonical (2026_08_18_150000) vẫn derive an toàn từ operation làm lưới đỡ thứ hai.
 *
 * Không sửa lại 2026_08_10_000791 (đã chạy) — CREATE OR REPLACE VIEW/FUNCTION cùng tên
 * là an toàn, không cần DROP.
 */
return new class extends Migration
{
    public function up(): void
    {
        // DEV195-P2-02R: PostgreSQL là canonical/production DB (docs/HN-BE-001_BACKEND_STANDARD.md
        // §3) — compatibility view `posting_event` số ít (INSTEAD OF trigger) chỉ tồn tại trên
        // pgsql; nhánh sqlite tương ứng nằm ở migration 2026_08_18_150200 (bảng vật lý, không view).
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (HoaNamErdSchema::relationKind('posting_event') !== 'v') {
            return;
        }
        if (! Schema::hasColumn('posting_events', 'posting_action')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW posting_event AS
            SELECT id AS posting_event_id, operation, idempotency_key, request_hash, status,
                   document_id, movement_id, response_snapshot, created_by, created_at, posting_action
              FROM posting_events
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_posting_event_write() RETURNS trigger AS $$ BEGIN
                IF TG_OP = 'DELETE' THEN DELETE FROM posting_events WHERE id=OLD.posting_event_id; RETURN OLD; END IF;
                INSERT INTO posting_events (id, operation, idempotency_key, request_hash, status, document_id, movement_id, response_snapshot, created_by, created_at, posting_action)
                VALUES (COALESCE(NEW.posting_event_id, gen_random_uuid()), NEW.operation, NEW.idempotency_key, NEW.request_hash, COALESCE(NEW.status, 'STARTED'), NEW.document_id, NEW.movement_id, NEW.response_snapshot, NEW.created_by, COALESCE(NEW.created_at, CURRENT_TIMESTAMP), NEW.posting_action)
                ON CONFLICT (id) DO UPDATE SET operation=EXCLUDED.operation, idempotency_key=EXCLUDED.idempotency_key, request_hash=EXCLUDED.request_hash, status=EXCLUDED.status, document_id=EXCLUDED.document_id, movement_id=EXCLUDED.movement_id, response_snapshot=EXCLUDED.response_snapshot, created_by=EXCLUDED.created_by, created_at=EXCLUDED.created_at, posting_action=EXCLUDED.posting_action;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        // Fail-forward: xem docblock 2026_08_18_150000 — không có bản "cũ đúng hơn".
    }
};
