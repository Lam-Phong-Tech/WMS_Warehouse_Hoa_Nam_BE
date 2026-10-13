<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

/** Disposable identities only. No live login, token or deployment configuration. */
class P11LegacyCredentialBoundaryTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    public function test_no_sid_same_second_fails_closed_after_password_change(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$user] = $this->identity();
        $legacy = $this->legacyToken($user);
        $this->getJson('/api/v1/auth/me', $this->headers($legacy))->assertOk();
        $user->markPasswordChangedNow();
        $this->getJson('/api/v1/auth/me', $this->headers($legacy))
            ->assertUnauthorized()->assertJsonPath('error_code', 'CREDENTIALS_CHANGED');
    }

    public function test_no_sid_before_boundary_is_denied_and_after_boundary_remains_supported(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$user] = $this->identity();
        $old = $this->legacyToken($user);
        $this->travel(1)->seconds();
        $user->markPasswordChangedNow();
        $this->getJson('/api/v1/auth/me', $this->headers($old))
            ->assertUnauthorized()->assertJsonPath('error_code', 'CREDENTIALS_CHANGED');
        $this->travel(1)->seconds();
        $new = $this->legacyToken($user->fresh());
        $this->getJson('/api/v1/auth/me', $this->headers($new))->assertOk();
    }

    public function test_real_password_change_denies_old_tokens_but_allows_new_sid_in_same_second(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$user, $secret] = $this->identity();
        $legacy = $this->legacyToken($user);
        $this->resetGuards();
        $login = $this->browserLogin($user->email, $secret)->assertOk();
        $old = $login->json('data.access_token');
        $replacement = bin2hex(random_bytes(18));
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => $secret,
            'new_password' => $replacement,
            'new_password_confirmation' => $replacement,
        ], $this->headers($old))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->headers($legacy))
            ->assertUnauthorized()->assertJsonPath('error_code', 'CREDENTIALS_CHANGED');
        $this->getJson('/api/v1/auth/me', $this->headers($old))->assertUnauthorized();
        $this->resetGuards();
        $fresh = $this->browserLogin($user->email, $replacement)->assertOk();
        $token = $fresh->json('data.access_token');
        $this->resetGuards();
        $payload = auth('api')->setToken($token)->payload();
        $this->assertNotNull($payload->get('sid'));
        $this->assertSame($user->fresh()->passwordChangedAtEpoch(), (int) $payload->get('iat'));
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk();
        $this->assertSame(1, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
    }

    public function test_pre_refresh_row_and_current_row_both_protect_the_current_family(): void
    {
        [$user, $secret] = $this->identity();
        $this->resetGuards();
        $login = $this->browserLogin($user->email, $secret)->assertOk();
        $token = $login->json('data.access_token');
        $raw = $this->refreshTokenFromCookie($login);
        $before = RefreshToken::query()->where('token_hash', hash('sha256', $raw))->firstOrFail();
        $rotated = app(RefreshTokenService::class)->rotate($raw, Request::create('/'));
        $this->assertNotNull($rotated);
        $this->assertNotSame((string) $before->id, (string) $rotated['id']);
        foreach ([$before->id, $rotated['id']] as $id) {
            $this->deleteJson('/api/v1/auth/sessions/'.$id, [], $this->headers($token))
                ->assertConflict()->assertJsonPath('error_code', 'CURRENT_SESSION_PROTECTED');
        }
        [$foreign, $foreignSecret] = $this->identity();
        $this->resetGuards();
        $this->browserLogin($foreign->email, $foreignSecret)->assertOk();
        $foreignId = RefreshToken::query()->where('user_id', $foreign->user_id)->value('id');
        $this->deleteJson('/api/v1/auth/sessions/'.$foreignId, [], $this->headers($token))->assertNotFound();
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk();
        $this->assertSame(1, RefreshToken::query()->where('user_id', $user->user_id)->active()->count());
    }

    private function identity(): array
    {
        $secret = bin2hex(random_bytes(18));
        $suffix = Str::lower(Str::random(12));
        $user = User::create([
            'username' => 'p11_boundary_'.$suffix,
            'email' => 'p11_boundary_'.$suffix.'@example.test',
            'display_name' => 'P11 isolated boundary test',
            'password_hash' => Hash::make($secret),
            'status' => 'ACTIVE', 'version' => 1, 'must_change_password' => false,
        ]);

        return [$user, $secret];
    }

    private function legacyToken(User $user): string
    {
        $this->resetGuards();
        $token = auth('api')->login($user);
        $this->assertNull(auth('api')->setToken($token)->payload()->get('sid'));

        return $token;
    }

    private function headers(string $token): array
    {
        $this->resetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function resetGuards(): void
    {
        // PHPUnit reuses the JWT factory across HTTP requests, unlike PHP-FPM.
        // Clear its accumulated claims so a prior signed sid cannot contaminate
        // the separately signed legacy/no-sid token under test.
        $this->app['tymon.jwt.payload.factory']->emptyClaims()->customClaims([]);
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
