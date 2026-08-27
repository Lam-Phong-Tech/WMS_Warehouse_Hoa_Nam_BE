<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng phụ độc lập cho OTP quên mật khẩu (mẫu theo refresh_tokens) — cố tình
 * KHÔNG thêm cột vào app_user để tránh landmine view/trigger Postgres đã lộ 2
 * lần (phone, department_id): xem 2026_08_21_100000_fix_iam_legacy_view_dropped_columns.php
 * và 2026_08_26_000100_fix_app_user_department_id_view_gap.php.
 *
 * FK KHÔNG thể trỏ thẳng `app_user` trên Postgres: app_user là VIEW (không
 * phải TABLE), Postgres từ chối "referenced relation is not a table". Bảng
 * refresh_tokens cũ trỏ được vì migration đó chạy từ lúc app_user còn là bảng
 * vật lý; migration mới phải trỏ thẳng bảng vật lý app_users (PK cột `id`,
 * được view alias thành `user_id`) trên Postgres, và app_user/user_id trên sqlite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('password_reset_otps', function (Blueprint $table) use ($isPgsql) {
            $table->uuid('id')->primary();
            if ($isPgsql) {
                $table->uuid('user_id');
                $table->foreign('user_id')->references('id')->on('app_users')->cascadeOnDelete();
            } else {
                $table->foreignUuid('user_id')->constrained('app_user', 'user_id')->cascadeOnDelete();
            }
            $table->string('email', 200);
            $table->string('otp_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('reset_token_hash')->nullable();
            $table->timestamp('reset_token_expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('expires_at');
            $table->index('reset_token_hash');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('password_reset_otps');
    }
};
