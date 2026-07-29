<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_role', function (Blueprint $table) {
            $table->uuid('user_role_id')->primary();
            $table->foreignUuid('user_id')->constrained('app_user', 'user_id')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('role', 'role_id')->restrictOnDelete();
            // scope_ref: để ngỏ cho nâng cấp sau (vd gán role theo phạm vi cụ thể); base 1 kho
            // không cần dùng tới, luôn null.
            $table->string('scope_ref', 100)->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'scope_ref', 'effective_from'], 'uk_user_role_scope_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role');
    }
};
