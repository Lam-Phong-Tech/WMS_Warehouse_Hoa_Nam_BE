<?php

namespace Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Modules\Warehouse\Services\PvRemoteImageDownloader;
use Tests\TestCase;

class PvRemoteImageDownloaderTest extends TestCase
{
    public function test_relative_redirect_is_resolved_and_only_public_dns_is_pinned(): void
    {
        $sentOptions = [];
        Http::fake(function ($request, $options) use (&$sentOptions) {
            $sentOptions[] = $options;

            return $request->url() === 'https://images.example.test/folder/start'
                ? Http::response('', 302, ['Location' => '../photo.png'])
                : Http::response('image-bytes');
        });
        $downloader = $this->downloader(['93.184.216.34']);
        $path = $downloader->download('https://images.example.test/folder/start');
        try {
            $this->assertSame('image-bytes', file_get_contents($path));
            Http::assertSentCount(2);
            foreach (Http::recorded() as $index => [$request]) {
                $options = $sentOptions[$index];
                $this->assertFalse($options['allow_redirects']);
                $this->assertSame(['images.example.test:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
                $this->assertFalse($request->hasHeader('Referer'));
                $this->assertFalse($request->hasHeader('Authorization'));
            }
        } finally {
            unlink($path);
        }
    }

    public function test_mixed_public_and_private_dns_is_rejected_without_a_request(): void
    {
        Http::fake();
        try {
            $this->downloader(['93.184.216.34', '10.0.0.1'])->download('https://images.example.test/photo.png');
            $this->fail('Private DNS address was accepted');
        } catch (HttpResponseException $error) {
            $this->assertSame(422, $error->getResponse()->getStatusCode());
            Http::assertNothingSent();
        }
    }

    public function test_malformed_redirect_returns_a_download_error(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://images.example.test:999999/image.png'])]);
        try {
            $this->downloader(['93.184.216.34'])->download('https://images.example.test/photo.png');
            $this->fail('Malformed redirect was accepted');
        } catch (HttpResponseException $error) {
            $this->assertSame(422, $error->getResponse()->getStatusCode());
        }
    }

    public function test_download_stops_when_headers_or_transfer_progress_exceed_limit(): void
    {
        config(['pv.image_max_bytes' => 100]);
        foreach (['headers', 'progress'] as $stage) {
            Http::fake(function ($request, $options) use ($stage) {
                if ($stage === 'headers') {
                    $options['on_headers'](new Response(200, ['Content-Length' => 101]));
                } else {
                    $options['progress'](0, 101); // No Content-Length / chunked response.
                }

                return Http::response('should not be reached');
            });
            try {
                $this->downloader(['93.184.216.34'])->download('https://images.example.test/photo.png');
                $this->fail('Oversized response was accepted');
            } catch (HttpResponseException $error) {
                $body = json_decode($error->getResponse()->getContent(), true);
                $this->assertSame('IMAGE_TOO_LARGE', $body['error']['code']);
            }
        }
    }

    private function downloader(array $addresses): PvRemoteImageDownloader
    {
        return new class($addresses) extends PvRemoteImageDownloader
        {
            public function __construct(private array $addresses) {}

            protected function resolveAddresses(string $host): array
            {
                return $this->addresses;
            }
        };
    }
}
