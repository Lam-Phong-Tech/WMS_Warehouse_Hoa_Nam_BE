<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chỉ mục theo THỜI GIAN cho sổ cái `stock_movements` (WWHN-perf 07/09/2026).
 *
 * VẤN ĐỀ ĐO ĐƯỢC: bảng có 10 chỉ mục nhưng KHÔNG cái nào dẫn đầu bằng
 * `occurred_at`, trong khi:
 *  - `StockMovementController::index` sắp xếp `orderByDesc('occurred_at')` rồi
 *    phân trang (mọi màn "Biến động tồn"),
 *  - 8 truy vấn trong `OperationalReportController` lọc `occurred_at BETWEEN`
 *    (dashboard, Nhập–Xuất–Tồn, sổ cái, truy vết).
 *
 * EXPLAIN trước khi thêm (PostgreSQL 16, dev DB 7.350 dòng):
 *   Limit -> Sort (Sort Key: occurred_at DESC) -> Seq Scan on stock_movements
 *   HashAggregate -> Seq Scan on stock_movements
 *                      Filter: occurred_at >= ... AND occurred_at <= ...
 * `SET enable_seqscan = off` vẫn ra Seq Scan (chi phí 1e10) = không có chỉ mục
 * nào dùng được. `pg_stat_user_tables`: seq_scan 1.497 lần đọc 11.002.950 dòng
 * — mỗi lần quét trọn sổ cái.
 *
 * VÌ SAO AN TOÀN: chỉ THÊM chỉ mục, không đổi cột/ràng buộc/nghiệp vụ. Bảng
 * chỉ ghi thêm (append-only) với `occurred_at` tăng dần, nên chi phí ghi là
 * chèn vào lá phải cùng của B-tree — rẻ nhất trong các kiểu chèn. Không đụng
 * `business_key`/`movement_no` (chốt idempotency) và giữ nguyên
 * `idx_sm_wh_product_time` (phục vụ chiều kho×hàng).
 *
 * PostgreSQL dùng CONCURRENTLY (không khoá ghi) nên phải chạy NGOÀI transaction.
 * sqlite (test) không có CONCURRENTLY và cũng không cần — bỏ qua cờ đó.
 */
return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY không chạy được trong transaction; Laravel bọc
     * migration trong transaction với driver hỗ trợ DDL transaction (pgsql).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $this->upPostgres(),
            'sqlite' => $this->upSqlite(),
            default => null,
        };
    }

    public function down(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? ' CONCURRENTLY' : '';

        foreach (['idx_sm_occurred_at_id', 'idx_sm_wh_occurred_at'] as $index) {
            DB::statement("DROP INDEX{$concurrently} IF EXISTS {$index}");
        }
    }

    private function upPostgres(): void
    {
        // (occurred_at DESC, id DESC): phục vụ ORDER BY occurred_at DESC của màn
        // danh sách (id là khoá phụ ổn định cho phân trang) VÀ mọi khoảng
        // `occurred_at BETWEEN` của báo cáo.
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sm_occurred_at_id '.
            'ON stock_movements (occurred_at DESC, id DESC)'
        );

        // (warehouse_id, occurred_at DESC): danh sách/dashboard đã lọc theo kho —
        // phần lớn người dùng chỉ nhìn kho của mình (ScopesWarehouseData).
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sm_wh_occurred_at '.
            'ON stock_movements (warehouse_id, occurred_at DESC)'
        );
    }

    private function upSqlite(): void
    {
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_sm_occurred_at_id '.
            'ON stock_movements (occurred_at DESC, id DESC)'
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_sm_wh_occurred_at '.
            'ON stock_movements (warehouse_id, occurred_at DESC)'
        );
    }
};
