<?php

namespace Tests\Feature;

use App\Support\HoaNamErdSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * WWHN-300 — "Backend GoLive check" (subtask of WWHN-80 cutover/rollback control).
 *
 * The command is the reviewable output of this ticket, so the contract that
 * matters is: it runs against a migrated+seeded database, it reports every
 * readiness dimension the cutover runbook asks about, and it fails loudly
 * rather than reporting an unknown state as ready.
 *
 * Nửa sau của lớp test này là các case NEGATIVE / FAULT-INJECTION cho
 * DEV300-P1-01..P1-04: mỗi case dựng đúng trạng thái nguy hiểm mà bản trước đây
 * vẫn cho PASS, rồi khẳng định lệnh thoát 1. Một gate chỉ được chứng minh bằng
 * lần nó BÁO ĐỎ, không phải bằng lần nó báo xanh.
 */
class Wwhn300BackendGoLiveCheckTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_CHECKS = [
        'migrations.applied',
        'migrations.reversible',
        'schema.canonical_relations',
        'seed.identity_markers',
        'security.account_hygiene',
        'security.runtime_config',
        'security.browser_runtime_contract',
        'security.route_default_deny',
        'release.build_identity',
        'runtime.queue_retry_window',
        'core.idempotency_store',
        'core.audit_backlog',
        'core.reconciliation_backlog',
    ];

    /**
     * The rest of the suite deliberately builds its own fixtures instead of
     * seeding, and RefreshDatabase shares one in-memory database across the
     * whole process — so `protected $seed` only takes effect when this class
     * happens to run first. Seed on demand instead, or the readiness gate would
     * silently pass/fail depending on test ordering.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! User::query()->where('username', 'admin')->exists()) {
            $this->artisan('db:seed', ['--force' => true])->run();
        }
    }

    public function test_golive_check_passes_on_a_migrated_and_seeded_backend(): void
    {
        $this->artisan('wms:golive-check')->assertSuccessful();
    }

    public function test_golive_check_emits_machine_readable_evidence_with_every_dimension(): void
    {
        $payload = $this->runCheck();

        $this->assertSame('WWHN-300', $payload['jira']);
        $this->assertSame('Backend GoLive check', $payload['output']);
        $this->assertSame('pass', $payload['overall']);
        $this->assertSame(0, $payload['failed_count']);

        foreach (self::EXPECTED_CHECKS as $check) {
            $this->assertArrayHasKey($check, $payload['checks'], "GoLive check is missing dimension {$check}");
            $this->assertContains($payload['checks'][$check]['status'], ['ok', 'warn'], "Dimension {$check} did not pass");
        }
    }

    /**
     * SRS v1.12 §8.1 default-deny. A route added without auth/permission
     * middleware passes every positive-path test but is an open endpoint in
     * production, so the readiness gate asserts the surface itself.
     */
    public function test_protected_api_surface_is_default_deny(): void
    {
        $detail = $this->runCheck()['checks']['security.route_default_deny']['detail'];

        $this->assertSame([], $detail['unauthenticated_non_public']);
        $this->assertSame([], $detail['authenticated_without_permission']);
    }

    /**
     * DEV300-P1-03 — bề mặt route phải khớp ĐÚNG ma trận đã pin, không chỉ "có
     * dạng đúng". Đây là điều bắt được lỗi gắn nhầm permission.
     */
    public function test_route_permission_matrix_matches_the_pinned_contract_exactly(): void
    {
        $detail = $this->runCheck()['checks']['security.route_default_deny']['detail'];

        $this->assertSame([], $detail['not_in_contract'], 'Route mới chưa được ghi vào ma trận đã pin.');
        $this->assertSame([], $detail['missing_from_runtime'], 'Ma trận đã pin còn route không tồn tại trong runtime.');
        $this->assertSame([], $detail['permission_mismatch'], 'Permission của route lệch so với bản đã duyệt.');
        $this->assertSame($detail['pinned_routes'], $detail['runtime_routes']);
    }

    /**
     * DEV300-P1-04 — tập fail-forward phải khớp CHÍNH XÁC allowlist có thẩm quyền.
     *
     * Bản trước chỉ pin ngưỡng `<= 75`, nên một migration fail-forward mới vẫn lọt
     * qua miễn là một migration khác rời khỏi tập. Nay so từng tên.
     */
    public function test_fail_forward_migrations_match_the_dispositioned_allowlist_exactly(): void
    {
        $detail = $this->runCheck()['checks']['migrations.reversible']['detail'];

        $this->assertSame(
            [],
            $detail['undispositioned'],
            'Migration fail-forward mới chưa có disposition: viết down() thật, hoặc thêm vào '
            .'database/golive/fail_forward_allowlist.php kèm duyệt của Tech Lead.',
        );
        $this->assertSame(
            [],
            $detail['stale_allowlist_entries'],
            'Allowlist còn mục đã có down() thật — gỡ ra để nó không che một mục mới.',
        );
        $this->assertSame($detail['dispositioned_count'], $detail['irreversible_count']);
    }

    /** DEV300-P1-04 — chiều ngược: bản ghi migration không còn file nguồn. */
    public function test_migration_ledger_matches_source_tree_in_both_directions(): void
    {
        $detail = $this->runCheck()['checks']['migrations.applied']['detail'];

        $this->assertSame([], $detail['pending']);
        $this->assertSame([], $detail['orphaned_in_database']);
    }

    // -----------------------------------------------------------------------
    // Negative / fault-injection
    // -----------------------------------------------------------------------

    /**
     * DEV300-P1-01 — `admin` ACTIVE dùng mật khẩu mặc định của seeder là trạng
     * thái mà bản trước tính là "readiness marker" và cho PASS.
     */
    public function test_default_credential_on_an_active_account_fails_the_gate_outside_development(): void
    {
        $this->pretendProductionEnvironment();

        // Mật khẩu ĐÃ LỘ của dự án — đọc từ deny-list thay vì chép literal vào test
        // (scripts/check-known-credentials.mjs chỉ cho phép nó nằm trong config/golive.php).
        // Trước 10/09/2026 test này đỏ vì chính giá trị đó bị cố ý loại khỏi deny-list.
        $compromised = (string) config('golive.credential.compromised.0');
        $this->assertNotSame('', $compromised);

        User::query()->where('username', 'admin')->update([
            'password_hash' => Hash::make($compromised),
        ]);

        $payload = $this->runCheck(expectFailure: true);
        $check = $payload['checks']['security.account_hygiene'];

        $this->assertSame('fail', $check['status']);
        $this->assertContains('admin', $check['detail']['accounts_on_default_credential']);
        $this->assertArrayHasKey('default_credentials', $check['detail']['findings']);
        // Evidence không bao giờ được chứa giá trị mật khẩu.
        $this->assertStringNotContainsString($compromised, (string) json_encode($check));
    }

    /** DEV300-P1-01 — tài khoản/role fixture còn sống trên môi trường production. */
    public function test_non_production_accounts_and_roles_fail_the_gate_outside_development(): void
    {
        $this->pretendProductionEnvironment();

        // Xoay vòng credential của mọi tài khoản để cô lập đúng phát hiện đang test.
        User::query()->update(['password_hash' => Hash::make('R0tated#Secret!2026')]);

        $payload = $this->runCheck(expectFailure: true);
        $detail = $payload['checks']['security.account_hygiene']['detail'];

        $this->assertSame([], $detail['accounts_on_default_credential']);
        $this->assertContains('test-super-admin', $detail['non_production_accounts']);
        $this->assertContains('staff01', $detail['non_production_accounts']);
        $this->assertContains('NO_PERMISSION', $detail['non_production_roles']);
    }

    /** DEV300-P1-01 — trạng thái sạch: gate xanh và ghi nhận bằng chứng xoay vòng. */
    public function test_account_hygiene_passes_once_fixtures_are_removed_and_credentials_rotated(): void
    {
        $this->pretendProductionEnvironment();

        $this->removeNonProductionFixtures();
        User::query()->update(['password_hash' => Hash::make('R0tated#Secret!2026')]);

        // Các dimension khác vẫn đỏ trên môi trường giả-production (thiếu
        // APP_BUILD_SHA, APP_URL localhost…), nên chỉ khẳng định đúng dimension này.
        $check = $this->runCheck(expectFailure: true)['checks']['security.account_hygiene'];

        $this->assertSame('ok', $check['status']);
        $this->assertSame([], $check['detail']['findings']);
        $this->assertStringContainsString('Không tài khoản ACTIVE nào', $check['detail']['rotation_evidence']);
    }

    /**
     * DEV300-P1-02 — trên môi trường production, `--allow-debug` KHÔNG được phép
     * tắt phát hiện APP_DEBUG=true. Đây chính là đường mà một non-dev operator
     * dùng để ép gate xanh.
     */
    public function test_allow_debug_override_is_rejected_on_a_production_environment(): void
    {
        $this->pretendProductionEnvironment();
        config()->set('app.debug', true);

        $payload = $this->runCheck(expectFailure: true, options: ['--allow-debug' => true]);
        $check = $payload['checks']['security.runtime_config'];

        $this->assertSame('fail', $check['status']);
        $this->assertSame('rejected', $check['detail']['debug_override']);
        $this->assertArrayHasKey('app_debug', $check['detail']['findings']);
    }

    /** Đối chứng: trên môi trường nằm trong allowlist, override vẫn dùng được. */
    public function test_allow_debug_override_still_applies_on_an_allowlisted_environment(): void
    {
        app()->detectEnvironment(fn (): string => 'development');
        config()->set('golive.development_environments', ['local', 'testing']);
        config()->set('golive.debug_override_environments', ['local', 'testing', 'development', 'dev']);
        config()->set('app.debug', true);

        $check = $this->runCheck(expectFailure: true, options: ['--allow-debug' => true])['checks']['security.runtime_config'];

        $this->assertSame('applied', $check['detail']['debug_override']);
        $this->assertArrayNotHasKey('app_debug', $check['detail']['findings']);
    }

    /**
     * DEV300-P1-03 — gỡ ĐÚNG unique index bảo đảm bất biến idempotency thì gate
     * phải đỏ. Trước đây trên SQLite biến kiểm tra mặc định `true` nên case này
     * vẫn PASS.
     */
    public function test_removing_the_idempotency_unique_index_fails_the_gate(): void
    {
        Schema::table('idempotency_keys', function ($table): void {
            $table->dropUnique('uk_idem_keys_key_op_user');
        });

        $check = $this->runCheck(expectFailure: true)['checks']['core.idempotency_store'];

        $this->assertSame('fail', $check['status']);
        $this->assertContains('idem_key,operation,user_id', $check['detail']['missing_unique_sets']);
    }

    /** DEV300-P1-03 — thiếu hạ tầng audit là FAIL, không phải "not present → ok". */
    public function test_missing_audit_outbox_fails_closed_instead_of_passing(): void
    {
        Schema::drop('audit_outbox');

        $check = $this->runCheck(expectFailure: true)['checks']['core.audit_backlog'];

        $this->assertSame('fail', $check['status']);
        $this->assertSame('missing', $check['detail']['state']);
        $this->assertSame('golive.governed_na.core.audit_backlog', $check['detail']['governed_na_key']);
    }

    /** ...trừ khi có disposition tường minh, và khi đó disposition đi vào evidence. */
    public function test_missing_audit_outbox_is_downgraded_only_by_an_explicit_disposition(): void
    {
        Schema::drop('audit_outbox');
        config()->set(
            'golive.governed_na.core.audit_backlog',
            'WWHN-298 — Tech Lead 2026-08-18 — outbox chưa triển khai ở môi trường này.',
        );

        $check = $this->runCheck()['checks']['core.audit_backlog'];

        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('WWHN-298', $check['detail']['disposition']);
    }

    /** DEV300-P1-03 — thiếu bảng reconciliation cũng phải fail-closed. */
    public function test_missing_reconciliation_relation_fails_closed(): void
    {
        // Tên số ít/số nhiều đảo chiều bảng↔view giữa PostgreSQL và SQLite, nên
        // phải gỡ cả hai để dựng đúng trạng thái "không có nguồn đối soát".
        foreach (['reconciliation_incidents', 'reconciliation_incident'] as $relation) {
            if (HoaNamErdSchema::relationExists($relation)) {
                HoaNamErdSchema::dropRelation($relation);
            }
        }

        $check = $this->runCheck(expectFailure: true)['checks']['core.reconciliation_backlog'];

        $this->assertSame('fail', $check['status']);
        $this->assertSame('missing', $check['detail']['state']);
    }

    /**
     * DEV300-P1-03 — gắn SAI permission cho một route ghi. Nó vẫn default-deny
     * đúng hình thức, nên chỉ ma trận đã pin mới bắt được.
     */
    public function test_a_wrong_permission_on_a_write_route_fails_the_pinned_contract(): void
    {
        /** @var array<string, string> $contract */
        $contract = require database_path((string) config('golive.contracts.route_permissions'));
        $key = 'POST api/v1/outbound-documents/{id}/post-issue';

        $this->assertArrayHasKey($key, $contract);
        $this->assertSame('permission:outbound.post', $contract[$key]);

        // Mô phỏng bản đã duyệt ghi `outbound.view` cho endpoint Post Issue: runtime
        // giữ `outbound.post`, nên gate phải báo lệch thay vì im lặng chấp nhận.
        $contract[$key] = 'permission:outbound.view';

        $this->withTemporaryRouteContract($contract, function () use ($key): void {
            $check = $this->runCheck(expectFailure: true)['checks']['security.route_default_deny'];

            $this->assertSame('fail', $check['status']);
            $this->assertArrayHasKey($key, $check['detail']['permission_mismatch']);
        });
    }

    /** DEV300-P1-03 — mất hợp đồng đã pin thì không kết luận được → FAIL. */
    public function test_a_missing_route_contract_fails_closed(): void
    {
        config()->set('golive.contracts.route_permissions', 'golive/does-not-exist.php');

        $check = $this->runCheck(expectFailure: true)['checks']['security.route_default_deny'];

        $this->assertSame('fail', $check['status']);
        $this->assertSame('missing', $check['detail']['contract']);
    }

    /** DEV300-P1-04 — mất allowlist fail-forward thì cũng không được PASS. */
    public function test_a_missing_fail_forward_allowlist_fails_closed(): void
    {
        config()->set('golive.contracts.fail_forward_migrations', 'golive/does-not-exist.php');

        $check = $this->runCheck(expectFailure: true)['checks']['migrations.reversible'];

        $this->assertSame('fail', $check['status']);
        $this->assertSame('missing', $check['detail']['allowlist']);
    }

    // -----------------------------------------------------------------------
    // WWHN-SEC-04 — trần vòng đời token
    // -----------------------------------------------------------------------

    /**
     * `.env` từng mang JWT_TTL=43200 (30 ngày) cho đợt quét, kèm ghi chú "KHÔNG
     * deploy giá trị này" — nhưng không có gate nào kiểm, nên nó lên được
     * production hoàn toàn im lặng. Đây là test khoá lại đường đó.
     */
    public function test_an_over_long_jwt_ttl_fails_the_gate_outside_development(): void
    {
        $this->pretendProductionEnvironment();
        config()->set('golive.session.max_jwt_ttl_minutes', 120);
        config()->set('jwt.ttl', 43200);

        $check = $this->runCheck(expectFailure: true)['checks']['security.runtime_config'];

        $this->assertSame('fail', $check['status']);
        $this->assertArrayHasKey('jwt_ttl', $check['detail']['findings']);
        $this->assertStringContainsString('43200', $check['detail']['findings']['jwt_ttl']);
    }

    /** TTL trống bị tymon hiểu là "token không bao giờ hết hạn". */
    public function test_an_empty_jwt_ttl_fails_the_gate_outside_development(): void
    {
        $this->pretendProductionEnvironment();
        config()->set('jwt.ttl', null);

        $check = $this->runCheck(expectFailure: true)['checks']['security.runtime_config'];

        $this->assertArrayHasKey('jwt_ttl', $check['detail']['findings']);
    }

    /** Blacklist tắt ⇒ đăng xuất không thu hồi được access token đã phát. */
    public function test_disabled_jwt_blacklist_fails_the_gate_outside_development(): void
    {
        $this->pretendProductionEnvironment();
        config()->set('jwt.blacklist_enabled', false);

        $check = $this->runCheck(expectFailure: true)['checks']['security.runtime_config'];

        $this->assertArrayHasKey('jwt_blacklist', $check['detail']['findings']);
    }

    /**
     * ĐỐI CHỨNG — quan trọng không kém các case đỏ ở trên: một gate báo đỏ với
     * MỌI cấu hình là gate vô dụng. Ở đây TTL hợp lệ (60 phút) nên phải KHÔNG có
     * phát hiện jwt_ttl, trong khi phát hiện app_debug vẫn xuất hiện — chứng tỏ
     * check thật sự chạy chứ không phải im lặng vì lý do khác.
     */
    public function test_a_policy_compliant_jwt_ttl_produces_no_finding(): void
    {
        $this->pretendProductionEnvironment();
        config()->set('golive.session.max_jwt_ttl_minutes', 120);
        config()->set('jwt.ttl', 60);
        config()->set('jwt.blacklist_enabled', true);
        config()->set('app.debug', true); // mồi để chắc chắn check có chạy

        $check = $this->runCheck(expectFailure: true)['checks']['security.runtime_config'];

        $this->assertArrayNotHasKey('jwt_ttl', $check['detail']['findings']);
        $this->assertArrayNotHasKey('jwt_blacklist', $check['detail']['findings']);
        $this->assertArrayHasKey('app_debug', $check['detail']['findings']);
    }

    /**
     * Trên môi trường dev, TTL dài là chuyện bình thường và không được báo đỏ —
     * nếu không, lập trình viên sẽ học cách phớt lờ gate.
     *
     * Dùng `runCheckIgnoringExit()` vì ở môi trường dev không có cách nào ép
     * gate đỏ một cách chắc chắn (app_debug bị bỏ qua ở đúng môi trường này),
     * mà điều cần khẳng định chỉ là NỘI DUNG phát hiện.
     */
    public function test_jwt_ttl_policy_does_not_apply_to_development(): void
    {
        app()->detectEnvironment(fn (): string => 'local');
        config()->set('golive.development_environments', ['local', 'testing']);
        config()->set('jwt.ttl', 43200);

        $check = $this->runCheckIgnoringExit()['checks']['security.runtime_config'];

        $this->assertArrayNotHasKey('jwt_ttl', $check['detail']['findings']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Chạy lệnh và trả về payload evidence.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runCheck(bool $expectFailure = false, array $options = []): array
    {
        $path = storage_path('app/testing/wwhn-300-golive-check.json');
        File::delete($path);

        $exitCode = $this->artisan('wms:golive-check', $options + ['--json' => $path])->run();

        $this->assertFileExists($path);
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) File::get($path), true);

        File::delete($path);

        // Thông báo lỗi phải nêu ĐÚNG dimension nào đỏ và vì sao; nếu chỉ báo
        // "expected 0 got 1" thì mỗi lần CI đỏ lại phải chạy lại tay để biết lý do.
        $failed = collect($payload['checks'] ?? [])
            ->filter(fn (array $check): bool => $check['status'] === 'fail')
            ->map(fn (array $check, string $name): string => $name.' => '
                .json_encode($check['detail'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->values()
            ->implode("\n");

        if (! $expectFailure) {
            $this->assertSame(0, $exitCode, "wms:golive-check phải thoát 0 ở trạng thái sẵn sàng.\n".$failed);
        } else {
            $this->assertSame(1, $exitCode, 'wms:golive-check phải thoát 1 khi có FAIL.');
        }

        return $payload;
    }

    /**
     * WWHN-SEC-04 — chạy gate và trả payload mà KHÔNG ràng buộc mã thoát.
     *
     * Dùng cho các case chỉ khẳng định về NỘI DUNG một phát hiện, ở những cấu
     * hình mà ta không kiểm soát được toàn bộ các dimension khác. Ép một
     * `expectFailure` sai chỗ sẽ biến test thành thứ đo nhầm đối tượng.
     *
     * @return array<string, mixed>
     */
    private function runCheckIgnoringExit(): array
    {
        $path = storage_path('app/testing/wwhn-300-golive-check.json');
        File::delete($path);

        $this->artisan('wms:golive-check', ['--json' => $path])->run();

        $this->assertFileExists($path);
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) File::get($path), true);
        File::delete($path);

        return $payload;
    }

    /**
     * Đưa runtime về trạng thái "giống production" theo đúng cách gate phân loại:
     * một APP_ENV KHÔNG nằm trong `golive.development_environments`.
     */
    private function pretendProductionEnvironment(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config()->set('golive.development_environments', ['local', 'testing']);
    }

    private function removeNonProductionFixtures(): void
    {
        $patterns = array_map('strval', (array) config('golive.non_production_usernames', []));

        $usernames = User::query()->pluck('username')
            ->filter(fn ($username): bool => collect($patterns)->contains(
                fn (string $pattern): bool => preg_match($pattern, (string) $username) === 1,
            ))
            ->all();

        User::query()->whereIn('username', $usernames)->update(['status' => 'INACTIVE']);

        $roleIds = Role::query()
            ->whereIn('role_code', (array) config('golive.non_production_roles', []))
            ->pluck('role_id')
            ->all();

        // Gỡ gán trước khi xóa role, đúng thứ tự mà một thao tác dọn dẹp thật phải
        // làm — FK của user_role/role_permission chặn việc xóa thẳng.
        DB::table('role_permission')->whereIn('role_id', $roleIds)->delete();
        DB::table('user_role')->whereIn('role_id', $roleIds)->delete();
        Role::query()->whereIn('role_id', $roleIds)->delete();
    }

    /**
     * @param  array<string, string>  $contract
     */
    private function withTemporaryRouteContract(array $contract, callable $assertions): void
    {
        $relative = 'golive/wwhn-300-temporary-route-contract.php';
        $path = database_path($relative);

        $lines = [];
        foreach ($contract as $key => $guard) {
            $lines[] = sprintf('    %s => %s,', var_export($key, true), var_export($guard, true));
        }

        File::put($path, "<?php\n\nreturn [\n".implode("\n", $lines)."\n];\n");
        config()->set('golive.contracts.route_permissions', $relative);

        try {
            $assertions();
        } finally {
            File::delete($path);
        }
    }
}
