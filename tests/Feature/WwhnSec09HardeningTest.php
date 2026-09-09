<?php

namespace Tests\Feature;

use App\Logging\RedactSensitiveLogContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-SEC-09 — bộ test cho đợt gia cố bảo mật 09/09/2026:
 *  1. JWT chỉ nhận qua Authorization header (chặn ?token= rò vào access log).
 *  2. Vô hiệu hoá tài khoản thu hồi ngay mọi refresh token đang sống.
 *     (Đổi mật khẩu đã có lớp EnforceCredentialFreshness + revokeAllForUser,
 *     được WwhnSecurityHardeningTest pin sẵn error contract CREDENTIALS_CHANGED
 *     — không thêm blacklist ở đây để không đổi hợp đồng lỗi đó.)
 *  4. `published_to_app` (bề mặt công khai App PV) đòi đúng quyền pv.publish ở
 *     MỌI đường ghi SKU, không chỉ route publish chuyên dụng.
 *  5. Download/delete chứng từ đính kèm phải khớp quyền với ĐÚNG loại phiếu.
 *  6. auth:prune-refresh-tokens dọn token chết quá cửa sổ lưu vết.
 *  7. Log ứng dụng che credential trong context + JWT trong message.
 *  8. Cookie refresh/session bắt buộc Secure ngoài local/testing.
 *  9. audit.sensitive_keys che các khoá credential mới (mfa_token/otp/...).
 * 10. scope_ref khi gán vai trò phải là kho có thật.
 * 11. Danh sách phiếu nhập lọc theo warehouse data-scope.
 */
