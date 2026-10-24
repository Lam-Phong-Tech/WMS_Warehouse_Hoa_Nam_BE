<?php

namespace Tests\Feature;

use App\Support\Security\Totp;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Models\UserMfa;
use Modules\Auth\Support\MfaService;
use Modules\Auth\Support\P11SecurityCommands as Native;
use Modules\Auth\Support\RefreshTokenService;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

/** Disposable test identities only. Never a deployed admin login or real credential. */
class P11SecurityCommandsTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    public function test_protocol_stays_off_and_preexisting_mfa_and_original_identity_are_required(): void
    {
        [$user, $token, $password] = $this->fixture();
        $key = (string) Str::uuid();
        config(['p11.security_commands_enabled' => false]);
        $this->postJson('/api/v1/auth/logout', [], $this->command($token, $key))->assertStatus(503);
        $this->assertDatabaseCount(Native::TABLE, 0);
        config(['p11.security_commands_enabled' => true]);
        $legacy = $this->headers($token);
        unset($legacy[Native::HEADER]);
        $this->postJson('/api/v1/auth/logout', [], $legacy)->assertOk(); // Legacy remains legacy.
        [$user, $token] = $this->fixture(false);
        $this->postJson('/api/v1/auth/logout', [], $this->command($token, $key))->assertStatus(409)
            ->assertJsonPath('error.code', 'SECURITY_PREEXISTING_MFA_REQUIRED');
        $this->assertDatabaseCount(Native::TABLE, 0);
        [$user, $token] = $this->fixture();
        $headers = $this->command($token, (string) Str::uuid());
        unset($headers['X-Request-ID']);
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertStatus(428);
    }

    public function test_password_receipt_is_atomic_secret_free_and_new_family_requires_password_plus_mfa(): void
    {
        [$user, $token, $password, $secret, $recovery] = $this->fixture();
        $new = bin2hex(random_bytes(18)).'aA1!';
        $key = (string) Str::uuid();
        $this->postJson('/api/v1/auth/change-password', ['current_password' => $password, 'new_password' => $new,
            'new_password_confirmation' => $new], $this->command($token, $key))->assertOk()
            ->assertJsonPath('data.receipt.state', 'SUCCEEDED')->assertJsonPath('data.receipt.result.relogin_required', true);
        $this->assertDatabaseHas(Native::TABLE, ['actor_id' => $user->user_id, 'operation_key' => $key, 'state' => 'SUCCEEDED']);
        $this->assertTrue(Hash::check($new, $user->fresh()->password_hash));
        $this->get($this->url($key), $this->headers($token))->assertUnauthorized();
        $next = $this->reauth($user, $new, $secret);
        $this->get($this->url($key), $this->headers($next))->assertForbidden()
            ->assertJsonPath('error.code', 'SECURITY_RECONCILIATION_MFA_REQUIRED');
        $this->postJson($this->url($key).'/verify', ['current_password' => 'deliberately-wrong', 'code' => $recovery], $this->headers($next))
            ->assertForbidden()->assertJsonPath('error.code', 'SECURITY_REAUTHENTICATION_FAILED');
        $this->postJson($this->url($key).'/verify', ['current_password' => $new, 'code' => $recovery], $this->headers($next))
            ->assertOk()->assertJsonPath('data.command_replayed', false);
        $read = $this->get($this->url($key), $this->headers($next))->assertOk()->assertJsonPath('data.receipt.state', 'SUCCEEDED');
        $this->noNull($read->json());
        $stored = json_encode(DB::table(Native::TABLE)->first(), JSON_THROW_ON_ERROR);
        foreach ([$password, $new, $secret, $token, $recovery] as $private) {
            $this->assertStringNotContainsString($private, $stored);
            $this->assertStringNotContainsString($private, $read->getContent());
        }
        $this->postJson($this->url($key).'/verify', ['current_password' => $new, 'code' => $recovery], $this->headers($next))
            ->assertForbidden(); // Recovery code is single-use, not an alternate password.
        $this->travel(301)->seconds();
        $this->get($this->url($key), $this->headers($next))->assertForbidden();
    }

    public function test_unknown_never_reexecutes_or_rotates_key_and_does_not_expire_browser_cookie(): void
    {
        [$user, $token, $password] = $this->fixture();
        $key = (string) Str::uuid();
        $trace = (string) Str::uuid();
        $inject = true;
        DB::listen(static function (QueryExecuted $event) use (&$inject): void {
            if ($inject && str_starts_with(strtolower($event->sql), 'update') && str_contains($event->sql, Native::TABLE)
                && in_array('SUCCEEDED', $event->bindings, true)) {
                $inject = false;
                throw new \RuntimeException('Isolated native receipt failure');
            }
        });
        $headers = $this->command($token, $key, $trace);
        $result = $this->postJson('/api/v1/auth/logout', [], $headers)->assertStatus(503)
            ->assertJsonPath('data.receipt.state', 'UNKNOWN')->assertJsonPath('data.receipt.retry_policy', 'CHECK_ONLY');
        $this->assertCount(0, $result->headers->getCookies());
        $this->assertDatabaseHas(Native::TABLE, ['operation_key' => $key, 'state' => 'PENDING']);
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk();
        $this->postJson('/api/v1/auth/logout', [], $this->command($token, $key, $trace))->assertStatus(503);
        $this->postJson('/api/v1/auth/logout', [], $this->command($token, (string) Str::uuid()))->assertConflict()
            ->assertJsonPath('error.code', 'SECURITY_COMMAND_UNKNOWN');
        $legacy = $this->headers($token);
        unset($legacy[Native::HEADER]);
        $this->postJson('/api/v1/auth/logout', [], $legacy)->assertConflict()
            ->assertJsonPath('error.code', 'SECURITY_COMMAND_UNKNOWN');
        $read = $this->get($this->url($key), $this->headers($token))->assertOk()->assertJsonPath('data.receipt.state', 'UNKNOWN');
        $this->noNull($read->json());
        $this->assertDatabaseCount(Native::TABLE, 1);
        $this->assertDatabaseCount('p11_security_reconciliations', 0);
        $this->assertTrue(Hash::check($password, $user->fresh()->password_hash));
    }

    public function test_native_rejection_is_durable_and_same_key_does_not_become_success_with_a_changed_body(): void
    {
        [$user, $token, $password] = $this->fixture();
        $key = (string) Str::uuid();
        $trace = (string) Str::uuid();
        $new = bin2hex(random_bytes(18));
        $body = ['current_password' => 'wrong', 'new_password' => $new, 'new_password_confirmation' => $new];
        $this->postJson('/api/v1/auth/change-password', $body, $this->command($token, $key, $trace))->assertStatus(422)
            ->assertJsonPath('data.receipt.state', 'REJECTED')->assertJsonPath('data.receipt.error_code', 'CURRENT_PASSWORD_INCORRECT');
        $this->postJson('/api/v1/auth/change-password', $body, $this->command($token, $key, $trace))->assertStatus(422)
            ->assertHeader('Idempotent-Replay', 'true');
        $body['current_password'] = $password;
        $this->postJson('/api/v1/auth/change-password', $body, $this->command($token, $key, $trace))->assertConflict();
        $legacy = $this->headers($token);
        unset($legacy[Native::HEADER]);
        $this->postJson('/api/v1/auth/logout', [], $legacy)->assertStatus(428)
            ->assertJsonPath('error.code', 'SECURITY_COMMAND_CONTRACT_REQUIRED');
        $this->assertTrue(Hash::check($password, $user->fresh()->password_hash));
    }

    public function test_revoke_target_ownership_and_receipt_replay_preserve_other_sessions(): void
    {
        [$user, $token] = $this->fixture();
        $target = app(RefreshTokenService::class)->issue($user, Request::create('/isolated-native-revoke'));
        $id = RefreshToken::where('token_hash', hash('sha256', $target))->value('id');
        $key = (string) Str::uuid();
        $trace = (string) Str::uuid();
        $this->deleteJson('/api/v1/auth/sessions/'.$id, [], $this->command($token, $key, $trace))->assertOk()
            ->assertJsonPath('data.receipt.result.revoked', true);
        $this->deleteJson('/api/v1/auth/sessions/'.$id, [], $this->command($token, $key, $trace))->assertOk()
            ->assertHeader('Idempotent-Replay', 'true');
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk();
        [, $foreign] = $this->fixture();
        $this->get($this->url($key), $this->headers($foreign))->assertOk()->assertJsonPath('data.state', 'UNKNOWN')
            ->assertJsonPath('data.support_resolution_required', true);
    }

    public function test_factor_replacement_cannot_authorize_old_receipt_or_take_over_an_old_command(): void
    {
        [$user, $token, $password, $secret] = $this->fixture();
        $key = (string) Str::uuid();
        $this->postJson('/api/v1/auth/logout', [], $this->command($token, $key))->assertOk();
        $next = $this->reauth($user, $password, $secret);
        $this->postJson('/api/v1/auth/logout', [], $this->command($next, $key))->assertConflict()
            ->assertJsonPath('error.code', 'SECURITY_COMMAND_CHECK_ONLY');
        UserMfa::whereKey($user->user_id)->firstOrFail()->forceFill(['secret' => Totp::generateSecret()])->save();
        $this->postJson($this->url($key).'/verify', ['current_password' => $password, 'code' => '000000'], $this->headers($next))
            ->assertForbidden()->assertJsonPath('error.code', 'SECURITY_MFA_FACTOR_CHANGED');
        $this->assertDatabaseCount('p11_security_reconciliations', 0);
    }

    public function test_mfa_success_and_lock_instants_are_not_seven_hours_in_the_future(): void
    {
        [$user, $token, $password, $secret] = $this->fixture();
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Asia/Ho_Chi_Minh');
            $mfa = app(MfaService::class);
            $this->assertSame('totp', $mfa->verifySecondFactor($user, Totp::code($secret)));
            $factor = UserMfa::findOrFail($user->user_id);
            $this->assertLessThanOrEqual(2, abs(time() - $factor->last_used_at->timestamp));
            config(['mfa.max_verify_attempts' => 1]);
            // Use the existing configured attempt limit to exercise the real lock path.
            for ($i = 0; $i < (int) config('mfa.max_verify_attempts'); $i++) {
                $mfa->verifySecondFactor($user, 'not-a-code');
            }
            $factor = $factor->fresh();
            $this->assertNotNull($factor->locked_until);
            $this->assertLessThanOrEqual(2, abs(time() + (int) config('mfa.lockout_seconds', 900) - $factor->locked_until->timestamp));
            $this->assertTrue($factor->isLocked());
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_single_session_replacement_does_not_implicitly_transfer_command_receipt_authority(): void
    {
        config(['refresh_token.single_active_session.enabled' => true]);
        [$user, $token, $password, $secret, $recovery] = $this->fixture();
        $key = (string) Str::uuid();
        $this->postJson('/api/v1/auth/logout', [], $this->command($token, $key))->assertOk();
        $next = $this->reauth($user, $password, $secret);
        $this->get($this->url($key), $this->headers($token))->assertUnauthorized();
        $this->get($this->url($key), $this->headers($next))->assertForbidden();
        $this->postJson($this->url($key).'/verify', ['current_password' => $password, 'code' => $recovery], $this->headers($next))->assertOk();
        $this->get($this->url($key), $this->headers($next))->assertOk()->assertJsonPath('data.receipt.state', 'SUCCEEDED');
        $this->assertDatabaseCount(Native::TABLE, 1);
    }

    public function test_credential_hash_change_invalidates_assurance_even_with_the_same_credential_epoch(): void
    {
        [$user, $token, $password, $secret, $recovery] = $this->fixture();
        $key = (string) Str::uuid();
        $this->postJson('/api/v1/auth/logout', [], $this->command($token, $key))->assertOk();
        $next = $this->reauth($user, $password, $secret);
        $this->postJson($this->url($key).'/verify', ['current_password' => $password, 'code' => $recovery], $this->headers($next))->assertOk();
        $epoch = $user->fresh()->passwordChangedAtEpoch();
        // Isolated fixture emulates a same-epoch credential update, not a real repair.
        $user->fresh()->forceFill(['password_hash' => Hash::make(bin2hex(random_bytes(18)))])->save();
        $this->assertSame($epoch, $user->fresh()->passwordChangedAtEpoch());
        $this->get($this->url($key), $this->headers($next))->assertForbidden()
            ->assertJsonPath('error.code', 'SECURITY_RECONCILIATION_MFA_REQUIRED');
    }

    private function fixture(bool $withMfa = true): array
    {
        config(['p11.security_commands_enabled' => true]);
        $password = bin2hex(random_bytes(18));
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p11native_'.$suffix, 'email' => 'p11native_'.$suffix.'@wms-hoanam.test',
            'display_name' => 'Isolated native receipt', 'password_hash' => Hash::make($password), 'status' => 'ACTIVE', 'version' => 1]);
        $this->headers('');
        $token = $this->browserLogin($user->email, $password)->assertOk()->json('data.access_token');
        $secret = Totp::generateSecret();
        $recovery = strtoupper(bin2hex(random_bytes(5)));
        if ($withMfa) {
            UserMfa::create(['user_id' => $user->user_id, 'secret' => $secret, 'confirmed_at' => now()->utc(),
                'recovery_codes' => [Hash::make($recovery)], 'failed_attempts' => 0]);
        }

        return [$user, $token, $password, $secret, $recovery];
    }

    private function reauth(User $user, string $password, string $secret): string
    {
        $this->headers('');
        $challenge = $this->browserLogin($user->email, $password)->assertOk()->json('data.mfa_token');

        return $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $challenge, 'code' => Totp::code($secret)])
            ->assertOk()->json('data.access_token');
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, Native::HEADER => Native::CONTRACT];
    }

    private function command(string $token, string $key, ?string $trace = null): array
    {
        return $this->headers($token) + ['Idempotency-Key' => $key, 'X-Request-ID' => $trace ?? (string) Str::uuid()];
    }

    private function url(string $key): string
    {
        return '/api/v1/auth/security-requests/'.$key;
    }

    private function noNull(mixed $value): void
    {
        $this->assertNotNull($value);
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->noNull($child);
            }
        }
    }
}
