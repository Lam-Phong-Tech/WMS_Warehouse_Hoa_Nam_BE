<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bản vá tiến cho 2026_08_25_171000_add_department_master_and_user_department:
 * migration đó test `Schema::hasTable('app_user')` để quyết định có thêm cột
 * department_id hay không, nhưng trên PostgreSQL app_user là VIEW (không phải
 * TABLE) nên hasTable() trả về false — toàn bộ khối thêm cột bị bỏ qua.
 * Migration đã "Ran" từ trước với bug này nên sửa lại file gốc không có tác
 * dụng gì trên các DB đã migrate; cần bản vá tiến độc lập, idempotent.
 *
 * Hậu quả thực tế đã xảy ra: mọi PUT /api/v1/users/{id} ném
 * SQLSTATE[42703] "column department_id of relation app_user does not exist"
 * (500) vì Modules\Auth\Models\User::$fillable có department_id nhưng cột đó
 * chưa từng được tạo ở bất cứ đâu trên PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasColumn('app_users', 'department_id')) {
            Schema::table('app_users', function (Blueprint $table): void {
                $table->uuid('department_id')->nullable()->index();
            });
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW app_user AS
            SELECT id AS user_id, username, display_name, email, phone,
                   password_hash, status, created_at, updated_at, NULL::timestamptz AS deleted_at, version,
                   department_id
              FROM app_users
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_app_user_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    UPDATE app_users SET status='INACTIVE', updated_at=CURRENT_TIMESTAMP, version=version+1 WHERE id=OLD.user_id;
                    RETURN OLD;
                END IF;
                INSERT INTO app_users (id, username, display_name, email, phone, department_id, password_hash, status, created_at, updated_at, version)
                VALUES (COALESCE(NEW.user_id, gen_random_uuid()), NEW.username, NEW.display_name, NEW.email, NEW.phone, NEW.department_id, NEW.password_hash,
                        CASE WHEN NEW.deleted_at IS NULL THEN COALESCE(NEW.status, 'ACTIVE') ELSE 'INACTIVE' END,
                        COALESCE(NEW.created_at, CURRENT_TIMESTAMP), COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), COALESCE(NEW.version, 0))
                ON CONFLICT (id) DO UPDATE SET
                    username=EXCLUDED.username, display_name=EXCLUDED.display_name, email=EXCLUDED.email,
                    phone=EXCLUDED.phone, department_id=EXCLUDED.department_id, password_hash=EXCLUDED.password_hash, status=EXCLUDED.status,
                    updated_at=EXCLUDED.updated_at, version=EXCLUDED.version;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        // Fail-forward, matching 2026_08_21_100000's convention: rolling this
        // compat view back to a department_id-less shape is not worth
        // reintroducing the original data-loss bug for.
    }
};
