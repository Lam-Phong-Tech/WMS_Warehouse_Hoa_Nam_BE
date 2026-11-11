<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\TrustedDeviceService;
use Tests\TestCase;
use Tests\TestCredentials;

class TrustedDeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_trusted_device_list_includes_signed_out_trust(): void
    {
        $user = $this->makeUser();
        $service = app(TrustedDeviceService::class);
        foreach (['Current browser', 'Signed-out browser'] as $label) {
            $installationId = (string) Str::uuid();
            DB::table('auth_client_installations')->insert([
                'id' => $installationId,
                'user_id' => $user->user_id,
                'client_device_id' => (string) Str::uuid(),
                'device_label' => $label,
                'platform' => 'Windows',
                'client_type' => 'web',
                'first_seen_at' => now('UTC'),
                'last_seen_at' => now('UTC'),
            ]);
            $service->trust($user, $installationId, Request::create('/'));
        }
        $token = auth('api')->login($user);
        $list = $this->getJson('/api/v1/auth/trusted-devices', ['Authorization' => "Bearer {$token}"]);
        $list->assertOk()->assertJsonCount(2, 'data');

        $target = $list->json('data.0.id');
        $this->deleteJson('/api/v1/auth/trusted-devices/'.$target, [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.revoked', true);
    }

    public function test_password_change_revokes_all_trusts(): void
    {
        $user = $this->makeUser('pwd');
        $installationId = (string) Str::uuid();
        DB::table('auth_client_installations')->insert([
            'id' => $installationId,
            'user_id' => $user->user_id,
            'client_device_id' => (string) Str::uuid(),
            'device_label' => 'Test browser',
            'platform' => 'Windows',
            'client_type' => 'web',
            'first_seen_at' => now('UTC'),
            'last_seen_at' => now('UTC'),
        ]);
        $trust = app(TrustedDeviceService::class)->trust($user, $installationId, Request::create('/'));
        $token = auth('api')->login($user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => TestCredentials::PASSWORD,
            'new_password' => 'NewPassword#2026',
            'new_password_confirmation' => 'NewPassword#2026',
        ], ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->assertNotNull($trust['model']->fresh()->revoked_at);
    }

    private function makeUser(string $suffix = 'trustmgmt'): User
    {
        return User::query()->create([
            'user_id' => (string) Str::uuid(),
            'username' => "user_{$suffix}_".Str::lower(Str::random(6)),
            'email' => "user.{$suffix}.".Str::lower(Str::random(6)).'@example.test',
            'display_name' => 'Trust Management User',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ]);
    }
}
