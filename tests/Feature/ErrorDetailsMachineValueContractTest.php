<?php

namespace Tests\Feature;

use App\Support\Security\PiiCrypto;
use App\Support\Security\PiiKeyring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tests\TestCredentials;

/**
 * Giá trị máy trong `error.details` là hợp đồng API, không phải câu cho người đọc.
 *
 * `UserFacingMessageLocalizer::translateDetails()` từng Việt hoá MỌI giá trị details ở
 * boundary API (ApiError::abort, ApiResponse::respondError, ApiConventionMiddleware).
 * Ngoài enum (`conflicting_document_status` SCANNING -> "Đang quét", xem
 * OutboundReservationDiagnosticsTest), nó làm hỏng cả đường dẫn: 403
 * MFA_ENROLLMENT_REQUIRED trả `status_endpoint` = "/api/v1/auth/mfa/trạng thái" — client
 * đi theo đúng trường này để tới màn đăng ký MFA sẽ gọi vào một đường dẫn không tồn tại.
 */
class ErrorDetailsMachineValueContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Cùng cấu hình keyring với WwhnSec08MfaTest: luồng MFA mã hoá secret bằng lớp PII.
        config()->set('pii.encryption.keys', (string) json_encode([
            'v1' => 'base64:'.base64_encode(hash('sha256', 'machine-details-enc', true)),
        ]));
        config()->set('pii.blind_index.keys', (string) json_encode([
            'v1' => 'base64:'.base64_encode(hash('sha256', 'machine-details-idx', true)),
        ]));
        PiiKeyring::flush();
        PiiCrypto::flush();

        config()->set('mfa.enforce', true);
        config()->set('mfa.required_roles', ['SUPER_ADMIN']);
    }

    public function test_mfa_enrollment_block_returns_endpoint_paths_the_client_can_follow(): void
    {
        $token = auth('api')->login($this->makeUser('SUPER_ADMIN'));

        $blocked = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/warranty-cases')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'MFA_ENROLLMENT_REQUIRED')
            ->assertJsonPath('error.details.enroll_endpoint', '/api/v1/auth/mfa/enroll')
            ->assertJsonPath('error.details.status_endpoint', '/api/v1/auth/mfa/status')
            ->assertJsonPath('errors.status_endpoint', '/api/v1/auth/mfa/status');

        // Chứng minh bằng hành vi chứ không chỉ bằng chuỗi: đi theo đúng giá trị server
        // trả về phải tới được endpoint thật (nhóm MFA nằm trong allowlist của middleware).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson((string) $blocked->json('error.details.status_endpoint'))
            ->assertOk();
    }

    private function makeUser(string $roleCode): User
    {
        $user = User::query()->create([
            'user_id' => (string) Str::uuid(),
            'username' => 'machine'.Str::lower(Str::random(8)),
            'display_name' => 'Machine Details Test',
            'email' => 'machine'.Str::lower(Str::random(8)).'@example.com',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $role = Role::query()->firstOrCreate(
            ['role_code' => $roleCode],
            ['role_id' => (string) Str::uuid(), 'role_name' => $roleCode, 'status' => 'ACTIVE'],
        );

        DB::table('user_role')->insert([
            'user_role_id' => (string) Str::uuid(),
            'user_id' => $user->user_id,
            'role_id' => $role->role_id,
        ]);

        return $user->fresh();
    }
}
