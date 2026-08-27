<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cùng pattern driver-aware với 2026_08_25_190001_create_notification_recipients_table:
        // bảng vật lý canonical của user aggregate đảo chiều theo driver.
        $isPgsql = DB::getDriverName() === 'pgsql';
        $userTable = $isPgsql ? 'app_users' : 'app_user';
        $userKey = $isPgsql ? 'id' : 'user_id';

        Schema::table('notifications', function (Blueprint $table) use ($userTable, $userKey) {
            // Người thực hiện hành động sinh ra thông báo (vd người Post điều
            // chỉnh, người bắt đầu quét...). Nullable vì một số sự kiện không
            // gắn với 1 actor xác thực (vd cảnh báo bảo mật khi đăng nhập thất
            // bại). nullOnDelete để lịch sử thông báo không mất khi actor bị xoá.
            $table->foreignUuid('actor_id')->nullable()->after('subject_id')
                ->references($userKey)->on($userTable)->nullOnDelete();
            // Snapshot tên hiển thị tại thời điểm tạo thông báo — giữ nguyên dù
            // sau này actor đổi tên hoặc bị xoá tài khoản (bảng notifications append-only).
            $table->string('actor_name', 150)->nullable()->after('actor_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('actor_id');
            $table->dropColumn('actor_name');
        });
    }
};
