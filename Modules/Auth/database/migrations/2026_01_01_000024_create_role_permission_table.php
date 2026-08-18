<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission', function (Blueprint $table) {
            $table->uuid('role_permission_id')->primary();
            $table->foreignUuid('role_id')->constrained('role', 'role_id')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('permission', 'permission_id')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id'], 'uk_role_permission');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('role_permission');
    }
};
