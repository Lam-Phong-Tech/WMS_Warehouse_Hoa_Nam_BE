<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-SEC-08 — xác thực hai bước (TOTP) cho tài khoản đặc quyền.
 *
 * BẢNG RIÊNG, KHÔNG THÊM CỘT VÀO `app_users`:
 *  - `app_user` là VIEW legacy trên `app_users` (giống warranty_case), thêm cột
 *    ở đó kéo theo sửa view và mọi nơi `SELECT *`.
 *  - Một hàng chỉ tồn tại khi người dùng THẬT SỰ bật MFA, nên bảng nhỏ và câu
 *    hỏi "ai đã bật MFA" trả lời bằng một COUNT thay vì quét toàn bộ tài khoản.
 *  - Bí mật TOTP có vòng đời và mức nhạy cảm riêng; tách bảng cho phép siết
 *    quyền đọc ở tầng DB nếu sau này cần.
 *
 * BÍ MẬT KHÔNG BAO GIỜ LƯU DẠNG RÕ. `secret_ciphertext` là AEAD của
 * App\Support\Security\PiiCrypto dưới keyring PII (WWHN-SEC-06) — lấy được bản
 * dump mà không có khoá thì không sinh được mã TOTP.
 *
 * MÃ KHÔI PHỤC LƯU DẠNG BĂM, không mã hoá: chúng là credential một chiều đúng
 * như mật khẩu; hệ thống chỉ cần KIỂM, không bao giờ cần đọc lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_mfa')) {
            return;
        }

        Schema::create('user_mfa', function (Blueprint $table): void {
            // user_id là khoá chính: một tài khoản có tối đa MỘT thiết bị TOTP.
            // Nhiều thiết bị là tính năng riêng, và nếu cần thì phải kèm theo
            // giao diện quản lý/thu hồi từng thiết bị — không được thêm âm thầm
            // bằng cách bỏ ràng buộc này.
            $table->uuid('user_id')->primary();

            $table->text('secret_ciphertext');

            // NULL = đã tạo bí mật nhưng CHƯA xác nhận bằng một mã hợp lệ. Bản
            // ghi chưa xác nhận KHÔNG được coi là đã bật MFA — nếu không, người
            // dùng quét QR hỏng rồi bỏ dở sẽ tự khoá mình ra khỏi hệ thống.
            $table->timestampTz('confirmed_at')->nullable();

            // Băm của các mã khôi phục còn hiệu lực (JSON mảng chuỗi băm).
            $table->json('recovery_codes')->nullable();

            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_used_at')->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['confirmed_at'], 'idx_user_mfa_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mfa');
    }
};
