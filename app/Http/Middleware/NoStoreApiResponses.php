<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoStoreApiResponses
{
    /**
     * Giá trị Cache-Control duy nhất của mọi response `/api/*`.
     *
     * WWHN-295/DEV295-P1-02: middleware này chạy ở lớp ngoài cùng (prepend) nên LUÔN
     * ghi đè giá trị controller tự đặt. Trước commit này HealthController set
     * 'no-store, no-cache, must-revalidate, private' và tưởng đó là contract, trong khi
     * client thật luôn nhận 'no-store, private'. Ai đọc code mà không chạy thật sẽ khai
     * sai vào OpenAPI/Postman — đúng loại lệch spec↔runtime mà DEV295-P1-02 đang đóng.
     * Đây là chỗ duy nhất được phép định nghĩa giá trị này.
     */
    public const CACHE_CONTROL = 'no-store, private';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('api/*') || $request->expectsJson()) {
            // WWHN-325: API payloads may contain auth/session context, PII or
            // operational inventory data. They must never be persisted by the
            // browser HTTP cache or an intermediary cache outside PWA policy.
            $response->headers->set('Cache-Control', self::CACHE_CONTROL);
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');

            // Authenticated JSON reads must remain partitioned by both the
            // bearer credential and the browser origin. Merge instead of
            // replacing a Vary value set by a route-specific middleware.
            $vary = ['Authorization', 'Origin'];
            if ($response->headers->has('X-Read-Fields-Contract')) {
                $vary[] = 'X-Read-Fields-Contract';
            }
            self::mergeVary($response, $vary);
        }

        return $response;
    }

    /** @param list<string> $values */
    private static function mergeVary(Response $response, array $values): void
    {
        $tokens = [];
        $current = (string) $response->headers->get('Vary', '');
        foreach ([$current, ...$values] as $value) {
            foreach (preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $key = strtolower($token);
                if (! isset($tokens[$key])) {
                    $tokens[$key] = $token;
                }
            }
        }
        $response->headers->set('Vary', implode(', ', array_values($tokens)));
    }
}
