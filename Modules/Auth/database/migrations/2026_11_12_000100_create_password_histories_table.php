<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lịch sử mật khẩu để chặn tái sử dụng.
 *
 * - Mật khẩu do hệ thống cấp (admin tạo tài khoản, admin đặt lại, bootstrap)
 *   được giữ VĨNH VIỄN: sau khi A→B, A không bao giờ được dùng lại.
 * - Mật khẩu người dùng tự đặt chỉ giữ `login_security.password.history_limit`
 *   bản gần nhất (mặc định 5), để chính sách vẫn cấu hình được.
 *
 * Bảng phụ độc lập, không thêm cột vào app_user — app_user là VIEW trên
 * PostgreSQL và đã từng làm mất cột khi quên rebuild view/trigger. FK vì vậy
 * trỏ thẳng bảng vật lý app_users trên PostgreSQL, giống password_reset_otps.
 *
 * Backfill: tài khoản cũ chưa có lịch sử. Mật khẩu hiện tại của tài khoản chưa
 * từng đổi (password_changed_at NULL) hoặc đang bắt buộc đổi được coi là mật
 * khẩu hệ thống cấp và đưa vào lịch sử vĩnh viễn. Nhờ vậy tài khoản QA đang
 * dùng mật khẩu ban đầu vẫn chặn được A→B→A ngay sau khi triển khai.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('password_histories', function (Blueprint $table) use ($isPgsql) {
            $table->bigIncrements('id');

            if ($isPgsql) {
                $table->uuid('user_id');
                $table->foreign('user_id')->references('id')->on('app_users')->cascadeOnDelete();
                $table->timestampTz('created_at')->useCurrent();
            } else {
                $table->foreignUuid('user_id')->constrained('app_user', 'user_id')->cascadeOnDelete();
                $table->timestamp('created_at')->useCurrent();
            }

            $table->string('password_hash');
            $table->string('source', 20);

            $table->index(['user_id', 'source', 'id']);
        });

        $table = $isPgsql ? 'app_users' : 'app_user';
        $userColumn = $isPgsql ? 'id' : 'user_id';

        DB::statement(
            "INSERT INTO password_histories (user_id, password_hash, source, created_at)
             SELECT {$userColumn}, password_hash, 'SYSTEM_ISSUED',
                    COALESCE(password_changed_at, created_at, CURRENT_TIMESTAMP)
               FROM {$table}
              WHERE password_hash IS NOT NULL
                AND (password_changed_at IS NULL OR must_change_password = TRUE)"
        );
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('password_histories');
    }
};
