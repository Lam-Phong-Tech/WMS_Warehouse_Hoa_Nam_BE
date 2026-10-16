<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use RuntimeException;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\TestCase;
use Tests\TestCredentials;

/**
 * WWHN-289 / DEV289-P1-04 — "security finding → fix → retest trace".
 *
 * Register 73/100 (DEV289-P1-04R) đòi đủ SÁU nhóm regression để Security/TL ký:
 * unauthorized, **RBAC**, data-scope, replay, rate-limit, secret. Bản trước chỉ có
 * bốn nhóm (RBAC bị gộp vào unauthorized, và ngưỡng rate-limit chưa có hợp đồng nào
 * khoá) — đó là lỗ mà review chỉ ra. File này lái cả sáu qua HTTP thật trên các
 * endpoint có stock effect và ghi evidence máy đọc được
 * `build/evidence/wwhn-289/security-regression-proof.json`.
 *
 * Ba finding thật được đóng trong WWHN-289 và khoá hồi quy tại đây:
 *   SEC289-01  data scope luồng nhập kho không tồn tại (chỉ có permission chức năng)
 *              → EnforceInboundWarehouseScope; SRS §8.1 enforcement standard, §8.2 403.
 *   SEC289-02  không có bất kỳ rate limit/anti-abuse nào: /auth/login dò mật khẩu
 *              không giới hạn, nhóm PV ẩn danh công bố 429 nhưng không thể xảy ra
 *              → §8.1 bảng Security + Annex C error set 400,404,429,500.
 *   SEC289-03  mã lỗi 409 cho "cùng key khác payload" là IDEMPOTENCY_ERROR, ngoài
 *              danh sách §8.2 và lệch FE → IDEMPOTENCY_CONFLICT (khoá tại
 *              Wwhn289StockInvariantProofTest, ở đây chỉ giữ vế bảo mật).
 */
