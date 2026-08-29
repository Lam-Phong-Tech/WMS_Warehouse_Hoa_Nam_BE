<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `public_quote_requests` — yêu cầu tư vấn gửi từ storefront ẩn danh
 * (POST /api/v1/public/quote-requests).
 *
 * Phạm vi CỐ Ý tối thiểu, đúng hợp đồng FE: "không cần, và không được thêm
 * CRM/ERP, notification, queue, outbox, retry worker, giá/tồn kho/cart/order".
 * Bảng này chỉ GHI NHẬN yêu cầu; không có trạng thái xử lý, không có luồng
 * nghiệp vụ downstream, không đụng tới ledger tồn kho.
 *
 * Tên bảng mang tiền tố `public_` để tách hẳn khỏi 59 bảng canonical của ERD
 * (docs/HoaNam_ERD_Moi_Nhat.md): đây là dữ liệu của kênh bán công khai, không
 * phải một thực thể trong mô hình kho.
 *
 * KHÔNG đặt khoá ngoại tới sản phẩm: bảng SKU vật lý mang tên khác nhau giữa
 * hai driver (`skus` trên PostgreSQL, `sku` trên sqlite — xem
 * Modules\Warehouse\Support\PvRelations), nên một FK cứng sẽ chỉ chạy được trên
 * một driver. Ràng buộc "sản phẩm phải đang publish và còn hàng" được kiểm ở
 * tầng ứng dụng, nơi nó vốn phải nằm vì điều kiện đó phụ thuộc tồn kho chứ
 * không phải phụ thuộc khoá.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('public_quote_requests')) {
            return;
        }

        Schema::create('public_quote_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Idempotency-Key của client. UNIQUE là thứ DUY NHẤT thật sự chống
            // trùng: người dùng bấm gửi hai lần hoặc mạng chập chờn khiến FE
            // retry đều rơi vào cùng một khoá, và lần thứ hai đọc lại bản ghi cũ
            // thay vì tạo yêu cầu mới. Kiểm tra ở tầng ứng dụng thôi là không đủ
            // vì hai request song song cùng vượt qua bước kiểm tra.
            $table->string('idempotency_key', 128)->unique();

            // Băm payload để phát hiện "cùng khoá nhưng khác nội dung" -> 409.
            $table->string('payload_hash', 64);

            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable();

            $table->string('full_name', 120);
            $table->string('phone', 32);
            // Số điện thoại đã chuẩn hoá (bỏ khoảng trắng/dấu chấm) để về sau
            // gộp trùng mà không phải đoán định dạng người dùng gõ.
            $table->string('phone_normalized', 32);
            $table->string('province_code', 16)->nullable();
            $table->text('note')->nullable();

            $table->boolean('consent');
            $table->string('privacy_version', 32);

            // RECEIVED là trạng thái DUY NHẤT ở phạm vi này. Cột tồn tại để
            // response có chỗ đọc ra, không phải để mở máy trạng thái.
            $table->string('status', 24)->default('RECEIVED');
            $table->string('source', 32)->default('PUBLIC_APP');

            // Dấu vết chống lạm dụng. IP tối đa 45 ký tự cho IPv6.
            $table->string('request_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            // Truy vấn vận hành hay dùng nhất: "các yêu cầu mới nhất".
            $table->index(['created_at'], 'idx_public_quote_requests_created_at');
            $table->index(['product_id'], 'idx_public_quote_requests_product_id');
            $table->index(['phone_normalized'], 'idx_public_quote_requests_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_quote_requests');
    }
};
