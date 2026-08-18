<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WWHN-231 / DEV231-P1-03 — index phục vụ aggregate đối soát ledger.
 *
 * §7.5 Reconciliation Controls đối soát theo dimension (warehouse_id, product_id,
 * stock_status) và cộng SUM(qty_delta). Index sẵn có `idx_sm_wh_product_time`
 * (warehouse_id, product_id, occurred_at) KHÔNG phục vụ được phép gom này: nó thiếu
 * stock_status ở tầng gom và thiếu qty_delta nên planner luôn phải quay lại heap, dẫn tới
 * Seq Scan + HashAggregate trên toàn bộ ledger. Ledger là append-only và giữ >= 5 năm
 * (§13.7 Retention) nên chi phí đó chỉ tăng theo thời gian.
 *
 * Index này cho phép Index Only Scan trả đúng thứ tự dimension, nên PostgreSQL đổi sang
 * GroupAggregate không cần sort. Cột đo để trong INCLUDE (PostgreSQL 11+) để không làm
 * phình khoá B-tree; SQLite không có INCLUDE nên đưa vào phần khoá.
 *
 * Đây thuần tuý là index đọc: không đổi ràng buộc, không đổi dữ liệu, không tạo đường ghi
 * tồn mới (REQ-SRS-037 / BR-NOSTK-01..03).
 */
return new class extends Migration
{
    private const INDEX = 'idx_sm_ledger_dimension';

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true) || ! $this->ledgerTableExists($driver)) {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS '.self::INDEX.' ON stock_movements '
                .'(warehouse_id, product_id, stock_status) INCLUDE (qty_delta, occurred_at)'
            );

            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS '.self::INDEX.' ON stock_movements '
            .'(warehouse_id, product_id, stock_status, qty_delta, occurred_at)'
        );
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }

    /**
     * `Schema::hasTable()` trả false cho VIEW trên SQLite, mà một số baseline dựng
     * stock_movements dưới dạng view — nên hỏi thẳng catalog thay vì tin hasTable.
     */
    private function ledgerTableExists(string $driver): bool
    {
        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('name', 'stock_movements')
                ->where('type', 'table')
                ->exists();
        }

        return DB::selectOne(
            "SELECT 1 AS ok FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relname = 'stock_movements' AND c.relkind = 'r' AND n.nspname = current_schema()"
        ) !== null;
    }
};
