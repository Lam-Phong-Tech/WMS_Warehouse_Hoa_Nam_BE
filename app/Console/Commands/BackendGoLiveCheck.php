<?php

namespace App\Console\Commands;

use App\Support\HoaNamErdSchema;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Throwable;

/**
 * WWHN-300 — "Backend GoLive check" output for WWHN-80 (Cutover, final
 * migration, deployment and rollback control).
 *
 * WWHN-155 already proves migrate:fresh --seed + /api/v1/health on a clean
 * database. This command answers the different question the cutover runbook
 * asks: is THIS deployed backend, against THIS database, safe to cut over —
 * migration chain complete and reversible, seed identity present, protected
 * surface default-deny, secrets not left at development defaults, and no
 * unreconciled ledger/audit backlog that would block Go/No-Go.
 *
 * Every check is read-only. Exit code 1 on any FAIL so a pipeline step or a
 * runbook operator gets a hard stop instead of a wall of text to interpret.
 *
 * Nguyên tắc fail-closed (DEV300-P1-03): một probe KHÔNG kết luận được — thiếu
 * bảng, driver không đọc được index, hợp đồng đã pin không tồn tại — là FAIL,
 * không phải PASS. "Không biết" tại cutover không được phép trông giống "sẵn
 * sàng". Miễn trừ duy nhất là disposition có thẩm quyền khai trong
 * `config/golive.php` → `governed_na`, và khi đó nguyên văn disposition đi vào
 * evidence artifact.
 */
class BackendGoLiveCheck extends Command
{
    protected $signature = 'wms:golive-check
        {--json= : Ghi kết quả machine-readable ra file (evidence artifact)}
        {--allow-debug : Bỏ qua phát hiện APP_DEBUG=true — CHỈ có hiệu lực trên allowlist môi trường non-prod tại config golive.debug_override_environments}
        {--write-route-contract : Ghi lại ma trận route→permission đã pin từ bảng route hiện tại; phải kèm review vì đây là thay đổi bề mặt bảo mật}';

    protected $description = 'Backend GoLive check — migration/seed/core/security readiness cho cutover (WWHN-300)';

    /**
     * /api/v1 routes that are public by specification and therefore must NOT be
     * reported as a missing-authentication finding.
     *
     * SRS v1.12 §3 + §8.4: App PV public GET anonymous; login/refresh are the
     * credential exchange itself; health is the readiness probe DevOps polls.
     *
     * @var array<int, string>
     */
    private const PUBLIC_ROUTES = [
        'api/v1/health',
        'api/v1/auth/login',
        'api/v1/auth/refresh',
        'api/v1/pv/catalog',
        // WWHN-252 / Annex C: path canonical của App PV (`security: []`), thay cho
        // alias legacy /pv/catalog. Public theo đặc tả nên không được tính là
        // "endpoint thiếu xác thực".
        'api/v1/pv/products',
        'api/v1/pv/products/{skuId}',
        'api/v1/pv/industries',
        'api/v1/pv/recommendations',
        'api/v1/pv/tags',
    ];

    /**
     * Authenticated routes that intentionally carry no permission gate because
     * they only ever act on the caller's own session (BR-IAM: self-service).
     *
     * @var array<int, string>
     */
    private const SELF_SERVICE_ROUTES = [
        'api/v1/auth/me',
        'api/v1/auth/logout',
        'api/v1/auth/change-password',
        'api/v1/auth/switch-context',
    ];

    /**
     * Bất biến idempotency theo SRS §8.2 (IDEMPOTENCY_CONFLICT) và BR-IDEMP-01..03:
     * cùng key + cùng payload trả lại kết quả cũ, cùng key khác payload trả 409.
     * Cả hai chỉ đúng khi DATABASE từ chối được bản ghi thứ hai.
     *
     * Trước đây check chỉ hỏi "có unique index nào không" — một unique index trên
     * cột bất kỳ cũng làm nó xanh, còn trên SQLite thì biến `$unique` mặc định
     * `true` nên không kiểm tra gì cả. Nay so ĐÚNG tập cột.
     *
     * @var array<int, array<int, string>>
     */
    private const IDEMPOTENCY_UNIQUE_SETS = [
        ['business_key'],
        ['idem_key', 'operation', 'user_id'],
    ];

    /** @var array<string, array{status:string, detail:array<string, mixed>}> */
    private array $results = [];