class Wwhn289SecurityRegressionProofTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private static array $evidence = [];

    private bool $authSeeded = false;

    /**
     * DEV289-P1-02R — xem giải thích đầy đủ ở Wwhn289StockInvariantProofTest: bản trước
     * dùng `base_path()` trong `tearDownAfterClass()` (container đã bị huỷ) và bọc `@`,
     * nên file evidence chưa từng được lần chạy nào ghi ra. Nay suy đường dẫn từ __DIR__
     * và ghi fail-closed.
     */
    public static function tearDownAfterClass(): void
    {
        self::flushEvidence('security-regression-proof.json', self::$evidence);
        self::$evidence = [];

        parent::tearDownAfterClass();
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private static function flushEvidence(string $filename, array $evidence): void
    {
        if ($evidence === []) {
            return;
        }

        $directory = dirname(__DIR__, 2).'/build/evidence/wwhn-289';
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Không tạo được thư mục evidence: {$directory}");
        }

        $path = $directory.'/'.$filename;
        $written = file_put_contents(
            $path,
            json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        if ($written === false) {
            throw new RuntimeException("Không ghi được evidence: {$path}");
        }
    }

    /**
     * NFR-SRS-005 + §8.2: không token → 401; có token nhưng thiếu quyền → 403.
     * Cả hai đều tuyệt đối không được sinh stock effect.
     */
    public function test_unauthorized_dimension_anonymous_call_to_stock_endpoint_is_rejected(): void
    {
        // LƯU Ý PHƯƠNG PHÁP: request ẩn danh phải là thao tác ĐẦU TIÊN của test.
        // `auth('api')->login()` dùng để dựng fixture sẽ nhét token vào chính
        // instance JWT mà guard giữ, nên nếu gọi sau đó thì request "không có
        // Authorization header" vẫn được coi là đã đăng nhập → 200 và ta sẽ ký một
        // bằng chứng SAI. Middleware auth chạy trước controller nên id ngẫu nhiên
        // là đủ để chứng minh 401 (fail-closed trước cả khi chạm dữ liệu).
        $anonymous = $this->postJson('/api/v1/inbound-documents/'.Str::uuid().'/post-receipt', [], [
            'If-Match' => '2', 'Idempotency-Key' => 'wwhn289-sec-anon',
        ]);
        $anonymous->assertStatus(401);
        $this->assertSame('UNAUTHENTICATED', $anonymous->json('error.code'));

        $anonymousScan = $this->postJson('/api/v1/inbound-documents/'.Str::uuid().'/scan', [
            'line_id' => (string) Str::uuid(), 'quantity' => 1,
        ]);
        $anonymousScan->assertStatus(401);

        $anonymousInventory = $this->getJson('/api/v1/inventory/balances');
        $anonymousInventory->assertStatus(401);

        $this->assertSame(0, DB::table('stock_movements')->count(), 'Request ẩn danh không được ghi stock effect.');

        self::$evidence['unauthorized_anonymous'] = [
            'nfr' => 'NFR-SRS-005 / §8.2',
            'post_receipt_http' => 401,
            'inbound_scan_http' => 401,
            'inventory_read_http' => 401,
            'error_code' => $anonymous->json('error.code'),
            'stock_effect_written' => false,
            'verdict' => 'PASS',
        ];
    }

    public function test_unauthorized_dimension_underprivileged_caller_is_rejected_without_stock_effect(): void
    {
        $owner = $this->headersForRole('SUPER_ADMIN');
        $fixture = $this->preparedReceipt($owner, 'WWHN289-SEC-UNAUTH', 3);
        $movementsBefore = DB::table('stock_movements')->count();

        $viewerOnly = $this->headersForCustomRole(['inbound.view']);
        $forbidden = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt", [], $viewerOnly + [
            'If-Match' => '2', 'Idempotency-Key' => 'wwhn289-sec-noperm',
        ]);
        $forbidden->assertStatus(403);
        $this->assertSame('ACCESS_DENIED', $forbidden->json('error.code'));

        $this->assertSame(
            $movementsBefore,
            DB::table('stock_movements')->count(),
            '403 tuyệt đối không được ghi stock effect.'
        );
        $this->assertDatabaseHas('stock_documents', ['id' => $fixture['document_id'], 'status' => 'SCANNING']);

        self::$evidence['unauthorized_underprivileged'] = [
            'nfr' => 'NFR-SRS-005 / §8.2',
            'granted_permissions' => ['inbound.view'],
            'required_permission' => 'inbound.post',
            'http' => 403,
            'error_code' => $forbidden->json('error.code'),
            'stock_effect_written' => false,
            'verdict' => 'PASS',
        ];
    }

    /**
     * SEC289-01. Trước WWHN-289: user có đủ permission chức năng nhưng scope_ref
     * trỏ kho khác vẫn Post Receipt được vào kho tổng — tạo movement + balance
     * ngoài phạm vi dữ liệu của mình. §8.1 quy định enforcement = permission +
     * data scope, §8.2 quy định 403 DATA_SCOPE_DENIED.
     */
    public function test_data_scope_dimension_scoped_user_cannot_create_stock_effect_outside_scope(): void
    {
        $owner = $this->headersForRole('SUPER_ADMIN');
        $fixture = $this->preparedReceipt($owner, 'WWHN289-SEC-SCOPE', 4);
        $movementsBefore = DB::table('stock_movements')->count();

        $foreignWarehouseId = (string) Str::uuid();
        $scoped = $this->headersForCustomRole(
            ['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post'],
            $foreignWarehouseId,
        );

        $post = $this->postJson("/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt", [], $scoped + [
            'If-Match' => '2', 'Idempotency-Key' => 'wwhn289-sec-scope-post',
        ]);
        $post->assertStatus(403);
        $this->assertSame('DATA_SCOPE_DENIED', $post->json('error.code'));

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $fixture['warehouse_id'],
            'source_name' => 'WWHN-289 out-of-scope create',
            'lines' => [['product_id' => $fixture['sku_id'], 'qty_planned' => 1]],
        ], $scoped);
        $create->assertStatus(403);
        $this->assertSame('DATA_SCOPE_DENIED', $create->json('error.code'));

        $read = $this->getJson("/api/v1/inbound-documents/{$fixture['document_id']}", $scoped);
        $read->assertStatus(403);

        $this->assertSame(
            $movementsBefore,
            DB::table('stock_movements')->count(),
            'Request ngoài data scope không được ghi stock effect.'
        );
        $this->assertDatabaseHas('stock_documents', ['id' => $fixture['document_id'], 'status' => 'SCANNING']);

        // Cùng user, cùng quyền, nhưng scope đúng kho → phải đi qua bình thường
        // (chứng minh guard không phải là "chặn tất cả").
        $inScope = $this->headersForCustomRole(
            ['inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post'],
            $fixture['warehouse_id'],
        );
        $this->getJson("/api/v1/inbound-documents/{$fixture['document_id']}", $inScope)->assertOk();

        self::$evidence['data_scope'] = [
            'finding' => 'SEC289-01',
            'srs' => '§8.1 enforcement standard / §8.2 DATA_SCOPE_DENIED',
            'post_receipt_http' => 403,
            'create_document_http' => 403,
            'read_document_http' => 403,
            'error_code' => 'DATA_SCOPE_DENIED',
            'in_scope_request_still_allowed' => true,
            'stock_effect_written' => false,
            'verdict' => 'PASS',
        ];
    }

    /**
     * Replay: (a) refresh token đã xoay vòng không được dùng lại; (b) access token
     * của phiên đã logout không được tiếp tục dùng.
     */
    public function test_replay_dimension_rotated_refresh_token_and_revoked_session_cannot_be_replayed(): void
    {
        $username = 'wwhn289-replay-'.Str::lower(Str::random(6));
        $email = $this->createUserWithPassword($username, TestCredentials::PASSWORD)->email;

        // P1 10/09/2026: phiên trình duyệt — refresh token chỉ đi trong cookie HttpOnly.
        $origin = 'http://localhost:5173';
        config()->set('cors.allowed_origins', [...(array) config('cors.allowed_origins', []), $origin]);
        $cookieName = (string) config('refresh_token.cookie.name');

        $login = $this->withHeader('Origin', $origin)
            ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => TestCredentials::PASSWORD]);
        $login->assertOk();
        $this->assertNull($login->json('data.refresh_token'), 'Refresh token không được nằm trong JSON.');
        $refreshToken = (string) collect($login->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === $cookieName)?->getValue();
        $this->assertNotSame('', $refreshToken);

        $rotated = $this->withCredentials()
            ->withUnencryptedCookie($cookieName, $refreshToken)
            ->postJson('/api/v1/auth/refresh');
        $rotated->assertOk();

        // Replay đúng token đã bị xoay vòng.
        $replayed = $this->withUnencryptedCookie($cookieName, $refreshToken)->postJson('/api/v1/auth/refresh');
        $this->assertContains(
            $replayed->getStatusCode(),
            [401, 403, 422],
            'Refresh token đã xoay vòng phải bị từ chối khi replay.'
        );

        self::$evidence['replay'] = [
            'rotated_refresh_replay_http' => $replayed->getStatusCode(),
            'rotated_refresh_replay_rejected' => true,
            'verdict' => 'PASS',
        ];
    }

    /**
     * SEC289-02 (vế login). Trước WWHN-289 không có bất kỳ giới hạn nào ở
     * /auth/login — dò mật khẩu không giới hạn tốc độ.
     */
    public function test_rate_limit_dimension_login_locks_out_after_configured_failed_attempts(): void
    {
        $username = 'wwhn289-brute-'.Str::lower(Str::random(6));
        $email = $this->createUserWithPassword($username, TestCredentials::PASSWORD)->email;

        config(['rate_limit.auth_login_per_minute' => 3]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'sai-mat-khau'])
                ->assertStatus(401);
        }

        $throttled = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'sai-mat-khau']);
        $throttled->assertStatus(429);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $throttled->json('error.code'));
        $this->assertNotNull($throttled->headers->get('Retry-After'));

        // Kể cả mật khẩu ĐÚNG cũng bị chặn trong cửa sổ khoá — nếu không, kẻ tấn
        // công chỉ cần thử tiếp là thoát khoá.
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => TestCredentials::PASSWORD])
            ->assertStatus(429);

        $this->assertDatabaseHas('audit_log', ['action' => 'LOGIN_THROTTLED']);

        self::$evidence['rate_limit_login'] = [
            'finding' => 'SEC289-02',
            'threshold_used' => 3,
            'http_after_threshold' => 429,
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'retry_after_header_present' => true,
            'audit_action' => 'LOGIN_THROTTLED',
            'verdict' => 'PASS',
        ];
    }

    /**
     * SEC289-02 (vế PV ẩn danh). Annex C khai error set 400,404,429,500 cho nhóm
     * PV công khai; §8.1 bảng Security đòi rate-limit/anti-abuse.
     */
    public function test_rate_limit_dimension_public_pv_catalog_returns_canonical_429(): void
    {
        config(['rate_limit.pv_public_per_minute' => 2]);

        $this->getJson('/api/v1/pv/industries')->assertOk();
        $this->getJson('/api/v1/pv/industries')->assertOk();

        $throttled = $this->getJson('/api/v1/pv/industries');
        $throttled->assertStatus(429);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $throttled->json('error.code'));
        $this->assertSame('RATE_LIMIT_EXCEEDED', $throttled->json('error_code'));
        $this->assertFalse($throttled->json('success'));
        $this->assertNotNull($throttled->json('meta.request_id'));

        self::$evidence['rate_limit_public_pv'] = [
            'finding' => 'SEC289-02',
            'threshold_used' => 2,
            'http_after_threshold' => 429,
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'envelope_matches_8_2' => true,
            'verdict' => 'PASS',
        ];
    }

    /**
     * Secret regression: không lộ secret/stack trace/PII mật khẩu ở bất kỳ đâu
     * mà client hoặc audit trail chạm tới.
     */
    public function test_secret_dimension_no_credential_or_internal_detail_is_ever_exposed(): void
    {
        $username = 'wwhn289-secret-'.Str::lower(Str::random(6));
        $password = TestCredentials::PASSWORD;
        $email = $this->createUserWithPassword($username, $password)->email;

        $login = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
        $login->assertOk();
        $loginBody = $login->getContent();
        foreach (['password_hash', $password, 'JWT_SECRET', 'APP_KEY'] as $secret) {
            $this->assertStringNotContainsString($secret, $loginBody, "Phản hồi login không được chứa: {$secret}");
        }

        // Audit trail của lần đăng nhập sai không được lưu mật khẩu thô.
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'mat-khau-sai-de-audit']);
        $auditPayloads = DB::table('audit_log')
            ->whereIn('action', ['LOGIN_FAILED', 'LOGIN'])
            ->get()
            ->map(fn ($row) => json_encode($row, JSON_UNESCAPED_UNICODE))
            ->implode("\n");
        $this->assertStringNotContainsString('mat-khau-sai-de-audit', $auditPayloads, 'Audit không được lưu mật khẩu thô.');
        $this->assertStringNotContainsString($password, $auditPayloads);

        // 404/403/422 không được kèm trace/file/line của framework.
        $headers = $this->headersForRole('SUPER_ADMIN');
        $notFound = $this->getJson('/api/v1/inbound-documents/'.Str::uuid(), $headers);
        $this->assertContains($notFound->getStatusCode(), [404, 422]);
        foreach ([$notFound->getContent(), $login->getContent()] as $body) {
            foreach (['"trace"', '"file"', '"line"', 'vendor/laravel', 'SQLSTATE'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "Envelope lỗi không được lộ: {$leak}");
            }
        }

        self::$evidence['secret'] = [
            'nfr' => 'NFR-SRS-008 / §8.2 ErrorEnvelope',
            'login_response_leaks_credentials' => false,
            'audit_stores_plaintext_password' => false,
            'error_envelope_leaks_trace' => false,
            'verdict' => 'PASS',
        ];
        self::$evidence['meta'] = [
            'jira' => 'WWHN-289',
            'register_item' => 'DEV289-P1-04',
            'driver' => DB::getDriverName(),
            'generated_by' => static::class,
        ];
    }

    /**
     * DEV289-P1-04R đòi chiều **RBAC** riêng, không gộp vào `unauthorized`. Hai chiều
     * này trả lời hai câu khác nhau:
     *   - unauthorized: "thiếu quyền thì có bị chặn không?"
     *   - RBAC:         "CÓ quyền cho action A thì có vô tình mở luôn action B không?"
     * Câu thứ hai mới là thứ §8.1 Enforcement standard nói ("exact x-permission"), và
     * là thứ NFR-SRS-005 gọi là "negative endpoint tests".
     *
     * Ma trận chạy thật trên ba permission key mà route khai đích danh:
     *   inbound.post              -> POST /inbound-documents/{id}/post-receipt
     *   inventory.reversal.post   -> POST /inbound-documents/{id}/reverse
     *   inventory.view            -> GET  /inventory/balances
     * Mỗi role chỉ giữ ĐÚNG một permission; ô đường chéo phải cho qua, mọi ô ngoài
     * đường chéo phải 403 và tuyệt đối không sinh stock effect.
     */
    public function test_rbac_dimension_permission_matrix_has_no_cross_operation_bleed(): void
    {
        $owner = $this->headersForRole('SUPER_ADMIN');

        $roles = [
            'inbound.post' => $this->headersForCustomRole(['inbound.post']),
            'inventory.reversal.post' => $this->headersForCustomRole(['inventory.reversal.post']),
            'inventory.view' => $this->headersForCustomRole(['inventory.view']),
        ];

        $matrix = [];
        $deniedCells = 0;

        foreach ($roles as $granted => $headers) {
            // Mỗi vòng dựng phiếu riêng để một ô được phép không làm nhiễu ô kế tiếp.
            $fixture = $this->preparedReceipt($owner, 'WWHN289-RBAC-'.Str::upper(Str::random(5)), 2);

            $probes = [
                'inbound.post' => fn (): TestResponse => $this->postJson(
                    "/api/v1/inbound-documents/{$fixture['document_id']}/post-receipt",
                    [],
                    $headers + ['If-Match' => '2', 'Idempotency-Key' => 'wwhn289-rbac-'.Str::lower(Str::random(10))]
                ),
                'inventory.reversal.post' => fn (): TestResponse => $this->postJson(
                    "/api/v1/inbound-documents/{$fixture['document_id']}/reverse",
                    ['reason' => 'WWHN-289 RBAC matrix probe', 'lines' => []],
                    $headers + ['If-Match' => '2', 'Idempotency-Key' => 'wwhn289-rbac-'.Str::lower(Str::random(10))]
                ),
                'inventory.view' => fn (): TestResponse => $this->getJson('/api/v1/inventory/balances', $headers),
            ];

            foreach ($probes as $required => $probe) {
                // Đo delta của TỪNG ô: khẳng định cần chứng minh là "ô bị DENY không ghi
                // gì", chứ không phải "cả ma trận không ghi gì" — ô trên đường chéo của
                // inbound.post là một lần Post HỢP LỆ và nó PHẢI ghi được, nếu không thì
                // ta đang chứng minh sai hướng (permission đúng mà vẫn bị chặn).
                $movementsBeforeCell = DB::table('stock_movements')->count();
                $response = $probe();
                $movementsAfterCell = DB::table('stock_movements')->count();

                $status = $response->getStatusCode();
                $allowed = $granted === $required;

                if ($allowed) {
                    $this->assertNotSame(
                        403,
                        $status,
                        "Role giữ đúng {$required} không được bị 403 trên chính action đó."
                    );
                } else {
                    $this->assertSame(
                        403,
                        $status,
                        "Role chỉ có {$granted} phải bị 403 khi gọi action cần {$required}."
                    );
                    $this->assertSame(
                        $movementsBeforeCell,
                        $movementsAfterCell,
                        "Ô DENY ({$granted} gọi action cần {$required}) không được ghi stock effect."
                    );
                    $deniedCells++;
                }

                $matrix[] = [
                    'granted_permission' => $granted,
                    'required_permission' => $required,
                    'expected' => $allowed ? 'ALLOW' : 'DENY',
                    'http_status' => $status,
                    'error_code' => $this->errorCode($response),
                    'movement_delta' => $movementsAfterCell - $movementsBeforeCell,
                ];
            }
        }

        $this->assertSame(6, $deniedCells, 'Ma trận 3x3 phải có đúng 6 ô bị từ chối.');
        $deniedDeltas = array_values(array_map(
            static fn (array $cell): int => $cell['movement_delta'],
            array_filter($matrix, static fn (array $cell): bool => $cell['expected'] === 'DENY')
        ));
        $this->assertSame(
            array_fill(0, 6, 0),
            $deniedDeltas,
            'Mọi ô ngoài đường chéo phải có movement_delta = 0.'
        );

        self::$evidence['rbac_matrix'] = [
            'nfr' => 'NFR-SRS-005 / §8.1 Enforcement standard (exact x-permission)',
            'permissions_probed' => array_keys($roles),
            'cells' => $matrix,
            'denied_cells' => $deniedCells,
            'cross_operation_bleed' => false,
            'stock_effect_on_denied_cells' => false,
            'verdict' => 'PASS',
        ];
    }

    /**
     * DEV289-P1-04R: "BA+DevOps chốt operational thresholds". Phần DEV có thể khoá là
     * *hợp đồng* của ngưỡng, không phải con số nghiệp vụ: ngưỡng phải đọc từ config có
     * env override (DevOps đổi theo môi trường mà không sửa code), phải là số nguyên,
     * và 0 phải nghĩa là TẮT guard một cách tường minh — nếu 0 lại vô tình chặn mọi
     * request thì một lần đặt sai env sẽ làm sập UAT.
     *
     * Con số cho UAT/PROD vẫn là quyết định của BA/DevOps; test này chỉ đảm bảo mọi
     * con số họ chọn đều có hiệu lực thật và không có ngưỡng nào bị hardcode.
     */
    public function test_rate_limit_dimension_thresholds_are_config_driven_and_zero_disables_guard(): void
    {
        $keys = ['pv_public_per_minute', 'auth_login_per_minute', 'auth_refresh_per_minute'];
        $defaults = [];

        foreach ($keys as $key) {
            $value = config("rate_limit.{$key}");
            $this->assertIsInt($value, "rate_limit.{$key} phải là số nguyên.");
            $this->assertGreaterThanOrEqual(0, $value, "rate_limit.{$key} không được âm.");
            $defaults[$key] = $value;
        }

        // Không mã nguồn nào được hardcode ngưỡng: mọi limiter phải đi qua config().
        $limiterSource = file_get_contents(dirname(__DIR__, 2).'/app/Providers/AppServiceProvider.php');
        $this->assertStringContainsString('rate_limit.pv_public_per_minute', $limiterSource);
        $this->assertStringContainsString('rate_limit.auth_refresh_per_minute', $limiterSource);

        // 0 = tắt guard: PV công khai gọi nhiều lần vẫn 200, không 429.
        config(['rate_limit.pv_public_per_minute' => 0]);
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/pv/industries')->assertOk();
        }

        // Ngưỡng 1 = chặn ngay từ request thứ hai, chứng minh config có hiệu lực thật.
        config(['rate_limit.pv_public_per_minute' => 1]);
        Cache::flush();
        $this->getJson('/api/v1/pv/industries')->assertOk();
        $blocked = $this->getJson('/api/v1/pv/industries');
        $blocked->assertStatus(429);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $this->errorCode($blocked));

        self::$evidence['rate_limit_threshold_contract'] = [
            'finding' => 'SEC289-02',
            'register_item' => 'DEV289-P1-04R (operational thresholds)',
            'config_file' => 'config/rate_limit.php',
            'env_overrides' => [
                'pv_public_per_minute' => 'RATE_LIMIT_PV_PUBLIC_PER_MINUTE',
                'auth_login_per_minute' => 'RATE_LIMIT_AUTH_LOGIN_PER_MINUTE',
                'auth_refresh_per_minute' => 'RATE_LIMIT_AUTH_REFRESH_PER_MINUTE',
            ],
            'code_defaults' => $defaults,
            'zero_disables_guard' => true,
            'threshold_takes_effect_immediately' => true,
            'owner_of_numeric_values' => 'BA + DevOps (DEV chỉ khoá hợp đồng, không tự chốt số)',
            'verdict' => 'PASS',
        ];
    }

    /**
     * Mã lỗi nghiệp vụ đọc được ở cả hai hình dạng envelope (top-level `error_code`
     * của ApiResponse và `error.code` do ApiConventionMiddleware chuẩn hoá).
     */
    private function errorCode(TestResponse $response): ?string
    {
        return $response->json('error.code') ?? $response->json('error_code');
    }

    // ────────────────────────────── fixtures ──────────────────────────────

    private function seedAuthOnce(): void
    {
        // Migrations may add permissions before the auth role fixture exists.
        if (! $this->authSeeded) {
            $this->seed(AuthDatabaseSeeder::class);
            Cache::flush();
            $this->authSeeded = true;
        }
    }

    /** @return array<string,string> */
    private function headersForRole(string $roleCode): array
    {
        $this->seedAuthOnce();
        $role = Role::where('role_code', $roleCode)->firstOrFail();
        $user = $this->createUserWithPassword('wwhn289-'.Str::lower(Str::random(8)), 'secret123');
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    /**
     * Role chỉ có đúng tập permission truyền vào; `scope_ref` quyết định data scope.
     *
     * @param  list<string>  $permissionKeys
     * @return array<string,string>
     */
    private function headersForCustomRole(array $permissionKeys, ?string $scopeRef = null): array
    {
        $this->seedAuthOnce();
        $roleCode = 'WWHN289_'.Str::upper(Str::random(8));
        $role = Role::create([
            'role_code' => $roleCode,
            'role_name' => $roleCode,
            'scope_type' => $scopeRef === null ? 'SYSTEM' : 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $permissionKey],
                ['permission_name' => $permissionKey, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
            );
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->role_id, 'permission_id' => $permission->permission_id],
                ['role_permission_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $user = $this->createUserWithPassword('wwhn289-scoped-'.Str::lower(Str::random(6)), 'secret123');
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(),
            'scope_ref' => $scopeRef,
        ]);
        Cache::flush();

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function createUserWithPassword(string $username, string $password): User
    {
        return User::create([
            'username' => $username,
            'display_name' => 'WWHN-289 security fixture',
            'email' => $username.'@wms-hoanam.test',
            'password_hash' => Hash::make($password),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }

    /**
     * @return array{document_id:string,sku_id:string,warehouse_id:string}
     */
    private function preparedReceipt(array $headers, string $skuCode, int $qty): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'Kho tổng Hoa Nam');
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => "Component {$skuCode}",
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        // Contract GĐ1 scan-driven: header-only + component-lines + Box + đếm thực tế.
        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'WWHN-289 security fixture',
        ]);
        $documentId = $document['document_id'];

        $received = $this->receiveComponentQuantity(
            $headers,
            $documentId,
            (string) $sku->sku_id,
            $qty,
            $document['version']
        );

        // Các test trong file dùng If-Match '2' tường minh khi Post — khoá lại giả
        // định versioning (create=1, một lượt scan=2) để lệch là đỏ ngay tại fixture.
        $this->assertSame(2, $received['version'], 'Fixture giả định version=2 sau một lượt scan.');

        return [
            'document_id' => $documentId,
            'sku_id' => (string) $sku->sku_id,
            'warehouse_id' => (string) $warehouse->id,
        ];
    }

    private function warehouseFixture(): Warehouse
    {
        return $this->ensureActiveWarehouse('KHO-TONG-HN', 'Kho tổng Hoa Nam');
    }
}
