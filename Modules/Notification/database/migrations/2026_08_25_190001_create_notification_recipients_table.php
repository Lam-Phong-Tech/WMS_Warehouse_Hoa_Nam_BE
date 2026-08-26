<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bảng vật lý canonical của user aggregate đảo chiều theo driver (xem
        // Modules/Warehouse/.../2026_08_09_000400_align_latest_erd_baseline.php):
        // trên PostgreSQL `app_users` (cột khoá `id`) là bảng vật lý còn
        // `app_user` là VIEW tương thích ngược; trên sqlite thì ngược lại —
        // `app_user` (cột khoá `user_id`) là bảng vật lý, `app_users` chỉ là
        // VIEW alias. FK phải trỏ đúng bảng vật lý + đúng cột khoá của driver
        // đang chạy, nếu không insert sẽ ăn "foreign key mismatch" (sqlite) vì
        // view không mang theo ràng buộc UNIQUE/PK mà FK cần khớp.
        $isPgsql = DB::getDriverName() === 'pgsql';
        $userTable = $isPgsql ? 'app_users' : 'app_user';
        $userKey = $isPgsql ? 'id' : 'user_id';

        // Fan-out ghi sẵn lúc tạo notification: mỗi recipient một dòng, cho phép
        // truy vấn "thông báo của tôi" / "số chưa đọc" trực tiếp mà không phải
        // join lại permission/warehouse-scope mỗi lần đọc (đã resolve 1 lần lúc ghi).
        Schema::create('notification_recipients', function (Blueprint $table) use ($userTable, $userKey) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignUuid('user_id')->references($userKey)->on($userTable)->cascadeOnDelete();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['notification_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('notification_recipients');
    }
};
