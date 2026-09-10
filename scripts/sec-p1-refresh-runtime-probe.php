<?php

declare(strict_types=1);

/**
 * P1 bảo mật 10/09/2026 — probe HỘP ĐEN hợp đồng refresh token của trình duyệt, qua HTTP thật.
 *
 * WwhnSecRefreshTokenTransportTest chứng minh hợp đồng ở tầng kernel Laravel. Script này
 * chứng minh CÙNG hợp đồng trên một máy chủ đang chạy — middleware, cookie, CORS và nhiều
 * tiến trình PHP thật — để bắt thứ test kernel không thấy: cấu hình CORS/cookie của môi
 * trường, header do máy chủ chèn, và race giữa các worker.
 *
 * Không bootstrap Laravel, không đọc DB: chỉ nói chuyện qua HTTP. Mật khẩu, access token và
 * refresh token chỉ nằm trong bộ nhớ tiến trình — stdout và evidence JSON chỉ có tên check,
 * mã trạng thái và số đo.
 *
 * CẢNH BÁO: script THU HỒI các phiên nó tạo (logout, phát hiện replay). Chỉ chạy bằng tài
 * khoản dành cho kiểm thử.
 *
 * Biến môi trường:
 *   SEC_PROBE_BASE_URL        gốc máy chủ, mặc định http://127.0.0.1:8000
 *   SEC_PROBE_ORIGIN          origin FE nằm trong CORS_ALLOWED_ORIGINS (bắt buộc)
 *   SEC_PROBE_EMAIL           tài khoản ACTIVE, không bị bắt đổi mật khẩu/đăng ký MFA (bắt buộc)
 *   SEC_PROBE_PASSWORD        mật khẩu của tài khoản đó (bắt buộc, không bao giờ in ra)
 *   SEC_PROBE_COOKIE          tên cookie refresh, mặc định wms_refresh_token
 *   SEC_PROBE_COOKIE_PATH     path mong đợi của cookie, mặc định /api/v1/auth
 *   SEC_PROBE_RACE            số refresh song song trong phép thử replay, mặc định 8
 *   SEC_PROBE_JSON            tệp evidence JSON (tuỳ chọn)
 *
 * Chế độ đo độ trễ (--perf), không chạy các check hợp đồng:
 *   SEC_PROBE_PERF_EMAILS     danh sách email cùng mật khẩu SEC_PROBE_PASSWORD, mỗi email một phiên
 *   SEC_PROBE_PERF_ROUNDS     số lần refresh nối tiếp của mỗi phiên, mặc định 20
 *   SEC_PROBE_PERF_TRANSPORT  cookie (mặc định) | body — body chỉ để đo bản BE cũ làm mốc so sánh
 *
 * Thoát: 0 = mọi check PASS, 1 = có check FAIL, 2 = cấu hình hoặc máy chủ không sẵn sàng.
 */
const UNTRUSTED_ORIGIN = 'https://evil.invalid';

function envOrFail(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Thiếu biến môi trường {$name}.\n");
        exit(2);
    }

    return $value;
}

function envOr(string $name, string $default): string
{
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
}

