<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role', function (Blueprint $table) {
            $table->uuid('role_id')->primary();
            $table->string('role_code', 60)->unique();
            $table->string('role_name', 200);
            $table->string('scope_type', 20)->nullable(); // SYSTEM | WAREHOUSE | FUNCTION
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | INACTIVE
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role');
    }
};
