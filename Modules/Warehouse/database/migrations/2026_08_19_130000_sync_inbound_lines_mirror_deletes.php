<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PATCH /inbound-documents/{id} thay cấu trúc dòng bằng cách XOÁ toàn bộ
 * `stock_document_lines` của phiếu rồi INSERT lại với id mới
 * (CurrentBaselineDocumentController::replaceDocumentLines). Mirror ERD
 * `hn_erd_mirror_inbound_lines` lại chỉ upsert theo `id` và **không xoá** row đã biến mất
 * khỏi projection, nên hàng cũ vẫn nằm trong `inbound_lines` với cùng
 * `(inbound_document_id, line_no)`. Lần INSERT tiếp theo đụng
 * `ux_erd_inbound_lines_f891eba6` → 23505 → abort transaction → API trả 409.
 *
 * Hệ quả thật trên DB tham chiếu (PostgreSQL): sau khi gỡ hết mã đã quét, người dùng KHÔNG
 * sửa lại được cấu trúc dòng của phiếu nhập nữa. Trên sqlite không lộ ra vì nhánh mirror
 * chỉ chạy trên pgsql.
 *
 * Đây là cùng một lớp lỗi đã vá cho `idempotency_records`
 * (2026_08_16_133000_sync_idempotency_mirror_deletes). Gốc chung nằm ở
 * 2026_08_10_000765: generator chỉ gắn mệnh đề cleanup cho một allowlist 4 bảng
 * (`user_roles`, `role_permissions`, `pv_product_industries`, `pv_product_tags`), mọi mirror
 * còn lại đều insert/update-only. Migration này vá đúng bảng đang hỏng và đã chứng minh
 * được; việc rà soát cả lớp mirror thuộc luồng ERD (nhóm WWHN-31/WWHN-310), không gộp vào
 * đây để tránh đổi hành vi của những mirror chưa có bằng chứng.
 *
 * KHÔNG áp dụng cho mirror append-only (`inventory_movements`, `warranty_events`): ở đó xoá
 * theo projection sẽ phá bất biến sổ cái.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql'
            || ! Schema::hasTable('inbound_lines')
            || ! Schema::hasTable('stock_document_lines')) {
            return;
        }

        // Projection là view do 2026_08_10_000765 tạo; thiếu nó thì mirror chưa dựng xong.
        $projectionExists = DB::selectOne(
            "SELECT 1 AS ok FROM pg_class WHERE relname = 'inbound_lines__legacy_projection' AND relkind = 'v'"
        );
        if ($projectionExists === null) {
            return;
        }

        // Dọn phần rác đã tích lại từ những lần PATCH trước khi có bản vá này, nếu không
        // lần chạy trigger đầu tiên sau migrate vẫn nổ đúng constraint đó.
        DB::statement('DELETE FROM inbound_lines c
             WHERE NOT EXISTS (
                SELECT 1 FROM inbound_lines__legacy_projection p WHERE p.id = c.id
             )');

        DB::statement('CREATE OR REPLACE FUNCTION hn_erd_mirror_inbound_lines() RETURNS trigger AS $$ BEGIN
            DELETE FROM inbound_lines c
             WHERE NOT EXISTS (
                SELECT 1 FROM inbound_lines__legacy_projection p WHERE p.id = c.id
             );
            INSERT INTO "inbound_lines" ("id", "inbound_document_id", "line_no", "sku_id", "import_batch_id", "expected_qty", "scanned_qty", "note", "created_at", "created_by", "updated_at", "updated_by", "version")
            SELECT "id", "inbound_document_id", "line_no", "sku_id", "import_batch_id", "expected_qty", "scanned_qty", "note", "created_at", "created_by", "updated_at", "updated_by", "version"
              FROM "inbound_lines__legacy_projection"
            ON CONFLICT ("id") DO UPDATE SET
                "inbound_document_id" = EXCLUDED."inbound_document_id",
                "line_no" = EXCLUDED."line_no",
                "sku_id" = EXCLUDED."sku_id",
                "import_batch_id" = EXCLUDED."import_batch_id",
                "expected_qty" = EXCLUDED."expected_qty",
                "scanned_qty" = EXCLUDED."scanned_qty",
                "note" = EXCLUDED."note",
                "created_at" = EXCLUDED."created_at",
                "created_by" = EXCLUDED."created_by",
                "updated_at" = EXCLUDED."updated_at",
                "updated_by" = EXCLUDED."updated_by",
                "version" = EXCLUDED."version";
            RETURN NULL;
        END; $$ LANGUAGE plpgsql');
    }

    public function down(): void
    {
        // Fail-forward: mirror ERD là ràng buộc đã ký, không hạ cấp ngược.
    }
};
