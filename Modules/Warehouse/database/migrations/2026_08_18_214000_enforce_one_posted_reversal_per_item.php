<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WWHN-239 (DEV239-P1-03/P1-04) — bất biến "duplicate movement = 0" ở grain ITEM.
 *
 * ERD bảng 34 ghi constraint "tổng posted reverse per original <= original abs qty".
 * Ràng buộc đó tính theo TỔNG số lượng, nên với một line serial-tracked gồm N item
 * (CurrentBaselineStockService::postStockDocument ghi MỘT movement quantity=N cho mỗi
 * stock_document_line) nó không chặn được việc khai đi khai lại cùng một `item_id`
 * trong hạn mức N. Một serial vật lý chỉ tồn tại một lần, nên mỗi cặp
 * (original_movement_id, item_id) chỉ được có ĐÚNG MỘT dòng reversal đã sinh movement.
 *
 * Guard nghiệp vụ nằm ở CurrentBaselineReversalService::assertItemNotAlreadyReversed()
 * (check khi tạo DRAFT) và re-check dưới `lockForUpdate` khi Post. Index dưới đây là
 * lớp phòng thủ cuối cùng ở tầng DB: hai transaction thật sự song song trên
 * PostgreSQL sẽ có đúng một transaction commit được, transaction còn lại vi phạm
 * unique và bị rollback toàn bộ — không phụ thuộc thứ tự lock của tầng ứng dụng.
 *
 * `posted_movement_id IS NOT NULL` là điều kiện đúng thay vì `status='POSTED'`:
 * status nằm ở bảng header nên không dùng được trong partial index một bảng, còn
 * `posted_movement_id` được ghi đúng tại thời điểm dòng sinh movement đối ứng.
 * DRAFT/CANCELLED vì thế không bị index chặn — đúng ý BR-REV-08.
 *
 * Tên bảng vật lý đảo chiều theo driver (2026_08_10_000791 chỉ chạy trên pgsql):
 * PostgreSQL dùng `reversal_lines`, sqlite dùng `reversal_line`; bên còn lại là view
 * nên không thể mang index. Migration tự dò relation vật lý thay vì đoán tên.
 */
return new class extends Migration
{
    private const INDEX = 'ux_reversal_line_posted_item_per_movement';

    public function up(): void
    {
        $table = $this->physicalTable();
        if ($table === null) {
            return;
        }

        $duplicates = (int) DB::scalar(
            "SELECT COUNT(*) FROM (
                SELECT original_movement_id, item_id
                FROM {$table}
                WHERE item_id IS NOT NULL AND posted_movement_id IS NOT NULL
                GROUP BY original_movement_id, item_id
                HAVING COUNT(*) > 1
             ) AS duplicated_items"
        );

        if ($duplicates > 0) {
            // Fail-safe cutover (cùng nguyên tắc với 2026_08_18_193100): dữ liệu lịch
            // sử lệch không được làm hỏng lần migrate. Ghi vào log để WWHN-241 có bằng
            // chứng phải làm sạch trước; ghi mới vẫn bị guard ứng dụng chặn.
            Log::warning('WWHN-239: bỏ qua unique index reversal theo item vì dữ liệu hiện có đã trùng.', [
                'table' => $table,
                'duplicated_item_movement_pairs' => $duplicates,
            ]);

            DB::statement("CREATE INDEX IF NOT EXISTS {$table}_item_movement_idx ON {$table} (original_movement_id, item_id)");

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX." ON {$table} (original_movement_id, item_id) ".
            'WHERE item_id IS NOT NULL AND posted_movement_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        $table = $this->physicalTable();
        if ($table === null) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        DB::statement("DROP INDEX IF EXISTS {$table}_item_movement_idx");
    }

    /** Trả về tên relation VẬT LÝ của reversal line trên driver hiện hành, null nếu chưa có. */
    private function physicalTable(): ?string
    {
        $driver = DB::getDriverName();

        foreach (['reversal_lines', 'reversal_line'] as $candidate) {
            if ($driver === 'pgsql') {
                $kind = DB::scalar(
                    'SELECT c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '.
                    'WHERE n.nspname = current_schema() AND c.relname = ?',
                    [$candidate]
                );
                if ($kind === 'r' || $kind === 'p') {
                    return $candidate;
                }

                continue;
            }

            if ($driver === 'sqlite') {
                $type = DB::scalar('SELECT type FROM sqlite_master WHERE name = ?', [$candidate]);
                if ($type === 'table') {
                    return $candidate;
                }

                continue;
            }

            return null;
        }

        return null;
    }
};
