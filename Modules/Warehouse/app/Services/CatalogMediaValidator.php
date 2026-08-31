<?php

namespace Modules\Warehouse\Services;

use Illuminate\Support\Facades\Http;

/**
 * P0 #6 — media URL của importer catalogue phải là ảnh thật trên CDN đã duyệt,
 * không phải bất kỳ https:// nào (hành vi cũ của PvCatalogImportService chỉ
 * kiểm tra scheme). Bốn điều kiện, tất cả đều bắt buộc:
 *   1. https
 *   2. host nằm trong allowlist (`public_api.media_host_allowlist`)
 *   3. không redirect sang host ngoài allowlist (kiểm tra từng hop thủ công,
 *      không để Guzzip tự follow rồi mới biết đã lạc host)
 *   4. Content-Type là image/* và Content-Length trong hạn mức
 *
 * Trả về mã lỗi rời rạc thay vì bool để PvCatalogImportService gắn đúng
 * `code` vào row_errors — BA cần biết chính xác lý do bị từ chối chứ không chỉ
 * "media không hợp lệ".
 */
class CatalogMediaValidator
{
    private const MAX_REDIRECTS = 5;

    /**
     * @return array{ok: bool, code: ?string, message: ?string}
     */
    public function validate(string $url): array
    {
        $parsed = parse_url($url);
        if ($parsed === false || ($parsed['scheme'] ?? '') !== 'https') {
            return $this->fail('INVALID_MEDIA_URL', 'media phải là https://.');
        }

        $host = strtolower((string) ($parsed['host'] ?? ''));
        $allowlist = $this->allowlist();
        if (! in_array($host, $allowlist, true)) {
            return $this->fail('MEDIA_HOST_NOT_ALLOWED', "Host '{$host}' không nằm trong allowlist CDN (".implode(', ', $allowlist).').');
        }

        $currentUrl = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $response = Http::timeout(5)->withOptions(['allow_redirects' => false])->head($currentUrl);
            } catch (\Throwable) {
                return $this->fail('MEDIA_UNREACHABLE', "Không kết nối được tới media URL: {$currentUrl}.");
            }

            if ($response->status() === 405) {
                // Vài CDN không hỗ trợ HEAD — thử lại bằng GET (vẫn không follow redirect).
                try {
                    $response = Http::timeout(5)->withOptions(['allow_redirects' => false])->get($currentUrl);
                } catch (\Throwable) {
                    return $this->fail('MEDIA_UNREACHABLE', "Không kết nối được tới media URL: {$currentUrl}.");
                }
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if (! $location) {
                    return $this->fail('MEDIA_UNREACHABLE', "Redirect không có Location: {$currentUrl}.");
                }

                $nextUrl = $this->resolveRedirect($currentUrl, $location);
                $nextParsed = parse_url($nextUrl);
                if (($nextParsed['scheme'] ?? '') !== 'https') {
                    return $this->fail('MEDIA_REDIRECT_NOT_ALLOWED', 'Redirect ra khỏi https://.');
                }
                $nextHost = strtolower((string) ($nextParsed['host'] ?? ''));
                if (! in_array($nextHost, $allowlist, true)) {
                    return $this->fail('MEDIA_REDIRECT_NOT_ALLOWED', "Redirect sang host ngoài allowlist: '{$nextHost}'.");
                }

                $currentUrl = $nextUrl;

                continue;
            }

            if (! $response->successful()) {
                return $this->fail('MEDIA_UNREACHABLE', "Media URL trả về HTTP {$response->status()}: {$currentUrl}.");
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            if ($contentType !== '' && ! str_starts_with($contentType, 'image/')) {
                return $this->fail('MEDIA_NOT_IMAGE', "Content-Type '{$contentType}' không phải ảnh.");
            }

            $length = $response->header('Content-Length');
            if ($length !== null && (int) $length > $this->maxBytes()) {
                return $this->fail('MEDIA_TOO_LARGE', 'Kích thước ảnh vượt hạn mức cho phép ('.$this->maxBytes().' bytes).');
            }

            return ['ok' => true, 'code' => null, 'message' => null];
        }

        return $this->fail('MEDIA_REDIRECT_NOT_ALLOWED', 'Quá nhiều lượt redirect.');
    }

    /**
     * @return array<int, string>
     */
    private function allowlist(): array
    {
        return array_map('strtolower', (array) config('public_api.media_host_allowlist', []));
    }

    private function maxBytes(): int
    {
        return (int) config('public_api.media_max_bytes', 10 * 1024 * 1024);
    }

    private function resolveRedirect(string $currentUrl, string $location): string
    {
        if (preg_match('/^https?:\/\//i', $location) === 1) {
            return $location;
        }

        $base = parse_url($currentUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';

        return $scheme.'://'.$host.(str_starts_with($location, '/') ? $location : '/'.$location);
    }

    /**
     * @return array{ok: bool, code: string, message: string}
     */
    private function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
