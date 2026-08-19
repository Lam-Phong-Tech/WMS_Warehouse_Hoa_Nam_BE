<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * WWHN-222 (DEV222-P1-03/P1-04) — chứng minh event/log catalog: cùng một
 * request_id xuyên suốt request → response envelope → audit_log, cho cả ba
 * nhánh success / business error / server-error-path; và PII (email) không
 * lọt raw vào audit_log kể cả trên đường lỗi.
 *
 * Đây là bằng chứng tự động hoá chạy local (sqlite/pgsql theo phpunit.xml).
 * Chạy thật trên môi trường UAT vẫn phụ thuộc WWHN-221/DEV222-P1-01 (log
 * sink/monitor do DevOps pin) — không nằm trong phạm vi test này.
 */
class Wwhn222AuditEventCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_login_shares_same_request_id_across_response_and_audit_log(): void
    {
        $user = $this->createUser('wwhn222_ok', 'wwhn222_ok@wms-hoanam.test');
        $requestId = 'wwhn222-success-'.(string) Str::uuid();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'wwhn222_ok@wms-hoanam.test',
            'password' => 'secret123',
        ], ['X-Request-ID' => $requestId])->assertOk();

        $response->assertJsonPath('meta.request_id', $requestId);
        $this->assertSame($requestId, $response->headers->get('X-Request-ID'));

        $audit = AuditLog::where('action', 'LOGIN')->where('object_id', $user->user_id)->firstOrFail();
        $this->assertSame($requestId, $audit->request_id);
        $this->assertSame('SUCCESS', $audit->result);
        $this->assertNull($audit->error_code);
    }

    public function test_business_error_login_shares_same_request_id_and_carries_error_code_without_raw_pii(): void
    {
        $this->createUser('wwhn222_bad', 'wwhn222_bad@wms-hoanam.test');
        $requestId = 'wwhn222-business-error-'.(string) Str::uuid();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'wwhn222_bad@wms-hoanam.test',
            'password' => 'wrong-password',
        ], ['X-Request-ID' => $requestId])->assertStatus(401);

        $response->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $response->assertJsonPath('meta.request_id', $requestId);

        $audit = AuditLog::where('action', 'LOGIN_FAILED')->where('request_id', $requestId)->firstOrFail();
        $this->assertSame('FAILURE', $audit->result);
        $this->assertSame('UNAUTHENTICATED', $audit->error_code);

        // DEV222-P1-04: email của lần đăng nhập sai không được lọt raw vào audit_log.
        $this->assertSame('[REDACTED]', $audit->after_data['email'] ?? null);
        $this->assertStringNotContainsString('wwhn222_bad@wms-hoanam.test', $audit->toJson());
    }

    public function test_server_error_path_audit_write_failure_is_logged_with_same_request_id(): void
    {
        // Mô phỏng nhánh "server/error-path": audit_log không ghi được (bảng bị
        // đổi tên tạm thời) — AuditLogger phải fallback ghi Monolog error kèm
        // đúng request_id, không được nuốt lỗi âm thầm và không lộ raw PII.
        $requestId = 'wwhn222-server-error-'.(string) Str::uuid();

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($requestId) {
                return $message === 'AUDIT_WRITE_FAILED' && ($context['request_id'] ?? null) === $requestId;
            });
        Log::shouldReceive('critical')->never();

        Schema::rename('audit_log', 'audit_log_wwhn222_unavailable');

        try {
            AuditLogger::record(
                'WWHN222_SERVER_ERROR_PROBE',
                'app_user',
                null,
                null,
                $requestId,
                afterData: ['email' => 'must-not-leak@wms-hoanam.test'],
                result: 'FAILURE',
                errorCode: 'INTERNAL_SERVER_ERROR',
            );

            $outbox = \Illuminate\Support\Facades\DB::table('audit_outbox')->latest('created_at')->first();
            $this->assertNotNull($outbox);
            $this->assertStringContainsString($requestId, $outbox->payload);
            $this->assertStringContainsString('[REDACTED]', $outbox->payload);
            $this->assertStringNotContainsString('must-not-leak@wms-hoanam.test', $outbox->payload);
        } finally {
            Schema::rename('audit_log_wwhn222_unavailable', 'audit_log');
        }
    }

    public function test_request_id_falls_back_to_current_request_when_caller_forgets_to_pass_it(): void
    {
        // DEV222-P1-03 gap đã ghi nhận: caller quên truyền requestId thì cột
        // request_id null, đứt chuỗi truy vết. AuditLogger phải tự lấy từ
        // request hiện tại (do ApiConventionMiddleware set attributes->request_id)
        // khi $requestId=null — mô phỏng trực tiếp thay vì phụ thuộc middleware
        // group của một route cụ thể.
        $requestId = 'wwhn222-fallback-'.(string) Str::uuid();

        $request = \Illuminate\Http\Request::create('/api/v1/wwhn222-probe', 'GET');
        $request->attributes->set('request_id', $requestId);
        $this->app->instance('request', $request);

        AuditLogger::record('WWHN222_FALLBACK_PROBE', 'app_user', null, null, null);

        $audit = AuditLog::where('action', 'WWHN222_FALLBACK_PROBE')->firstOrFail();
        $this->assertSame($requestId, $audit->request_id);
    }

    private function createUser(string $username, string $email): User
    {
        return User::create([
            'username' => $username,
            'display_name' => Str::headline($username),
            'email' => $email,
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }
}
