<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ERD bảng 33/34 (reversal_documents / reversal_lines) là nguồn đọc của luồng 05
 * REV-00/REV-01. Trên PostgreSQL đây là bảng vật lý đủ cột; trên sqlite (bộ CI chạy
 * full suite) 2026_08_09_000400 mới chỉ tạo view rút gọn:
 *
 *   reversal_documents: thiếu warehouse_id, original_document_type, correction_chain_id,
 *                       reason_code, reason_text, cancelled_at/cancel_reason,
 *                       condition_status, physical_return_status, idempotency_key.
 *   reversal_lines:     thiếu line_no, sku_id, item_id, quantity_to_reverse, stock_status,
 *                       return_condition, downstream_check_result, posted_movement_id.
 *
 * Hệ quả: GET /reversal-documents, /{id} và /{id}/lines nổ "no such column" trên sqlite
 * dù chạy đúng trên DB tham chiếu — đúng loại drift mà 2026_08_17_000100 đã xử cho các
 * bảng vật lý. Migration này chiếu đủ cột ERD từ bảng số ít, giữ nguyên quyết định kiến
 * trúc "chỉ PostgreSQL đảo chiều số ít/số nhiều" của 2026_08_10_000791.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS reversal_lines');
        DB::statement('DROP VIEW IF EXISTS reversal_documents');

        DB::statement(<<<'SQL'
            CREATE VIEW reversal_documents AS
            SELECT reversal_document_id AS id,
                   document_no          AS reversal_no,
                   warehouse_id,
                   original_document_type,
                   COALESCE(original_document_id, stock_document_id) AS original_document_id,
                   correction_chain_id,
                   reason_code,
                   COALESCE(reason_text, reason) AS reason_text,
                   reason,
                   status,
                   condition_status,
                   physical_return_status,
                   idempotency_key,
                   posted_at,
                   posted_by,
                   cancelled_at,
                   cancel_reason,
                   created_at,
                   created_by,
                   updated_at,
                   updated_by,
                   version
              FROM reversal_document
        SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW reversal_lines AS
            SELECT reversal_line_id AS id,
                   reversal_document_id,
                   line_no,
                   original_movement_id,
                   sku_id,
                   item_id,
                   COALESCE(quantity_to_reverse, reversal_qty) AS quantity,
                   COALESCE(quantity_to_reverse, reversal_qty) AS quantity_to_reverse,
                   stock_status,
                   return_condition,
                   downstream_check_result,
                   posted_movement_id,
                   reason,
                   created_at,
                   created_by,
                   updated_at,
                   updated_by,
                   version
              FROM reversal_line
        SQL);
    }

    public function down(): void
    {
        // Fail-forward: view chỉ mở rộng tập cột, không đổi semantics đã ký.
    }
};
