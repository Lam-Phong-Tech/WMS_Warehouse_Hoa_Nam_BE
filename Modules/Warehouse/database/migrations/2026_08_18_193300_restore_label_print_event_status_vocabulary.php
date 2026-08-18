<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-310 (bàn giao dữ liệu) — trả `label_print_events.status` về đúng bộ giá trị
 * của ERD bảng 44 và làm `attempt_no`/`event_type` có nghĩa thật.
 *
 * LỖI ĐANG CÓ: trên PostgreSQL **không thể ghi nổi một lần in thất bại**.
 *
 *  - `2026_08_10_000620` từng đặt CHECK `chk_label_print_current_status`
 *    (PENDING_PRINT/PRINTED/PRINT_FAILED) trên `label_print_events`.
 *  - `2026_08_10_000769` cố ý GỠ nó vì đã có guard theo ERD.
 *  - `2026_08_16_136000` (nới lifecycle của Packing Label theo §6) vô tình dựng lại
 *    CHECK đó cho CẢ `label_snapshots` LẪN `label_print_events`, bằng bộ giá trị
 *    vòng đời của *nhãn* (DRAFT/PREVIEWED/READY_TO_PRINT/PENDING_PRINT/PRINTED/
 *    PRINT_FAILED).
 *  - Trigger `trg_erd_normalize_runtime_label_print_events` lại chuẩn hoá status của
 *    *sự kiện in* về bộ ERD (PENDING/SENT/PRINTED/FAILED).
 *
 * Giao của hai bộ chỉ còn đúng 'PRINTED' ⇒ mọi sự kiện PENDING/SENT/FAILED bị chặn
 * 23514. RPT-LABEL-01 yêu cầu cột "result" và số lần in lỗi, nhưng bằng chứng in
 * lỗi không bao giờ tồn tại được trên DB tham chiếu.
 *
 * Xử lý: gỡ CHECK bị đặt nhầm bảng (giữ nguyên trên `label_snapshots` — nơi bộ giá
 * trị đó mới đúng), giữ `chk_erd_label_print_events_2` làm nguồn duy nhất cho
 * status của sự kiện in; đồng thời suy ra `attempt_no`/`event_type` từ lịch sử thay
 * vì luôn gán cứng 1/'PRINT'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('label_print_events')) {
            return;
        }

        DB::statement('ALTER TABLE label_print_events DROP CONSTRAINT IF EXISTS chk_label_print_current_status');

        // Bộ giá trị ERD bảng 44 là nguồn duy nhất cho status của sự kiện in.
        DB::statement('ALTER TABLE label_print_events DROP CONSTRAINT IF EXISTS chk_erd_label_print_events_2');
        DB::statement(
            'ALTER TABLE label_print_events ADD CONSTRAINT chk_erd_label_print_events_2 '.
            "CHECK (status IN ('PENDING', 'SENT', 'PRINTED', 'FAILED'))"
        );

        // attempt_no/event_type trước đây luôn là 1/'PRINT' nên hai cột của ERD tồn
        // tại mà không mang thông tin. Suy từ lịch sử của chính nhãn đó: lần in lại
        // là lần in sau khi đã có một lần in THÀNH CÔNG; retry sau khi lỗi vẫn là
        // attempt của cùng lần in, không phải reprint.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_normalize_runtime_label_print_events()
            RETURNS trigger AS $$
            BEGIN
                NEW.printer_id := COALESCE(NEW.printer_id, NEW.printer_config_id);
                NEW.printer_config_id := COALESCE(NEW.printer_config_id, NEW.printer_id);
                NEW.attempt_no := COALESCE(
                    NEW.attempt_no,
                    (SELECT COUNT(*) + 1 FROM label_print_events WHERE label_snapshot_id = NEW.label_snapshot_id)
                );
                NEW.event_type := COALESCE(
                    NEW.event_type,
                    CASE
                        WHEN EXISTS (
                            SELECT 1 FROM label_print_events
                            WHERE label_snapshot_id = NEW.label_snapshot_id AND status = 'PRINTED'
                        ) THEN 'REPRINT'
                        ELSE 'PRINT'
                    END
                );
                NEW.requested_at := COALESCE(NEW.requested_at, NEW.created_at, CURRENT_TIMESTAMP);
                NEW.completed_at := COALESCE(NEW.completed_at, NEW.printed_at);
                NEW.status := CASE
                    WHEN NEW.status IN ('PENDING', 'SENT', 'PRINTED', 'FAILED') THEN NEW.status
                    WHEN NEW.status = 'PRINT_FAILED' THEN 'FAILED'
                    WHEN NEW.status = 'PENDING_PRINT' THEN 'PENDING'
                    ELSE COALESCE(NEW.status, 'PENDING')
                END;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    /**
     * Nghịch đảo thật (không fail-forward) để `migrate:rollback` của cutover giữ
     * được đường lùi: trả lại CHECK cũ và trigger cũ.
     *
     * CHECK cũ được dựng lại ở dạng NOT VALID — dữ liệu sự kiện in đã ghi trong
     * lúc migration này có hiệu lực (PENDING/SENT/FAILED) sẽ không thoả nó, và
     * một ADD CONSTRAINT có validate sẽ ném 23514 làm rollback dừng giữa chừng
     * (đúng sự cố P0 của WWHN-300).
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('label_print_events')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_erd_normalize_runtime_label_print_events()
            RETURNS trigger AS $$
            BEGIN
                NEW.printer_id := COALESCE(NEW.printer_id, NEW.printer_config_id);
                NEW.printer_config_id := COALESCE(NEW.printer_config_id, NEW.printer_id);
                NEW.attempt_no := COALESCE(NEW.attempt_no, 1);
                NEW.event_type := COALESCE(NEW.event_type, 'PRINT');
                NEW.requested_at := COALESCE(NEW.requested_at, NEW.created_at, CURRENT_TIMESTAMP);
                NEW.completed_at := COALESCE(NEW.completed_at, NEW.printed_at);
                NEW.status := CASE
                    WHEN NEW.status IN ('PENDING', 'SENT', 'PRINTED', 'FAILED') THEN NEW.status
                    WHEN NEW.status = 'PRINT_FAILED' THEN 'FAILED'
                    WHEN NEW.status = 'PENDING_PRINT' THEN 'PENDING'
                    ELSE COALESCE(NEW.status, 'PENDING')
                END;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('ALTER TABLE label_print_events DROP CONSTRAINT IF EXISTS chk_label_print_current_status');
        DB::statement(
            'ALTER TABLE label_print_events ADD CONSTRAINT chk_label_print_current_status '.
            "CHECK (status IN ('DRAFT', 'PREVIEWED', 'READY_TO_PRINT', 'PENDING_PRINT', 'PRINTED', 'PRINT_FAILED')) NOT VALID"
        );
    }
};
