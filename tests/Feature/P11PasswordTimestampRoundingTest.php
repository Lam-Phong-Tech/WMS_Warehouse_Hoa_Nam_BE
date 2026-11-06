<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class P11PasswordTimestampRoundingTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    #[DataProvider('instants')]
    public function test_new_password_epoch_does_not_round_a_fresh_login_into_the_future(string $instant): void
    {
        $this->travelTo(Carbon::parse($instant));
        $secret = bin2hex(random_bytes(18));
        $suffix = Str::lower(Str::random(12));
        $user = User::create(['username' => 'pwd_epoch_'.$suffix, 'email' => 'pwd_epoch_'.$suffix.'@example.test',
            'display_name' => 'Isolated timestamp fixture', 'password_hash' => Hash::make($secret),
            'status' => 'ACTIVE', 'version' => 1, 'must_change_password' => false]);
        $user->markPasswordChangedNow();
        $this->assertSame(now()->utc()->getTimestamp(), $user->fresh()->passwordChangedAtEpoch(), 'Persisted second-precision security epoch must not round into the future.');
        $this->app['auth']->forgetGuards();
        $response = $this->browserLogin($user->email, $secret)->assertOk();
        $token = $response->json('data.access_token');
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public static function instants(): array
    {
        return [
            'before_rounding_boundary' => ['2026-11-06T10:25:30.100000Z'],
            'after_rounding_boundary_utc' => ['2026-11-06T10:25:30.900000Z'],
            'same_instant_with_vietnam_offset' => ['2026-11-06T17:25:30.900000+07:00'],
        ];
    }
}
