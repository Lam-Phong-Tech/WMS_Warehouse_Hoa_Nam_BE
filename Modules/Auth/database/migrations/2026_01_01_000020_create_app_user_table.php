<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * app_user theo docs/HoaNam_ERD_v1.0.md §5.1 — tên bảng cố ý tránh từ khóa "users" mặc định
 * của Laravel (điểm mở WWHN-31 mục 1 đã áp dụng phương án mặc định).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_user', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->string('username', 100)->unique();
            $table->string('display_name', 200);
            $table->string('email', 200)->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->string('password_hash');
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | INACTIVE | LOCKED
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_user');
    }
};