final class Probe
{
    /** @var array<int, array{id: string, pass: bool, detail: string}> */
    public array $checks = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $cookieName,
    ) {}

    /**
     * @param  array{origin?: ?string, cookie?: ?string, bearer?: ?string, transport?: ?string, json?: mixed, raw?: ?string, headers?: array<int, string>}  $options
     */
    public function handle(string $method, string $path, array $options = []): array
    {
        $handle = curl_init($this->baseUrl.$path);
        $box = new ArrayObject(['headers' => []]);
        $headers = ['Accept: application/json'];

        foreach (['origin' => 'Origin', 'transport' => 'X-WMS-Token-Transport'] as $key => $header) {
            if (($options[$key] ?? null) !== null) {
                $headers[] = $header.': '.$options[$key];
            }
        }
        if (($options['cookie'] ?? null) !== null) {
            $headers[] = 'Cookie: '.$this->cookieName.'='.$options['cookie'];
        }
        if (($options['bearer'] ?? null) !== null) {
            $headers[] = 'Authorization: Bearer '.$options['bearer'];
        }
        foreach ($options['headers'] ?? [] as $header) {
            $headers[] = $header;
        }

        $body = $options['raw'] ?? null;
        if (array_key_exists('json', $options)) {
            $body = json_encode($options['json'], JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use ($box): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $all = $box['headers'];
                    $all[strtolower(trim($parts[0]))][] = trim($parts[1]);
                    $box['headers'] = $all;
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        return [$handle, $box];
    }

    /** @return array{status: int, headers: array<string, array<int, string>>, json: mixed, ms: float} */
    public function finish(CurlHandle $handle, ArrayObject $box, ?string $raw): array
    {
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $ms = 1000.0 * (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME);
        curl_close($handle);

        return [
            'status' => $status,
            'headers' => $box['headers'],
            'json' => is_string($raw) ? json_decode($raw, true) : null,
            'ms' => $ms,
        ];
    }

    public function send(string $method, string $path, array $options = []): array
    {
        [$handle, $box] = $this->handle($method, $path, $options);
        $raw = curl_exec($handle);

        if ($raw === false) {
            fwrite(STDERR, "Không gọi được {$method} {$path}: ".curl_error($handle)."\n");
            exit(2);
        }

        return $this->finish($handle, $box, $raw);
    }

    /**
     * Gửi đồng thời, trả kết quả theo đúng thứ tự yêu cầu.
     *
     * @param  array<int, array{0: string, 1: string, 2: array}>  $requests
     * @return array<int, array>
     */
    public function sendConcurrently(array $requests): array
    {
        $multi = curl_multi_init();
        $pending = [];

        foreach ($requests as $index => [$method, $path, $options]) {
            [$handle, $box] = $this->handle($method, $path, $options);
            curl_multi_add_handle($multi, $handle);
            $pending[$index] = [$handle, $box];
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($pending as $index => [$handle, $box]) {
            $raw = curl_multi_getcontent($handle);
            curl_multi_remove_handle($multi, $handle);
            $results[$index] = $this->finish($handle, $box, $raw);
        }
        curl_multi_close($multi);

        return $results;
    }

    public function check(string $id, bool $pass, string $detail = ''): void
    {
        $this->checks[] = ['id' => $id, 'pass' => $pass, 'detail' => $detail];
        fwrite(STDOUT, sprintf("%s  %s%s\n", $pass ? 'PASS' : 'FAIL', $id, $detail !== '' ? '  — '.$detail : ''));
    }

    /** @return array{value: string, attributes: array<string, string|true>}|null */
    public function refreshCookie(array $response): ?array
    {
        foreach ($response['headers']['set-cookie'] ?? [] as $line) {
            $segments = array_map('trim', explode(';', $line));
            [$name, $value] = array_pad(explode('=', (string) array_shift($segments), 2), 2, '');

            if ($name !== $this->cookieName) {
                continue;
            }

            $attributes = [];
            foreach ($segments as $segment) {
                [$key, $attributeValue] = array_pad(explode('=', $segment, 2), 2, null);
                $attributes[strtolower($key)] = $attributeValue ?? true;
            }

            return ['value' => urldecode($value), 'attributes' => $attributes];
        }

        return null;
    }

    public static function header(array $response, string $name): ?string
    {
        return $response['headers'][strtolower($name)][0] ?? null;
    }

    public static function containsKey(mixed $data, string $key): bool
    {
        if (! is_array($data)) {
            return false;
        }

        foreach ($data as $itemKey => $value) {
            if ($itemKey === $key || self::containsKey($value, $key)) {
                return true;
            }
        }

        return false;
    }

    public static function errorCode(array $response): ?string
    {
        $json = $response['json'];

        return is_array($json) ? ($json['error']['code'] ?? $json['error_code'] ?? null) : null;
    }

    public static function cookieCleared(?array $cookie): bool
    {
        if ($cookie === null) {
            return false;
        }

        $maxAge = $cookie['attributes']['max-age'] ?? null;
        $expires = $cookie['attributes']['expires'] ?? null;

        return $cookie['value'] === ''
            || $cookie['value'] === 'deleted'
            || (is_string($maxAge) && (int) $maxAge <= 0)
            || (is_string($expires) && strtotime($expires) !== false && strtotime($expires) < time());
    }
}

/** @return array<int, float> */
function percentiles(array $values): array
{
    sort($values);
    $pick = static fn (float $p): float => $values === [] ? 0.0 : $values[min(count($values) - 1, (int) floor(count($values) * $p))];

    return ['p50' => round($pick(0.50), 1), 'p95' => round($pick(0.95), 1), 'p99' => round($pick(0.99), 1), 'max' => round($values === [] ? 0.0 : max($values), 1)];
}

$baseUrl = rtrim(envOr('SEC_PROBE_BASE_URL', 'http://127.0.0.1:8000'), '/');
$password = envOrFail('SEC_PROBE_PASSWORD');
$cookieName = envOr('SEC_PROBE_COOKIE', 'wms_refresh_token');
$probe = new Probe($baseUrl, $cookieName);
$isHttps = str_starts_with($baseUrl, 'https://');

$health = $probe->send('GET', '/api/v1/health');
if ($health['status'] === 0) {
    fwrite(STDERR, "Không kết nối được {$baseUrl}.\n");
    exit(2);
}
if ($health['status'] >= 500) {
    // Health 503 = một phụ thuộc/dấu hiệu seed chưa đạt; luồng auth vẫn đo được và header vẫn phải có.
    fwrite(STDERR, "Cảnh báo: health trả HTTP {$health['status']} — tiếp tục đo hợp đồng auth.\n");
}

if (in_array('--perf', $argv, true)) {
    $emails = array_values(array_filter(array_map('trim', explode(',', envOrFail('SEC_PROBE_PERF_EMAILS')))));
    $rounds = max(1, (int) envOr('SEC_PROBE_PERF_ROUNDS', '20'));
    $transport = envOr('SEC_PROBE_PERF_TRANSPORT', 'cookie');
    $origin = $transport === 'cookie' ? envOrFail('SEC_PROBE_ORIGIN') : null;

    $tokens = [];
    foreach ($emails as $email) {
        $login = $probe->send('POST', '/api/v1/auth/login', ['origin' => $origin, 'json' => ['email' => $email, 'password' => $password]]);
        $token = $transport === 'cookie' ? ($probe->refreshCookie($login)['value'] ?? null) : ($login['json']['data']['refresh_token'] ?? null);
        if ($login['status'] !== 200 || ! is_string($token) || $token === '') {
            fwrite(STDERR, "Đăng nhập phiên đo thất bại (HTTP {$login['status']}).\n");
            exit(2);
        }
        $tokens[] = $token;
    }

    $latencies = [];
    $statuses = [];
    $started = microtime(true);
    for ($round = 0; $round < $rounds; $round++) {
        $requests = array_map(
            static fn (string $token): array => $transport === 'cookie'
                ? ['POST', '/api/v1/auth/refresh', ['origin' => $origin, 'cookie' => $token, 'json' => new stdClass]]
                : ['POST', '/api/v1/auth/refresh', ['json' => ['refresh_token' => $token]]],
            $tokens,
        );

        foreach ($probe->sendConcurrently($requests) as $index => $response) {
            $statuses[$response['status']] = ($statuses[$response['status']] ?? 0) + 1;
            $latencies[] = $response['ms'];
            $next = $transport === 'cookie' ? ($probe->refreshCookie($response)['value'] ?? null) : ($response['json']['data']['refresh_token'] ?? null);
            if ($response['status'] === 200 && is_string($next) && $next !== '') {
                $tokens[$index] = $next;
            }
        }
    }
    $elapsed = microtime(true) - $started;

    $result = [
        'mode' => 'perf',
        'transport' => $transport,
        'sessions' => count($tokens),
        'rounds' => $rounds,
        'requests' => count($latencies),
        'statuses' => $statuses,
        'latency_ms' => percentiles($latencies),
        'throughput_rps' => round(count($latencies) / max($elapsed, 0.001), 1),
    ];
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    if (($file = getenv('SEC_PROBE_JSON')) !== false && $file !== '') {
        file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
    exit(($statuses[200] ?? 0) === count($latencies) ? 0 : 1);
}

$origin = envOrFail('SEC_PROBE_ORIGIN');
$email = envOrFail('SEC_PROBE_EMAIL');
$cookiePath = envOr('SEC_PROBE_COOKIE_PATH', '/api/v1/auth');
$raceSize = max(2, (int) envOr('SEC_PROBE_RACE', '8'));
$credentials = ['email' => $email, 'password' => $password];
$login = static fn (?string $from): array => $probe->send('POST', '/api/v1/auth/login', ['origin' => $from, 'json' => $credentials]);
$refresh = static fn (?string $cookie, ?string $from): array => $probe->send('POST', '/api/v1/auth/refresh', ['origin' => $from, 'cookie' => $cookie, 'json' => new stdClass]);

// 1. Header bảo mật của chính API.
$probe->check('api_headers.nosniff', strtolower((string) Probe::header($health, 'X-Content-Type-Options')) === 'nosniff');
$probe->check('api_headers.frame_options', in_array(strtolower((string) Probe::header($health, 'X-Frame-Options')), ['deny', 'sameorigin'], true));
$probe->check('api_headers.referrer_policy', Probe::header($health, 'Referrer-Policy') !== null);
$probe->check('api_headers.no_x_powered_by', Probe::header($health, 'X-Powered-By') === null);
if ($isHttps) {
    $probe->check('api_headers.hsts', preg_match('/max-age=(\d+)/i', (string) Probe::header($health, 'Strict-Transport-Security'), $hsts) === 1 && (int) $hsts[1] >= 15552000);
}

// 2. Đăng nhập từ origin FE được phép: refresh token CHỈ ở cookie HttpOnly.
$browser = $login($origin);
$browserCookie = $probe->refreshCookie($browser);
$probe->check('login.browser.status_200', $browser['status'] === 200, "HTTP {$browser['status']}");
$probe->check('login.browser.access_token_in_json', is_string($browser['json']['data']['access_token'] ?? null));
$probe->check('login.browser.no_refresh_token_in_json', ! Probe::containsKey($browser['json'], 'refresh_token'));
$probe->check('login.browser.refresh_cookie_set', $browserCookie !== null && $browserCookie['value'] !== '');
$probe->check('login.browser.cookie_httponly', ($browserCookie['attributes']['httponly'] ?? false) === true);
$probe->check('login.browser.cookie_path', ($browserCookie['attributes']['path'] ?? null) === $cookiePath, 'path='.($browserCookie['attributes']['path'] ?? '-'));
$probe->check('login.browser.cookie_samesite', in_array(strtolower((string) ($browserCookie['attributes']['samesite'] ?? '')), ['lax', 'strict'], true), 'samesite='.($browserCookie['attributes']['samesite'] ?? '-'));
if ($isHttps) {
    $probe->check('login.browser.cookie_secure', ($browserCookie['attributes']['secure'] ?? false) === true);
}

// 3. Origin lạ: chặn trước khi chạm mật khẩu (login CSRF), không phát credential nào.
$foreign = $login(UNTRUSTED_ORIGIN);
$probe->check('login.untrusted_origin.denied', $foreign['status'] === 403 && $probe->refreshCookie($foreign) === null && ! Probe::containsKey($foreign['json'], 'access_token'), "HTTP {$foreign['status']}");

// 4. Không Origin/Referer (công cụ dòng lệnh): có access token, KHÔNG có refresh token nào.
$cli = $login(null);
$probe->check('login.no_origin.status_200', $cli['status'] === 200, "HTTP {$cli['status']}");
$probe->check('login.no_origin.no_refresh_material', $probe->refreshCookie($cli) === null && ! Probe::containsKey($cli['json'], 'refresh_token'));

$cookie = (string) ($browserCookie['value'] ?? '');

// 5. CSRF: cookie hợp lệ nhưng không có Origin tin cậy → 403, và phiên KHÔNG bị kết thúc.
$noOrigin = $refresh($cookie, null);
$probe->check('refresh.csrf.no_origin_denied', $noOrigin['status'] === 403, "HTTP {$noOrigin['status']}");
$evilOrigin = $refresh($cookie, UNTRUSTED_ORIGIN);
$probe->check('refresh.csrf.untrusted_origin_denied', $evilOrigin['status'] === 403, "HTTP {$evilOrigin['status']}");

// 6. Refresh token gửi từ JavaScript (body) bị từ chối; kênh body tắt mặc định.
$bodyToken = $probe->send('POST', '/api/v1/auth/refresh', ['origin' => $origin, 'json' => ['refresh_token' => $cookie]]);
$probe->check('refresh.body_token_from_browser_rejected', $bodyToken['status'] === 422, 'HTTP '.$bodyToken['status'].' '.Probe::errorCode($bodyToken));
$bodyTransport = $probe->send('POST', '/api/v1/auth/refresh', ['transport' => 'body', 'json' => ['refresh_token' => $cookie]]);
$probe->check('refresh.body_transport_disabled', $bodyTransport['status'] === 403, 'HTTP '.$bodyTransport['status'].' '.Probe::errorCode($bodyTransport));
$badTransport = $probe->send('POST', '/api/v1/auth/refresh', ['transport' => 'bogus', 'origin' => $origin, 'cookie' => $cookie, 'json' => new stdClass]);
$probe->check('refresh.invalid_transport_header', $badTransport['status'] === 422, 'HTTP '.$badTransport['status'].' '.Probe::errorCode($badTransport));

// 7. Xoay vòng: cookie mới, JSON không có refresh token.
$rotated = $refresh($cookie, $origin);
$rotatedCookie = $probe->refreshCookie($rotated);
$probe->check('refresh.rotation.status_200', $rotated['status'] === 200, "HTTP {$rotated['status']} (phiên còn sống sau các lần CSRF bị chặn)");
$probe->check('refresh.rotation.new_cookie', $rotatedCookie !== null && $rotatedCookie['value'] !== '' && $rotatedCookie['value'] !== $cookie);
$probe->check('refresh.rotation.no_refresh_token_in_json', ! Probe::containsKey($rotated['json'], 'refresh_token'));

// 8. Replay cookie đã xoay → 401, và cả họ token bị thu hồi (cookie mới cũng chết).
$replay = $refresh($cookie, $origin);
$probe->check('refresh.replay.old_cookie_401', $replay['status'] === 401, "HTTP {$replay['status']}");
$afterReplay = $refresh((string) ($rotatedCookie['value'] ?? ''), $origin);
$probe->check('refresh.replay.family_revoked', $afterReplay['status'] === 401, "HTTP {$afterReplay['status']}");

// 9. Race: N refresh song song cùng một cookie → tối đa MỘT thành công.
$raceLogin = $login($origin);
$raceCookie = (string) ($probe->refreshCookie($raceLogin)['value'] ?? '');
$raceResults = $probe->sendConcurrently(array_fill(0, $raceSize, ['POST', '/api/v1/auth/refresh', ['origin' => $origin, 'cookie' => $raceCookie, 'json' => new stdClass]]));
$raceStatuses = array_count_values(array_map(static fn (array $response): int => $response['status'], $raceResults));
ksort($raceStatuses);
$winners = array_values(array_filter($raceResults, static fn (array $response): bool => $response['status'] === 200));
$probe->check('refresh.race.at_most_one_success', count($winners) <= 1 && array_diff(array_keys($raceStatuses), [200, 401]) === [], 'statuses='.json_encode($raceStatuses));
if (count($winners) === 1) {
    $winnerCookie = (string) ($probe->refreshCookie($winners[0])['value'] ?? '');
    $winnerAfter = $refresh($winnerCookie, $origin);
    $probe->check('refresh.race.reuse_revokes_family', $winnerAfter['status'] === 401, "HTTP {$winnerAfter['status']}");
}

// 10. CORS: preflight từ origin FE được cho phép kèm credentials; origin lạ không được.
$preflightHeaders = ['Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: content-type'];
$allowed = $probe->send('OPTIONS', '/api/v1/auth/refresh', ['origin' => $origin, 'headers' => $preflightHeaders]);
$probe->check('cors.preflight.allowed_origin', Probe::header($allowed, 'Access-Control-Allow-Origin') === $origin && strtolower((string) Probe::header($allowed, 'Access-Control-Allow-Credentials')) === 'true');
$denied = $probe->send('OPTIONS', '/api/v1/auth/refresh', ['origin' => UNTRUSTED_ORIGIN, 'headers' => $preflightHeaders]);
$probe->check('cors.preflight.untrusted_origin', ! in_array(Probe::header($denied, 'Access-Control-Allow-Origin'), [UNTRUSTED_ORIGIN, '*'], true));

// 11. Logout: body rỗng, đọc cookie, xoá cookie, thu hồi refresh token VÀ access token.
$session = $login($origin);
$sessionCookie = (string) ($probe->refreshCookie($session)['value'] ?? '');
$sessionAccess = (string) ($session['json']['data']['access_token'] ?? '');
$logout = $probe->send('POST', '/api/v1/auth/logout', ['origin' => $origin, 'cookie' => $sessionCookie, 'bearer' => $sessionAccess, 'json' => new stdClass]);
$probe->check('logout.status_200', $logout['status'] === 200, "HTTP {$logout['status']}");
$probe->check('logout.clears_cookie', Probe::cookieCleared($probe->refreshCookie($logout)));
$probe->check('logout.refresh_token_revoked', $refresh($sessionCookie, $origin)['status'] === 401);
$probe->check('logout.access_token_revoked', $probe->send('GET', '/api/v1/auth/me', ['bearer' => $sessionAccess])['status'] === 401);

$failed = count(array_filter($probe->checks, static fn (array $check): bool => ! $check['pass']));
$evidence = [
    'probe' => 'sec-p1-refresh-runtime-probe',
    'base_url' => $baseUrl,
    'checked_at' => gmdate(DATE_ATOM),
    'result' => $failed === 0 ? 'PASS' : 'FAIL',
    'failed' => $failed,
    'checks' => $probe->checks,
];

if (($file = getenv('SEC_PROBE_JSON')) !== false && $file !== '') {
    file_put_contents($file, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

fwrite(STDOUT, sprintf("\n%s — %d check, %d FAIL\n", $failed === 0 ? 'PASS' : 'FAIL', count($probe->checks), $failed));
exit($failed === 0 ? 0 : 1);
