<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cấp tài khoản nhân viên: admin đặt mật khẩu ban đầu -> nhân viên phải đăng
 * nhập lần đầu trong 7 ngày (must_change_password + first_login_deadline_at)
 * hoặc bị khoá tự động (xem LockExpiredFirstLoginAccounts). Cũng nối
 * last_login_at vào view/trigger: cột này đã tồn tại trên bảng vật lý
 * app_users từ 2026_08_10_000774_sync_iam_runtime_to_erd.php nhưng chưa từng
 * được SELECT trong view app_user hay ghi trong trigger nên luôn NULL.
 *
 * Bắt buộc theo đúng pattern 2 bước (thêm cột vật lý + rebuild view/trigger)
 * của 2026_08_26_000100_fix_app_user_department_id_view_gap.php — app_user là
 * VIEW trên PostgreSQL, Schema::hasTable('app_user') trả false cho nó, và dự
 * án đã mất cột 2 lần (phone, department_id) vì quên bước rebuild này.
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
            if (! Schema::hasColumn('app_user', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false);
            }
            if (! Schema::hasColumn('app_user', 'first_login_deadline_at')) {
                $table->timestamp('first_login_deadline_at')->nullable();
            }
            if (! Schema::hasColumn('app_user', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
            }
        });
    }

    private function upPgsql(): void
    {
        Schema::table('app_users', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false);
            }
            if (! Schema::hasColumn('app_users', 'first_login_deadline_at')) {
                $table->timestamp('first_login_deadline_at')->nullable();
            }
            // last_login_at: cột vật lý đã tồn tại từ sync_iam_runtime_to_erd, không tạo lại.
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW app_user AS
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
    }

    /**
     * Rollback thật (WWHN-300 `migrations.reversible`, 10/09/2026).
     *
     * FAIL-CLOSED: từ chối khi còn tài khoản đang bị buộc đổi mật khẩu lần đầu
     * (`must_change_password = true`). Bỏ cột lúc đó sẽ âm thầm xoá nghĩa vụ đổi mật khẩu
     * của các tài khoản vừa được cấp mật khẩu ban đầu (kể cả SUPER_ADMIN tạo qua
     * `wms:bootstrap-super-admin`), và nghĩa vụ đó không quay lại khi migrate lại.
     *
     * sqlite: bỏ ba cột up() thêm vào bảng `app_user`.
     * pgsql: view `app_user` và `hn_legacy_app_user_write()` về đúng bản
     * 2026_08_26_000100 — view không bỏ được cột bằng CREATE OR REPLACE nên phải DROP rồi
     * dựng lại và gắn lại trigger INSTEAD OF — rồi bỏ hai cột vật lý up() thêm vào
     * `app_users`. `last_login_at` có từ 2026_08_10_000774 nên giữ nguyên.
     */
    public function down(): void
    {
        $relation = DB::getDriverName() === 'pgsql' ? 'app_users' : 'app_user';

        if (Schema::hasColumn($relation, 'must_change_password')) {
            $pending = DB::table($relation)->where('must_change_password', true)->count();

            if ($pending > 0) {
                throw new RuntimeException(
                    'Rollback 2026_08_27_150100 bị chặn: còn '.$pending.' tài khoản đang bị buộc đổi mật khẩu lần đầu '
                    .'(must_change_password = true). Bỏ cột lúc này sẽ âm thầm gỡ nghĩa vụ đó. '
                    .'Xử lý các tài khoản này (hoàn tất đăng nhập lần đầu hoặc khoá/đặt lại mật khẩu) rồi chạy lại rollback.'
                );
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->downPgsql();

            return;
        }

        $columns = array_values(array_filter(
            ['must_change_password', 'first_login_deadline_at', 'last_login_at'],
            static fn (string $column): bool => Schema::hasColumn('app_user', $column),
        ));

        if ($columns !== []) {
            Schema::table('app_user', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
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
        DB::statement('CREATE TRIGGER trg_hn_legacy_app_user_write INSTEAD OF INSERT OR UPDATE OR DELETE ON app_user FOR EACH ROW EXECUTE FUNCTION hn_legacy_app_user_write()');

        Schema::table('app_users', function (Blueprint $table): void {
            $table->dropColumn(['must_change_password', 'first_login_deadline_at']);
        });
    }
};