class WwhnSec09HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_jwt_chi_nhan_qua_authorization_header(): void
    {
        $user = $this->createUser('sec09_transport');
        $token = JWTAuth::fromUser($user);

        // Kênh hợp lệ duy nhất: Authorization header.
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        // JWTGuard cache user trong cùng một app instance giữa các sub-request
        // của test — phải quên guard đi thì lần gọi sau mới parse token lại.
        $this->app['auth']->forgetGuards();
        // Singleton `tymon.jwt` còn cache token của sub-request trước — không
        // unset thì lần gọi sau "xác thực" bằng token cũ chứ không parse gì cả.
        app('tymon.jwt')->unsetToken();

        // Query string từng được tymon/jwt-auth mặc định chấp nhận — token sẽ
        // nằm lại trong access log nginx/proxy/history. Phải chết.
        $this->getJson("/api/v1/auth/me?token={$token}")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->app['auth']->forgetGuards();
        // Singleton `tymon.jwt` còn cache token của sub-request trước — không
        // unset thì lần gọi sau "xác thực" bằng token cũ chứ không parse gì cả.
        app('tymon.jwt')->unsetToken();

        // Body input (InputSource) cũng không phải kênh xác thực.
        $this->postJson('/api/v1/auth/logout', ['token' => $token])
            ->assertStatus(401);
    }

    public function test_vo_hieu_hoa_tai_khoan_thu_hoi_moi_refresh_token_dang_song(): void
    {
        $adminRole = $this->createRole('SEC09_IAM', ['iam.user.manage']);
        $admin = $this->createUser('sec09_admin', $adminRole);
        $target = $this->createUser('sec09_target');

        // Tạo phiên thật cho target (login phát refresh token).
        $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => 'secret123',
        ])->assertOk();
        $this->assertTrue(RefreshToken::where('user_id', $target->user_id)->active()->exists());

        $adminToken = JWTAuth::fromUser($admin);
        $this->patchJson("/api/v1/users/{$target->user_id}", ['status' => 'INACTIVE'], [
            'Authorization' => "Bearer {$adminToken}",
            'If-Match' => '1',
        ])->assertOk();

        $this->assertFalse(
            RefreshToken::where('user_id', $target->user_id)->active()->exists(),
            'Vô hiệu hoá tài khoản phải thu hồi ngay mọi refresh token, không chờ token bị đem ra dùng.'
        );
    }

    public function test_published_to_app_doi_quyen_pv_publish_o_moi_duong_ghi_sku(): void
    {
        $managerRole = $this->createRole('SEC09_SKU_MANAGER', ['sku.manage']);
        $manager = $this->createUser('sec09_sku_manager', $managerRole);
        $managerHeaders = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($manager),
        ];

        // Tạo SKU thường (không đụng published) — vẫn thuộc quyền sku.manage.
        $create = $this->postJson('/api/v1/skus', [
            'code' => 'SEC09-SKU-01',
            'name' => 'SKU kiểm thử SEC09',
            'unit' => 'cái',
        ], $managerHeaders + ['Idempotency-Key' => 'sec09-sku-create-01'])
            ->assertCreated();
        $skuId = $create->json('data.sku_id') ?? $create->json('data.id');
        $this->assertNotNull($skuId);

        // Create publish-ngay-khi-tạo mà không có pv.publish → chặn.
        $this->postJson('/api/v1/skus', [
            'code' => 'SEC09-SKU-02',
            'name' => 'SKU publish lậu',
            'published_to_app' => true,
        ], $managerHeaders + ['Idempotency-Key' => 'sec09-sku-create-02'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        // Đổi giá trị published qua generic update mà không có pv.publish → chặn.
        $this->patchJson("/api/v1/skus/{$skuId}", ['published_to_app' => true], $managerHeaders + [
            'Idempotency-Key' => 'sec09-sku-publish-01',
            'If-Match' => '1',
        ])->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        // Form echo lại đúng giá trị hiện tại (false) → không phải thao tác publish.
        $this->patchJson("/api/v1/skus/{$skuId}", [
            'name' => 'SKU kiểm thử SEC09 (đổi tên)',
            'published_to_app' => false,
        ], $managerHeaders + [
            'Idempotency-Key' => 'sec09-sku-noop-01',
            'If-Match' => '1',
        ])->assertOk();

        // Có pv.publish thì generic update đổi được cờ.
        $publisherRole = $this->createRole('SEC09_SKU_PUBLISHER', ['sku.manage', 'pv.publish']);
        $publisher = $this->createUser('sec09_sku_publisher', $publisherRole);
        $this->patchJson("/api/v1/skus/{$skuId}", ['published_to_app' => true], [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($publisher),
            'Idempotency-Key' => 'sec09-sku-publish-02',
            'If-Match' => '2',
        ])->assertOk();

        // Audit deny phải để lại dấu vết đòi đúng quyền pv.publish.
        $this->assertDatabaseHas('audit_log', ['action' => 'AUTHZ_PERMISSION_DENIED']);
    }

    public function test_thao_tac_chung_tu_dinh_kem_phai_khop_quyen_voi_loai_phieu(): void
    {
        $attachmentId = (string) Str::uuid();
        DB::table('document_attachments')->insert([
            'id' => $attachmentId,
            'document_type' => 'INBOUND',
            'document_id' => (string) Str::uuid(),
            'file_name' => 'phieu-nhap.pdf',
            'content_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'storage_disk' => 'local',
            'storage_key' => 'document-attachments/INBOUND/test/'.Str::uuid().'.pdf',
            'status' => 'ACTIVE',
            'uploaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Actor chỉ có quyền OUTBOUND: lọt middleware route (union 3 luồng)
        // nhưng phải bị chặn ở lớp khớp-loại-phiếu.
        $outboundOnly = $this->createUser(
            'sec09_outbound_only',
            $this->createRole('SEC09_OUTBOUND_ONLY', ['outbound.view', 'outbound.scan', 'outbound.post'])
        );
        $outboundHeaders = ['Authorization' => 'Bearer '.JWTAuth::fromUser($outboundOnly)];

        $this->getJson("/api/v1/document-attachments/{$attachmentId}/download", $outboundHeaders)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->deleteJson("/api/v1/document-attachments/{$attachmentId}", [], $outboundHeaders)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCESS_DENIED');
        $this->assertDatabaseHas('document_attachments', ['id' => $attachmentId, 'status' => 'ACTIVE']);

        // Actor đúng luồng INBOUND xoá được (soft-delete).
        $inboundActor = $this->createUser(
            'sec09_inbound_actor',
            $this->createRole('SEC09_INBOUND_ACTOR', ['inbound.view', 'inbound.create'])
        );
        $this->deleteJson("/api/v1/document-attachments/{$attachmentId}", [], [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($inboundActor),
        ])->assertOk();
        $this->assertDatabaseHas('document_attachments', ['id' => $attachmentId, 'status' => 'DELETED']);
    }

    public function test_prune_refresh_tokens_xoa_token_chet_qua_cua_so_luu_vet(): void
    {
        $user = $this->createUser('sec09_prune');

        $mk = function (array $overrides) use ($user): void {
            DB::table('refresh_tokens')->insert(array_merge([
                'id' => (string) Str::uuid(),
                'user_id' => $user->user_id,
                'token_hash' => hash('sha256', Str::random(64)),
                'expires_at' => now()->addDays(14),
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ], $overrides));
        };

        $mk(['expires_at' => now()->subDays(40)]);                    // hết hạn quá cửa sổ → xoá
        $mk(['revoked_at' => now()->subDays(40)]);                    // thu hồi quá cửa sổ → xoá
        $mk(['expires_at' => now()->subDays(5)]);                     // hết hạn còn trong cửa sổ → giữ
        $mk(['revoked_at' => now()->subDays(5)]);                     // thu hồi còn trong cửa sổ → giữ
        $mk([]);                                                      // đang sống → giữ

        $this->artisan('auth:prune-refresh-tokens')->assertSuccessful();

        $this->assertSame(3, RefreshToken::where('user_id', $user->user_id)->count());
    }

    public function test_log_ung_dung_che_credential_trong_context_va_jwt_trong_message(): void
    {
        $handler = new TestHandler;
        $monolog = new MonologLogger('sec09-test', [$handler]);
        (new RedactSensitiveLogContext)(new Logger($monolog));

        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.TJVA95OrM7E2cBab30RMHrHDcEfxjoYZgeFONFh7HgQ';
        $monolog->warning("Token bi loi: Bearer {$jwt}", [
            'password' => 'plain-secret',
            'mfa_token' => 'mfa-secret-value',
            'nested' => ['refresh_token' => 'raw-refresh-value'],
            'note' => 'binh thuong',
        ]);

        $record = $handler->getRecords()[0];
        $this->assertStringNotContainsString($jwt, $record->message);
        $this->assertStringContainsString('[REDACTED_TOKEN]', $record->message);
        $this->assertSame('[REDACTED]', $record->context['password']);
        $this->assertSame('[REDACTED]', $record->context['mfa_token']);
        $this->assertSame('[REDACTED]', $record->context['nested']['refresh_token']);
        $this->assertSame('binh thuong', $record->context['note']);
    }

    public function test_cookie_refresh_va_session_bat_buoc_secure_ngoai_local_testing(): void
    {
        // phpunit đặt APP_ENV=testing ở nhiều adapter ($_SERVER/$_ENV/getenv) —
        // phải ghi đè CẢ BA thì env() trong file config mới thấy giá trị mới.
        $originalServer = $_SERVER['APP_ENV'] ?? null;
        $originalEnvVar = $_ENV['APP_ENV'] ?? null;
        $originalGetenv = getenv('APP_ENV');

        try {
            $_SERVER['APP_ENV'] = 'production';
            $_ENV['APP_ENV'] = 'production';
            putenv('APP_ENV=production');
            $refreshConfig = require base_path('config/refresh_token.php');
            $sessionConfig = require base_path('config/session.php');

            $this->assertTrue(
                (bool) $refreshConfig['cookie']['secure'],
                'Ngoài local/testing, refresh cookie phải Secure bất kể env REFRESH_TOKEN_COOKIE_SECURE.'
            );
            $this->assertTrue(
                (bool) $sessionConfig['secure'],
                'Ngoài local/testing, session cookie phải mặc định Secure khi SESSION_SECURE_COOKIE không đặt.'
            );
        } finally {
            if ($originalServer === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $originalServer;
            }
            if ($originalEnvVar === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $originalEnvVar;
            }
            putenv($originalGetenv === false ? 'APP_ENV' : "APP_ENV={$originalGetenv}");
        }
    }

    public function test_audit_che_cac_khoa_credential_moi(): void
    {
        AuditLogger::record(
            'SEC09_MASK_TEST',
            'app_user',
            null,
            null,
            (string) Str::uuid(),
            afterData: [
                'mfa_token' => 'challenge-value',
                'otp' => '123456',
                'reset_token' => 'reset-value',
                'token' => 'bare-token-value',
                'recovery_codes' => ['AAAA-BBBB'],
            ],
        );

        $audit = AuditLog::where('action', 'SEC09_MASK_TEST')->firstOrFail();
        foreach (['mfa_token', 'otp', 'reset_token', 'token', 'recovery_codes'] as $key) {
            $this->assertSame('[REDACTED]', $audit->after_data[$key], "Khoá {$key} phải bị che trong audit.");
        }
        $this->assertStringNotContainsString('challenge-value', $audit->toJson());
        $this->assertStringNotContainsString('123456', $audit->toJson());
    }

    public function test_scope_ref_khi_gan_vai_tro_phai_la_kho_co_that(): void
    {
        $adminRole = $this->createRole('SEC09_IAM2', ['iam.user.manage']);
        $admin = $this->createUser('sec09_iam2', $adminRole);
        $memberRole = $this->createRole('SEC09_MEMBER', ['inbound.view']);
        $target = $this->createUser('sec09_scope_target');
        $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($admin)];

        $this->postJson("/api/v1/users/{$target->user_id}/roles", [
            'roles' => [[
                'role_id' => $memberRole->role_id,
                'scope_ref' => (string) Str::uuid(), // UUID trôi nổi, không phải kho nào
            ]],
            'reason' => 'kiểm thử scope_ref không tồn tại',
        ], $headers)->assertStatus(422);

        $warehouse = $this->ensureActiveWarehouse('KHO-SEC09', 'Kho SEC09');
        $this->postJson("/api/v1/users/{$target->user_id}/roles", [
            'roles' => [[
                'role_id' => $memberRole->role_id,
                'scope_ref' => $warehouse->getKey(),
            ]],
            'reason' => 'kiểm thử scope_ref hợp lệ',
        ], $headers)->assertOk();
    }

    public function test_danh_sach_phieu_nhap_loc_theo_warehouse_scope(): void
    {
        $warehouseA = $this->ensureActiveWarehouse('KHO-SEC09-A', 'Kho SEC09 A');
        $warehouseB = Warehouse::create([
            'code' => 'KHO-SEC09-B', 'name' => 'Kho SEC09 B', 'status' => 'INACTIVE',
        ]);

        $creator = $this->createUser('sec09_doc_creator');
        $mkDoc = function (string $no, string $warehouseId) use ($creator): void {
            DB::table('stock_documents')->insert([
                'id' => (string) Str::uuid(),
                'doc_type' => 'receipt',
                'doc_no' => $no,
                'doc_date' => now()->toDateString(),
                'status' => 'DRAFT',
                'dst_warehouse_id' => $warehouseId,
                'created_by' => $creator->user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };
        $mkDoc('SEC09-RCPT-A', $warehouseA->getKey());
        $mkDoc('SEC09-RCPT-B', $warehouseB->getKey());

        $scopedRole = $this->createRole('SEC09_SCOPED_INBOUND', ['inbound.view']);
        $scopedUser = $this->createUser('sec09_scoped_inbound', $scopedRole, ['scope_ref' => $warehouseA->getKey()]);
        $response = $this->getJson('/api/v1/inbound-documents', [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($scopedUser),
        ])->assertOk();

        $docNos = collect($response->json('data'))->pluck('doc_no');
        $this->assertTrue($docNos->contains('SEC09-RCPT-A'), 'Phiếu trong scope phải hiện.');
        $this->assertFalse($docNos->contains('SEC09-RCPT-B'), 'Phiếu ngoài scope không được lộ trên danh sách.');

        // Người không bị giới hạn kho (scope_ref null) giữ nguyên hành vi cũ: thấy tất.
        $globalUser = $this->createUser('sec09_global_inbound', $this->createRole('SEC09_GLOBAL_INBOUND', ['inbound.view']));
        $all = $this->getJson('/api/v1/inbound-documents', [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($globalUser),
        ])->assertOk();
        $this->assertTrue(collect($all->json('data'))->pluck('doc_no')->contains('SEC09-RCPT-B'));
    }

    private function createRole(string $code, array $permissions): Role
    {
        $role = Role::create([
            'role_code' => $code,
            'role_name' => $code,
            'scope_type' => 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);

        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE'],
            );
            DB::table('role_permission')->insert([
                'role_permission_id' => (string) Str::uuid(),
                'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $role;
    }

    private function createUser(string $username, ?Role $role = null, array $pivot = []): User
    {
        $user = User::create([
            'username' => $username,
            'display_name' => Str::headline($username),
            'email' => "{$username}@wms-hoanam.test",
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        if ($role !== null) {
            $user->roles()->attach($role->role_id, array_merge([
                'user_role_id' => (string) Str::uuid(),
            ], $pivot));
        }

        return $user;
    }
}
