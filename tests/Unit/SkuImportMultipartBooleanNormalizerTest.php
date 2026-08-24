<?php

namespace Tests\Unit;

use Modules\Warehouse\Support\SkuImportMultipartBooleanNormalizer;
use PHPUnit\Framework\TestCase;

final class SkuImportMultipartBooleanNormalizerTest extends TestCase
{
    public function test_it_normalizes_browser_boolean_strings(): void
    {
        self::assertSame(
            ['update_existing' => true, 'dry_run' => false],
            SkuImportMultipartBooleanNormalizer::normalize([
                'update_existing' => 'true',
                'dry_run' => 'false',
            ])
        );
    }

    public function test_it_accepts_numeric_boolean_strings(): void
    {
        self::assertSame(
            ['update_existing' => true, 'dry_run' => false],
            SkuImportMultipartBooleanNormalizer::normalize([
                'update_existing' => '1',
                'dry_run' => '0',
            ])
        );
    }

    public function test_it_preserves_invalid_values_for_validator_to_reject(): void
    {
        self::assertSame(
            ['update_existing' => 'yes', 'dry_run' => 'invalid'],
            SkuImportMultipartBooleanNormalizer::normalize([
                'update_existing' => 'yes',
                'dry_run' => 'invalid',
            ])
        );
    }

    public function test_it_does_not_invent_missing_fields(): void
    {
        self::assertSame([], SkuImportMultipartBooleanNormalizer::normalize([]));
    }
}
