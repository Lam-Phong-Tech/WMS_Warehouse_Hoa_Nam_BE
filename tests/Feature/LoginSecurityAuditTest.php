<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Support\LoginThrottle;
use Tests\TestCase;

class LoginSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_clear_command_removes_state_and_audits_without_email(): void
    {
        Cache::flush();
        app(LoginThrottle::class)->registerFailure('user@example.test', '10.0.0.5');
        $this->artisan('auth:clear-login-throttle', ['--email' => 'user@example.test'])->assertSuccessful();
        $this->assertSame(0, app(LoginThrottle::class)->retryAfter('user@example.test', '10.0.0.5'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'LOGIN_THROTTLE_CLEARED']);
        $this->assertStringNotContainsString('user@example.test', json_encode(DB::table('audit_logs')->latest('id')->first()));
    }
}
