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
        $this->check('security.route_default_deny', $this->routeDefaultDeny(...));
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

        return [
            'status' => $findings === [] ? 'ok' : 'fail',
            'detail' => [
                'environment' => $environment,
                'debug' => (bool) config('app.debug'),
                'findings' => $findings,
            ],
        ];
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
