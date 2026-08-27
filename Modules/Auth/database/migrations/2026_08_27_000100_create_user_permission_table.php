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
        // Trên PostgreSQL `app_user` (số ít) chỉ là VIEW tương thích ngược trên
        // bảng vật lý `app_users` (số nhiều, PK `id`) — không FK được vào VIEW
        // (lỗi 42809 "referenced relation is not a table"). sqlite chưa retire
        // legacy runtime table nên `app_user`/`user_id` vẫn là bảng vật lý thật.
        // Cùng quy ước với 2026_08_25_171000_add_department_master_and_user_department.
        $userTable = DB::getDriverName() === 'pgsql' ? 'app_users' : 'app_user';
        $userKey = DB::getDriverName() === 'pgsql' ? 'id' : 'user_id';
        // permission (số ít) cũng bị retire thành VIEW trên PostgreSQL, bảng vật
        // lý canonical là permissions (số nhiều, PK id) — cùng lý do như app_user.
        $permissionTable = DB::getDriverName() === 'pgsql' ? 'permissions' : 'permission';
        $permissionKey = DB::getDriverName() === 'pgsql' ? 'id' : 'permission_id';

        Schema::create('user_permission', function (Blueprint $table) use ($userTable, $userKey, $permissionTable, $permissionKey) {
            $table->uuid('user_permission_id')->primary();
            $table->foreignUuid('user_id')->constrained($userTable, $userKey)->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained($permissionTable, $permissionKey)->cascadeOnDelete();
            // granted_by: ai đã cấp quyền riêng này; nullOnDelete để không mất lịch sử nếu người cấp bị xoá.
            $table->foreignUuid('granted_by')->nullable()->constrained($userTable, $userKey)->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'permission_id'], 'uk_user_permission');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('user_permission');
    }
};
