<?php

namespace App\Console\Commands;

use App\Support\HoaNamErdSchema;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
 */
class BackendGoLiveCheck extends Command
{
    protected $signature = 'wms:golive-check
        {--json= : Ghi kết quả machine-readable ra file (evidence artifact)}
        {--allow-debug : Cho phép APP_DEBUG=true (chỉ dùng khi chạy trên môi trường dev)}';

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

    /** @var array<string, array{status:string, detail:array<string, mixed>}> */
    private array $results = [];

    public function handle(Migrator $migrator): int
    {
        $this->check('migrations.applied', fn (): array => $this->migrationsApplied($migrator));
        $this->check('migrations.reversible', fn (): array => $this->migrationsReversible($migrator));
        $this->check('schema.canonical_relations', $this->canonicalRelations(...));
        $this->check('seed.identity_markers', $this->seedMarkers(...));
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

    /** @return array{status:string, detail:array<string,mixed>} */
    private function migrationsApplied(Migrator $migrator): array
    {
        $files = $migrator->getMigrationFiles($this->migrationPaths($migrator));
        $ran = $migrator->getRepository()->getRan();
        $pending = array_values(array_diff(array_keys($files), $ran));

        return [
            'status' => $pending === [] ? 'ok' : 'fail',
            'detail' => [
                'total' => count($files),
                'applied' => count($ran),
                'pending' => $pending,
            ],
        ];
    }

    /**
     * A cutover abort has to be able to unwind the batch it just applied. A
     * migration whose down() is missing or empty silently turns
     * migrate:rollback into a no-op and leaves the schema ahead of the code.
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

        // Fail-forward steps are a governed decision, not a defect — the check
        // reports them so the runbook states restore-from-backup as the only
        // rollback authority past them, instead of promising migrate:rollback.
        return [
            'status' => $irreversible === [] ? 'ok' : 'warn',
            'detail' => ['irreversible' => $irreversible],
        ];
    }

    /** @return array<int, string> */
    private function migrationPaths(Migrator $migrator): array
    {
        return array_merge([database_path('migrations')], $migrator->paths());
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

    /** @return array{status:string, detail:array<string,mixed>} */
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
            'detail' => $markers,
        ];
    }

    /** @return array{status:string, detail:array<string,mixed>} */
    private function runtimeConfig(): array
    {
        $environment = app()->environment();
        $isDevelopment = in_array($environment, ['local', 'testing'], true);
        $jwtSecret = (string) config('jwt.secret');

        $findings = [];

        if (config('app.debug') && ! $isDevelopment && ! $this->option('allow-debug')) {
            $findings['app_debug'] = 'APP_DEBUG=true ngoài local/testing — stack trace và config có thể lộ ra response.';
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
                'debug' => (bool) config('app.debug'),
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
        $isDevelopment = in_array(app()->environment(), ['local', 'testing'], true);
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
        $isDevelopment = in_array($environment, ['local', 'testing'], true);

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
     * SRS §8.1: every protected endpoint is default-deny with an exact
     * permission. A route added without its middleware is invisible in tests
     * that only assert positive cases, so assert the surface itself.
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function routeDefaultDeny(): array
    {
        $unauthenticated = [];
        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/') && $uri !== 'api/v1') {
                continue;
            }

            $middleware = implode(',', $route->gatherMiddleware());
            $hasAuth = str_contains($middleware, 'auth:api');

            if (! $hasAuth) {
                if (! in_array($uri, self::PUBLIC_ROUTES, true)) {
                    $unauthenticated[] = implode('|', $route->methods()).' '.$uri;
                }

                continue;
            }

            if (! str_contains($middleware, 'permission:') && ! in_array($uri, self::SELF_SERVICE_ROUTES, true)) {
                $ungated[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        return [
            'status' => $unauthenticated === [] && $ungated === [] ? 'ok' : 'fail',
            'detail' => [
                'unauthenticated_non_public' => $unauthenticated,
                'authenticated_without_permission' => $ungated,
            ],
        ];
    }

    /** @return array{status:string, detail:array<string,mixed>} */
    private function idempotencyStore(): array
    {
        if (! Schema::hasTable('idempotency_keys')) {
            return ['status' => 'fail', 'detail' => ['idempotency_keys' => 'missing']];
        }

        $driver = DB::connection()->getDriverName();
        $unique = true;

        if ($driver === 'pgsql') {
            $unique = (bool) DB::selectOne(<<<'SQL'
                SELECT 1 AS present
                FROM pg_index i
                JOIN pg_class c ON c.oid = i.indrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema()
                  AND c.relname = 'idempotency_keys'
                  AND i.indisunique
                LIMIT 1
            SQL);
        }

        // A stale lease that never expires blocks a retry of the very posting
        // the cutover smoke test performs, so surface the count instead of
        // discovering it during the window.
        $stale = Schema::hasColumn('idempotency_keys', 'expires_at')
            ? DB::table('idempotency_keys')->where('expires_at', '<', now())->count()
            : 0;

        return [
            'status' => $unique ? 'ok' : 'fail',
            'detail' => ['unique_index' => $unique, 'expired_rows' => $stale],
        ];
    }

    /** @return array{status:string, detail:array<string,mixed>} */
    private function auditBacklog(): array
    {
        if (! Schema::hasTable('audit_outbox')) {
            return ['status' => 'ok', 'detail' => ['audit_outbox' => 'not present']];
        }

        $pending = DB::table('audit_outbox')->whereNull('processed_at')->count();

        return [
            'status' => $pending === 0 ? 'ok' : 'warn',
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
        $table = Schema::hasTable('reconciliation_incidents') ? 'reconciliation_incidents' : 'reconciliation_incident';

        if (! Schema::hasTable($table)) {
            return ['status' => 'ok', 'detail' => ['reconciliation_incidents' => 'not present']];
        }

        $open = DB::table($table)->whereIn('status', ['OPEN', 'INVESTIGATING'])->count();

        return [
            'status' => $open === 0 ? 'ok' : 'fail',
            'detail' => ['open_incidents' => $open, 'source' => $table],
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
