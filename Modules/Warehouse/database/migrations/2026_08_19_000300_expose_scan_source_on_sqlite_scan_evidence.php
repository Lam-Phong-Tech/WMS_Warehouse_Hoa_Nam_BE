<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Database\Migrations\Concerns\DropsBaselineViewsAroundAlter;

/**
 * WWHN-195 / DEV195-P1-01R2 + DEV195-P2-02R2 — đưa `scan_source` của ERD bảng 25
 * xuống được nhánh sqlite.
 *
 * Hiện trạng trước migration này (đã probe trên cả hai driver):
 *
 * - PostgreSQL: `scan_events` là bảng vật lý, có sẵn `scan_source` với CHECK
 *   IN ('CAMERA','MANUAL','HARDWARE') do 2026_08_10_000770 tạo; `scan_event` số ít
 *   là view bridge có INSTEAD OF trigger.
 * - sqlite: ngược chiều — `scan_event` số ít mới là bảng vật lý, `scan_events` là
 *   view do 2026_08_09_000400 tạo. **Cả hai đều không có cột `scan_source`**, nên
 *   trường audit mà ERD yêu cầu không tồn tại trên nhánh CI: không test nào chứng
 *   minh được chế độ quét đã được ghi lại đúng.
 *
 * Migration này chỉ chạy trên sqlite (PostgreSQL đã đủ cột):
 *
 * 1. Thêm `scan_source`, `context_type`, `context_id` vào bảng vật lý `scan_event`.
 *    Để nullable — đúng nguyên tắc đã chốt ở 2026_08_17_000100: sqlite không có tầng
 *    trigger backfill như PostgreSQL nên ép NOT NULL sẽ chặn chính fixture đang chạy.
 * 2. Dựng lại view `scan_events` để lộ ba cột đó, giữ nguyên phần alias cũ
 *    (scan_event_id AS id, ...) để mọi consumer hiện hành không đổi.
 *
 * Không đụng dữ liệu và không đụng nghiệp vụ: scan không tự đổi tồn (ERD bảng 25).
 */
return new class extends Migration
{
    use DropsBaselineViewsAroundAlter;

    private const VIEW_SQL = 'SELECT scan_event_id AS id, scan_batch_id, raw_code, physical_code_id, '.
        'resolved_object_type, resolved_object_id, result_status, message, created_at, '.
        'scan_source, context_type, context_id FROM scan_event';

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! Schema::hasTable('scan_event')) {
            return;
        }

        $this->alterTableAroundBaselineViews('scan_event', function (): void {
            Schema::table('scan_event', function (Blueprint $table): void {
                if (! Schema::hasColumn('scan_event', 'scan_source')) {
                    // varchar(20) theo ERD bảng 25; giá trị hợp lệ CAMERA/MANUAL/HARDWARE
                    // được ép ở tầng validation (AlignedOutboundFlowController::SCAN_SOURCES)
                    // vì sqlite không add CHECK sau khi bảng đã tạo.
                    $table->string('scan_source', 20)->nullable();
                }
                if (! Schema::hasColumn('scan_event', 'context_type')) {
                    $table->string('context_type', 50)->nullable();
                }
                if (! Schema::hasColumn('scan_event', 'context_id')) {
                    $table->uuid('context_id')->nullable();
                }
            });
        });

        // alterTableAroundBaselineViews dựng lại view theo định nghĩa CŨ trong
        // sqlite_master, nên phải thay bằng định nghĩa có ba cột mới.
        DB::statement('DROP VIEW IF EXISTS scan_events');
        DB::statement('CREATE VIEW scan_events AS '.self::VIEW_SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! Schema::hasTable('scan_event')) {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS scan_events');

        $this->alterTableAroundBaselineViews('scan_event', function (): void {
            Schema::table('scan_event', function (Blueprint $table): void {
                foreach (['scan_source', 'context_type', 'context_id'] as $column) {
                    if (Schema::hasColumn('scan_event', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        });

        DB::statement('DROP VIEW IF EXISTS scan_events');
        DB::statement('CREATE VIEW scan_events AS SELECT scan_event_id AS id, scan_batch_id, raw_code, '.
            'physical_code_id, resolved_object_type, resolved_object_id, result_status, message, created_at FROM scan_event');
    }
};
