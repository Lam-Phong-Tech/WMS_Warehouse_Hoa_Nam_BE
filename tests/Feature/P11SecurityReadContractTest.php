<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Support\P11SecurityContract;
use Modules\Auth\Support\RefreshTokenService;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

/** Synthetic isolated test identities only; never seed devices or touch deployment data. */
class P11SecurityReadContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const BASE = '/api/v1/auth/';

    public function test_contract_is_additive_and_unsupported_operations_stay_unavailable(): void
    {
        [$user, $token] = $this->loginFixture();
        $response = $this->getJson(self::BASE.'me', $this->headers($token))->assertOk()
            ->assertJsonPath('data.user_id', $user->user_id)
            ->assertJsonPath('data.password_changed_at', null)
            ->assertJsonPath('meta.profile_contract.avatar.supported', true)
            ->assertJsonPath('meta.security_contract.version', P11SecurityContract::VERSION)
            ->assertJsonPath('meta.security_contract.password_policy.min_length', 8)
            ->assertJsonPath('meta.security_contract.password_policy.confirmation_required', true)
            ->assertJsonPath('meta.security_contract.operations.durable_receipt_supported', false)
            ->assertJsonPath('meta.security_contract.operations.reconciliation_supported', false)
            ->assertJsonPath('meta.security_contract.operations.url', null)
            ->assertJsonPath('meta.security_contract.release.production_ready', false);
        $this->assertSame('"1"', $response->headers->get('ETag'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_wrong_current_password_has_its_own_code_without_ending_auth(): void
    {
        [$user, $token, $secret] = $this->loginFixture();
        $before = $user->fresh()->password_hash;
        $new = bin2hex(random_bytes(16));
        $response = $this->postJson(self::BASE.'change-password', [
            'current_password' => 'wrong-'.$secret,
            'new_password' => $new, 'new_password_confirmation' => $new,
        ], $this->headers($token))->assertUnprocessable()
            ->assertJsonPath('error_code', 'CURRENT_PASSWORD_INCORRECT');
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($new, $response->getContent());
        $this->assertSame($before, $user->fresh()->password_hash);
        $this->assertSame(1, (int) $user->fresh()->version);
        $this->getJson(self::BASE.'me', $this->headers($token))->assertOk();
    }

    public function test_confirmation_and_length_errors_are_distinct_and_never_commit(): void
    {
        [$user, $token, $secret] = $this->loginFixture();
        $new = bin2hex(random_bytes(16));
        $base = ['current_password' => $secret, 'new_password' => $new];
        $this->postJson(self::BASE.'change-password', $base, $this->headers($token))
            ->assertUnprocessable()->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_REQUIRED');
        $this->postJson(self::BASE.'change-password', $base + ['new_password_confirmation' => 'different'], $this->headers($token))
            ->assertUnprocessable()->assertJsonPath('error_code', 'PASSWORD_CONFIRMATION_MISMATCH');
        $this->postJson(self::BASE.'change-password', [
            'current_password' => $secret, 'new_password' => 'short', 'new_password_confirmation' => 'short',
        ], $this->headers($token))->assertUnprocessable()
            ->assertJsonPath('error_code', 'PASSWORD_POLICY_VIOLATION')
            ->assertJsonPath('data.password_policy.min_length', 8);
        $this->assertTrue(Hash::check($secret, $user->fresh()->password_hash));
        $this->assertSame(1, (int) $user->fresh()->version);
    }

    public function test_bcrypt_byte_limit_and_nul_are_rejected_before_hashing(): void
    {
        [$user, $token, $secret] = $this->loginFixture();
        config()->set('hashing.driver', 'bcrypt');
        foreach ([str_repeat('á', 37), "valid-length\0invalid"] as $new) {
            $this->postJson(self::BASE.'change-password', [
                'current_password' => $secret, 'new_password' => $new, 'new_password_confirmation' => $new,
            ], $this->headers($token))->assertUnprocessable()
                ->assertJsonPath('error_code', 'PASSWORD_POLICY_VIOLATION')
                ->assertJsonPath('data.password_policy.max_utf8_bytes', 72);
        }
        $this->assertSame(1, (int) $user->fresh()->version);
        $this->getJson(self::BASE.'me', $this->headers($token))->assertOk();
    }

    public function test_password_result_uses_server_time_and_revokes_current_and_other_families(): void
    {
        [$user, $token, $secret] = $this->loginFixture();
        [, $other] = $this->loginFixture($user, $secret);
        $new = bin2hex(random_bytes(16));
        $response = $this->postJson(self::BASE.'change-password', [
            'current_password' => $secret, 'new_password' => $new, 'new_password_confirmation' => $new,
        ], $this->headers($token))->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.auth_result.relogin_required', true)
            ->assertJsonPath('meta.auth_result.durable_request_receipt', false)
            ->assertJsonPath('meta.auth_result.request_reconcile_supported', false);
        $time = $response->json('meta.auth_result.password_changed_at');
        $this->assertIsString($time);
        $this->assertSame($user->fresh()->passwordChangedAtEpoch(), Carbon::parse($time)->getTimestamp());
        $this->assertTrue(Hash::check($new, $user->fresh()->password_hash));
        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
        $this->getJson(self::BASE.'me', $this->headers($token))->assertUnauthorized();
        $this->getJson(self::BASE.'me', $this->headers($other))->assertUnauthorized();
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($new, $response->getContent());
    }

    public function test_current_family_id_is_present_when_it_is_not_on_the_page(): void
    {
        [$user, $token, $secret, $raw] = $this->loginFixture();
        $family = RefreshToken::query()->where('token_hash', hash('sha256', $raw))->value('auth_session_id');
        $this->travel(2)->seconds();
        $this->loginFixture($user, $secret);
        $first = $this->getJson(self::BASE.'sessions?per_page=1&page=1', $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_session_id', $family)
            ->assertJsonPath('data.0.is_current', false);
        $second = $this->getJson(self::BASE.'sessions?per_page=1&page=2', $this->headers($token))->assertOk()
            ->assertJsonPath('meta.current_session_id', $family)->assertJsonPath('data.0.session_id', $family)
            ->assertJsonPath('data.0.is_current', true)->assertJsonPath('data.0.can_revoke', false);
        $this->assertSame($second->json('data.0.auth_session_id'), $second->json('data.0.session_id'));
        $this->assertNull($first->json('data.0.device_label'));
        $this->assertNull($first->json('data.0.location'));
        $this->travelBack();
    }

    public function test_rotation_preserves_session_id_without_changing_delete_target_semantics(): void
    {
        [$user, $token, , $raw] = $this->loginFixture();
        $before = $this->getJson(self::BASE.'sessions', $this->headers($token))->assertOk();
        $rotated = app(RefreshTokenService::class)->rotate($raw, Request::create('/'));
        $this->assertNotNull($rotated);
        $after = $this->getJson(self::BASE.'sessions', $this->headers($token))->assertOk();
        $this->assertSame($before->json('data.0.session_id'), $after->json('data.0.session_id'));
        $this->assertSame($rotated['id'], $after->json('data.0.id'));
        $this->assertNotSame($before->json('data.0.id'), $after->json('data.0.id'));
        $this->deleteJson(self::BASE.'sessions/'.$after->json('data.0.id'), [], $this->headers($token))
            ->assertConflict()->assertJsonPath('error_code', 'CURRENT_SESSION_PROTECTED');
        $this->assertSame(1, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
    }

    public function test_session_ownership_and_secret_free_responses_remain_enforced(): void
    {
        [$user, $token, $secret, $raw] = $this->loginFixture();
        [$foreign] = $this->loginFixture();
        $foreignId = RefreshToken::query()->where('user_id', $foreign->user_id)->value('id');
        $this->deleteJson(self::BASE.'sessions/'.$foreignId, [], $this->headers($token))->assertNotFound();
        $response = $this->getJson(self::BASE.'sessions', $this->headers($token))->assertOk()->assertJsonPath('meta.total', 1);
        foreach (['access_token', 'refresh_token', 'token_hash', 'password_hash', 'password', 'secret'] as $key) {
            $this->assertArrayNotHasKey($key, $response->json('data.0'));
        }
        foreach ([$secret, $raw, $token] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }
    }

    public function test_no_proposed_operations_or_password_alias_route_is_registered(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertFalse(str_starts_with($route->uri(), 'api/v1/auth/operations'));
            $this->assertNotSame('api/v1/auth/password/change', $route->uri());
            $this->assertNotSame('api/v1/auth/password/policy', $route->uri());
        }
        $this->getJson(self::BASE.'me')->assertUnauthorized();
        $this->getJson(self::BASE.'sessions')->assertUnauthorized();
    }

    private function loginFixture(?User $user = null, ?string $secret = null): array
    {
        $secret ??= bin2hex(random_bytes(18));
        $suffix = Str::lower(Str::random(12));
        $user ??= User::create(['username' => 'p11_'.$suffix, 'email' => 'p11_'.$suffix.'@example.test',
            'display_name' => 'P11 isolated test', 'password_hash' => Hash::make($secret),
            'status' => 'ACTIVE', 'version' => 1, 'must_change_password' => false]);
        $this->resetGuards();
        $response = $this->browserLogin($user->email, $secret)->assertOk();

        return [$user, $response->json('data.access_token'), $secret, $this->refreshTokenFromCookie($response)];
    }

    private function headers(string $token): array
    {
        $this->resetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function resetGuards(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
