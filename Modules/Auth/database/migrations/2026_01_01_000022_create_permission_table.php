<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission', function (Blueprint $table) {
            $table->uuid('permission_id')->primary();
            $table->string('permission_key', 100)->unique(); // vd: inbound.post, inventory.reversal.post
            $table->string('permission_name', 200);
            $table->string('scope_type', 20)->nullable(); // SYSTEM | WAREHOUSE | FUNCTION
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE | INACTIVE
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission');
    }
};
