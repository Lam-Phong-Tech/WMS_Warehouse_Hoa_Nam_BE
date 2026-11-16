<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pv_image_uploads', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('disk', 80);
            $table->string('storage_key', 500);
            $table->string('public_url', 500)->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pv_image_uploads');
    }
};
