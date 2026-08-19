<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-331 (FE331-P1-08R4) — CHỖ GIỮ CHÍNH DANH (durable claim) CHO IMPORT COMMIT.
 *
 * Bất biến phải giữ: với mỗi bộ định danh `(actor_id, file_sha256, options_fingerprint)`,
 * bước "kiểm tra đã import chưa" và bước "ghi SKU + tạo Import báo cáo" phải là MỘT thao
 * tác nguyên tử. Vòng DEV330-P1-07R3 đã bỏ nhánh fail-open lúc hết thời gian chờ, nhưng
 * bất biến đó vẫn treo trên MỘT sợi dây duy nhất là `Cache::lock`, và sợi dây ấy đứt
 * lặng lẽ trong ba hoàn cảnh có thật, không hoàn cảnh nào ném lỗi để ai đó biết:
 *
 * 1. **Cache store không khoá được liên tiến trình.** `array`/`null` (và `file` khi chạy
 *    nhiều node không dùng chung đĩa) vẫn trả về một đối tượng Lock hợp lệ và `block()`
 *    vẫn "thành công" cho CẢ HAI request. Không có exception, không có log — hai request
 *    cùng đi qua bước kiểm tra trùng và cùng ghi, ra hai dòng `import_reports` COMMITTED
 *    cho cùng một file.
 * 2. **Redis/Valkey hỏng.** Khoá không dựng được thì hoặc ném lỗi (500 cho một request
 *    hợp lệ) hoặc bị nuốt — cả hai đều không phải "từ chối đúng cách".
 * 3. **`lock.on_timeout = FAIL_OPEN`.** Hợp đồng cho phép cấu hình này, và nó tái tạo lại
 *    ĐÚNG lỗ hổng vừa vá — chỉ khác là lần này có văn bản.
 *
 * Bảng này là chốt chặn không phụ thuộc cache: một dòng = một request đang giữ quyền
 * chạy cho đúng bộ định danh đó. `identity_hash` UNIQUE nên chính cơ sở dữ liệu (nơi
 * đằng nào cũng phải ghi kết quả) là trọng tài, dùng chung cho mọi node, mọi driver, và
 * không tồn tại kịch bản "khoá im lặng không khoá gì".
 *
 * `expires_at` KHÔNG phải cửa sổ idempotent (24h, DEC-IMP-001 §2) — nó chỉ là van cứu
 * hộ cho tiến trình chết giữa chừng: hết hạn thì request sau được TIẾP QUẢN, và mỗi lần
 * tiếp quản đều ghi log WARNING (`SKU_IMPORT_CLAIM_TAKEOVER`) chứ không bao giờ im lặng.
 *
 * KHÔNG phải thực thể ERD: đây là bảng vận hành cùng loại với `import_reports` (cũng
 * không nằm trong 59 bảng canonical — xem DEC-IMP-001 §5). `ErdCompilerSourcePinTest` /
 * `ErdPhysicalSchemaParityTest` kiểm "đủ 59 bảng tài liệu hoá và đúng kiểu quan hệ", chứ
 * không cấm bảng vận hành, nên TABLE_COUNT giữ nguyên 59.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sku_import_claims')) {
            return;
        }

        Schema::create('sku_import_claims', function (Blueprint $table) {
            // sha256(actor_id|file_sha256|options_fingerprint) — xem SkuImportContract::identityHash().
            // UNIQUE ở đây chính là chốt chặn: hai request cùng định danh thì đúng một
            // request chèn được dòng này, request kia nhận unique violation.
            $table->string('identity_hash', 64)->primary();

            // Token của riêng lần chạy này. Giải phóng luôn kèm điều kiện `owner_token`
            // để một request đã bị TIẾP QUẢN không xoá mất chỗ giữ của người kế nhiệm.
            $table->string('owner_token', 64);

            // Ba trường dưới đây thuần để điều tra sự cố (đọc bảng là biết ai đang giữ,
            // giữ file nào, với tùy chọn nào) — logic không đọc chúng.
            $table->string('actor_id', 64);
            $table->string('file_sha256', 64);
            $table->string('options_fingerprint', 64);
            $table->string('request_id', 64)->nullable();

            $table->timestamp('claimed_at');
            $table->timestamp('expires_at')->index('idx_sku_import_claims_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_import_claims');
    }
};
