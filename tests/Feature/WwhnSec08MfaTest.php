<?php

namespace Tests\Feature;

use App\Support\Security\PiiCrypto;
use App\Support\Security\PiiKeyring;
use App\Support\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Models\UserMfa;
use Modules\Auth\Support\ApiAuthContext;
use Modules\Auth\Support\MfaService;
use Tests\TestCase;

/**
 * WWHN-SEC-08 — xác thực hai bước.
 *
 * Bộ test chia làm hai nhóm và cả hai đều cần thiết:
 *
 *  A. TÍNH ĐÚNG CỦA THUẬT TOÁN — đối chiếu với chính vector kiểm thử của
 *     RFC 6238. Đây là cách duy nhất chứng minh một bản cài TOTP tự viết là
 *     đúng chuẩn chứ không phải "chạy được trên máy tôi".
 *
 *  B. TÍNH ĐÚNG CỦA LUỒNG — những chỗ MFA hay bị cài thành "bước bấm thêm":
 *     mật khẩu đúng có phát token không, mã đã dùng có dùng lại được không,
 *     phiên chờ có dùng lại được không, dò mã có bị chặn không.
 */
class WwhnSec08MfaTest extends TestCase
{
    use RefreshDatabase;

    private MfaService $mfa;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pii.encryption.keys', (string) json_encode([
            'v1' => 'base64:'.base64_encode(hash('sha256', 'wwhn-sec-08-enc', true)),
        ]));
        config()->set('pii.blind_index.keys', (string) json_encode([
            'v1' => 'base64:'.base64_encode(hash('sha256', 'wwhn-sec-08-idx', true)),
        ]));
        PiiKeyring::flush();
        PiiCrypto::flush();

        $this->mfa = app(MfaService::class);
    }

    // ---------------------------------------------------------------- A

    /**
     * RFC 6238, Appendix B — bảng vector kiểm thử chính thức (HMAC-SHA1, seed
     * ASCII "12345678901234567890", 8 chữ số).
     *
     * Nếu bản cài lệch dù một bit thì ít nhất một dòng dưới đây sẽ sai. Đây là
     * lý do dám tự cài TOTP thay vì thêm một dependency.
     */
    public function test_khop_vector_kiem_thu_cua_rfc_6238(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        $vectors = [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $timestamp => $expected) {
            $this->assertSame(
                $expected,
                Totp::code($secret, $timestamp, 30, 8),
                "Sai vector RFC 6238 tại T={$timestamp}",
            );
        }
    }

    public function test_base32_di_va_ve_khong_mat_du_lieu(): void
    {
        foreach (['a', 'ab', 'abc', 'abcd', 'abcde', random_bytes(20)] as $raw) {
            $this->assertSame($raw, Totp::base32Decode(Totp::base32Encode($raw)));
        }
    }

    public function test_cua_so_thoi_gian_chap_nhan_lech_dong_ho_mot_buoc(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        // ±1 bước (±30 giây) — điện thoại lệch vài giây là chuyện thường.
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now - 30), $now));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now), $now));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now + 30), $now));

        // Ngoài cửa sổ thì KHÔNG chấp nhận — nếu không thì "hết hạn" mất nghĩa.
        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now - 90), $now));
        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now + 90), $now));

        // Rác không bao giờ được coi là hợp lệ.
        $this->assertFalse(Totp::verify($secret, 'abcdef', $now));
        $this->assertFalse(Totp::verify($secret, '', $now));
        $this->assertFalse(Totp::verify($secret, '12345', $now));
    }

    // ---------------------------------------------------------------- B

    public function test_bi_mat_totp_khong_nam_o_dang_ro_trong_csdl(): void
    {
        $user = $this->makeUser();
        $enrollment = $this->mfa->beginEnrollment($user);

        $raw = DB::table('user_mfa')->where('user_id', $user->user_id)->first();
        $serialized = (string) json_encode((array) $raw);

        $this->assertStringNotContainsString($enrollment['secret'], $serialized);
        $this->assertStringStartsWith('v1$', (string) $raw->secret_ciphertext);

        // Nhưng ứng dụng vẫn đọc lại đúng.
        $this->assertSame($enrollment['secret'], UserMfa::query()->find($user->user_id)?->secret);
    }

    public function test_dang_ky_chua_xac_nhan_thi_chua_duoc_coi_la_da_bat(): void
    {
        $user = $this->makeUser();
        $this->mfa->beginEnrollment($user);

        // Người dùng quét QR hỏng rồi bỏ dở KHÔNG được tự khoá mình ra ngoài.
        $this->assertFalse($this->mfa->isEnabled($user));
        $this->assertNull($this->mfa->confirmEnrollment($user, '000000'));
        $this->assertFalse($this->mfa->isEnabled($user));
    }

    public function test_mat_khau_dung_nhung_chua_qua_mfa_thi_khong_phat_token(): void
    {
        $user = $this->makeUser();
        $this->enableMfa($user);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Cntt@1234',
        ]);

        $response->assertOk();
        $body = $response->json('data');

        // Đây là điểm cốt lõi của cả tính năng: sau bước mật khẩu, kẻ có mật khẩu
        // vẫn KHÔNG có gì gọi được API.
        $this->assertTrue($body['mfa_required']);
        $this->assertArrayNotHasKey('access_token', $body);
        $this->assertArrayNotHasKey('refresh_token', $body);
        $this->assertNotEmpty($body['mfa_token']);
    }

    public function test_hoan_tat_dang_nhap_hai_buoc(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableMfa($user);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Cntt@1234',
        ])->json('data.mfa_token');

        $response = $this->postJson('/api/v1/auth/mfa/verify', [
            'mfa_token' => $challenge,
            'code' => Totp::code($secret),
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.access_token'));
        $this->assertSame('totp', $response->json('data.second_factor'));
    }

    public function test_ma_phien_cho_chi_dung_duoc_mot_lan(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableMfa($user);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Cntt@1234',
        ])->json('data.mfa_token');

        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $challenge, 'code' => Totp::code($secret)])->assertOk();

        // Dùng lại chính mã phiên chờ đó — kể cả với một mã TOTP hợp lệ mới.
        $this->postJson('/api/v1/auth/mfa/verify', [
            'mfa_token' => $challenge,
            'code' => Totp::code($secret, time() + 30),
        ])->assertStatus(401)->assertJsonPath('error_code', 'MFA_CHALLENGE_INVALID');
    }

    public function test_ma_totp_da_dung_khong_dung_lai_duoc(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableMfa($user);
        $code = Totp::code($secret);

        $this->assertSame('totp', $this->mfa->verifySecondFactor($user, $code));

        // TOTP còn hiệu lực suốt cả cửa sổ 30-90 giây. Không chặn dùng lại thì
        // một mã bị nhìn trộm/chụp màn hình còn dùng được cho lần đăng nhập sau.
        $this->assertNull($this->mfa->verifySecondFactor($user, $code));
    }

    public function test_ma_khoi_phuc_dung_duoc_mot_lan_va_bi_tieu_huy(): void
    {
        $user = $this->makeUser();
        $this->enableMfa($user);
        $codes = $this->issueRecoveryCodes($user);

        $this->assertCount((int) config('mfa.recovery_codes.count'), $codes);
        $this->assertSame('recovery', $this->mfa->verifySecondFactor($user, $codes[0]));
        $this->assertSame(count($codes) - 1, $this->mfa->remainingRecoveryCodes($user));

        // Dùng lại chính mã đó -> hỏng. Đây là điểm phân biệt mã khôi phục với
        // một mật khẩu phụ.
        $this->assertNull($this->mfa->verifySecondFactor($user, $codes[0]));
    }

    public function test_ma_khoi_phuc_luu_o_dang_bam_chu_khong_phai_ma_hoa(): void
    {
        $user = $this->makeUser();
        $this->enableMfa($user);
        $codes = $this->issueRecoveryCodes($user);

        $stored = (array) UserMfa::query()->find($user->user_id)?->recovery_codes;

        foreach ($codes as $plain) {
            $this->assertStringNotContainsString($plain, (string) json_encode($stored));
        }

        // Băm, không phải mã hoá: hệ thống chỉ cần KIỂM, không bao giờ cần đọc lại.
        $this->assertTrue(Hash::check($codes[0], (string) $stored[0]));
    }

    public function test_do_ma_sai_lien_tuc_bi_khoa(): void
    {
        $user = $this->makeUser();
        $this->enableMfa($user);
        $max = (int) config('mfa.max_verify_attempts', 5);

        for ($i = 0; $i < $max; $i++) {
            $this->assertNull($this->mfa->verifySecondFactor($user, '000000'));
        }

        $record = UserMfa::query()->find($user->user_id);
        $this->assertTrue($record?->isLocked());

        // Sau khi khoá thì mã ĐÚNG cũng không qua được — nếu không, trần số lần
        // thử chỉ làm chậm chứ không chặn.
        $this->assertNull($this->mfa->verifySecondFactor($user, Totp::code($record->secret)));
    }

    public function test_vai_tro_dac_quyen_bi_chan_khi_chua_dang_ky_va_cuong_che_bat(): void
    {
        config()->set('mfa.enforce', true);
        config()->set('mfa.required_roles', ['SUPER_ADMIN']);

        $user = $this->makeUser(roleCode: 'SUPER_ADMIN');
        $token = auth('api')->login($user);

        $this->assertTrue($this->mfa->mustEnrollNow($user));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/warranty-cases')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'MFA_ENROLLMENT_REQUIRED');
    }

    /**
     * Nhánh dễ làm sai nhất: chặn quá tay thì khoá luôn chính các endpoint cần
     * dùng ĐỂ đăng ký, và cách thoát duy nhất là sửa CSDL bằng tay.
     */
    public function test_van_goi_duoc_nhom_endpoint_mfa_khi_dang_bi_chan(): void
    {
        config()->set('mfa.enforce', true);
        config()->set('mfa.required_roles', ['SUPER_ADMIN']);

        $user = $this->makeUser(roleCode: 'SUPER_ADMIN');
        $token = auth('api')->login($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/mfa/status')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/mfa/enroll')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
    }

    public function test_vai_tro_thuong_khong_bi_bat_buoc_mfa(): void
    {
        config()->set('mfa.enforce', true);
        config()->set('mfa.required_roles', ['SUPER_ADMIN']);

        // Nhân viên kho quét mã cả ca — bắt họ nhập 6 chữ số mỗi phiên là cách
        // chắc chắn để MFA biến mất trên thực tế (một điện thoại dùng chung đặt
        // cạnh máy quét) nhưng vẫn "có" trên giấy tờ.
        $user = $this->makeUser(roleCode: 'WAREHOUSE_STAFF');

        $this->assertFalse($this->mfa->isPrivileged($user));
        $this->assertFalse($this->mfa->mustEnrollNow($user));
    }

    /**
     * Phiên chờ sống 5 phút, và bước PHÁT TOKEN là bước 2 chứ không phải bước
     * mật khẩu. Nếu bước 2 không kiểm lại trạng thái thì lệnh khoá khẩn cấp chỉ
     * có hiệu lực sau khi phiên chờ hết hạn — đúng lúc không còn cần nữa.
     *
     * Đây là chỗ KHÔNG nhìn ra được bằng mắt: cả hai đường đều trả token hợp lệ,
     * khác biệt duy nhất nằm ở một tài khoản vừa bị khoá vài giây trước.
     */
    public function test_khoa_tai_khoan_giua_hai_buoc_thi_khong_phat_token(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableMfa($user);

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Cntt@1234',
        ])->json('data.mfa_token');

        $this->assertNotEmpty($challenge);

        // Quản trị viên khoá tài khoản NGAY SAU khi mật khẩu đã được chấp nhận.
        $user->forceFill(['status' => 'LOCKED'])->save();

        $response = $this->postJson('/api/v1/auth/mfa/verify', [
            'mfa_token' => $challenge,
            'code' => Totp::code($secret),
        ]);

        $response->assertStatus(403)->assertJsonPath('error_code', 'ACCESS_DENIED');
        $this->assertNull($response->json('data.access_token'));
    }

    /**
     * Route bước 2 phải nằm trong danh sách public: nó chạy khi CHƯA có JWT nào.
     * Test này khoá lại chính chỗ đã sai một lần — khai route mà quên
     * ApiAuthContext thì ba middleware yêu cầu actor vẫn chạy trên request rỗng.
     */
    public function test_route_buoc_hai_duoc_khai_la_public_va_van_co_throttle_rieng(): void
    {
        $request = Request::create('/api/v1/auth/mfa/verify', 'POST');

        $this->assertTrue(ApiAuthContext::isPublicEndpoint($request));

        $route = collect(Route::getRoutes())->first(
            fn ($candidate) => $candidate->uri() === 'api/v1/auth/mfa/verify',
        );

        $this->assertNotNull($route);
        $this->assertNotContains('auth:api', $route->gatherMiddleware());

        // Public KHÔNG được đồng nghĩa với "không giới hạn tần suất": ApiRateLimit
        // bỏ qua endpoint public, nên route này phải tự mang throttle riêng.
        $this->assertNotEmpty(array_filter(
            $route->gatherMiddleware(),
            fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'),
        ));
    }
    // ------------------------------------------------------------------

    private function makeUser(string $roleCode = 'SUPER_ADMIN'): User
    {
        $user = User::query()->create([
            'user_id' => (string) Str::uuid(),
            'username' => 'mfa'.Str::lower(Str::random(8)),
            'display_name' => 'MFA Test',
            'email' => 'mfa'.Str::lower(Str::random(8)).'@example.com',
            'password_hash' => Hash::make('Cntt@1234'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $role = Role::query()->firstOrCreate(
            ['role_code' => $roleCode],
            ['role_id' => (string) Str::uuid(), 'role_name' => $roleCode, 'status' => 'ACTIVE'],
        );

        DB::table('user_role')->insert([
            // user_role có khoá chính riêng và NOT NULL — bảng pivot ở ERD này
            // không dùng khoá kép (user_id, role_id).
            'user_role_id' => (string) Str::uuid(),
            'user_id' => $user->user_id,
            'role_id' => $role->role_id,
        ]);

        return $user->fresh();
    }

    private function enableMfa(User $user): string
    {
        $enrollment = $this->mfa->beginEnrollment($user);

        // Xác nhận bằng mã của BƯỚC THỜI GIAN TRƯỚC (vẫn trong cửa sổ ±1) để mã
        // của bước hiện tại còn nguyên cho chính test đang chạy. Dùng cùng một
        // mã ở cả hai chỗ sẽ bị lớp chống-dùng-lại chặn — và khi đó test báo
        // đỏ vì FIXTURE, không phải vì code sai.
        $this->mfa->confirmEnrollment($user, Totp::code($enrollment['secret'], time() - 30));

        return $enrollment['secret'];
    }

    /** @return array<int, string> */
    private function issueRecoveryCodes(User $user): array
    {
        // Đăng ký lại từ đầu để lấy bộ mã khôi phục nguyên vẹn.
        UserMfa::query()->where('user_id', $user->user_id)->delete();
        $enrollment = $this->mfa->beginEnrollment($user);

        return (array) $this->mfa->confirmEnrollment($user, Totp::code($enrollment['secret'], time() - 30));
    }
}
