<?php

namespace Tests\Unit\Swagger;

use App\Swagger\Processors\RouteSyncProcessor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RouteSyncProcessorMiniAppTagTest extends TestCase
{
    public function test_mini_app_routes_use_dedicated_swagger_tag(): void
    {
        $method = new ReflectionMethod(RouteSyncProcessor::class, 'tag');
        $method->setAccessible(true);
        $processor = new RouteSyncProcessor;

        self::assertSame(
            '22. Mini App',
            $method->invoke($processor, 'api/v1/mini-app/inbound/resolve-code')
        );
        self::assertSame(
            '22. Mini App',
            $method->invoke($processor, 'api/v1/mini-app/inbound-documents/{id}/post-receipt')
        );
    }

    public function test_canonical_inbound_routes_stay_in_stock_in_tag(): void
    {
        $method = new ReflectionMethod(RouteSyncProcessor::class, 'tag');
        $method->setAccessible(true);
        $processor = new RouteSyncProcessor;

        self::assertSame(
            '09. Stock In (Inbound)',
            $method->invoke($processor, 'api/v1/inbound-documents/{id}/post-receipt')
        );
    }
}
