<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

class P05SwaggerPolicyTest extends TestCase
{
    public function test_explicit_release_cache_policy_is_not_overridden_by_testing_environment(): void
    {
        config(['l5-swagger.defaults.generate_always' => false, 'l5-swagger.defaults.generate_always_configured' => true]);
        (new AppServiceProvider($this->app))->boot();
        $this->assertFalse(config('l5-swagger.defaults.generate_always'));
    }

    public function test_development_without_an_explicit_policy_keeps_automatic_generation(): void
    {
        config(['l5-swagger.defaults.generate_always' => false, 'l5-swagger.defaults.generate_always_configured' => false]);
        (new AppServiceProvider($this->app))->boot();
        $this->assertTrue(config('l5-swagger.defaults.generate_always'));
    }
}
