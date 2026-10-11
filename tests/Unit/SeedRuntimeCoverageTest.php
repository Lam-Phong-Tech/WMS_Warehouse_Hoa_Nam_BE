<?php

namespace Tests\Unit;

use Modules\Auth\Support\SeedRuntimeCoverage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SeedRuntimeCoverageTest extends TestCase
{
    public function test_parse_rejects_implicit_coercion_and_overflow(): void
    {
        foreach ([false, null, true, [], '', 'abc', '-1', '1.5', '1e2', ' 0', '01', str_repeat('9', 30)] as $invalid) {
            $this->assertNull(SeedRuntimeCoverage::parse($invalid));
        }
        foreach ([0, 14, '0', '14'] as $valid) {
            $this->assertSame((int) $valid, SeedRuntimeCoverage::parse($valid));
        }
    }

    public function test_process_env_is_used_even_when_php_env_array_is_stale(): void
    {
        $key = 'HN_DAILY_COVERAGE_DAYS';
        $process = getenv($key);
        $env = $_ENV[$key] ?? null;
        try {
            $_ENV[$key] = '0';
            putenv($key.'=14');
            $this->assertSame(14, SeedRuntimeCoverage::configured());
            $this->assertSame(14, SeedRuntimeCoverage::required());
            putenv($key);
            $this->assertNull(SeedRuntimeCoverage::configured());
            try {
                SeedRuntimeCoverage::required();
                $this->fail('Missing process input must not fall back to a cached/array value.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('EXPLICIT_RUNTIME_COVERAGE_REQUIRED', $error->getMessage());
            }
        } finally {
            putenv($process === false ? $key : $key.'='.$process);
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        }
    }
}
