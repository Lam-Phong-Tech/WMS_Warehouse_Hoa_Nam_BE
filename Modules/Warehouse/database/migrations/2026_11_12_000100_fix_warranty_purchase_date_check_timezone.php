<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * chk_erd_warranty_purchase_date đang so purchase_date với CURRENT_DATE của
 * PostgreSQL, trong khi session DB là UTC còn APP_TIMEZONE là Asia/Ho_Chi_Minh.
 * Từ 00:00 đến 07:00 giờ Việt Nam, "hôm nay" của ứng dụng đã sang ngày mới
 * nhưng CURRENT_DATE của DB vẫn là ngày cũ; tiếp nhận bảo hành với
 * purchase_date = hôm nay bị constraint từ chối (SQLSTATE 23514) và API trả 500.
 *
 * So sánh bằng ngày lịch Việt Nam để khớp rule before_or_equal:today của
 * Laravel. KHÔNG đổi timezone session DB: contract timestamp của hệ thống vẫn
 * lưu/đọc theo UTC, chỉ biểu thức CHECK này cần mốc ngày địa phương.
 */
return new class extends Migration
{
    private const TIMEZONE = 'Asia/Ho_Chi_Minh';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE warranty_cases DROP CONSTRAINT IF EXISTS chk_erd_warranty_purchase_date');
        DB::statement(
            'ALTER TABLE warranty_cases ADD CONSTRAINT chk_erd_warranty_purchase_date '
            ."CHECK (purchase_date IS NULL OR purchase_date <= ((CURRENT_TIMESTAMP AT TIME ZONE '".self::TIMEZONE."')::date))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE warranty_cases DROP CONSTRAINT IF EXISTS chk_erd_warranty_purchase_date');
        DB::statement(
            'ALTER TABLE warranty_cases ADD CONSTRAINT chk_erd_warranty_purchase_date '
            .'CHECK (purchase_date IS NULL OR purchase_date <= CURRENT_DATE)'
        );
    }
};
