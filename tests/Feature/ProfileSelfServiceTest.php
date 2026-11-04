<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use Tests\TestCase;

class ProfileSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function identity(): array
    {
        $password = Str::random(32);
        $user = User::create(['username' => 'self_'.Str::random(12), 'email' => Str::random(12).'@example.test',
            'display_name' => 'Self profile', 'password_hash' => Hash::make($password), 'status' => 'ACTIVE', 'version' => 1]);

        return [$user, $password];
    }

    private function makeSession(User $user, ?string $device = null): array
    {
        $request = Request::create('/');
        if ($device !== null) {
            $request->headers->add(['X-Device-Id' => $device, 'X-Device-Label' => 'Office browser',
                'X-Client-Type' => 'web', 'X-Client-Platform' => 'Windows']);
        }
        $service = app(RefreshTokenService::class);
        $session = $service->issueSession($user, $request);

        return [$service->accessTokenFor($user, $session['id']), $session];
    }

    private function headers(string $token, ?int $version = null): array
    {
        app('auth')->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']
            + ($version === null ? [] : ['If-Match' => '"'.$version.'"']);
    }

    public function test_preferences_are_self_scoped_versioned_partial_and_strict(): void
    {
        [$a] = $this->identity();
        [$b] = $this->identity();
        [$token] = $this->makeSession($a);
        [$other] = $this->makeSession($b);
        $this->getJson('/api/v1/auth/preferences', $this->headers($token))->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.theme', 'system')
            ->assertJsonPath('data.notifications.account.in_app', true)->assertJsonPath('data.notifications.account.email', false);
        $this->patchJson('/api/v1/auth/preferences', ['theme' => 'dark'], $this->headers($token))->assertStatus(428);
        $this->patchJson('/api/v1/auth/preferences', ['theme' => 'dark', 'notifications' => ['inventory' => ['in_app' => false]]], $this->headers($token, 1))
            ->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.theme', 'dark')
            ->assertJsonPath('data.notifications.inventory.in_app', false)->assertJsonPath('data.notifications.warranty.in_app', true);
        $this->patchJson('/api/v1/auth/preferences', ['locale' => 'en'], $this->headers($token, 1))->assertStatus(412);
        $this->patchJson('/api/v1/auth/preferences', ['notifications' => ['account' => ['in_app' => false]]], $this->headers($token, 2))->assertUnprocessable();
        $this->patchJson('/api/v1/auth/preferences', ['user_id' => $b->user_id], $this->headers($token, 2))->assertUnprocessable();
        $this->patchJson('/api/v1/auth/preferences', ['notifications' => ['inventory' => ['push' => true]]], $this->headers($token, 2))->assertUnprocessable();
        $this->getJson('/api/v1/auth/preferences', $this->headers($other))->assertOk()->assertJsonPath('data.theme', 'system');
        $this->assertSame(1, (int) $a->fresh()->version);
    }

    public function test_email_requires_current_password_only_when_changed_and_cannot_impersonate(): void
    {
        [$user, $password] = $this->identity();
        [$other] = $this->identity();
        [$token] = $this->makeSession($user);
        $this->patchJson('/api/v1/auth/me', ['email' => 'new@example.test'], $this->headers($token, 1))->assertUnprocessable();
        $this->patchJson('/api/v1/auth/me', ['email' => 'new@example.test', 'current_password' => 'wrong'], $this->headers($token, 1))->assertUnprocessable();
        $this->patchJson('/api/v1/auth/me', ['email' => $other->email, 'current_password' => $password], $this->headers($token, 1))->assertUnprocessable();
        $this->patchJson('/api/v1/auth/me', ['email' => $user->email, 'display_name' => ' Same email '], $this->headers($token, 1))
            ->assertOk()->assertJsonPath('data.display_name', 'Same email');
        $this->patchJson('/api/v1/auth/me', ['email' => 'new@example.test', 'current_password' => $password], $this->headers($token, 2))
            ->assertOk()->assertJsonPath('data.email', 'new@example.test')->assertJsonPath('data.version', 3);
        $this->assertTrue(Hash::check($password, $user->fresh()->password_hash));
    }

    public function test_avatar_and_profile_commit_together_and_are_private_versioned_and_validated(): void
    {
        [$user] = $this->identity();
        [$token] = $this->makeSession($user);
        $this->post('/api/v1/auth/me/avatar', ['avatar' => UploadedFile::fake()->image('a.png', 20, 20), 'display_name' => 'Photo owner'], $this->headers($token, 1))
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.display_name', 'Photo owner')
            ->assertJsonPath('data.avatar.mime_type', 'image/png');
        $this->get('/api/v1/auth/me/avatar', $this->headers($token))->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/api/v1/auth/me/avatar', ['avatar' => UploadedFile::fake()->image('b.jpg', 20, 20), 'display_name' => 'Stale'], $this->headers($token, 1))->assertStatus(412);
        $this->post('/api/v1/auth/me/avatar', ['avatar' => UploadedFile::fake()->create('evil.svg', 1, 'image/svg+xml')], $this->headers($token, 2))->assertUnprocessable();
        $this->assertSame('Photo owner', $user->fresh()->display_name);
        $this->deleteJson('/api/v1/auth/me/avatar', [], $this->headers($token, 2))->assertOk()->assertJsonPath('data.avatar', null)->assertJsonPath('data.version', 3);
        $this->get('/api/v1/auth/me/avatar', $this->headers($token))->assertNotFound();
    }

    public function test_installations_group_only_declared_ids_and_rotation_preserves_original_identity(): void
    {
        [$user] = $this->identity();
        $device = (string) Str::uuid();
        [$token, $first] = $this->makeSession($user, $device);
        $this->makeSession($user, $device);
        $this->makeSession($user);
        $rotated = app(RefreshTokenService::class)->rotate($first['raw'], Request::create('/'));
        $this->assertNotNull($rotated);
        $original = RefreshToken::findOrFail($first['id']);
        $this->travel(1)->minutes();
        $changedHeaders = Request::create('/');
        $changedHeaders->headers->add(['X-Device-Id' => (string) Str::uuid(), 'X-Device-Label' => 'Different declaration']);
        $again = app(RefreshTokenService::class)->rotate($rotated['raw'], $changedHeaders);
        $this->assertNotNull($again);
        $latest = RefreshToken::findOrFail($again['id']);
        $this->assertSame($original->auth_device_id, $latest->auth_device_id);
        $this->assertSame((string) $original->getRawOriginal('auth_session_started_at'), (string) $latest->getRawOriginal('auth_session_started_at'));
        $response = $this->getJson('/api/v1/auth/devices', $this->headers($token))->assertOk()
            ->assertJsonPath('meta.active_device_count', 1)->assertJsonPath('meta.unknown_device_group_count', 1)
            ->assertJsonPath('meta.active_session_count', 3)->assertJsonPath('meta.other_session_count', 2)
            ->assertJsonPath('meta.device_policy.max_devices', null)->assertJsonPath('meta.device_policy.limits_configured', false);
        $known = collect($response->json('data'))->firstWhere('device_id', '!=', null);
        $this->assertSame(2, $known['session_count']);
        $this->assertTrue($known['is_current']);
        $this->assertFalse($known['can_revoke']);
        $this->assertSame('Office browser', $known['device_label']);
        $this->deleteJson('/api/v1/auth/devices/'.$known['device_id'], [], $this->headers($token))->assertConflict()->assertJsonPath('error_code', 'CURRENT_DEVICE_PROTECTED');
    }

    public function test_device_and_bulk_revocation_preserve_current_and_enforce_ownership(): void
    {
        [$user] = $this->identity();
        [$foreign] = $this->identity();
        [$current] = $this->makeSession($user, (string) Str::uuid());
        $this->makeSession($user, (string) Str::uuid());
        $this->makeSession($user);
        [$foreignToken] = $this->makeSession($foreign, (string) Str::uuid());
        $foreignDevice = $this->getJson('/api/v1/auth/devices', $this->headers($foreignToken))->json('data.0.device_id');
        $this->deleteJson('/api/v1/auth/devices/'.$foreignDevice, [], $this->headers($current))->assertNotFound();
        $groups = $this->getJson('/api/v1/auth/devices', $this->headers($current))->json('data');
        $target = collect($groups)->first(fn ($group) => $group['device_id'] !== null && ! $group['is_current']);
        $this->deleteJson('/api/v1/auth/devices/'.$target['device_id'], [], $this->headers($current))->assertOk()->assertJsonPath('data.revoked_session_count', 1);
        $this->deleteJson('/api/v1/auth/devices/'.$target['device_id'], [], $this->headers($current))->assertOk()->assertJsonPath('data.already_revoked', true);
        $this->postJson('/api/v1/auth/sessions/revoke-others', [], $this->headers($current))->assertOk()->assertJsonPath('data.revoked_session_count', 1);
        $this->getJson('/api/v1/auth/me', $this->headers($current))->assertOk();
        $this->assertSame(1, RefreshToken::where('user_id', $user->user_id)->active()->count());
        $this->assertSame(1, RefreshToken::where('user_id', $foreign->user_id)->active()->count());
    }

    public function test_existing_account_replacement_and_bad_header_rollback_remain_unchanged(): void
    {
        config()->set('refresh_token.single_active_session.enabled', true);
        [$user] = $this->identity();
        [$old] = $this->makeSession($user, (string) Str::uuid());
        [$current] = $this->makeSession($user, (string) Str::uuid());
        $this->getJson('/api/v1/auth/me', $this->headers($old))->assertUnauthorized()->assertJsonPath('error_code', 'AUTH_SESSION_REPLACED');
        $this->getJson('/api/v1/auth/devices', $this->headers($current))->assertOk()
            ->assertJsonPath('meta.active_session_count', 1)->assertJsonPath('meta.account_session_policy.enabled', true)
            ->assertJsonPath('meta.device_policy.max_sessions_per_device', null);
        $bad = Request::create('/');
        $bad->headers->set('X-Device-Id', 'not-a-uuid');
        try {
            app(RefreshTokenService::class)->issueSession($user, $bad);
            $this->fail('Malformed installation identity must fail validation.');
        } catch (ValidationException) {
            // The failed completed login must not revoke the previous current family.
        }
        $this->getJson('/api/v1/auth/me', $this->headers($current))->assertOk();
        $this->assertSame(1, RefreshToken::where('user_id', $user->user_id)->active()->count());
    }

    public function test_login_history_keeps_replaced_phone_and_separates_login_families(): void
    {
        config()->set('refresh_token.single_active_session.enabled', true);
        [$user] = $this->identity();
        [$foreign] = $this->identity();
        $service = app(RefreshTokenService::class);
        $phone = Request::create('/');
        $phone->headers->add(['X-Device-Id' => (string) Str::uuid(), 'X-Device-Label' => 'Phone Chrome',
            'X-Client-Type' => 'web', 'X-Client-Platform' => 'Android', 'User-Agent' => 'Chrome/140 Mobile Android']);
        $login = $service->issueSession($user, $phone);
        $rotated = $service->rotate($login['raw'], $phone);
        $phoneToken = $service->accessTokenFor($user, $rotated['id']);
        $this->getJson('/api/v1/auth/device-history', $this->headers($phoneToken))->assertOk()->assertJsonCount(0, 'data');
        $this->travel(1)->minutes();
        [$current] = $this->makeSession($user, (string) Str::uuid());
        [$otherToken] = $this->makeSession($foreign, (string) Str::uuid());
        $history = $this->getJson('/api/v1/auth/device-history?per_page=1', $this->headers($current))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.device_label', 'Phone Chrome')
            ->assertJsonPath('data.0.status', 'SIGNED_OUT')->assertJsonPath('data.0.can_revoke', false)
            ->assertJsonPath('data.0.is_current', false)->assertJsonPath('data.0.platform', 'Android');
        $this->assertNotNull($history->json('data.0.started_at'));
        $this->assertNotNull($history->json('data.0.ended_at'));
        $this->assertArrayNotHasKey('token_hash', $history->json('data.0'));
        $this->assertStringNotContainsString($login['raw'], $history->getContent());
        $this->getJson('/api/v1/auth/device-history', $this->headers($otherToken))->assertOk()->assertJsonCount(0, 'data');
        $service->issueSession($user, $phone);
        $this->travel(1)->minutes();
        [$current] = $this->makeSession($user, (string) Str::uuid());
        $this->getJson('/api/v1/auth/device-history?per_page=1&page=2', $this->headers($current))->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/auth/devices', $this->headers($current))->assertOk()->assertJsonPath('meta.active_session_count', 1);
    }

    public function test_login_history_includes_expiry_and_rejects_foreign_filters(): void
    {
        config()->set('refresh_token.single_active_session.enabled', false);
        [$user] = $this->identity();
        [, $expired] = $this->makeSession($user);
        RefreshToken::whereKey($expired['id'])->update(['expires_at' => now()->subMinute()]);
        [$current] = $this->makeSession($user);
        $this->getJson('/api/v1/auth/device-history', $this->headers($current))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'EXPIRED')
            ->assertJsonPath('data.0.can_revoke', false);
        $this->getJson('/api/v1/auth/device-history?user_id=someone', $this->headers($current))->assertUnprocessable();
        $this->getJson('/api/v1/auth/device-history?per_page=101', $this->headers($current))->assertUnprocessable();
        $this->getJson('/api/v1/auth/device-history', $this->headers(''))->assertUnauthorized();
    }

    public function test_photo_profile_failure_is_atomic_and_other_users_cannot_read_it(): void
    {
        [$user] = $this->identity();
        [$token] = $this->makeSession($user);
        $this->post('/api/v1/auth/me/avatar', ['avatar' => UploadedFile::fake()->image('photo.jpg'),
            'display_name' => 'Do not commit', 'email' => 'changed@example.test'], $this->headers($token, 1))
            ->assertUnprocessable()->assertJsonPath('error_code', 'INVALID_CURRENT_PASSWORD');
        $this->assertSame('Self profile', $user->fresh()->display_name);
        $this->assertSame(1, (int) $user->fresh()->version);
        $this->assertDatabaseMissing('user_profile_avatars', ['user_id' => $user->user_id]);
        $this->post('/api/v1/auth/me/avatar', ['avatar' => UploadedFile::fake()->image('photo.png')->size(2049)], $this->headers($token, 1))->assertUnprocessable();
        $this->post('/api/v1/auth/me/avatar', ['avatar' => UploadedFile::fake()->image('photo.jpg')], $this->headers($token, 1))->assertOk();
        [$other] = $this->identity();
        [$foreign] = $this->makeSession($other);
        $this->get('/api/v1/auth/me/avatar', $this->headers($foreign))->assertNotFound();
        $this->get('/api/v1/auth/me/avatar?user_id='.$user->user_id, $this->headers($foreign))->assertUnprocessable();
    }

    public function test_invalid_utf8_installation_headers_return_422_and_preserve_current_login(): void
    {
        config()->set('refresh_token.single_active_session.enabled', true);
        [$user, $password] = $this->identity();
        $user->forceFill(['email' => strtolower($user->email)])->save();
        [$current, $session] = $this->makeSession($user, (string) Str::uuid());
        foreach (['X-Device-Label' => "Windows \xB7 Chrome", 'X-Client-Platform' => "Invalid \xC3"] as $header => $value) {
            $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password],
                $this->headers($current) + ['Origin' => 'http://localhost:5173', 'X-Device-Id' => (string) Str::uuid(), $header => $value])
                ->assertUnprocessable();
            $this->assertSame('INVALID_DEVICE_METADATA', $response->json('error_code'), json_encode($response->json('errors'), JSON_THROW_ON_ERROR));
            $this->getJson('/api/v1/auth/me', $this->headers($current))->assertOk();
            $this->assertNull(RefreshToken::findOrFail($session['id'])->replaced_by_auth_session_id);
            $this->assertSame(1, RefreshToken::where('user_id', $user->user_id)->active()->count());
        }
        $this->assertDatabaseCount('auth_client_installations', 1);
    }
}
