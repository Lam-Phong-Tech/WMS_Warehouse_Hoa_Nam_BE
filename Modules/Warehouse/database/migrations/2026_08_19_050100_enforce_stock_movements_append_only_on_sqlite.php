<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WWHN-204 / DEV204-P1-04 — parity append-only cho `stock_movements` trên sqlite.
 *
 * Migration 2026_08_15_000100 (WWHN-205) đã đặt trigger BEFORE UPDATE OR DELETE trên
 * `stock_movements`, nhưng mở đầu bằng `if (DB::getDriverName() !== 'pgsql') return;` nên
 * **chỉ pgsql được bảo vệ ở tầng DB**. Trên sqlite — driver mà toàn bộ test suite và CI
 * `current-baseline-doc-contract.yml` đang chạy — ledger chỉ còn hàng rào ở tầng Eloquent
 * (`StockMovement::booted()`), trong khi mọi đường ghi tồn thật lại dùng `DB::table(...)`
 * (CurrentBaselineStockService, AlignedOutboundStockService, CurrentBaselineReversalService)
 * tức là đi vòng qua Eloquent. Hệ quả: bất biến "Movement append-only" của DEC-INV-001 §7.1
 * **không thể chứng minh được bằng test trên driver mặc định** — test có thể sửa/xoá ledger
 * mà không gì chặn lại, nên một hồi quy làm hỏng tính bất biến sẽ lọt.
 *
 * Migration này đóng đúng khoảng hở đó: cùng ngữ nghĩa, cùng thông điệp lỗi
 * (`... is append-only under HN-WMS-ERD-DD-001`) với bản pgsql, để test append-only viết
 * một lần chạy đúng trên cả hai driver.
 *
 * Vì sao an toàn: mọi migration có UPDATE dữ liệu trên `stock_movements`
 * (2026_08_09_000420 chuẩn hoá movement_type, 2026_08_10_000600 điền source_type) đều nằm
 * TRƯỚC mốc thời gian này nên đã chạy xong khi trigger được tạo. Không có code ứng dụng,
 * seeder hay test nào UPDATE/DELETE `stock_movements` (đã quét toàn repo). Sửa sai nghiệp vụ
 * đi theo Reversal append-only (BR-REV-01), không sửa dòng ledger cũ.
 */
return new class extends Migration
{
    private const TRIGGERS = [
        'trg_stock_movements_append_only_update' => 'UPDATE',
        'trg_stock_movements_append_only_delete' => 'DELETE',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        // Chỉ gắn khi `stock_movements` là BẢNG VẬT LÝ. Trên nhánh schema khác nó có thể là
        // view (2026_08_10_000791 đảo chiều số ít/số nhiều theo driver) — sqlite không cho
        // tạo trigger BEFORE trên view, và trigger đặt nhầm chỗ sẽ bảo vệ sai đối tượng,
        // đúng loại lỗi mà 2026_08_10_000770 đã mắc phải với `inventory_movements`.
        if (! $this->isPhysicalTable('stock_movements')) {
            return;
        }

        foreach (self::TRIGGERS as $name => $event) {
            DB::statement("DROP TRIGGER IF EXISTS {$name}");
            DB::statement(
                "CREATE TRIGGER {$name} BEFORE {$event} ON stock_movements FOR EACH ROW ".
                "BEGIN SELECT RAISE(ABORT, 'stock_movements is append-only under HN-WMS-ERD-DD-001'); END"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach (array_keys(self::TRIGGERS) as $name) {
            DB::statement("DROP TRIGGER IF EXISTS {$name}");
        }
    }

    private function isPhysicalTable(string $name): bool
    {
        return (bool) DB::selectOne(
            "SELECT 1 AS present FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$name],
        );
    }
};
