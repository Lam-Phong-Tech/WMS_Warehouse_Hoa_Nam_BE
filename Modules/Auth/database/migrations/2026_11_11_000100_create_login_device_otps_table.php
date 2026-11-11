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
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('login_device_otps', function (Blueprint $table) use ($isPgsql): void {
            $table->uuid('id')->primary();
            if ($isPgsql) {
                $table->uuid('user_id');
                $table->foreign('user_id')->references('id')->on('app_users')->cascadeOnDelete();
            } else {
                $table->foreignUuid('user_id')->constrained('app_user', 'user_id')->cascadeOnDelete();
            }
            $table->string('email', 200);
            $table->string('purpose', 16)->default('DEVICE');
            $table->string('otp_hash', 255);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'expires_at'], 'login_device_otps_user_purpose_expires_idx');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('login_device_otps');
    }
};
