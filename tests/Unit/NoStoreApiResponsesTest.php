<?php

namespace Tests\Unit;

use App\Http\Middleware\NoStoreApiResponses;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class NoStoreApiResponsesTest extends TestCase
{
    public function test_api_response_is_forced_to_no_store(): void
    {
        $request = Request::create('/api/v1/warehouses', 'GET');
        $middleware = new NoStoreApiResponses;

        $response = $middleware->handle(
            $request,
            static fn () => new Response('ok', 200, [
                'Cache-Control' => 'public, max-age=600',
            ]),
        );

        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('0', $response->headers->get('Expires'));
    }

    public function test_non_api_response_keeps_existing_cache_policy(): void
    {
        $request = Request::create('/docs', 'GET');
        $middleware = new NoStoreApiResponses;

        $response = $middleware->handle(
            $request,
            static fn () => new Response('ok', 200, [
                'Cache-Control' => 'public, max-age=600',
            ]),
        );

        // Symfony ResponseHeaderBag chuẩn hoá lại Cache-Control (sắp xếp directive theo
        // alphabet: 'max-age=600, public'), nên KHÔNG assert nguyên văn chuỗi — điều cần
        // bảo đảm là middleware giữ nguyên CHÍNH SÁCH cache của route không phải /api/*,
        // không ép no-store như nhánh /api/*.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=600', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl);
        $this->assertNull($response->headers->get('Pragma'));
    }
}
