<?php

namespace App\Console\Commands;

use App\Http\Middleware\EnsureApiDocsEnabled;
use App\Support\HoaNamErdSchema;
use App\Support\Security\PiiKeyring;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Support\CredentialPolicy;
use Modules\Warehouse\Database\Seeders\DongChengAnchorSkus;
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
        {--write-route-contract : Ghi lại ma trận route→permission đã pin từ bảng route hiện tại; phải kèm review vì đây là thay đổi bề mặt bảo mật}
        {--expect-sha= : Exact candidate SHA mà artifact đang chạy phải khớp; mặc định đọc env RELEASE_EXPECTED_SHA}';

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

        // ---- WWHN-SEC-04 (07/09/2026) --------------------------------------
        // Danh sách trên được viết khi hệ thống mới chỉ có App PV. Từ đó thêm cả
        // mặt storefront `api/v1/public/*` và hai bước còn lại của luồng quên
        // mật khẩu, nhưng allowlist không được cập nhật — nên gate
        // `security.route_default_deny` đỏ liên tục vì 14 route CÓ CHỦ Ý công
        // khai. Một gate đỏ thường trực là gate bị bỏ qua, nên nó phải phản ánh
        // đúng thực tế thì mới dùng làm cổng chặn deploy được.
        //
        // Đã kiểm từng route trước khi thêm (không thêm hàng loạt theo tiền tố):
        // toàn bộ nhóm này đều là GET chỉ đọc dữ liệu đã publish, hoặc là các
        // bước trao đổi credential vốn không thể yêu cầu đăng nhập trước. Mỗi
        // route đều có trần gọi riêng — xem `config/rate_limit.php`.
        'api/v1/public/home',
        'api/v1/public/config',
        'api/v1/public/categories',
        'api/v1/public/facets',
        'api/v1/public/products',
        'api/v1/public/products/{slug}',
        'api/v1/public/products/{slug}/related',
        'api/v1/public/health/version',

        // Ghi PII từ người ẩn danh — an toàn nhờ throttle 5/phút khoá theo
        // (IP + số điện thoại), validation tường minh và DB constraint.
        'api/v1/public/quote-requests',

        // Ảnh sản phẩm App PV. `KEY_PATTERN` ép {key} là UUID + đuôi ảnh nên
        // không mở đường path traversal.
        'api/v1/pv/product-images/{key}',

        // Luồng quên mật khẩu: cả ba bước đều phải gọi được khi CHƯA đăng nhập.
        // Cả ba nay đều có limiter riêng — bước reset-password mới được bổ sung
        // ở WWHN-SEC-04, trước đó là endpoint ẩn danh duy nhất không có trần.
        'api/v1/auth/forgot-password',
        'api/v1/auth/verify-otp',
        'api/v1/auth/reset-password',

        // Chỉ tăng counter Web Vitals trong Valkey, whitelist LCP/INP/CLS.
        'api/v1/telemetry/vitals',

        // ---- WWHN-SEC-08 ----------------------------------------------------
        // Bước 2 của đăng nhập. KHÔNG thể yêu cầu `auth:api`: đúng lúc gọi nó,
        // người dùng mới qua bước mật khẩu và CỐ Ý chưa được phát token nào —
        // thêm auth vào đây sẽ làm luồng đăng nhập không bao giờ hoàn tất được.
        //
        // Thứ thay thế vai trò của `auth:api` ở endpoint này là mã phiên chờ
        // dùng-một-lần (hết hạn 5 phút, huỷ ngay khi tiêu thụ), cộng hai lớp
        // chống dò độc lập: `throttle:auth-mfa-verify` theo (IP + mfa_token) và
        // trần theo TÀI KHOẢN trong MfaService (failed_attempts/locked_until).
        'api/v1/auth/mfa/verify',
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

        // ---- WWHN-SEC-04 (07/09/2026) --------------------------------------
        // Sáu route dưới đây đã có `auth:api` nhưng không có `permission:`, nên
        // gate xếp chúng vào "authenticated_without_permission". Đã đọc từng
        // controller: tất cả đều lọc CỨNG theo user_id của chính người gọi, nên
        // không có quyền nào để mà kiểm.
        //
        // CẢNH BÁO CHO NGƯỜI SỬA SAU: tính an toàn ở đây nằm trong câu query,
        // KHÔNG nằm ở middleware. Bỏ mệnh đề `where('user_id', ...)` trong bất
        // kỳ controller nào bên dưới sẽ biến nó thành lỗ hổng đọc dữ liệu người
        // khác mà gate này KHÔNG bắt được — nó chỉ kiểm cấu hình route.
        'api/v1/notifications',
        'api/v1/notifications/unread-count',
        'api/v1/notifications/read-all',
        'api/v1/notifications/{id}/read',

        // Ép cứng object_type='user' + object_id = user_id người gọi bằng scope
        // closure, không đi qua danh sách filter nên client không override được.
        'api/v1/audit-logs/me',

        // Uỷ quyền cho Broadcast::auth(); routes/channels.php chỉ khai kênh
        // `user.{userId}` với điều kiện so khớp chính chủ, kênh không khai báo
        // thì mặc định từ chối.
        'api/v1/broadcasting/auth',

        // ---- WWHN-SEC-08 ----------------------------------------------------
        // Quản lý MFA của CHÍNH tài khoản đang đăng nhập. Không có `permission:`
        // vì không tồn tại quyền nào để kiểm: mọi thao tác đều đọc/ghi bản ghi
        // `user_mfa` khoá theo `auth()->user()->user_id`, không nhận id từ client.
        //
        // Thêm `permission:` vào nhóm này còn TẠO RA lỗ hổng thay vì bịt: người
        // chưa được cấp quyền đó sẽ không tự bật được MFA, tức là lớp bảo vệ chỉ
        // đến được với những người vốn đã có nhiều quyền nhất.
        //
        // `disable` là thao tác nguy hiểm nhất trong nhóm và được bảo vệ ở tầng
        // controller thay vì tầng route: đòi mật khẩu hiện tại + một mã hợp lệ,
        // và từ chối thẳng nếu vai trò thuộc nhóm bắt buộc MFA.
        'api/v1/auth/mfa/status',
        'api/v1/auth/mfa/enroll',
        'api/v1/auth/mfa/confirm',
        'api/v1/auth/mfa/disable',
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
        // WWHN-SEC-05 — bien van chuyen tang du lieu (DB / Redis / object storage).
        $this->check('security.data_transport', $this->dataTransport(...));
        // WWHN-SEC-06 — du lieu ca nhan o trang thai nghi.
        $this->check('security.pii_at_rest', $this->piiAtRest(...));
        // WWHN-SEC-08 — xac thuc hai buoc cho tai khoan dac quyen.
        $this->check('security.mfa', $this->mfaReadiness(...));
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
            // P0 10/09/2026: dấu hiệu là "có một SUPER_ADMIN ACTIVE", không còn gắn với
            // username `admin` mà seeder cũ tạo bằng mật khẩu hằng số. Môi trường mới
            // nhận quản trị viên đầu tiên qua `wms:bootstrap-super-admin`.
            'admin_user' => User::query()
                ->where('status', 'ACTIVE')
                ->whereHas('roles', fn ($query) => $query->where('role.role_code', 'SUPER_ADMIN'))
                ->exists(),
            'baseline_warehouse' => Warehouse::query()->where('code', 'KHO-TONG-HN')->where('status', 'ACTIVE')->exists(),
            // Mã hàng nền = hàng THẬT DongCheng (xem DongChengAnchorSkus), không
            // còn mã demo HN-PV-BASE-001 của bộ seeder đời trước.
            'baseline_sku' => Sku::query()->where('sku_code', DongChengAnchorSkus::BASELINE_PRODUCT)->where('status', 'ACTIVE')->exists(),
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

        // Gồm cả mật khẩu ĐÃ LỘ của chính dự án (golive.credential.compromised). Trước
        // 10/09/2026 giá trị đó bị cố ý loại khỏi deny-list, nên gate không bao giờ thấy
        // tài khoản còn dùng nó.
        $defaults = CredentialPolicy::knownDefaults();

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

        // WWHN-SEC-04 — trần vòng đời token. Lý do đầy đủ ở config/golive.php
        // ('session'). Tóm tắt: access token là JWT stateless, TTL của nó CHÍNH
        // LÀ trần thời gian một token bị đánh cắp còn dùng được.
        if (! $isDevelopment) {
            $rawTtl = config('jwt.ttl');
            $maxTtl = (int) config('golive.session.max_jwt_ttl_minutes', 120);

            if ($rawTtl === null || $rawTtl === '') {
                // tymon/jwt-auth coi ttl null là "không bao giờ hết hạn".
                $findings['jwt_ttl'] = 'JWT_TTL trống — token sẽ KHÔNG BAO GIỜ hết hạn.';
            } elseif ($maxTtl > 0 && (int) $rawTtl > $maxTtl) {
                $findings['jwt_ttl'] = sprintf(
                    'JWT_TTL=%d phút vượt trần %d phút (golive.session.max_jwt_ttl_minutes). '
                    .'Dùng refresh token xoay vòng cho thiết bị chạy dài, đừng kéo dài access token.',
                    (int) $rawTtl,
                    $maxTtl,
                );
            }

            $maxRefreshTtl = (int) config('golive.session.max_jwt_refresh_ttl_minutes', 20160);
            $refreshTtl = (int) config('jwt.refresh_ttl');
            if ($maxRefreshTtl > 0 && $refreshTtl > $maxRefreshTtl) {
                $findings['jwt_refresh_ttl'] = sprintf(
                    'JWT_REFRESH_TTL=%d phút vượt trần %d phút (golive.session.max_jwt_refresh_ttl_minutes).',
                    $refreshTtl,
                    $maxRefreshTtl,
                );
            }

            if (! config('jwt.blacklist_enabled')) {
                $findings['jwt_blacklist'] = 'JWT_BLACKLIST_ENABLED=false — đăng xuất không thu hồi được access token đã phát.';
            }
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
     * WWHN-SEC-08 — xác thực hai bước cho tài khoản đặc quyền.
     *
     * Ba trạng thái khác hẳn nhau mà nếu không kiểm thì đều trông giống "đã có
     * MFA" trong một bản báo cáo:
     *
     *  1. Tính năng có mặt nhưng CƯỠNG CHẾ TẮT — MFA chỉ là tuỳ chọn, và lớp bảo
     *     vệ tồn tại đúng với những người vốn đã cẩn thận.
     *  2. Cưỡng chế bật nhưng KHÔNG CÓ tài khoản đặc quyền nào đăng ký — nghĩa là
     *     đúng vào lúc bật, toàn bộ quản trị viên bị chặn ra ngoài. Đây là lý do
     *     trình tự đúng luôn là: deploy -> đăng ký -> mới bật cờ.
     *  3. Cưỡng chế bật và mọi tài khoản đặc quyền đã đăng ký — trạng thái đích.
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function mfaReadiness(): array
    {
        $isDevelopment = $this->isDevelopment();
        $findings = [];
        $warnings = [];

        $enforced = (bool) config('mfa.enforce', false);
        $requiredRoles = array_values(array_filter(array_map(
            static fn ($role): string => strtoupper(trim((string) $role)),
            (array) config('mfa.required_roles', []),
        )));

        if ($requiredRoles === []) {
            $message = 'MFA_REQUIRED_ROLES trống — không vai trò nào bắt buộc xác thực hai bước.';
            $isDevelopment ? $warnings['mfa_no_required_roles'] = $message : $findings['mfa_no_required_roles'] = $message;
        }

        if (! $enforced && ! $isDevelopment) {
            $findings['mfa_not_enforced'] = 'MFA_ENFORCE_FOR_PRIVILEGED_ROLES=false ngoài local/testing — '
                .'tài khoản đặc quyền vẫn đăng nhập được chỉ bằng mật khẩu. '
                .'Trình tự đúng: quản trị viên đăng ký MFA trước, rồi bật cờ này.';
        }

        // Đếm tài khoản ACTIVE có vai trò bắt buộc nhưng CHƯA bật MFA.
        $privilegedWithoutMfa = null;
        $privilegedTotal = null;

        if ($requiredRoles !== [] && Schema::hasTable('user_mfa') && Schema::hasTable('user_role') && Schema::hasTable('role')) {
            $privilegedIds = DB::table('user_role as ur')
                ->join('role as r', 'r.role_id', '=', 'ur.role_id')
                ->whereIn(DB::raw('UPPER(r.role_code)'), $requiredRoles)
                ->distinct()
                ->pluck('ur.user_id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            $privilegedTotal = count($privilegedIds);

            if ($privilegedIds !== []) {
                $enrolled = DB::table('user_mfa')
                    ->whereIn('user_id', $privilegedIds)
                    ->whereNotNull('confirmed_at')
                    ->count();

                $privilegedWithoutMfa = $privilegedTotal - $enrolled;

                if ($privilegedWithoutMfa > 0 && ! $isDevelopment) {
                    $message = $privilegedWithoutMfa.'/'.$privilegedTotal.' tài khoản đặc quyền chưa đăng ký MFA.';
                    // Cưỡng chế ĐANG bật mà còn tài khoản chưa đăng ký = những
                    // người đó sẽ bị chặn ngay khi cutover. Cưỡng chế chưa bật thì
                    // đây chỉ là cảnh báo tiến độ.
                    $enforced
                        ? $findings['mfa_privileged_locked_out'] = $message.' Cưỡng chế đang BẬT — họ sẽ bị chặn ngay sau cutover.'
                        : $warnings['mfa_privileged_pending'] = $message;
                }
            }
        }

        return [
            'status' => $findings !== [] ? 'fail' : ($warnings !== [] ? 'warn' : 'ok'),
            'detail' => [
                'environment_class' => $isDevelopment ? 'development' : 'production-like',
                'enforced' => $enforced,
                'required_roles' => $requiredRoles,
                'privileged_accounts' => $privilegedTotal,
                'privileged_without_mfa' => $privilegedWithoutMfa,
                'totp' => [
                    'digits' => (int) config('mfa.totp.digits'),
                    'period_seconds' => (int) config('mfa.totp.period'),
                    'window_steps' => (int) config('mfa.totp.window'),
                ],
                'max_verify_attempts' => (int) config('mfa.max_verify_attempts'),
                'findings' => $findings,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * WWHN-SEC-06 — dữ liệu cá nhân ở trạng thái NGHỈ.
     *
     * Ba câu hỏi mà một môi trường production phải trả lời được, và trước check
     * này không ai trả lời được câu nào:
     *
     *  1. Keyring PII có được khai tường minh không, hay hệ thống đang chạy bằng
     *     khoá DẪN XUẤT từ APP_KEY? Khoá dẫn xuất là tiện ích dành cho máy lập
     *     trình viên. Trên dữ liệu thật nó có nghĩa là: ai đọc được APP_KEY (biến
     *     môi trường, dump cấu hình, log lỗi có config) là giải mã được toàn bộ
     *     PII — tức lớp mã hoá không bảo vệ thêm được gì.
     *
     *  2. Khoá mã hoá và khoá blind index có PHẢI là hai khoá khác nhau không?
     *     Dùng chung thì một khoá lộ vừa giải mã được dữ liệu vừa cho phép dựng
     *     bảng tra ngược số điện thoại.
     *
     *  3. Cột plaintext đã thực sự biến mất khỏi schema chưa? Có ciphertext mà
     *     vẫn còn cột plaintext bên cạnh thì bản dump vẫn đọc được như cũ —
     *     đây đúng là trạng thái giữa Phase 1 và Phase 6, và nó KHÔNG được coi
     *     là "đã mã hoá".
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function piiAtRest(): array
    {
        $isDevelopment = $this->isDevelopment();
        $findings = [];
        $warnings = [];

        $derivedEncryption = null;
        $derivedBlindIndex = null;
        $sameKeyMaterial = null;

        try {
            $derivedEncryption = PiiKeyring::isDerived(PiiKeyring::PURPOSE_ENCRYPTION);
            $derivedBlindIndex = PiiKeyring::isDerived(PiiKeyring::PURPOSE_BLIND_INDEX);

            // So sánh bằng hash_equals trên chính vật liệu khoá. Không log, không
            // trả giá trị ra ngoài — chỉ trả về true/false.
            $sameKeyMaterial = hash_equals(
                PiiKeyring::activeKey(PiiKeyring::PURPOSE_ENCRYPTION),
                PiiKeyring::activeKey(PiiKeyring::PURPOSE_BLIND_INDEX),
            );
        } catch (Throwable $error) {
            $findings['pii_keyring'] = 'Không phân giải được keyring PII: '.mb_substr($error->getMessage(), 0, 200);
        }

        if (! $isDevelopment) {
            if ($derivedEncryption === true) {
                $findings['pii_encryption_key_derived'] = 'PII_ENCRYPTION_KEYS chưa khai — hệ thống đang dùng khoá dẫn xuất từ APP_KEY. '
                    .'Sinh khoá bằng `php artisan pii:make-key` và đưa vào secret manager.';
            }
            if ($derivedBlindIndex === true) {
                $findings['pii_blind_index_key_derived'] = 'PII_BLIND_INDEX_KEYS chưa khai — blind index đang dùng khoá dẫn xuất từ APP_KEY.';
            }
        }

        if ($sameKeyMaterial === true) {
            $findings['pii_key_reuse'] = 'Khoá mã hoá và khoá blind index đang GIỐNG NHAU. '
                .'Một khoá lộ sẽ vừa giải mã dữ liệu vừa dựng được bảng tra ngược — phải là hai khoá riêng.';
        }

        // Trạng thái schema: cột plaintext còn hay hết.
        //
        // Kiểm trên BẢNG GỐC, không phải view: `warranty_case` là view legacy,
        // còn dữ liệu thật (thứ nằm trong bản dump) ở `warranty_cases`.
        $plaintextWatchlist = [
            'public_quote_requests' => ['full_name', 'phone', 'phone_normalized', 'note'],
            'warranty_cases' => ['customer_name', 'customer_phone', 'customer_address'],
        ];

        $pendingPlaintextColumns = [];

        foreach ($plaintextWatchlist as $relation => $columns) {
            if (! Schema::hasTable($relation)) {
                continue;
            }

            foreach ($columns as $column) {
                if (Schema::hasColumn($relation, $column)) {
                    $pendingPlaintextColumns[] = $relation.'.'.$column;
                }
            }
        }

        if ($pendingPlaintextColumns !== []) {
            $message = 'Cột plaintext còn tồn tại: '.implode(', ', $pendingPlaintextColumns)
                .'. Chạy `php artisan pii:quote-requests --encrypt` / `pii:warranty-cases --encrypt`, rồi `--verify`, '
                .'rồi `php artisan migrate` (Phase 6). Chừng nào cột còn đó thì bản dump vẫn đọc được như trước khi mã hoá.';
            $isDevelopment ? $warnings['pii_plaintext_columns'] = $message : $findings['pii_plaintext_columns'] = $message;
        }

        return [
            'status' => $findings !== [] ? 'fail' : ($warnings !== [] ? 'warn' : 'ok'),
            'detail' => [
                'environment_class' => $isDevelopment ? 'development' : 'production-like',
                // Chỉ trạng thái, KHÔNG bao giờ là giá trị khoá hay key id thật.
                'encryption_key_derived_from_app_key' => $derivedEncryption,
                'blind_index_key_derived_from_app_key' => $derivedBlindIndex,
                'encryption_and_blind_index_share_key' => $sameKeyMaterial,
                'encrypted_columns' => array_values((array) config('pii.classification.encrypted', [])),
                'blind_indexed_columns' => array_values((array) config('pii.classification.blind_indexed', [])),
                'plaintext_columns_remaining' => $pendingPlaintextColumns,
                'findings' => $findings,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * WWHN-SEC-05 — biên vận chuyển của TẦNG DỮ LIỆU.
     *
     * `security.browser_runtime_contract` đã phủ biên browser <-> API (HTTPS,
     * CORS, cookie). Nhưng phía SAU API vẫn còn ba đường mạng chở đúng thứ dữ
     * liệu mà TLS phía trước đang bảo vệ, và trước check này không có gate nào
     * nhìn tới chúng:
     *
     *  1. API -> PostgreSQL: mọi câu lệnh, mật khẩu ứng dụng, PII phiếu bảo hành.
     *     `sslmode=prefer` (giá trị cũ của repo) THÀNH CÔNG cả khi server từ chối
     *     TLS — libpq tự hạ xuống kết nối rõ, không exception, không log.
     *  2. API -> Valkey/Redis: session, hàng đợi, bộ đếm rate-limit và cache đọc
     *     (payload báo cáo bảo hành có customer_name/phone/address).
     *  3. API -> object storage: file export báo cáo, ảnh/video hồ sơ bảo hành.
     *
     * Gate này CỐ Ý không tự "sửa" gì. Nó chỉ từ chối cutover khi cấu hình cho
     * phép rơi xuống plaintext ngoài local/testing, và nói rõ thiếu gì.
     *
     * Ranh giới hiểu biết: lệnh này đọc CẤU HÌNH, không đo được rằng handshake
     * TLS thật sự đã xảy ra (điều đó cần quyền trên cụm DB/Redis). Vì vậy phần
     * `verified` của detail ghi rõ 'config' — reviewer không được đọc PASS ở đây
     * thành "đã kiểm chứng trên đường dây".
     *
     * @return array{status:string, detail:array<string,mixed>}
     */
    private function dataTransport(): array
    {
        $isDevelopment = $this->isDevelopment();
        $findings = [];
        $warnings = [];

        // --- PostgreSQL ------------------------------------------------------
        $driver = (string) config('database.default');
        $pgsql = (array) config('database.connections.pgsql', []);
        $sslMode = strtolower(trim((string) ($pgsql['sslmode'] ?? '')));
        $sslRootCert = trim((string) ($pgsql['sslrootcert'] ?? ''));

        // disable/allow/prefer đều CHO PHÉP kết nối rõ. require trở lên thì libpq
        // huỷ kết nối nếu không bắt tay được TLS.
        $insecureModes = ['disable', 'allow', 'prefer'];
        $verifyingModes = ['verify-ca', 'verify-full'];

        if ($driver === 'pgsql' && ! $isDevelopment) {
            if ($sslMode === '' || in_array($sslMode, $insecureModes, true)) {
                $findings['db_sslmode'] = sprintf(
                    'DB_SSLMODE=%s ngoài local/testing — chế độ này KẾT NỐI ĐƯỢC cả khi không có TLS, '
                    .'nên toàn bộ câu lệnh và PII có thể đi qua mạng ở dạng rõ mà không có lỗi nào. '
                    .'Tối thiểu "require", đích đến "verify-full".',
                    $sslMode === '' ? '(trống)' : $sslMode,
                );
            } elseif (! in_array($sslMode, $verifyingModes, true)) {
                // require = mã hoá nhưng KHÔNG xác thực danh tính server: vẫn hở
                // với MITM chủ động. Đây là WARN chứ không FAIL vì nâng lên
                // verify-full cần CA do DevOps cấp, không phải việc của repo.
                $warnings['db_sslmode_unverified'] = sprintf(
                    'DB_SSLMODE=%s chỉ mã hoá, KHÔNG xác thực danh tính server (vẫn MITM được). '
                    .'Nâng lên verify-full và trỏ DB_SSLROOTCERT tới CA của cụm DB.',
                    $sslMode,
                );
            } elseif ($sslRootCert === '') {
                $findings['db_sslrootcert'] = sprintf(
                    'DB_SSLMODE=%s nhưng DB_SSLROOTCERT trống — không có CA thì libpq không xác thực được chứng chỉ server.',
                    $sslMode,
                );
            }
        }

        // --- Valkey / Redis --------------------------------------------------
        $redisScheme = strtolower(trim((string) (config('database.redis.default.scheme') ?? 'tcp')));
        $redisScheme = $redisScheme !== '' ? $redisScheme : 'tcp';
        $redisPassword = (string) (config('database.redis.default.password') ?? '');
        $redisContext = (array) (config('database.redis.default.context') ?? []);
        $redisVerifyPeer = (bool) ($redisContext['ssl']['verify_peer'] ?? false);

        // Redis chỉ đáng siết khi nó thật sự chở dữ liệu nhạy cảm. Ở dự án này
        // luôn đúng: session + queue + rate limit + cache đọc đều nằm trên đó.
        $redisCarriesSensitiveData = in_array('redis', [
            (string) config('session.driver'),
            (string) config('queue.default'),
            (string) config('cache.default'),
        ], true);

        if (! $isDevelopment && $redisCarriesSensitiveData) {
            if (! in_array($redisScheme, ['tls', 'ssl'], true)) {
                $findings['redis_scheme'] = 'REDIS_SCHEME='.$redisScheme.' ngoài local/testing trong khi Valkey đang giữ '
                    .'session/hàng đợi/cache đọc (có PII) — đặt REDIS_SCHEME=tls và REDIS_SSL_CAFILE.';
            } elseif (! $redisVerifyPeer) {
                $findings['redis_verify_peer'] = 'REDIS_SSL_VERIFY_PEER=false — TLS không xác thực danh tính thì vẫn MITM được.';
            }

            if ($redisPassword === '') {
                $findings['redis_password'] = 'REDIS_PASSWORD trống ngoài local/testing — Valkey không yêu cầu xác thực.';
            }
        }

        // --- Object storage --------------------------------------------------
        // Chỉ đọc cấu hình đĩa đang dùng cho export/ảnh: endpoint http:// ngoài
        // local nghĩa là file export báo cáo đi qua mạng ở dạng rõ.
        $storageFindings = [];
        foreach (['s3', 'r2'] as $disk) {
            $endpoint = trim((string) config('filesystems.disks.'.$disk.'.endpoint', ''));
            if ($endpoint === '') {
                continue;
            }
            $scheme = strtolower((string) (parse_url($endpoint, PHP_URL_SCHEME) ?: ''));
            $host = strtolower((string) (parse_url($endpoint, PHP_URL_HOST) ?: ''));
            $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1', 'minio'], true);
            if (! $isDevelopment && $scheme !== 'https' && ! $isLoopback) {
                $storageFindings[$disk] = 'endpoint không phải https: '.$endpoint;
            }
        }
        if ($storageFindings !== []) {
            $findings['storage_endpoint_insecure'] = 'Object storage endpoint đi HTTP ngoài local/testing: '
                .json_encode($storageFindings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // --- Tài liệu API ----------------------------------------------------
        // Swagger UI công bố toàn bộ bề mặt API + schema. Ngoài local/testing nó
        // phải tắt trừ khi được bật tường minh bằng API_DOCS_ENABLED.
        if (! $isDevelopment && EnsureApiDocsEnabled::enabled()) {
            $warnings['api_docs_enabled'] = 'Swagger UI đang BẬT ngoài local/testing — công bố toàn bộ bề mặt API. '
                .'Đặt API_DOCS_ENABLED=false nếu không phải chủ đích.';
        }

        return [
            'status' => $findings !== [] ? 'fail' : ($warnings !== [] ? 'warn' : 'ok'),
            'detail' => [
                'environment_class' => $isDevelopment ? 'development' : 'production-like',
                // "config" = đọc từ cấu hình đang chạy. KHÔNG phải bằng chứng
                // handshake TLS đã xảy ra thật trên đường dây.
                'verified' => 'config',
                'database' => [
                    'driver' => $driver,
                    'sslmode' => $sslMode === '' ? null : $sslMode,
                    'sslrootcert_present' => $sslRootCert !== '',
                ],
                'redis' => [
                    'scheme' => $redisScheme,
                    'password_present' => $redisPassword !== '',
                    'verify_peer' => $redisVerifyPeer,
                    'carries_sensitive_data' => $redisCarriesSensitiveData,
                ],
                'api_docs_enabled' => EnsureApiDocsEnabled::enabled(),
                'findings' => $findings,
                'warnings' => $warnings,
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
        $expected = strtolower(trim((string) ($this->option('expect-sha') ?: env('RELEASE_EXPECTED_SHA', ''))));

        $detail = [
            'version' => (string) config('health.version'),
            'sha' => $sha === '' ? null : $sha,
            'ref' => config('health.build.ref'),
            'built_at' => config('health.build.built_at'),
            'published_at' => '/api/v1/health -> data.build',
            'expected_sha' => $expected === '' ? null : $expected,
        ];

        if ($sha !== '') {
            // DEV295-P1-04/P1-05: "có SHA" chưa phải bằng chứng cutover. Gate chỉ đóng
            // khi SHA đang chạy khớp exact candidate đã pin trên WWHN-295 — nếu không
            // thì QA đang đo một artifact khác với artifact được duyệt, và không ai
            // nhìn ra điều đó từ một artifact evidence chỉ ghi "build_identity: ok".
            if ($expected !== '') {
                $matches = str_starts_with(strtolower($sha), $expected) && strlen($expected) >= 7;

                return [
                    'status' => $matches ? 'ok' : 'fail',
                    'detail' => $detail + [
                        'matches_expected' => $matches,
                        'note' => $matches
                            ? 'Artifact đang chạy khớp exact candidate đã pin.'
                            : 'SHA đang chạy KHÁC exact candidate đã pin (hoặc expect-sha ngắn hơn 7 ký tự) — bằng chứng thu ở đây không thuộc về candidate.',
                    ],
                ];
            }

            return ['status' => 'ok', 'detail' => $detail];
        }

        // Local/dev không build qua CI nên thiếu SHA là bình thường, chỉ cảnh báo —
        // TRỪ khi người chạy đã khai expect-sha: khi đó họ đang thu bằng chứng cho một
        // candidate cụ thể và "không có SHA" không thể là warn.
        return [
            'status' => ($isDevelopment && $expected === '') ? 'warn' : 'fail',
            'detail' => $detail + [
                'matches_expected' => $expected === '' ? null : false,
                'note' => 'APP_BUILD_SHA chưa được inject — không buộc được bằng chứng vào commit đã deploy.',
            ],
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

        // --- P1 10/09/2026: kênh refresh token qua JSON cho client không phải trình duyệt ---
        $bodyTransportEnabled = (bool) config('refresh_token.body_transport.enabled', false);
        $bodyTransportOrigins = array_values(array_map(
            static fn ($origin): string => strtolower(rtrim(trim((string) $origin), '/')),
            (array) config('refresh_token.body_transport.allowed_origins', []),
        ));

        if ($bodyTransportEnabled) {
            $browserOrigins = array_map(static fn (string $origin): string => strtolower(rtrim($origin, '/')), $origins);
            $overlap = array_values(array_intersect($bodyTransportOrigins, $browserOrigins));

            $warnings['refresh_body_transport_enabled'] = 'Kênh refresh token qua JSON (REFRESH_TOKEN_BODY_TRANSPORT_ENABLED) đang bật — chỉ dành cho client không phải web FE.'
                .($overlap === [] ? '' : ' Origin vừa được CORS cho phép vừa dùng kênh body: '.implode(', ', $overlap).' — JavaScript trên các origin này đọc được refresh token; đảm bảo đó không phải web FE WMS.');
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
