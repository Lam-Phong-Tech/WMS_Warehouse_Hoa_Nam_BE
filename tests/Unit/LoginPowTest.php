<?php

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\Auth\Support\LoginPow;
use Modules\Auth\Support\LoginThrottle;
use Tests\TestCase;

class LoginPowTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_challenge_verifies_leading_zero_bits_once(): void
    {
        config(['login_security.pow.enabled' => true, 'login_security.pow.base_difficulty' => 4]);
        $issued = app(LoginPow::class)->challenge('a@b.test', '10.0.0.5');
        $solution = 0;
        while (! str_starts_with(bin2hex(hash('sha256', $issued['nonce'].':'.$solution, true)), '0')) {
            $solution++;
        }
        $this->assertTrue(app(LoginPow::class)->verify(
            ['challenge_id' => $issued['challenge_id'], 'solution' => $solution],
            'a@b.test',
            '10.0.0.5',
        ));
        $this->assertFalse(app(LoginPow::class)->verify(
            ['challenge_id' => $issued['challenge_id'], 'solution' => $solution],
            'a@b.test',
            '10.0.0.5',
        ));
    }

    public function test_difficulty_escalates_with_failure_count(): void
    {
        config([
            'login_security.pow.enabled' => true,
            'login_security.pow.trigger_failures' => 1,
            'login_security.pow.base_difficulty' => 16,
        ]);
        Cache::flush();
        $throttle = app(LoginThrottle::class);
        for ($i = 0; $i < 9; $i++) {
            $throttle->registerFailure('risk@example.test', '10.0.0.5');
            Carbon::setTestNow(now()->addSeconds(901));
        }
        $issued = app(LoginPow::class)->challenge('risk@example.test', '10.0.0.5');
        $this->assertSame(20, $issued['difficulty']);
    }
}
