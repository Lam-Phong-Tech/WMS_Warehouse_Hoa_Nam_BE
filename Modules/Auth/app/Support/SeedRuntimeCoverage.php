<?php

namespace Modules\Auth\Support;

use RuntimeException;

/** Process environment is authoritative, including under config:cache. */
final class SeedRuntimeCoverage
{
    public static function parse(mixed $value): ?int
    {
        if ((! is_string($value) && ! is_int($value))
            || preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) !== 1) {
            return null;
        }
        $days = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $days === false ? null : $days;
    }

    public static function configured(): ?int
    {
        return self::parse(getenv('HN_DAILY_COVERAGE_DAYS'));
    }

    public static function required(): int
    {
        return self::configured()
            ?? throw new RuntimeException('EXPLICIT_RUNTIME_COVERAGE_REQUIRED: set the approved HN_DAILY_COVERAGE_DAYS in the CLI process environment; no implicit 0/14.');
    }
}
