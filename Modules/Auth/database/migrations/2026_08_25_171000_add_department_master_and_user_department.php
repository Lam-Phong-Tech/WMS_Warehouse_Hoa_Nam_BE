<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('department')) {
            Schema::create('department', function (Blueprint $table): void {
                $table->uuid('department_id')->primary();
                $table->string('department_code', 80)->unique();
                $table->string('department_name', 200);
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->string('description', 500)->nullable();
                $table->timestamps();
            });
        }

        // Trên PostgreSQL `app_user` là VIEW tương thích ngược (xem
        // Modules/Warehouse/.../2026_08_21_100000_fix_iam_legacy_view_dropped_columns.php);
        // Schema::hasTable('app_user') trả về false cho VIEW nên khối thêm cột
        // dưới đây sẽ bị bỏ qua hoàn toàn nếu target sai tên — cột department_id
        // không bao giờ được tạo ở đâu cả (không trên app_users, không trên
        // view), UPDATE .../users/{id} nào cũng ăn "column does not exist".
        // Bảng vật lý canonical đúng driver: pgsql -> app_users, sqlite -> app_user.
        $userTable = DB::getDriverName() === 'pgsql' ? 'app_users' : 'app_user';

        if (Schema::hasTable($userTable) && ! Schema::hasColumn($userTable, 'department_id')) {
            Schema::table($userTable, function (Blueprint $table): void {
                // Nullable during rollout: existing users keep working until BA
                // assigns the confirmed department master values.
                $table->uuid('department_id')->nullable()->index();
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->refreshAppUserCompatView();
        }
    }

    public function down(): void
    {
        $userTable = DB::getDriverName() === 'pgsql' ? 'app_users' : 'app_user';

        if (Schema::hasTable($userTable) && Schema::hasColumn($userTable, 'department_id')) {
            Schema::table($userTable, fn (Blueprint $table) => $table->dropColumn('department_id'));
        }
        Schema::dropIfExists('department');
    }

    /**
     * app_user (view) chỉ chiếu đúng danh sách cột được liệt kê tường minh
     * trong CREATE VIEW — thêm cột vào app_users vật lý không tự động lộ ra
     * qua view. Phải recreate cả view lẫn trigger ghi hai chiều, giữ nguyên
     * mọi cột hiện có (theo đúng bản 2026_08_21_100000) và thêm department_id.
     */
    private function refreshAppUserCompatView(): void
    {
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
};
