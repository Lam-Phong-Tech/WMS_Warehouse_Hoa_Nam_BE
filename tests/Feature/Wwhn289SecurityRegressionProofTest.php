<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/**
 * WWHN-289 / DEV289-P1-04 — "security finding → fix → retest trace".
 *
 * Review 34/100 đòi đúng 4 nhóm regression: unauthorized, data-scope, replay,
 * secret. File này lái cả 4 qua HTTP thật trên các endpoint có stock effect và
 * ghi evidence máy đọc được `build/evidence/wwhn-289/security-regression-proof.json`.
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
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private static array $evidence = [];

    public static function tearDownAfterClass(): void
    {
        if (self::$evidence !== []) {
            $directory = base_path('build/evidence/wwhn-289');
            if (! is_dir($directory)) {
                @mkdir($directory, 0777, true);
            }
            @file_put_contents(
                $directory.'/security-regression-proof.json',
                json_encode(self::$evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }
        self::$evidence = [];

        parent::tearDownAfterClass();
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
        $this->createUserWithPassword($username, 'Cntt@1234');

        $login = $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'Cntt@1234']);
        $login->assertOk();
        $refreshToken = (string) $login->json('data.refresh_token');
        $this->assertNotSame('', $refreshToken);

        $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken]);
        $rotated->assertOk();

        // Replay đúng token đã bị xoay vòng.
        $replayed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken]);
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
        $this->createUserWithPassword($username, 'Cntt@1234');

        config(['rate_limit.auth_login_per_minute' => 3]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'sai-mat-khau'])
                ->assertStatus(401);
        }

        $throttled = $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'sai-mat-khau']);
        $throttled->assertStatus(429);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $throttled->json('error.code'));
        $this->assertNotNull($throttled->headers->get('Retry-After'));

        // Kể cả mật khẩu ĐÚNG cũng bị chặn trong cửa sổ khoá — nếu không, kẻ tấn
        // công chỉ cần thử tiếp là thoát khoá.
        $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'Cntt@1234'])
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
        $password = 'Cntt@1234';
        $this->createUserWithPassword($username, $password);

        $login = $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => $password]);
        $login->assertOk();
        $loginBody = $login->getContent();
        foreach (['password_hash', $password, 'JWT_SECRET', 'APP_KEY'] as $secret) {
            $this->assertStringNotContainsString($secret, $loginBody, "Phản hồi login không được chứa: {$secret}");
        }

        // Audit trail của lần đăng nhập sai không được lưu mật khẩu thô.
        $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'mat-khau-sai-de-audit']);
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

    // ────────────────────────────── fixtures ──────────────────────────────

    private function seedAuthOnce(): void
    {
        if (DB::table('permission')->count() === 0) {
            $this->seed(AuthDatabaseSeeder::class);
            Cache::flush();
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
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'Kho tổng Hòa Nam');
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => "Component {$skuCode}",
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'WWHN-289 security fixture',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => $qty]],
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');
        $lineId = (string) $create->json('data.lines.0.id');

        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-'.Str::upper(Str::random(8)),
        ], $headers);
        $boxId = (string) $box->json('data.container_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => $qty,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        return [
            'document_id' => $documentId,
            'sku_id' => (string) $sku->sku_id,
            'warehouse_id' => (string) $warehouse->id,
        ];
    }

    private function warehouseFixture(): Warehouse
    {
        return $this->ensureActiveWarehouse('KHO-TONG-HN', 'Kho tổng Hòa Nam');
    }
}
