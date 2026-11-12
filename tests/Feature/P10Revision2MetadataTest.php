<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

/** Explicitly disposable database tests; no real account or live server is used. */
class P10Revision2MetadataTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    public function test_sparse_phone_delete_preserves_name_and_returns_new_strong_etag(): void
    {
        [$user, $token] = $this->loginFixture();
        $read = $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk();
        $etag = $read->headers->get('ETag');
        $this->assertSame('"1"', $etag);
        foreach (['W/"1"', '*', '"0"', '"1", "2"'] as $invalid) {
            $this->patchJson('/api/v1/auth/me', ['phone' => null], $this->headers($token) + ['If-Match' => $invalid])
                ->assertStatus(422)->assertJsonPath('error_code', 'INVALID_VERSION');
        }
        $updated = $this->patchJson('/api/v1/auth/me', ['phone' => null], $this->headers($token) + ['If-Match' => $etag])
            ->assertOk()->assertJsonPath('data.phone', null)->assertJsonPath('data.display_name', $user->display_name)
            ->assertJsonPath('data.version', 2)->assertJsonPath('meta.profile_contract.version', 'auth-profile-p10-v1')
            ->assertJsonPath('meta.profile_contract.ui_permission_groups.groups', null);
        $this->assertSame('"2"', $updated->headers->get('ETag'));
        $this->assertSame($read->json('data.auth_session_id'), $updated->json('data.auth_session_id'));
        $this->assertSame($read->json('data.warehouse_context'), $updated->json('data.warehouse_context'));
    }

    public function test_sessions_keep_total_and_current_family_when_current_is_off_page(): void
    {
        [$user, $token, $secret, $raw] = $this->loginFixture();
        $first = RefreshToken::query()->where('token_hash', hash('sha256', $raw))->firstOrFail();
        $first->forceFill(['created_at' => now()->subHour()])->save();
        [, , , $otherRaw] = $this->loginFixture($user, $secret);
        $other = RefreshToken::query()->where('token_hash', hash('sha256', $otherRaw))->firstOrFail();
        $page = $this->getJson('/api/v1/auth/sessions?page=1&per_page=1', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.current_session_id', $first->auth_session_id)
            ->assertJsonPath('data.0.id', $other->id)->assertJsonPath('data.0.is_current', false)
            ->assertJsonPath('data.0.can_revoke', true)->assertJsonPath('data.0.device_label', null)
            ->assertJsonPath('data.0.location', null)
            ->assertJsonPath('meta.session_contract.count_unit', 'ACTIVE_AUTH_SESSION_FAMILY');
        $this->assertSame($other->auth_session_id, $page->json('data.0.auth_session_id'));
        $this->assertNotSame($page->json('data.0.id'), $page->json('data.0.auth_session_id'));
        $this->getJson('/api/v1/auth/sessions?page=3&per_page=1', $this->headers($token))
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_session_id', $first->auth_session_id);
    }

    public function test_allowed_native_logout_has_canonical_result_and_preserves_other_family(): void
    {
        [$user, $token, $secret, $raw] = $this->loginFixture();
        [, $otherToken] = $this->loginFixture($user, $secret);
        config()->set('refresh_token.body_transport.enabled', true);
        config()->set('refresh_token.body_transport.allowed_origins', [$this->browserOrigin()]);
        $this->postJson('/api/v1/auth/logout', ['refresh_token' => $raw], $this->headers($token) + ['X-WMS-Token-Transport' => 'body'])
            ->assertOk()->assertJsonPath('data', null)->assertJsonPath('meta.auth_result.state', 'SUCCEEDED')
            ->assertJsonPath('meta.auth_result.scope', 'CURRENT_AUTH_SESSION_FAMILY')
            ->assertJsonPath('meta.auth_result.durable_request_receipt', false)
            ->assertJsonPath('meta.auth_result.request_reconcile_supported', false)
            ->assertJsonPath('meta.auth_result.business_drafts_deleted', false)
            ->assertJsonPath('meta.auth_result.work_shift_ended', false);
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->headers($otherToken))->assertOk();
    }

    public function test_unapproved_native_transport_cannot_revoke_the_session(): void
    {
        [, $token, , $raw] = $this->loginFixture();
        config()->set('refresh_token.body_transport.enabled', false);
        $this->postJson('/api/v1/auth/logout', ['refresh_token' => $raw], $this->headers($token) + ['X-WMS-Token-Transport' => 'body'])
            ->assertForbidden()->assertJsonPath('error_code', 'TOKEN_TRANSPORT_NOT_ALLOWED');
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk();
    }

    public function test_wrong_current_password_preserves_auth_and_declares_no_reconciliation(): void
    {
        [, $token] = $this->loginFixture();
        $new = 'New#Pass2026';
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => bin2hex(random_bytes(18)),
            'new_password' => $new, 'new_password_confirmation' => $new,
        ], $this->headers($token))->assertStatus(422)->assertJsonPath('error_code', 'CURRENT_PASSWORD_INCORRECT');
        $this->getJson('/api/v1/auth/me', $this->headers($token))->assertOk()
            ->assertJsonPath('meta.profile_contract.request_reconciliation.supported', false)
            ->assertJsonPath('meta.profile_contract.request_reconciliation.readback_proves_original_request', false)
            ->assertJsonPath('meta.security_contract.operations.reconciliation_supported', false)
            ->assertJsonPath('meta.security_contract.operations.automatic_mutation_retry', false)
            ->assertJsonPath('meta.security_contract.password_policy.confirmation_required', true);
    }

    private function loginFixture(?User $user = null, ?string $secret = null): array
    {
        $secret ??= bin2hex(random_bytes(18));
        $suffix = Str::lower(Str::random(12));
        $user ??= User::create(['username' => 'p10r2_'.$suffix, 'email' => 'p10r2_'.$suffix.'@example.test',
            'display_name' => 'Isolated P10 revision two', 'password_hash' => Hash::make($secret),
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
