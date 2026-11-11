<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tests\TestCredentials;

class InstallationIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_device_header_gets_a_server_issued_installation_cookie(): void
    {
        $user = $this->makeUser();
        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD]);
        $response->assertOk();
        $response->assertCookie('wms_installation_id');
        $this->assertDatabaseCount('auth_client_installations', 1);
    }

    public function test_same_installation_cookie_is_reused_on_the_next_login(): void
    {
        $user = $this->makeUser('reuse');
        $first = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD]);
        $first->assertOk();
        $cookie = $first->getCookie('wms_installation_id');
        $firstId = DB::table('auth_client_installations')->where('user_id', $user->user_id)->value('id');

        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD])
            ->assertOk();

        $this->assertSame(1, DB::table('auth_client_installations')->where('user_id', $user->user_id)->count());
        $this->assertSame($firstId, DB::table('auth_client_installations')->where('user_id', $user->user_id)->value('id'));
    }

    private function makeUser(string $suffix = 'inst'): User
    {
        return User::query()->create([
            'user_id' => (string) Str::uuid(),
            'username' => "user_{$suffix}_".Str::lower(Str::random(6)),
            'email' => "user.{$suffix}.".Str::lower(Str::random(6)).'@example.test',
            'display_name' => 'Installation Test User',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ]);
    }
}
