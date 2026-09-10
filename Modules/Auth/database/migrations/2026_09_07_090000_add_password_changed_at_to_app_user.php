<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-SEC-02 — mốc thời gian đổi mật khẩu, dùng để vô hiệu hoá access token cũ.
 *
 * Vấn đề: đổi mật khẩu chỉ thu hồi refresh token (AuthController::changePassword,
 * PasswordResetController::resetPassword). Access token JWT là stateless, không
 * mang claim nào suy ra được credential đã đổi, nên token bị đánh cắp TRƯỚC lúc
 * đổi mật khẩu vẫn dùng được tới khi hết hạn tự nhiên — với JWT_TTL đang dùng
 * cho máy quét thì đó là một khoảng rất dài. Đổi mật khẩu vì nghi bị lộ token
 * do đó không cắt được quyền truy cập của kẻ tấn công.
 *
 * Cột này là nguồn sự thật cho EnforceCredentialFreshness: token có `iat` cũ hơn
 * `password_changed_at` bị từ chối 401.
 *
 * Bắt buộc theo pattern 2 bước (cột vật lý trên app_users + rebuild view/trigger)
 * của 2026_08_27_150100_add_first_login_policy_to_app_user.php — app_user là VIEW
 * trên PostgreSQL và dự án đã mất cột 2 lần (phone, department_id) vì quên bước
 * rebuild. Định nghĩa view/trigger dưới đây được đối chiếu 1:1 với bản đang chạy
 * (pg_get_viewdef + pg_proc.prosrc) rồi mới thêm đúng một cột.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->upPgsql();

            return;
        }

        // sqlite (test): app_user vẫn là bảng vật lý gốc.
        Schema::table('app_user', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_user', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable();
            }
        });
    }

    private function upPgsql(): void
    {
        Schema::table('app_users', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_users', 'password_changed_at')) {
                $table->timestampTz('password_changed_at')->nullable();
            }
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW app_user AS
            SELECT id AS user_id, username, display_name, email, phone,
                   password_hash, status, created_at, updated_at, NULL::timestamptz AS deleted_at, version,
                   department_id, must_change_password, first_login_deadline_at, last_login_at,
                   password_changed_at
              FROM app_users
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_app_user_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    UPDATE app_users SET status='INACTIVE', updated_at=CURRENT_TIMESTAMP, version=version+1 WHERE id=OLD.user_id;
                    RETURN OLD;
                END IF;
                INSERT INTO app_users (id, username, display_name, email, phone, department_id, password_hash, status,
                        must_change_password, first_login_deadline_at, last_login_at, password_changed_at,
                        created_at, updated_at, version)
                VALUES (COALESCE(NEW.user_id, gen_random_uuid()), NEW.username, NEW.display_name, NEW.email, NEW.phone, NEW.department_id, NEW.password_hash,
                        CASE WHEN NEW.deleted_at IS NULL THEN COALESCE(NEW.status, 'ACTIVE') ELSE 'INACTIVE' END,
                        COALESCE(NEW.must_change_password, false), NEW.first_login_deadline_at, NEW.last_login_at, NEW.password_changed_at,
                        COALESCE(NEW.created_at, CURRENT_TIMESTAMP), COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), COALESCE(NEW.version, 0))
                ON CONFLICT (id) DO UPDATE SET
                    username=EXCLUDED.username, display_name=EXCLUDED.display_name, email=EXCLUDED.email,
                    phone=EXCLUDED.phone, department_id=EXCLUDED.department_id, password_hash=EXCLUDED.password_hash, status=EXCLUDED.status,
                    must_change_password=EXCLUDED.must_change_password, first_login_deadline_at=EXCLUDED.first_login_deadline_at,
                    last_login_at=EXCLUDED.last_login_at, password_changed_at=EXCLUDED.password_changed_at,
                    updated_at=EXCLUDED.updated_at, version=EXCLUDED.version;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    /**
     * Rollback thật (WWHN-300 `migrations.reversible`, 10/09/2026).
     *
     * sqlite: bỏ `password_changed_at` khỏi bảng `app_user`.
     * pgsql: view `app_user` và `hn_legacy_app_user_write()` về đúng bản 2026_08_27_150100
     * (DROP rồi dựng lại view vì CREATE OR REPLACE không bỏ được cột, gắn lại trigger
     * INSTEAD OF), rồi bỏ cột vật lý khỏi `app_users`.
     *
     * Hệ quả bảo mật cần biết trước khi rollback: mốc đổi mật khẩu mất theo cột. Nếu sau đó
     * build mới được deploy lại, access token phát TRƯỚC một lần đổi mật khẩu cũ không còn bị
     * EnforceCredentialFreshness từ chối cho tới khi tự hết hạn (tối đa JWT_TTL). Rollback
     * trong một sự cố nghi lộ token thì phải xoay JWT_SECRET để thu hồi toàn bộ token.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->downPgsql();

            return;
        }

        if (Schema::hasColumn('app_user', 'password_changed_at')) {
            Schema::table('app_user', function (Blueprint $table): void {
                $table->dropColumn('password_changed_at');
            });
        }
    }

    private function downPgsql(): void
    {
        DB::statement('DROP VIEW IF EXISTS app_user');
        DB::statement(<<<'SQL'
            CREATE VIEW app_user AS
            SELECT id AS user_id, username, display_name, email, phone,
                   password_hash, status, created_at, updated_at, NULL::timestamptz AS deleted_at, version,
                   department_id, must_change_password, first_login_deadline_at, last_login_at
              FROM app_users
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hn_legacy_app_user_write() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    UPDATE app_users SET status='INACTIVE', updated_at=CURRENT_TIMESTAMP, version=version+1 WHERE id=OLD.user_id;
                    RETURN OLD;
                END IF;
                INSERT INTO app_users (id, username, display_name, email, phone, department_id, password_hash, status,
                        must_change_password, first_login_deadline_at, last_login_at,
                        created_at, updated_at, version)
                VALUES (COALESCE(NEW.user_id, gen_random_uuid()), NEW.username, NEW.display_name, NEW.email, NEW.phone, NEW.department_id, NEW.password_hash,
                        CASE WHEN NEW.deleted_at IS NULL THEN COALESCE(NEW.status, 'ACTIVE') ELSE 'INACTIVE' END,
                        COALESCE(NEW.must_change_password, false), NEW.first_login_deadline_at, NEW.last_login_at,
                        COALESCE(NEW.created_at, CURRENT_TIMESTAMP), COALESCE(NEW.updated_at, CURRENT_TIMESTAMP), COALESCE(NEW.version, 0))
                ON CONFLICT (id) DO UPDATE SET
                    username=EXCLUDED.username, display_name=EXCLUDED.display_name, email=EXCLUDED.email,
                    phone=EXCLUDED.phone, department_id=EXCLUDED.department_id, password_hash=EXCLUDED.password_hash, status=EXCLUDED.status,
                    must_change_password=EXCLUDED.must_change_password, first_login_deadline_at=EXCLUDED.first_login_deadline_at,
                    last_login_at=EXCLUDED.last_login_at,
                    updated_at=EXCLUDED.updated_at, version=EXCLUDED.version;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER trg_hn_legacy_app_user_write INSTEAD OF INSERT OR UPDATE OR DELETE ON app_user FOR EACH ROW EXECUTE FUNCTION hn_legacy_app_user_write()');

        Schema::table('app_users', function (Blueprint $table): void {
            $table->dropColumn('password_changed_at');
        });
    }
};
