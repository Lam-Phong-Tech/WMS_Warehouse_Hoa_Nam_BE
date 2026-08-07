<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chặn cứng ở DB: chỉ được tồn tại tối đa 1 warehouse có status=ACTIVE.
 * Postgres hỗ trợ partial unique index; SQLite (test suite) không hỗ trợ WHERE
 * trong CREATE UNIQUE INDEX theo cùng cú pháp nên chỉ tạo index này trên pgsql —
 * hành vi tương đương trên SQLite được đảm bảo ở tầng WarehouseRepository.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX uk_warehouses_single_active ON warehouses (status) WHERE status = \'ACTIVE\''
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS uk_warehouses_single_active');
        }
    }
};
