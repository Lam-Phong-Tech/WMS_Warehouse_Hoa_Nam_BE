<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GĐ1: luồng nhập là scan-driven — không còn "số lượng dự kiến".
 *
 * `chk_stock_document_line_integer_positive` (migration 2026_08_10_000620) và
 * `chk_erd_inbound_lines_0` (migration 2026_08_10_000770) cùng mã hoá một quy tắc
 * của ERD bảng 27 `inbound_lines.expected_qty CHECK > 0`: quy tắc đó thuộc mô hình
 * CŨ, khi người dùng khai trước số lượng dự kiến rồi mới quét đối chiếu.
 *
 * Baseline GĐ1 đã bỏ hẳn số lượng dự kiến ở luồng nhập (`INBOUND_SCAN_DRIVEN`):
 * `qty_planned`/`qty_actual` của dòng receipt chỉ còn là cột tương thích, luôn
 * phản ánh tổng số đã quét/đếm THỰC TẾ — xem BaM1InboundComponentController và
 * BaM1InboundScanCorrectionController. Vì vậy dòng receipt = 0 là trạng thái hợp
 * lệ ở hai thời điểm:
 *   - vừa materialize theo Box qua POST /inbound-documents/{id}/component-lines,
 *     trước khi đếm/quét mã đầu tiên;
 *   - sau khi gỡ hết mã đã quét qua POST /inbound-documents/{id}/unscan.
 *
 * Luồng XUẤT không đổi: `required_qty` đến từ toa của khách nên vẫn phải > 0
 * (ERD bảng 30 `outbound_lines.required_qty CHECK > 0`). CHECK không tham chiếu
 * được `stock_documents.doc_type`, nên nửa "> 0" của luồng xuất chuyển sang
 * trigger BEFORE ROW — đúng khuôn mẫu `hn_guard_stock_line_sku_scope` đã có sẵn
 * trên chính bảng này.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Gate sqlite chưa bao giờ cài các CHECK này (2026_08_10_000620 và
        // 2026_08_10_000770 đều chỉ chạy trên pgsql).
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->relaxStockDocumentLineShape();
        $this->guardIssueLineQuantities();
        $this->relaxErdInboundMirror();
    }

    public function down(): void
    {
        // Fail-forward: siết lại "> 0" sẽ làm brick mọi CSDL đang giữ dòng receipt
        // hợp lệ có qty_planned = 0 của GĐ1 (ALTER TABLE ... ADD CHECK validate
        // toàn bảng ngay). Không tái lập ràng buộc cũ.
    }

    private function relaxStockDocumentLineShape(): void
    {
        if (! Schema::hasTable('stock_document_lines')) {
            return;
        }

        DB::statement('ALTER TABLE stock_document_lines DROP CONSTRAINT IF EXISTS chk_stock_document_line_integer_positive');
        DB::statement('ALTER TABLE stock_document_lines DROP CONSTRAINT IF EXISTS chk_stock_document_line_quantity_shape');
        DB::statement(<<<'SQL'
            ALTER TABLE stock_document_lines
              ADD CONSTRAINT chk_stock_document_line_quantity_shape
              CHECK (qty_planned >= 0 AND qty_actual >= 0 AND scanned_qty >= 0 AND scanned_qty <= qty_planned)
        SQL);
    }

    private function guardIssueLineQuantities(): void
    {
        if (! Schema::hasTable('stock_document_lines')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_guard_stock_line_issue_quantity()
            RETURNS trigger AS $$
            DECLARE
                v_doc_type text;
            BEGIN
                SELECT doc_type INTO v_doc_type FROM stock_documents WHERE id = NEW.stock_document_id;
                IF v_doc_type = 'issue' AND (NEW.qty_planned <= 0 OR NEW.qty_actual <= 0) THEN
                    RAISE EXCEPTION 'outbound stock document line requires required_qty > 0';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS trg_stock_line_issue_quantity ON stock_document_lines');
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_stock_line_issue_quantity
            BEFORE INSERT OR UPDATE OF qty_planned, qty_actual, stock_document_id
            ON stock_document_lines
            FOR EACH ROW EXECUTE FUNCTION hn_guard_stock_line_issue_quantity()
        SQL);
    }

    private function relaxErdInboundMirror(): void
    {
        // Bảng gương ERD `inbound_lines` được nạp bằng trigger AFTER STATEMENT từ
        // chính stock_document_lines (hn_erd_mirror_inbound_lines), nên nếu không
        // nới ở đây thì dòng receipt = 0 vẫn nổ ở tầng gương.
        if (! Schema::hasTable('inbound_lines')) {
            return;
        }

        // Giữ nguyên TÊN chk_erd_inbound_lines_0: 2026_08_10_000770 bỏ qua khi tên
        // đã tồn tại, nên bản nới này không bị bản "> 0" cũ ghi đè lúc migrate lại.
        DB::statement('ALTER TABLE inbound_lines DROP CONSTRAINT IF EXISTS chk_erd_inbound_lines_0');
        DB::statement('ALTER TABLE inbound_lines ADD CONSTRAINT chk_erd_inbound_lines_0 CHECK (expected_qty >= 0)');
    }
};
