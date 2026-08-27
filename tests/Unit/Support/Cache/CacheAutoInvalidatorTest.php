<?php

namespace Tests\Unit\Support\Cache;

use App\Support\Cache\CacheAutoInvalidator;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class CacheAutoInvalidatorTest extends TestCase
{
    public function test_clears_wms_cache_after_successful_db_seed(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('wms:cache:clear', ['--scope' => 'all']);

        CacheAutoInvalidator::handle(new CommandFinished('db:seed', new ArrayInput([]), new NullOutput(), 0));
    }

    public function test_clears_wms_cache_after_successful_migrate_fresh(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('wms:cache:clear', ['--scope' => 'all']);

        CacheAutoInvalidator::handle(new CommandFinished('migrate:fresh', new ArrayInput([]), new NullOutput(), 0));
    }

    public function test_clears_wms_cache_after_successful_migrate_refresh_and_reset(): void
    {
        Artisan::shouldReceive('call')
            ->twice()
            ->with('wms:cache:clear', ['--scope' => 'all']);

        CacheAutoInvalidator::handle(new CommandFinished('migrate:refresh', new ArrayInput([]), new NullOutput(), 0));
        CacheAutoInvalidator::handle(new CommandFinished('migrate:reset', new ArrayInput([]), new NullOutput(), 0));
    }

    public function test_does_not_clear_cache_when_command_exits_with_error(): void
    {
        Artisan::shouldReceive('call')->never();

        CacheAutoInvalidator::handle(new CommandFinished('db:seed', new ArrayInput([]), new NullOutput(), 1));
    }

    public function test_ignores_unrelated_commands(): void
    {
        Artisan::shouldReceive('call')->never();

        CacheAutoInvalidator::handle(new CommandFinished('route:list', new ArrayInput([]), new NullOutput(), 0));
    }
}
