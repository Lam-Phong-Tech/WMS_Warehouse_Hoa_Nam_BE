<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tests\TestCredentials;

class LoginSecurityFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_layers_do_not_change_the_existing_login_contract(): void
    {
        config([
            'login_security.trusted_device.enabled' => false,
            'login_security.pow.enabled' => false,
            'login_security.backoff.enabled' => false,
        ]);
        $user = $this->makeUser();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD])
            ->assertOk()
            ->assertJsonMissingPath('data.device_verification_required');
    }

    private function makeUser(string $suffix = 'flags'): User
    {
        return User::query()->create([
            'user_id' => (string) Str::uuid(),
            'username' => "user_{$suffix}_".Str::lower(Str::random(6)),
            'email' => "user.{$suffix}.".Str::lower(Str::random(6)).'@example.test',
            'display_name' => 'Security Flags User',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ]);
    }
}
