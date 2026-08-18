<?php

use App\Support\HoaNamErdSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng dùng chung cho App\Support\IdempotencyStore — cơ chế idempotency DUY NHẤT của dự án.
 * Khóa unique gồm cả user_id ngay từ đầu (ERP-Backend gốc từng thiếu user_id trong unique
 * key ban đầu, gây rò response giữa các user, phải vá sau — ở đây làm đúng ngay từ đầu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('idem_key', 100)->comment('Idempotency-Key từ client');
            $table->string('user_id', 40)->default('0')->comment('Chủ sở hữu khóa (UUID app_user.user_id), "0" = chưa xác định');
            $table->string('operation', 160)->comment('Định danh thao tác + đối tượng, vd create_inbound_document');
            $table->string('business_key', 180)->unique()->comment('sha1(operation|user_id|idem_key)');
            $table->string('request_hash', 128)->nullable()->comment('Hash request body để phát hiện xung đột payload');
            $table->string('status', 20)->default('processing')->comment('processing | done | failed');
            $table->smallInteger('response_code')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('locked_until')->comment('Thời gian lock tối đa trước khi cho chiếm lại khóa');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['idem_key', 'operation', 'user_id'], 'uk_idem_keys_key_op_user');
            $table->index('locked_until', 'idx_idem_locked_until');
            $table->index(['operation', 'status'], 'idx_idem_operation_status');
        });
    }

    public function down(): void
    {
        HoaNamErdSchema::dropRelation('idempotency_keys');
    }
};
