<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\P10ProfileController;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

/** Isolated test database only; generated credentials are never logged or shipped as defaults. */
class P10ProfileContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const BASE = '/api/v1/auth/';

    public function test_existing_and_new_self_service_routes_use_authenticated_p10(): void
    {
        $this->assertInstanceOf(P10ProfileController::class, app(AuthController::class));
        $wanted = ['me', 'logout', 'sessions', 'sessions/{id}', 'change-password',
            'me/avatar', 'preferences', 'devices', 'devices/{id}', 'sessions/revoke-others'];
        $seen = [];
        foreach (Route::getRoutes() as $route) {
            $path = str_replace('api/v1/auth/', '', $route->uri());
            if (in_array($path, $wanted, true)) {
                $this->assertContains('auth:api', $route->gatherMiddleware());
                $seen[$path] = true;
            }
        }
        $this->assertCount(10, $seen);
    }

    public function test_me_exposes_etag_canonical_context_and_explicit_unsupported_actions(): void
    {
        [$user, $token] = $this->loginFixture();
        $response = $this->getJson(self::BASE.'me', $this->headers($token))->assertOk()
            ->assertJsonPath('data.user_id', $user->user_id)
            ->assertJsonPath('meta.profile_contract.ui_name_field', 'display_name')
            ->assertJsonPath('meta.profile_contract.nickname_supported', false)
            ->assertJsonPath('meta.profile_contract.avatar.supported', true)
            ->assertJsonPath('meta.profile_contract.request_reconciliation.supported', false);
        $this->assertSame('"'.$response->json('data.version').'"', $response->headers->get('ETag'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertArrayHasKey('warehouse_context', $response->json('data'));
        $this->assertArrayNotHasKey('password_hash', $response->json('data'));
    }

    public function test_profile_whitelist_does_not_accept_query_or_client_authorization(): void
    {
        [$user, $token] = $this->loginFixture();
        foreach (['name', 'nickname', 'avatar', 'avatar_url', 'actor_id', 'roles', 'permissions', 'warehouse_id'] as $field) {
            $this->patchJson(self::BASE.'me', ['display_name' => 'Updated', $field => 'not-allowed'], $this->headers($token, 1))
                ->assertUnprocessable()->assertJsonPath('error_code', 'PROFILE_FIELD_NOT_EDITABLE');
        }
        $this->patchJson(self::BASE.'me?display_name=not-from-body', [], $this->headers($token, 1))->assertUnprocessable();
        $this->assertSame(1, (int) $user->fresh()->version);
    }

    public function test_profile_version_is_required_atomic_and_null_phone_is_supported(): void
    {
        [$user, $token] = $this->loginFixture();
        $this->patchJson(self::BASE.'me', ['display_name' => 'New name'], $this->headers($token))->assertStatus(428);
        $first = $this->patchJson(self::BASE.'me', ['display_name' => '  New name  ', 'phone' => null], $this->headers($token, 1))
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.display_name', 'New name')->assertJsonPath('data.phone', null);
        $this->assertSame('"2"', $first->headers->get('ETag'));
        $this->patchJson(self::BASE.'me', ['display_name' => 'Overwrite'], $this->headers($token, 1))
            ->assertStatus(412)->assertJsonPath('error_code', 'STALE_VERSION');
        $this->assertSame('New name', $user->fresh()->display_name);
    }

    public function test_blank_name_and_invalid_phone_are_not_persisted(): void
    {
        [$user, $token] = $this->loginFixture();
        foreach ([['display_name' => '   '], ['display_name' => null], ['phone' => 'invalid'], []] as $body) {
            $this->patchJson(self::BASE.'me', $body, $this->headers($token, 1))->assertUnprocessable();
        }
        $this->assertSame(1, (int) $user->fresh()->version);
    }

    public function test_current_family_survives_rotation_and_metadata_is_not_inferred(): void
    {
        [$user, $oldToken, $secret, $raw] = $this->loginFixture();
        $old = RefreshToken::query()->where('user_id', $user->user_id)->first();
        $rotated = app(RefreshTokenService::class)->rotate($raw, Request::create('/'));
        $this->assertNotNull($rotated);
        $list = $this->getJson(self::BASE.'sessions?per_page=1', $this->headers($oldToken))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.0.can_revoke', false)->assertJsonPath('data.0.device_label', null)
            ->assertJsonPath('data.0.location', null);
        $this->assertSame($old->auth_session_id, $list->json('data.0.auth_session_id'));
        $this->assertSame($rotated['id'], $list->json('data.0.id'));
        $this->deleteJson(self::BASE.'sessions/'.$old->id, [], $this->headers($oldToken))
            ->assertConflict()->assertJsonPath('error_code', 'CURRENT_SESSION_PROTECTED');
    }

    public function test_logout_without_refresh_token_revokes_only_current_family(): void
    {
        [$user, $a, $secret] = $this->loginFixture();
        [, $b] = $this->loginFixture($user, $secret);
        $this->postJson(self::BASE.'logout', [], $this->headers($a))->assertOk()
            ->assertJsonPath('data', null)->assertJsonPath('meta.auth_result.scope', 'CURRENT_AUTH_SESSION_FAMILY')
            ->assertJsonPath('meta.auth_result.business_drafts_deleted', false)
            ->assertJsonPath('meta.auth_result.work_shift_ended', false);
        $this->getJson(self::BASE.'me', $this->headers($a))->assertUnauthorized();
        $this->getJson(self::BASE.'me', $this->headers($b))->assertOk();
        $this->assertSame(1, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
    }

    public function test_native_logout_rejects_refresh_token_from_another_family(): void
    {
        [$user, $a, $secret] = $this->loginFixture();
        [, $b, , $rawB] = $this->loginFixture($user, $secret);
        config()->set('refresh_token.body_transport.enabled', true);
        config()->set('refresh_token.body_transport.allowed_origins', [$this->browserOrigin()]);
        $this->postJson(self::BASE.'logout', ['refresh_token' => $rawB], $this->headers($a) + ['X-WMS-Token-Transport' => 'body'])
            ->assertConflict()->assertJsonPath('error_code', 'AUTH_SESSION_TOKEN_MISMATCH');
        $this->getJson(self::BASE.'me', $this->headers($a))->assertOk();
        $this->getJson(self::BASE.'me', $this->headers($b))->assertOk();
    }

    public function test_cookie_channel_does_not_accept_refresh_body(): void
    {
        [, $token, , $raw] = $this->loginFixture();
        $this->postJson(self::BASE.'logout', ['refresh_token' => $raw], $this->headers($token))
            ->assertUnprocessable()->assertJsonPath('error_code', 'REFRESH_TOKEN_TRANSPORT_MISMATCH');
    }

    public function test_other_actor_session_is_not_found_and_revoke_is_family_scoped(): void
    {
        [$user, $a, $secret] = $this->loginFixture();
        [, $b, , $rawB] = $this->loginFixture($user, $secret);
        $bRow = RefreshToken::query()->where('token_hash', hash('sha256', $rawB))->first();
        [$foreign] = $this->loginFixture();
        $foreignId = RefreshToken::query()->where('user_id', $foreign->user_id)->value('id');
        $this->deleteJson(self::BASE.'sessions/'.$foreignId, [], $this->headers($a))->assertNotFound();
        $this->deleteJson(self::BASE.'sessions/'.$bRow->id, [], $this->headers($a))->assertOk()
            ->assertJsonPath('data.revoked', true)->assertJsonPath('data.was_current', false);
        $this->deleteJson(self::BASE.'sessions/'.$bRow->id, [], $this->headers($a))->assertOk()
            ->assertJsonPath('data.already_revoked', true);
        $this->getJson(self::BASE.'me', $this->headers($b))->assertUnauthorized();
        $this->getJson(self::BASE.'me', $this->headers($a))->assertOk();
    }

    public function test_password_requires_confirmation_rejects_keep_and_ends_all_login_families(): void
    {
        [$user, $a, $secret] = $this->loginFixture();
        [, $b] = $this->loginFixture($user, $secret);
        $new = bin2hex(random_bytes(18));
        $body = ['current_password' => $secret, 'new_password' => $new, 'new_password_confirmation' => $new];
        $this->postJson(self::BASE.'change-password', $body + ['keep_sessions' => true], $this->headers($a))->assertUnprocessable();
        $bad = $body;
        unset($bad['new_password_confirmation']);
        $this->postJson(self::BASE.'change-password', $bad, $this->headers($a))->assertUnprocessable();
        $this->postJson(self::BASE.'change-password', $body, $this->headers($a))->assertOk()
            ->assertJsonPath('data', null)->assertJsonPath('meta.auth_result.relogin_required', true);
        $this->assertTrue(Hash::check($new, $user->fresh()->password_hash));
        $this->assertSame(0, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
        $this->getJson(self::BASE.'me', $this->headers($b))->assertUnauthorized();
        $this->getJson(self::BASE.'me', $this->headers($a))->assertUnauthorized();
    }

    public function test_auth_is_required_and_pagination_is_validated(): void
    {
        $this->getJson(self::BASE.'me')->assertUnauthorized();
        [, $token] = $this->loginFixture();
        foreach (['per_page=0', 'per_page=101', 'page=-1', 'user_id=ignored', 'cursor=not-supported'] as $query) {
            $this->getJson(self::BASE.'sessions?'.$query, $this->headers($token))->assertUnprocessable();
        }
    }

    private function loginFixture(?User $user = null, ?string $secret = null): array
    {
        $secret ??= bin2hex(random_bytes(18));
        $suffix = Str::lower(Str::random(12));
        $user ??= User::create(['username' => 'p10_'.$suffix, 'email' => 'p10_'.$suffix.'@example.test',
            'display_name' => 'P10 isolated test', 'password_hash' => Hash::make($secret),
            'status' => 'ACTIVE', 'version' => 1, 'must_change_password' => false]);
        $this->resetGuards();
        $response = $this->browserLogin($user->email, $secret)->assertOk();

        return [$user, $response->json('data.access_token'), $secret, $this->refreshTokenFromCookie($response)];
    }

    private function headers(string $token, ?int $version = null): array
    {
        $this->resetGuards();

        return ['Authorization' => 'Bearer '.$token] + ($version === null ? [] : ['If-Match' => '"'.$version.'"']);
    }

    private function resetGuards(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
