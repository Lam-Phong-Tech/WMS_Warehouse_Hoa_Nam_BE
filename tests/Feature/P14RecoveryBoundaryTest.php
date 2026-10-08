<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\ApiAuthContext;
use Modules\Auth\Support\RefreshTokenService;
use Tests\TestCase;

class P14RecoveryBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/auth/recovery-requests';

    protected function setUp(): void
    {
        parent::setUp();
        config(['p14.recovery_enabled' => true, 'p14.support_email' => 'support@p14.example.test',
            'p14.recovery_ip_per_hour' => 100, 'p14.recovery_username_per_hour' => 100]);
    }

    public function test_only_recovery_paths_are_public_not_profile_or_shift_commands(): void
    {
        foreach ([self::BASE, self::BASE.'/operations/'.Str::uuid()] as $path) {
            $this->assertTrue(ApiAuthContext::isPublicEndpoint(Request::create($path)));
        }
        foreach (['/api/v1/auth/me', '/api/v1/mini-app/work-shifts/'.Str::uuid().'/end',
            '/api/v1/mini-app/inbound/record'] as $path) {
            $this->assertFalse(ApiAuthContext::isPublicEndpoint(Request::create($path)));
        }
    }

    public function test_stale_locked_account_bearer_does_not_change_anonymous_support_semantics(): void
    {
        $user = User::create(['username' => 'p14_locked_'.Str::random(8), 'display_name' => 'P14 isolated boundary',
            'email' => Str::uuid().'@example.test', 'password_hash' => Hash::make(Str::random(32)), 'status' => 'ACTIVE', 'version' => 1]);
        $token = app(RefreshTokenService::class)->accessTokenFor($user, null);
        $user->update(['status' => 'LOCKED']);
        $this->app['auth']->forgetGuards();
        $response = $this->postJson(self::BASE, ['username' => 'unverified-name'], $this->proof() + ['Authorization' => 'Bearer '.$token])
            ->assertStatus(202)->assertJsonPath('data.state', 'RECEIVED')->assertJsonPath('data.account_access_granted', false);
        $this->assertStringNotContainsString($user->email, $response->getContent());
        $this->assertDatabaseCount('p14_recovery_requests', 1);
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertForbidden();
    }

    public function test_malformed_account_token_cannot_replace_required_recovery_read_capability(): void
    {
        $headers = $this->proof() + ['Authorization' => 'Bearer malformed'];
        $id = $this->postJson(self::BASE, ['username' => 'unverified-name'], $headers)->assertStatus(202)->json('data.operation_id');
        $this->getJson(self::BASE.'/operations/'.$id, ['Authorization' => 'Bearer malformed'])->assertNotFound();
        $this->getJson(self::BASE.'/operations/'.$id, $headers)->assertOk()->assertJsonPath('data.operation_id', $id);
    }

    public function test_wrong_proof_cannot_rewrite_or_duplicate_an_accepted_support_request(): void
    {
        $headers = $this->proof();
        $this->postJson(self::BASE, ['username' => 'original'], $headers)->assertStatus(202);
        $before = (array) DB::table('p14_recovery_requests')->first();
        $headers['X-Recovery-Proof'] = $this->proof()['X-Recovery-Proof'];
        $this->postJson(self::BASE, ['username' => 'replacement'], $headers)->assertNotFound();
        $this->assertSame($before, (array) DB::table('p14_recovery_requests')->first());
        $this->assertDatabaseCount('p14_recovery_requests', 1);
    }

    private function proof(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid(),
            'X-Recovery-Proof' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')];
    }
}
