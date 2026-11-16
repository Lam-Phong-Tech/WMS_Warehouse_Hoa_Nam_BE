<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/** Downloads public image URLs without sending the user's cookies or Referer. */
class PvRemoteImageDownloader
{
    /** Caller owns the returned temporary file and must unlink it. */
    public function download(string $url): string
    {
        if (! extension_loaded('curl')) {
            ApiError::abort(503, 'IMAGE_DOWNLOAD_UNAVAILABLE', 'Chức năng tải ảnh từ link chưa khả dụng. Vui lòng chọn ảnh từ máy.');
        }

        $maxBytes = (int) config('pv.image_max_bytes', 10 * 1024 * 1024);
        $deadline = microtime(true) + 20;
        for ($hop = 0; $hop <= 3; $hop++) {
            [$host, $port, $ip] = $this->publicTarget($url);
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $sink = tmpfile();
            if ($sink === false) {
                ApiError::abort(503, 'IMAGE_DOWNLOAD_UNAVAILABLE', 'Không tạo được tệp ảnh tạm. Vui lòng thử lại.');
            }
            $tooLarge = false;
            try {
                try {
                    $response = Http::connectTimeout(min(4, $remaining))->timeout($remaining)
                        ->withHeaders(['Accept' => 'image/jpeg,image/png,image/webp', 'Accept-Encoding' => 'identity'])
                        ->withOptions([
                            'allow_redirects' => false,
                            'proxy' => '',
                            'decode_content' => false,
                            'sink' => $sink,
                            // Pin validated DNS to prevent a second lookup reaching a private IP.
                            'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)], CURLOPT_PROXY => ''],
                            'on_headers' => function (ResponseInterface $response) use ($maxBytes, &$tooLarge): void {
                                if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                                    $tooLarge = true;
                                    throw new \RuntimeException('Image too large');
                                }
                            },
                            'progress' => function ($total, $downloaded) use ($maxBytes, &$tooLarge): void {
                                if ($total > $maxBytes || $downloaded > $maxBytes) {
                                    $tooLarge = true;
                                    throw new \RuntimeException('Image too large');
                                }
                            },
                        ])->get($url);
                } catch (\Throwable) {
                    $this->failDownload($tooLarge);
                }

                if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                    $location = $response->header('Location');
                    if (! $location || $hop === 3) {
                        break;
                    }
                    try {
                        $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
                    } catch (\InvalidArgumentException) {
                        $this->failDownload();
                    }

                    continue; // Validate and pin EVERY redirect destination before connecting.
                }
                if (! $response->successful()) {
                    $this->failDownload();
                }

                $source = $response->resource();
                rewind($source);
                $path = tempnam(sys_get_temp_dir(), 'pv-image-');
                if ($path === false) {
                    ApiError::abort(503, 'IMAGE_DOWNLOAD_UNAVAILABLE', 'Không tạo được tệp ảnh tạm. Vui lòng thử lại.');
                }
                try {
                    $target = fopen($path, 'wb');
                    if ($target === false) {
                        throw new \RuntimeException('Cannot write temporary image');
                    }
                    try {
                        $bytes = stream_copy_to_stream($source, $target, $maxBytes + 1);
                    } finally {
                        fclose($target);
                    }
                    if ($bytes === false || $bytes === 0 || $bytes > $maxBytes) {
                        $this->failDownload($bytes > $maxBytes);
                    }
                } catch (\Throwable $error) {
                    unlink($path);
                    throw $error;
                }

                return $path;
            } finally {
                if (is_resource($sink)) {
                    fclose($sink);
                }
            }
        }
        $this->failDownload();
    }

    private function publicTarget(string $url): array
    {
        $parts = parse_url($url);
        if (strlen($url) > 4096 || ! filter_var($url, FILTER_VALIDATE_URL) || ! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            $this->failUrl();
        }
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            $this->failUrl();
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolveAddresses($host);
        if ($addresses === []) {
            $this->failDownload();
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) || IpUtils::isPrivateIp($address)) {
                $this->failUrl();
            }
        }

        return [$host, $port, $addresses[0]];
    }

    protected function resolveAddresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: [])));
    }

    private function failUrl(): never
    {
        ApiError::abort(422, 'IMAGE_URL_INVALID', 'Link ảnh phải là địa chỉ http(s) công khai, không chứa thông tin đăng nhập.');
    }

    private function failDownload(bool $tooLarge = false): never
    {
        ApiError::abort(422, $tooLarge ? 'IMAGE_TOO_LARGE' : 'IMAGE_DOWNLOAD_FAILED', $tooLarge
            ? 'Ảnh vượt quá dung lượng cho phép. Vui lòng chọn ảnh nhỏ hơn.'
            : 'Không tải được ảnh từ link này. Vui lòng chọn ảnh từ máy.');
    }
}