    public function handle(Migrator $migrator): int
    {
        if ($this->option('write-route-contract')) {
            return $this->writeRouteContract();
        }

        $this->check('migrations.applied', fn (): array => $this->migrationsApplied($migrator));
        $this->check('migrations.reversible', fn (): array => $this->migrationsReversible($migrator));
        $this->check('schema.canonical_relations', $this->canonicalRelations(...));
        $this->check('seed.identity_markers', $this->seedMarkers(...));
        $this->check('security.account_hygiene', $this->accountHygiene(...));
        $this->check('security.runtime_config', fn (): array => $this->runtimeConfig());
        $this->check('security.browser_runtime_contract', $this->browserRuntimeContract(...));
        $this->check('security.route_default_deny', $this->routeDefaultDeny(...));
        $this->check('release.build_identity', $this->buildIdentity(...));
        $this->check('runtime.queue_retry_window', $this->queueRetryWindow(...));
        $this->check('core.idempotency_store', $this->idempotencyStore(...));
        $this->check('core.audit_backlog', $this->auditBacklog(...));
        $this->check('core.reconciliation_backlog', $this->reconciliationBacklog(...));

        $failed = collect($this->results)->filter(fn (array $r): bool => $r['status'] === 'fail');
        $warned = collect($this->results)->filter(fn (array $r): bool => $r['status'] === 'warn');

        $payload = [
            'jira' => 'WWHN-300',
            'output' => 'Backend GoLive check',
            'environment' => app()->environment(),
            'environment_class' => $this->isDevelopment() ? 'development' : 'production-like',
            'driver' => DB::connection()->getDriverName(),
            'generated_at' => now()->toIso8601String(),
            'overall' => $failed->isEmpty() ? 'pass' : 'fail',
            'failed_count' => $failed->count(),
            'warned_count' => $warned->count(),
            'checks' => $this->results,
        ];

        $this->renderTable();

        if ($path = $this->option('json')) {
            File::ensureDirectoryExists(dirname((string) $path));
            File::put((string) $path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->line('Evidence: '.$path);
        }

        if ($failed->isNotEmpty()) {
            $this->error('Backend GoLive check FAIL — '.$failed->keys()->implode(', '));

            return self::FAILURE;
        }

        $this->info('Backend GoLive check PASS'.($warned->isNotEmpty() ? ' (có cảnh báo: '.$warned->keys()->implode(', ').')' : ''));

        return self::SUCCESS;
    }

    /** @param callable():array{status:string, detail:array<string,mixed>} $probe */
    private function check(string $name, callable $probe): void
    {
        try {
            $this->results[$name] = $probe();
        } catch (Throwable $error) {
            // A probe that cannot run is a failed check, never a silent pass:
            // "unknown" readiness must not be reported as ready at cutover.
            $this->results[$name] = [
                'status' => 'fail',
                'detail' => ['error' => $error::class, 'message' => mb_substr($error->getMessage(), 0, 500)],
            ];
        }
    }

    /**
     * Mọi APP_ENV không nằm trong allowlist development — kể cả một tên gõ sai —
     * được đối xử như production. Fail-closed: gõ nhầm APP_ENV không được nới
     * lỏng gate.
     */
    private function isDevelopment(): bool
    {
        return in_array(
            app()->environment(),
            array_map('strval', (array) config('golive.development_environments', ['local', 'testing'])),
            true,
        );
    }

    /**
     * DEV300-P1-04 — so hai chiều giữa source tree và database.
     *
     * Chỉ đếm "pending" là so MỘT chiều: nó bắt được migration chưa chạy nhưng
     * không bắt được chiều ngược lại — một bản ghi trong bảng `migrations` không
     * còn file tương ứng. Chiều đó nghĩa là artifact đang deploy KHÔNG phải
     * artifact đã tạo ra schema này (sai nhánh, file bị xóa/đổi tên sau khi đã
     * chạy), và `migrate:rollback` sẽ không tìm thấy file để gọi `down()`.
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function migrationsApplied(Migrator $migrator): array
    {
        $files = $migrator->getMigrationFiles($this->migrationPaths($migrator));
        $ran = $migrator->getRepository()->getRan();

        $pending = array_values(array_diff(array_keys($files), $ran));
        $orphaned = array_values(array_diff($ran, array_keys($files)));

        return [
            'status' => $pending === [] && $orphaned === [] ? 'ok' : 'fail',
            'detail' => [
                'source_total' => count($files),
                'applied' => count($ran),
                'pending' => $pending,
                // Đã ghi trong bảng migrations nhưng không còn file: rollback mất
                // đường gọi down(), và source tree không khớp schema đang chạy.
                'orphaned_in_database' => $orphaned,
            ],
        ];
    }

    /**
     * A cutover abort has to be able to unwind the batch it just applied. A
     * migration whose down() is missing or empty silently turns
     * migrate:rollback into a no-op and leaves the schema ahead of the code.
     *
     * DEV300-P1-04 — trước đây check chỉ WARN và test chỉ pin NGƯỠNG (<= 75), nên
     * một migration fail-forward MỚI vẫn lọt qua miễn là một migration khác rời
     * khỏi tập. Nay so với ALLOWLIST TƯỜNG MINH có thẩm quyền
     * (database/golive/fail_forward_allowlist.php):
     *   - fail-forward không có trong allowlist → FAIL (viết `down()` thật hoặc
     *     xin disposition; không im lặng đi qua)
     *   - mục trong allowlist nay đã có `down()` thật → WARN (allowlist stale, gỡ
     *     ra để nó không che một mục mới)
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function migrationsReversible(Migrator $migrator): array
    {
        $irreversible = [];

        foreach ($migrator->getMigrationFiles($this->migrationPaths($migrator)) as $name => $path) {
            $source = (string) file_get_contents($path);

            if (! preg_match('/function\s+down\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n\s{4}\}/s', $source, $match)) {
                $irreversible[$name] = 'no down()';

                continue;
            }

            $body = trim(preg_replace('#(//[^\n]*|/\*.*?\*/)#s', '', $match[1]) ?? '');
            if ($body === '') {
                $irreversible[$name] = 'down() is an empty fail-forward no-op';
            }
        }

        $allowlistPath = $this->contractPath('fail_forward_migrations');

        if (! is_file($allowlistPath)) {
            return [
                'status' => 'fail',
                'detail' => [
                    'allowlist' => 'missing',
                    'expected_at' => $allowlistPath,
                    'irreversible_count' => count($irreversible),
                ],
            ];
        }

        /** @var array<string, string> $allowlist */
        $allowlist = require $allowlistPath;

        $undispositioned = array_diff_key($irreversible, $allowlist);
        $stale = array_values(array_diff(array_keys($allowlist), array_keys($irreversible)));

        // Fail-forward steps are a governed decision, not a defect — the check
        // reports them so the runbook states restore-from-backup as the only
        // rollback authority past them, instead of promising migrate:rollback.
        $status = 'ok';
        if ($undispositioned !== []) {
            $status = 'fail';
        } elseif ($irreversible !== [] || $stale !== []) {
            $status = 'warn';
        }

        return [
            'status' => $status,
            'detail' => [
                'allowlist' => 'database/'.(string) config('golive.contracts.fail_forward_migrations'),
                'dispositioned_count' => count($allowlist),
                'irreversible_count' => count($irreversible),
                'undispositioned' => $undispositioned,
                'stale_allowlist_entries' => $stale,
                'irreversible' => $irreversible,
            ],
        ];
    }

    /** @return array<int, string> */
    private function migrationPaths(Migrator $migrator): array
    {
        return array_merge([database_path('migrations')], $migrator->paths());
    }

    /** Đường dẫn tuyệt đối tới một hợp đồng đã pin trong `database/golive/`. */
    private function contractPath(string $key): string
    {
        return database_path((string) config('golive.contracts.'.$key));
    }

    /**
     * The compatibility layer means a name can legitimately be a table on one
     * driver and a view on another. What must never happen is a canonical
     * aggregate missing entirely — that is the shape of a half-applied cutover.
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function canonicalRelations(): array
    {
        $required = [
            'app_users', 'roles', 'permissions', 'user_roles', 'role_permissions',
            'skus', 'warehouses', 'inventory_balances', 'stock_movements',
            'stock_documents', 'stock_document_lines', 'posting_events',
            'idempotency_keys', 'audit_logs', 'reversal_documents', 'reversal_lines',
            'warranty_cases', 'warranty_events', 'label_snapshots', 'scan_events',
        ];

        $missing = array_values(array_filter(
            $required,
            fn (string $relation): bool => ! HoaNamErdSchema::relationExists($relation),
        ));

        return [
            'status' => $missing === [] ? 'ok' : 'fail',
            'detail' => ['required' => count($required), 'missing' => $missing],
        ];
    }

    /**
     * Sự TỒN TẠI của identity baseline. Trạng thái CREDENTIAL của chính những tài
     * khoản này là câu hỏi khác và do `security.account_hygiene` trả lời — một
     * `admin` ACTIVE dùng mật khẩu mặc định của seeder thỏa mãn check này nhưng là
     * lỗ hổng nghiêm trọng nhất có thể mang lên production (DEV300-P1-01).
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function seedMarkers(): array
    {
        $markers = [
            'admin_user' => User::query()->where('username', 'admin')->where('status', 'ACTIVE')->exists(),
            'baseline_warehouse' => Warehouse::query()->where('code', 'KHO-TONG-HN')->where('status', 'ACTIVE')->exists(),
            'baseline_sku' => Sku::query()->where('sku_code', 'HN-PV-BASE-001')->where('status', 'ACTIVE')->exists(),
            'permission_catalog' => DB::table('permissions')->count() > 0,
        ];

        return [
            'status' => collect($markers)->every(fn (bool $v): bool => $v) ? 'ok' : 'fail',
            'detail' => $markers + ['credential_state_asserted_by' => 'security.account_hygiene'],
        ];
    }

    /**
     * DEV300-P1-01 — vệ sinh tài khoản trước cutover.
     *
     * BR-IAM-01..08 (SRS v1.12 §5.6) yêu cầu least privilege và backend
     * enforcement, nhưng không có gì kiểm tra trạng thái CREDENTIAL tại thời điểm
     * Go/No-Go. AuthDatabaseSeeder tạo `admin` + 9 tài khoản mẫu dùng chung một
     * mật khẩu hằng số nằm trong source code, và các tài khoản `test-*` tồn tại
     * chỉ để test RBAC. Mang nguyên trạng thái đó lên production nghĩa là tài
     * khoản SUPER_ADMIN có credential ai đọc repo cũng biết — và không check nào
     * trước đây nhìn thấy điều đó, vì "admin ACTIVE" được tính là dấu hiệu SẴN SÀNG.
     *
     * Dò bằng Hash::check nên bắt được cả khi tài khoản đã đổi tên, và chỉ ghi
     * username vi phạm — không bao giờ ghi giá trị mật khẩu vào evidence. Chính
     * tập rỗng ở đây LÀ bằng chứng credential đã được xoay vòng.
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function accountHygiene(): array
    {
        $isDevelopment = $this->isDevelopment();

        $defaults = array_values(array_filter(array_map(
            static fn ($value): string => (string) $value,
            (array) config('golive.credential.known_defaults', []),
        )));

        $limit = max(1, (int) config('golive.credential.probe_limit', 500));

        $accounts = User::query()
            ->where('status', 'ACTIVE')
            ->orderBy('username')
            ->limit($limit + 1)
            ->get(['user_id', 'username', 'password_hash']);

        $truncated = $accounts->count() > $limit;
        $accounts = $accounts->take($limit);

        $defaultCredentialAccounts = [];
        foreach ($accounts as $account) {
            $hash = (string) $account->password_hash;

            foreach ($defaults as $candidate) {
                if ($hash !== '' && Hash::check($candidate, $hash)) {
                    $defaultCredentialAccounts[] = (string) $account->username;

                    break;
                }
            }
        }

        $patterns = array_map('strval', (array) config('golive.non_production_usernames', []));
        $nonProductionAccounts = $accounts
            ->filter(fn ($account): bool => collect($patterns)->contains(
                fn (string $pattern): bool => preg_match($pattern, (string) $account->username) === 1,
            ))
            ->pluck('username')
            ->map(fn ($username): string => (string) $username)
            ->values()
            ->all();

        $nonProductionRoleCodes = array_map('strval', (array) config('golive.non_production_roles', []));
        $nonProductionRoles = $nonProductionRoleCodes === []
            ? []
            : Role::query()
                ->whereIn('role_code', $nonProductionRoleCodes)
                ->pluck('role_code')
                ->map(fn ($code): string => (string) $code)
                ->values()
                ->all();

        $findings = [];

        if ($defaultCredentialAccounts !== []) {
            $findings['default_credentials'] = 'Tài khoản ACTIVE còn dùng mật khẩu mặc định đã biết: '
                .implode(', ', $defaultCredentialAccounts)
                .' — phải xoay vòng credential trước cutover.';
        }

        if ($nonProductionAccounts !== []) {
            $findings['non_production_accounts'] = 'Tài khoản chỉ dành cho test/demo còn ACTIVE: '
                .implode(', ', $nonProductionAccounts).'.';
        }

        if ($nonProductionRoles !== []) {
            $findings['non_production_roles'] = 'Role fixture chỉ dành cho test còn tồn tại: '
                .implode(', ', $nonProductionRoles).'.';
        }

        if ($truncated) {
            $findings['probe_truncated'] = 'Số tài khoản ACTIVE vượt golive.credential.probe_limit ('
                .$limit.') — coverage không đầy đủ nên không kết luận được.';
        }

        // Trên local/testing những tài khoản này TỒN TẠI CÓ CHỦ Ý (chúng là fixture
        // của chính bộ test RBAC), nên ở đó là WARN. Mọi môi trường khác là FAIL.
        $status = $findings === [] ? 'ok' : ($isDevelopment ? 'warn' : 'fail');

        return [
            'status' => $status,
            'detail' => [
                'environment_class' => $isDevelopment ? 'development' : 'production-like',
                'active_accounts_probed' => $accounts->count(),
                'known_default_passwords_tested' => count($defaults),
                'accounts_on_default_credential' => $defaultCredentialAccounts,
                'non_production_accounts' => $nonProductionAccounts,
                'non_production_roles' => $nonProductionRoles,
                'rotation_evidence' => $defaultCredentialAccounts === []
                    ? 'Không tài khoản ACTIVE nào khớp mật khẩu mặc định đã biết.'
                    : 'CHƯA xoay vòng credential.',
                'findings' => $findings,
            ],
        ];
    }

    /** @return array{status:string, detail:array<string,mixed>} */
    private function runtimeConfig(): array
    {
        $environment = app()->environment();
        $isDevelopment = $this->isDevelopment();
        $jwtSecret = (string) config('jwt.secret');

        $findings = [];

        // DEV300-P1-02 — cờ --allow-debug từng tắt được phát hiện này ở BẤT KỲ môi
        // trường nào, kể cả production: operator chỉ cần thêm cờ là gate xanh trong
        // khi stack trace + toàn bộ config vẫn phơi ra response. Nay cờ chỉ có hiệu
        // lực trên allowlist môi trường non-prod khai tường minh; ngoài đó
        // APP_DEBUG=true là FAIL không thể override.
        $overrideRequested = (bool) $this->option('allow-debug');
        $overrideAllowedHere = in_array(
            $environment,
            array_map('strval', (array) config('golive.debug_override_environments', [])),
            true,
        );
        $overrideApplied = $overrideRequested && $overrideAllowedHere;

        $debugOverrideState = match (true) {
            ! $overrideRequested => 'not_requested',
            $overrideAllowedHere => 'applied',
            default => 'rejected',
        };

        if (config('app.debug') && ! $isDevelopment && ! $overrideApplied) {
            $findings['app_debug'] = 'APP_DEBUG=true ngoài local/testing — stack trace và config có thể lộ ra response.'
                .($debugOverrideState === 'rejected'
                    ? ' Cờ --allow-debug bị TỪ CHỐI trên môi trường "'.$environment.'": override chỉ hợp lệ trên allowlist golive.debug_override_environments.'
                    : '');
        }

        if ((string) config('app.key') === '') {
            $findings['app_key'] = 'APP_KEY trống — session/encrypted payload không dùng được.';
        }

        if ($jwtSecret === '') {
            $findings['jwt_secret'] = 'JWT_SECRET trống.';
        } elseif (! $isDevelopment && str_contains($jwtSecret, 'testing-jwt-secret')) {
            $findings['jwt_secret'] = 'JWT_SECRET vẫn là giá trị mặc định dùng cho test.';
        }

        // WWHN-295: BE luôn chạy sau nginx/TLS terminator. Không khai trusted proxy
        // thì $request->ip() ghi vào refresh_tokens.ip_address là IP của proxy —
        // trường bằng chứng phiên đăng nhập mất giá trị điều tra mà không có lỗi nào.
        $trustedProxies = trim((string) config('trustedproxy.proxies'));
        if ($trustedProxies === '') {
            $findings['trusted_proxies'] = 'TRUSTED_PROXIES trống — X-Forwarded-* bị bỏ qua, IP client và scheme https ghi nhận sai.';
        }

        // Hồ sơ log của .env.example là hồ sơ DEV: 'single' ghi vào một file không
        // giới hạn (đầy đĩa là sự cố runtime), 'debug' ghi cả payload request.
        if (! $isDevelopment) {
            $logChannels = array_map(
                static fn ($channel): string => strtolower(trim((string) $channel)),
                (array) config('logging.channels.stack.channels', [])
            );
            if (in_array('single', $logChannels, true)) {
                $findings['log_stack'] = 'LOG_STACK=single ngoài local/testing — log không xoay vòng, đầy đĩa sẽ làm sập runtime.';
            }
            if (strtolower((string) config('logging.channels.single.level')) === 'debug') {
                $findings['log_level'] = 'LOG_LEVEL=debug ngoài local/testing — payload request bị ghi vào log.';
            }
        }

        return [
            'status' => $findings === [] ? 'ok' : 'fail',
            'detail' => [
                'environment' => $environment,
                'environment_class' => $isDevelopment ? 'development' : 'production-like',
                'debug' => (bool) config('app.debug'),
                'debug_override' => $debugOverrideState,
                'debug_override_environments' => array_values((array) config('golive.debug_override_environments', [])),
                'trusted_proxies' => $trustedProxies === '' ? null : $trustedProxies,
                'findings' => $findings,
            ],
        ];
    }

    /**
     * WWHN-295 — danh tính artifact đang chạy.
     *
     * Mọi gate exact-SHA của dự án (WWHN-273/290/296/300) yêu cầu bằng chứng gắn
     * với đúng commit đã deploy, nhưng trước đây không có cách nào ĐỌC được SHA đó
     * từ backend đang chạy: reviewer phải tin lời khai trong mô tả deploy. CI/DevOps
     * inject APP_BUILD_SHA lúc build image; thiếu nó ngoài local/testing thì bằng
     * chứng cutover không buộc được vào artifact nào cả.
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function buildIdentity(): array
    {
        $isDevelopment = $this->isDevelopment();
        $sha = trim((string) config('health.build.sha'));

        $detail = [
            'version' => (string) config('health.version'),
            'sha' => $sha === '' ? null : $sha,
            'ref' => config('health.build.ref'),
            'built_at' => config('health.build.built_at'),
            'published_at' => '/api/v1/health -> data.build',
        ];

        if ($sha !== '') {
            return ['status' => 'ok', 'detail' => $detail];
        }

        // Local/dev không build qua CI nên thiếu SHA là bình thường, chỉ cảnh báo.
        return [
            'status' => $isDevelopment ? 'warn' : 'fail',
            'detail' => $detail + ['note' => 'APP_BUILD_SHA chưa được inject — không buộc được bằng chứng vào commit đã deploy.'],
        ];
    }

    /**
     * WWHN-295 — cửa sổ retry của queue phải rộng hơn timeout của worker.
     *
     * Nếu retry_after <= timeout, một job chạy sát ngưỡng được queue release lại
     * đúng lúc worker đầu tiên vẫn đang xử lý, và worker thứ hai nhận LẠI job đó.
     * Với audit outbox và reconcile tồn kho, chạy hai lần nghĩa là bản ghi audit
     * trùng hoặc số liệu đối soát bị cộng dồn — hỏng dữ liệu chứ không chỉ chậm.
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function queueRetryWindow(): array
    {
        $connection = (string) config('queue.default');
        $retryAfter = config("queue.connections.{$connection}.retry_after");
        $workerTimeout = (int) config('queue.worker_timeout');

        $detail = [
            'connection' => $connection,
            'retry_after' => $retryAfter,
            'worker_timeout' => $workerTimeout,
        ];

        // sync/deferred/background chạy trong tiến trình gọi, không có vòng release.
        if ($retryAfter === null) {
            return ['status' => 'ok', 'detail' => $detail + ['note' => 'Driver không dùng retry_after.']];
        }

        return [
            'status' => (int) $retryAfter > $workerTimeout ? 'ok' : 'fail',
            'detail' => $detail,
        ];
    }

    /**
     * WWHN-296 — browser runtime contract for the deployed environment.
     *
     * The FE/BE auth contract is credentialed: the access JWT stays in memory
     * and the session only survives F5 because the browser replays the HttpOnly
     * refresh cookie. That contract has deployment-only failure modes which no
     * unit test can see, because they depend on the values injected at cutover:
     * an empty/wildcard CORS allow-list, an http origin, or SameSite=Lax while
     * the FE is served from a different site. Each of those still returns 200 on
     * a curl probe and still passes /api/v1/health, then silently logs every
     * browser user out on reload.
     *
     * Running this against the real environment is what turns "config was
     * reviewed" into the sanitized config matrix DevOps/QA must attach.
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function browserRuntimeContract(): array
    {
        $environment = app()->environment();
        $isDevelopment = $this->isDevelopment();

        $appUrl = rtrim((string) config('app.url'), '/');
        $origins = array_values(array_filter(
            array_map(static fn ($origin): string => trim((string) $origin), (array) config('cors.allowed_origins')),
            static fn (string $origin): bool => $origin !== ''
        ));
        $supportsCredentials = (bool) config('cors.supports_credentials');

        $cookie = (array) config('refresh_token.cookie');
        $cookiePath = (string) ($cookie['path'] ?? '');
        $cookieSecure = (bool) ($cookie['secure'] ?? false);
        $cookieSameSite = strtolower(trim((string) ($cookie['same_site'] ?? '')));

        $findings = [];
        $warnings = [];

        // --- CORS allow-list -------------------------------------------------
        if ($origins === []) {
            $message = 'CORS_ALLOWED_ORIGINS trống — browser FE không gọi được API.';
            $isDevelopment ? $warnings['cors_origins'] = $message : $findings['cors_origins'] = $message;
        }

        $appHost = parse_url($appUrl, PHP_URL_HOST) ?: '';
        $crossSiteOrigins = [];

        foreach ($origins as $origin) {
            if (str_contains($origin, '*')) {
                $findings['cors_wildcard'] = 'Wildcard origin không hợp lệ với credentialed CORS: '.$origin;

                continue;
            }

            $scheme = strtolower((string) (parse_url($origin, PHP_URL_SCHEME) ?: ''));
            $host = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?: ''));
            $path = (string) (parse_url($origin, PHP_URL_PATH) ?: '');

            if ($scheme === '' || $host === '' || ! in_array($scheme, ['http', 'https'], true)) {
                $findings['cors_origin_invalid'] = 'Origin phải là scheme://host[:port] hợp lệ: '.$origin;

                continue;
            }

            if ($path !== '' && $path !== '/') {
                $findings['cors_origin_path'] = 'Origin không được chứa path: '.$origin;
            }

            if (! $isDevelopment && $scheme !== 'https') {
                $findings['cors_origin_insecure'] = 'Ngoài local/testing mọi FE origin phải là https: '.$origin;
            }

            if ($appHost !== '' && $this->registrableDomain($host) !== $this->registrableDomain($appHost)) {
                $crossSiteOrigins[] = $origin;
            }
        }

        if (! $supportsCredentials) {
            $findings['cors_credentials'] = 'supports_credentials=false — browser không gửi được refresh cookie, session mất sau F5.';
        }

        // --- Transport -------------------------------------------------------
        if ($appUrl === '') {
            $findings['app_url'] = 'APP_URL trống — không suy ra được health URL/cookie domain.';
        } elseif (! $isDevelopment) {
            if (strtolower((string) (parse_url($appUrl, PHP_URL_SCHEME) ?: '')) !== 'https') {
                $findings['app_url_insecure'] = 'Ngoài local/testing APP_URL phải là https: '.$appUrl;
            }
            if (in_array($appHost, ['localhost', '127.0.0.1', '::1'], true)) {
                $findings['app_url_localhost'] = 'APP_URL vẫn trỏ localhost ngoài local/testing: '.$appUrl;
            }
        }

        // --- Refresh cookie policy ------------------------------------------
        if (! in_array($cookieSameSite, ['lax', 'strict', 'none'], true)) {
            $findings['refresh_cookie_same_site'] = 'REFRESH_TOKEN_COOKIE_SAME_SITE phải là lax|strict|none, đang là "'.$cookieSameSite.'".';
        }

        if ($crossSiteOrigins !== [] && $cookieSameSite !== 'none') {
            // Same rule locally, but http://localhost vs http://127.0.0.1 is
            // already cross-site and must not turn a dev machine into a FAIL.
            $message = 'FE khác site với API ('.implode(', ', $crossSiteOrigins).') nên SameSite phải là "none"; giá trị "'.$cookieSameSite.'" khiến browser không gửi refresh cookie và user bị đăng xuất sau F5.';
            $isDevelopment ? $warnings['refresh_cookie_cross_site'] = $message : $findings['refresh_cookie_cross_site'] = $message;
        }

        if ($cookieSameSite === 'none' && ! $cookieSecure) {
            $findings['refresh_cookie_none_insecure'] = 'SameSite=None bắt buộc Secure=true.';
        }

        if ($cookieSameSite === 'strict') {
            $warnings['refresh_cookie_strict'] = 'SameSite=Strict chặn cookie khi user quay lại từ liên kết ngoài; cân nhắc "lax" cho FE cùng site.';
        }

        if (! $isDevelopment && ! $cookieSecure) {
            $findings['refresh_cookie_insecure'] = 'Refresh cookie phải Secure ngoài local/testing.';
        }

        if (! str_starts_with($cookiePath, '/api/v1/auth')) {
            $findings['refresh_cookie_path'] = 'REFRESH_TOKEN_COOKIE_PATH phải nằm dưới /api/v1/auth để cookie chỉ đi kèm endpoint refresh, đang là "'.$cookiePath.'".';
        }

        if (! $isDevelopment && config('session.secure') !== true) {
            $warnings['session_secure_cookie'] = 'SESSION_SECURE_COOKIE chưa bật ngoài local/testing.';
        }

        return [
            'status' => $findings !== [] ? 'fail' : ($warnings !== [] ? 'warn' : 'ok'),
            // Sanitized config matrix: origins/paths/flags only, never secrets.
            'detail' => [
                'environment' => $environment,
                'app_url' => $appUrl,
                'health_url' => $appUrl === '' ? null : $appUrl.'/api/v1/health',
                'cors' => [
                    'allowed_origins' => $origins,
                    'supports_credentials' => $supportsCredentials,
                    'exposed_headers' => array_values((array) config('cors.exposed_headers')),
                ],
                'refresh_cookie' => [
                    'name' => (string) ($cookie['name'] ?? ''),
                    'path' => $cookiePath,
                    'domain' => $cookie['domain'] ?? null,
                    'secure' => $cookieSecure,
                    'same_site' => $cookieSameSite,
                    'http_only' => true,
                ],
                'cross_site_frontend_origins' => $crossSiteOrigins,
                'findings' => $findings,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * SameSite is scoped to the registrable domain, not the origin: an FE on
     * app.example.com and an API on api.example.com are same-site and Lax keeps
     * working. Only a different registrable domain needs SameSite=None. There is
     * no public-suffix list in the runtime, so multi-label suffixes that matter
     * for this deployment (.vn in particular) are listed explicitly; anything
     * else falls back to the last two labels.
     */
    private function registrableDomain(string $host): string
    {
        $host = strtolower(trim($host, '. '));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $labels = explode('.', $host);
        if (count($labels) <= 2) {
            return $host;
        }

        $multiLabelSuffixes = [
            'com.vn', 'net.vn', 'org.vn', 'edu.vn', 'gov.vn', 'biz.vn', 'info.vn', 'name.vn', 'pro.vn', 'ac.vn', 'int.vn', 'health.vn',
            'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'com.au', 'net.au', 'org.au', 'co.jp', 'co.kr', 'com.sg', 'com.my', 'co.th',
            'com.cn', 'com.hk', 'com.tw', 'co.in', 'co.id', 'com.br', 'co.nz', 'com.ph',
        ];

        $take = in_array(implode('.', array_slice($labels, -2)), $multiLabelSuffixes, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }

    /**
     * Bề mặt /api/v1 hiện tại, mô tả bằng ĐÚNG guard mà middleware áp lên.
     *
     * @return array<string, string> "METHODS uri" => guard descriptor
     */
    private function routeGuardMap(): array
    {
        $map = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/') && $uri !== 'api/v1') {
                continue;
            }

            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $key = implode('|', $route->methods()).' '.$uri;

            if (! in_array('auth:api', $middleware, true)) {
                $map[$key] = in_array($uri, self::PUBLIC_ROUTES, true) ? 'PUBLIC_ANONYMOUS' : 'UNAUTHENTICATED';

                continue;
            }

            $permissions = [];
            foreach ($middleware as $entry) {
                if (str_starts_with($entry, 'permission:')) {
                    $permissions[] = substr($entry, strlen('permission:'));
                }
            }

            if ($permissions === []) {
                $map[$key] = in_array($uri, self::SELF_SERVICE_ROUTES, true) ? 'SELF_SERVICE' : 'UNGATED';

                continue;
            }

            sort($permissions);
            $map[$key] = 'permission:'.implode('+', $permissions);
        }

        ksort($map);

        return $map;
    }

    /**
     * SRS §8.1: every protected endpoint is default-deny with an exact
     * permission. A route added without its middleware is invisible in tests
     * that only assert positive cases, so assert the surface itself.
     *
     * DEV300-P1-03 — kiểm tra theo CẤU TRÚC ("có auth + có permission nào đó") vẫn
     * để lọt dạng lỗi nguy hiểm nhất: route gắn đúng hình thức nhưng SAI
     * permission — ví dụ `post-issue` gắn `outbound.view`. Nó default-deny hợp lệ
     * và mọi test happy-path vẫn xanh, nhưng đã cấp quyền ghi cho vai trò chỉ
     * được đọc. Vì vậy bề mặt được so với MA TRẬN ĐÃ PIN
     * (database/golive/route_permission_contract.php): thêm route, mất route,
     * hoặc đổi permission đều là FAIL cho tới khi ma trận được cập nhật kèm review.
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function routeDefaultDeny(): array
    {
        $live = $this->routeGuardMap();

        $unauthenticated = array_values(array_keys(array_filter(
            $live,
            static fn (string $guard): bool => $guard === 'UNAUTHENTICATED',
        )));
        $ungated = array_values(array_keys(array_filter(
            $live,
            static fn (string $guard): bool => $guard === 'UNGATED',
        )));

        $contractPath = $this->contractPath('route_permissions');
        $contractRelative = 'database/'.(string) config('golive.contracts.route_permissions');

        if (! is_file($contractPath)) {
            return [
                'status' => 'fail',
                'detail' => [
                    'contract' => 'missing',
                    'expected_at' => $contractPath,
                    'runtime_routes' => count($live),
                    'unauthenticated_non_public' => $unauthenticated,
                    'authenticated_without_permission' => $ungated,
                ],
            ];
        }

        /** @var array<string, string> $pinned */
        $pinned = require $contractPath;

        $notInContract = array_values(array_diff(array_keys($live), array_keys($pinned)));
        $missingFromRuntime = array_values(array_diff(array_keys($pinned), array_keys($live)));

        $mismatch = [];
        foreach ($live as $key => $guard) {
            if (array_key_exists($key, $pinned) && $pinned[$key] !== $guard) {
                $mismatch[$key] = ['pinned' => $pinned[$key], 'actual' => $guard];
            }
        }

        $ok = $unauthenticated === []
            && $ungated === []
            && $notInContract === []
            && $missingFromRuntime === []
            && $mismatch === [];

        return [
            'status' => $ok ? 'ok' : 'fail',
            'detail' => [
                'contract' => $contractRelative,
                'pinned_routes' => count($pinned),
                'runtime_routes' => count($live),
                'unauthenticated_non_public' => $unauthenticated,
                'authenticated_without_permission' => $ungated,
                // Route mới chưa qua review bề mặt bảo mật.
                'not_in_contract' => $notInContract,
                // Route biến mất so với hợp đồng: artifact không khớp bản đã duyệt.
                'missing_from_runtime' => $missingFromRuntime,
                // Permission bị đổi so với bản đã duyệt — lỗi phân quyền im lặng.
                'permission_mismatch' => $mismatch,
                'regenerate_with' => 'php artisan wms:golive-check --write-route-contract',
            ],
        ];
    }

    /**
     * Ghi lại ma trận route→permission. Đây là hành vi CÓ CHỦ Ý, không bao giờ tự
     * chạy trong CI: diff của file chính là bằng chứng review cho một thay đổi bề
     * mặt bảo mật.
     */
    private function writeRouteContract(): int
    {
        $map = $this->routeGuardMap();
        $path = $this->contractPath('route_permissions');

        $lines = [];
        foreach ($map as $key => $guard) {
            $lines[] = sprintf('    %s => %s,', var_export($key, true), var_export($guard, true));
        }

        $header = <<<'PHP'
<?php

/**
 * WWHN-300 / DEV300-P1-03 — ma trận route→permission đã pin cho /api/v1.
 *
 * SRS v1.12 §8.1 "Enforcement standard": mọi endpoint được bảo vệ phải có EXACT
 * permission + data scope, default deny. Kiểm tra theo cấu trúc (có auth, có
 * permission nào đó) KHÔNG phát hiện được dạng lỗi nguy hiểm nhất: route gắn
 * nhầm permission — hợp lệ về hình thức, nhưng cấp quyền ghi cho vai trò chỉ
 * được đọc, và mọi test happy-path vẫn xanh.
 *
 * Giá trị guard:
 *   permission:<expr>  permission chính xác trên middleware; `A|B` là dạng alias
 *                      canonical/legacy, nhiều middleware nối bằng `+`
 *   PUBLIC_ANONYMOUS   public theo đặc tả (SRS §3 + §8.4: App PV, health,
 *                      login/refresh)
 *   SELF_SERVICE       đã xác thực, chỉ tác động lên chính phiên của người gọi
 *
 * Sinh lại: `php artisan wms:golive-check --write-route-contract`.
 * DIFF CỦA FILE NÀY LÀ BẰNG CHỨNG REVIEW: thêm/xóa/đổi permission của một route
 * phải xuất hiện ở đây và phải có người duyệt.
 *
 * @return array<string, string> "METHODS uri" => guard
 */

return [
PHP;

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $header."\n".implode("\n", $lines)."\n];\n");

        $this->info('Đã ghi '.count($map).' route vào '.$path);
        $this->warn('Đây là thay đổi bề mặt bảo mật: commit file này kèm review của Tech Lead/Security.');

        return self::SUCCESS;
    }

    /**
     * Tập cột của mọi unique index THẬT trên một quan hệ, chuẩn hóa theo thứ tự
     * alphabet để so sánh được giữa các driver.
     *
     * Trả về `null` khi driver không đọc được — người gọi phải coi đó là FAIL,
     * không phải "không có vấn đề".
     *
     * @return array<int, string>|null
     */
    private function uniqueIndexColumnSets(string $relation): ?array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $rows = DB::select(<<<'SQL'
                SELECT array_to_string(array_agg(DISTINCT a.attname), ',') AS columns
                FROM pg_index x
                JOIN pg_class c ON c.oid = x.indrelid
                JOIN pg_class i ON i.oid = x.indexrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ANY (x.indkey)
                WHERE n.nspname = current_schema()
                  AND c.relname = ?
                  AND x.indisunique
                  AND x.indpred IS NULL
                  AND x.indexprs IS NULL
                GROUP BY i.relname
            SQL, [$relation]);

            return array_values(array_unique(array_map(
                static fn ($row): string => (string) $row->columns,
                $rows,
            )));
        }

        if ($driver === 'sqlite') {
            $sets = [];

            // PRAGMA không nhận binding nên tên quan hệ được quote thủ công.
            foreach (DB::select('PRAGMA index_list('.$this->quoteLiteral($relation).')') as $index) {
                if ((int) ($index->unique ?? 0) !== 1) {
                    continue;
                }

                $columns = array_map(
                    static fn ($info): string => (string) $info->name,
                    DB::select('PRAGMA index_info('.$this->quoteLiteral((string) $index->name).')'),
                );

                sort($columns);
                $sets[] = implode(',', $columns);
            }

            return array_values(array_unique($sets));
        }

        return null;
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Thiếu hạ tầng bắt buộc = KHÔNG kết luận được, nên mặc định FAIL.
     *
     * Ngoại lệ duy nhất là disposition có thẩm quyền khai trong
     * `config/golive.php` → `governed_na`; khi đó check hạ xuống WARN và nguyên
     * văn disposition (ai duyệt, ngày, lý do) đi thẳng vào evidence artifact thay
     * vì biến mất khỏi báo cáo.
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function missingCriticalInfrastructure(string $check, string $relation, string $consequence): array
    {
        $disposition = trim((string) config('golive.governed_na.'.$check, ''));

        if ($disposition !== '') {
            return [
                'status' => 'warn',
                'detail' => [
                    'relation' => $relation,
                    'state' => 'missing — governed N/A',
                    'disposition' => $disposition,
                    'consequence' => $consequence,
                ],
            ];
        }

        return [
            'status' => 'fail',
            'detail' => [
                'relation' => $relation,
                'state' => 'missing',
                'consequence' => $consequence,
                'governed_na_key' => 'golive.governed_na.'.$check,
            ],
        ];
    }

    /** @return array{status:string, detail:array<string,mixed>} */
    private function idempotencyStore(): array
    {
        if (! HoaNamErdSchema::relationExists('idempotency_keys')) {
            return $this->missingCriticalInfrastructure(
                'core.idempotency_store',
                'idempotency_keys',
                'Không có store idempotency thì retry của Post Receipt/Post Issue có thể ghi tồn hai lần (BR-IDEMP-01..03).',
            );
        }

        $actual = $this->uniqueIndexColumnSets('idempotency_keys');

        if ($actual === null) {
            return [
                'status' => 'fail',
                'detail' => [
                    'driver' => DB::connection()->getDriverName(),
                    'error' => 'Không đọc được unique index trên driver này — không kết luận được bất biến idempotency.',
                ],
            ];
        }

        $expected = [];
        foreach (self::IDEMPOTENCY_UNIQUE_SETS as $columns) {
            sort($columns);
            $expected[] = implode(',', $columns);
        }

        $missing = array_values(array_diff($expected, $actual));

        // Một lease treo không bao giờ được nhả sẽ chặn đúng lần Post mà smoke test
        // của cutover thực hiện, nên đếm sẵn thay vì phát hiện trong cửa sổ cutover.
        $staleLocks = null;
        if (Schema::hasColumn('idempotency_keys', 'locked_until')) {
            $staleLocks = DB::table('idempotency_keys')
                ->where('status', 'processing')
                ->where('locked_until', '<', now())
                ->count();
        }

        $status = 'ok';
        if ($missing !== []) {
            $status = 'fail';
        } elseif ($staleLocks !== null && $staleLocks > 0) {
            $status = 'warn';
        }

        return [
            'status' => $status,
            'detail' => [
                'expected_unique_sets' => $expected,
                'actual_unique_sets' => $actual,
                'missing_unique_sets' => $missing,
                'stale_processing_locks' => $staleLocks,
            ],
        ];
    }

    /** @return array{status:string, detail:array<string,mixed>} */
    private function auditBacklog(): array
    {
        if (! HoaNamErdSchema::relationExists('audit_outbox')) {
            return $this->missingCriticalInfrastructure(
                'core.audit_backlog',
                'audit_outbox',
                'Không có outbox thì không chứng minh được mọi hành vi đã vào audit log (SRS §7.5, BR-IAM-06).',
            );
        }

        $pending = DB::table('audit_outbox')->whereNull('processed_at')->count();

        // Bản ghi chưa xử lý tại thời điểm Go/No-Go = audit chưa bền vững cho những
        // giao dịch vừa chạy. Trên dev là bình thường; ngoài dev là chặn cutover.
        return [
            'status' => $pending === 0 ? 'ok' : ($this->isDevelopment() ? 'warn' : 'fail'),
            'detail' => ['unprocessed' => $pending, 'drain_with' => 'php artisan audit:reconcile'],
        ];
    }

    /**
     * SRS §7.5: an open reconciliation incident blocks readiness/sign-off.
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function reconciliationBacklog(): array
    {
        $relation = collect(['reconciliation_incidents', 'reconciliation_incident'])
            ->first(static fn (string $candidate): bool => HoaNamErdSchema::relationExists($candidate));

        if ($relation === null) {
            return $this->missingCriticalInfrastructure(
                'core.reconciliation_backlog',
                'reconciliation_incident(s)',
                'Không có bảng incident thì không chứng minh được tồn kho đã đối soát khớp trước cutover (SRS §7.5).',
            );
        }

        if (! Schema::hasColumn($relation, 'status')) {
            return [
                'status' => 'fail',
                'detail' => [
                    'source' => $relation,
                    'error' => 'Quan hệ tồn tại nhưng thiếu cột status — không kết luận được incident nào đang mở.',
                ],
            ];
        }

        $open = DB::table($relation)->whereIn('status', ['OPEN', 'INVESTIGATING'])->count();

        return [
            'status' => $open === 0 ? 'ok' : 'fail',
            'detail' => ['open_incidents' => $open, 'source' => $relation],
        ];
    }

    private function renderTable(): void
    {
        $this->table(
            ['Check', 'Status', 'Detail'],
            collect($this->results)->map(fn (array $result, string $name): array => [
                $name,
                strtoupper($result['status']),
                mb_substr((string) json_encode($result['detail'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 110),
            ])->values()->all(),
        );
    }
}
