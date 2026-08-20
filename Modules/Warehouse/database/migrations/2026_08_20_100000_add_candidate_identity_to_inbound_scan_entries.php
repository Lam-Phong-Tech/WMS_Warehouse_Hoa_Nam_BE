<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change Pack QR/SKU 20/08/2026 (DEC-QR-001, DEC-QR-03): inbound scan được phép
 * chấp nhận item CHƯA tồn tại (new item candidate) khi QR composite hợp lệ;
 * Item_Instance + Physical_Code chỉ được tạo atomically tại Post Receipt để
 * Cancel không để lại orphan. Hai cột dưới giữ identity của candidate giữa lúc
 * scan và lúc Post:
 *
 * - candidate_item_unique: normalized item_unique parse từ payload (uppercase);
 *   NULL với entry thường (item đã tồn tại / quantity-mode COMPONENT).
 * - candidate_serial_number: serial NSX nếu payload có SER= và khác item_unique.
 *
 * Đây là cột bổ sung ngoài Data Dictionary ERD bảng 28 — cùng tiền lệ với
 * created_at/updated_at/version đã có trên bảng này; parity test chỉ yêu cầu
 * cột ĐÃ tài liệu hoá tồn tại đúng kiểu, không cấm cột runtime bổ sung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_scan_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('inbound_scan_entries', 'candidate_item_unique')) {
                $table->string('candidate_item_unique', 150)->nullable();
            }
            if (! Schema::hasColumn('inbound_scan_entries', 'candidate_serial_number')) {
                $table->string('candidate_serial_number', 150)->nullable();
            }
        });

        // Duplicate-guard theo document + cross-document tra theo giá trị này.
        // CREATE INDEX IF NOT EXISTS: cú pháp chung của cả pgsql lẫn sqlite, an
        // toàn khi migration chạy lại trên schema đã có sẵn cột/index.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_inbound_scan_candidate_unique ON inbound_scan_entries (candidate_item_unique)'
        );
    }

    public function down(): void
    {
        HoaNamErdSchema::dropIndexIfExists('inbound_scan_entries', 'idx_inbound_scan_candidate_unique');
        HoaNamErdSchema::dropColumnsCascade('inbound_scan_entries', [
            'candidate_item_unique',
            'candidate_serial_number',
        ]);
    }
};
