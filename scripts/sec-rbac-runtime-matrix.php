<?php

declare(strict_types=1);

/**
 * Bảo mật 10/09/2026 — ma trận phân quyền chạy THẬT theo từng vai trò, qua HTTP tới máy chủ đang chạy.
 *
 * Feature test chứng minh từng luật một. Script này quét RỘNG:
 *  - lấy mỗi vai trò một tài khoản ACTIVE chỉ giữ đúng vai trò đó;
 *  - với mỗi route GET /api/v1 cần đăng nhập, không có tham số đường dẫn, phát JWT cho tài khoản
 *    (không dùng mật khẩu) và gọi route qua HTTP;
 *  - so kết quả với quyết định suy ra từ middleware `permission:` của route cộng quyền của tài
 *    khoản trong CSDL, qua đúng hàm `User::hasPermission()` mà CheckPermission dùng.
 *
 * Phân loại:
 *   FAIL  route kỳ vọng TỪ CHỐI nhưng không trả 403 (leo quyền hoặc thiếu guard)
 *   FAIL  token hợp lệ bị 401
 *   WARN  route kỳ vọng CHO PHÉP nhưng trả 403 (controller kiểm chặt hơn route — cần xem xét)
 *   WARN  5xx hoặc 429
 *
 * Chỉ chạy ở local/testing: script phát token cho tài khoản thật.
 *
 *   php scripts/sec-rbac-runtime-matrix.php --base-url=http://127.0.0.1:8000 [--json=out.json] [--concurrency=8]
 *
 * Thoát 0 = không có FAIL, 1 = có FAIL, 2 = môi trường không hợp lệ.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

require __DIR__.'/lib/assert-non-production.php';

$options = getopt('', ['base-url:', 'json:', 'concurrency:']);
$baseUrl = rtrim((string) ($options['base-url'] ?? 'http://127.0.0.1:8000'), '/');
$concurrency = max(1, (int) ($options['concurrency'] ?? 8));

// 1. Route GET cần đăng nhập, không tham số đường dẫn, không phải tải tệp/tài liệu.
$routes = [];
foreach (Route::getRoutes() as $route) {
    $uri = $route->uri();

    if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'api/v1/') || str_contains($uri, '{')) {
        continue;
    }
    if (str_starts_with($uri, 'api/v1/auth/') || preg_match('#(export|download|template|documentation)#', $uri) === 1) {
        continue;
    }

    $middleware = array_filter($route->gatherMiddleware(), 'is_string');
    $permissions = [];
    foreach ($middleware as $item) {
        if (str_starts_with($item, 'permission:')) {
            $permissions[] = array_values(array_filter(array_map('trim', explode('|', substr($item, strlen('permission:'))))));
        }
    }

    if ($permissions === [] && ! in_array('auth:api', $middleware, true)) {
        continue; // route công khai: không phụ thuộc vai trò
    }

    $routes[] = ['uri' => $uri, 'permissions' => $permissions];
}

// 2. Mỗi vai trò một tài khoản ACTIVE, không bị bắt đổi mật khẩu, chỉ giữ đúng một vai trò.
$rows = DB::table('user_role')
    ->join('role', 'role.role_id', '=', 'user_role.role_id')
    ->join('app_user', 'app_user.user_id', '=', 'user_role.user_id')
    ->where('app_user.status', 'ACTIVE')
    ->whereNull('app_user.deleted_at')
    ->where(static fn ($query) => $query->whereNull('app_user.must_change_password')->orWhere('app_user.must_change_password', false))
    ->orderBy('app_user.email')
    ->get(['role.role_code', 'app_user.user_id', 'app_user.email']);

$roleCountByUser = $rows->countBy('user_id');
$accounts = [];
foreach ($rows as $row) {
    if (($roleCountByUser[$row->user_id] ?? 0) !== 1 || isset($accounts[$row->role_code])) {
        continue;
    }
    $accounts[$row->role_code] = User::query()->findOrFail($row->user_id);
}
ksort($accounts);

if ($routes === [] || $accounts === []) {
    fwrite(STDERR, 'Không có route hoặc tài khoản vai trò nào để đo (đã seed AuthDatabaseSeeder chưa?).'.PHP_EOL);
    exit(2);
}

// 3. Quyết định kỳ vọng cho từng cặp (vai trò, route).
$queue = [];
foreach ($accounts as $roleCode => $user) {
    $token = JWTAuth::fromUser($user);

    foreach ($routes as $route) {
        $allowed = true;
        foreach ($route['permissions'] as $anyOf) {
            $granted = false;
            foreach ($anyOf as $permissionKey) {
                if ($user->hasPermission($permissionKey)) {
                    $granted = true;
                    break;
                }
            }
            if (! $granted) {
                $allowed = false;
                break;
            }
        }

        $queue[] = ['role' => $roleCode, 'uri' => $route['uri'], 'expected' => $allowed ? 'allow' : 'deny', 'token' => $token];
    }
}

// 4. Gọi HTTP song song theo cửa sổ `concurrency`.
$multi = curl_multi_init();
$active = [];
$results = [];
$next = 0;

while ($next < count($queue) || $active !== []) {
    while (count($active) < $concurrency && $next < count($queue)) {
        $job = $queue[$next++];
        $handle = curl_init($baseUrl.'/'.$job['uri']);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer '.$job['token']],
        ]);
        curl_multi_add_handle($multi, $handle);
        $active[spl_object_id($handle)] = [$handle, $job];
    }

    do {
        $status = curl_multi_exec($multi, $running);
    } while ($status === CURLM_CALL_MULTI_PERFORM);
    curl_multi_select($multi, 1.0);

    while (($info = curl_multi_info_read($multi)) !== false) {
        $handle = $info['handle'];
        [, $job] = $active[spl_object_id($handle)];
        $json = json_decode((string) curl_multi_getcontent($handle), true);
        unset($job['token']);

        $results[] = $job + [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'error_code' => is_array($json) ? ($json['error']['code'] ?? $json['error_code'] ?? null) : null,
        ];

        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
        unset($active[spl_object_id($handle)]);
    }
}
curl_multi_close($multi);

// 5. Phân loại và tổng hợp.
$summary = [];
$findings = ['FAIL' => [], 'WARN' => []];
foreach ($results as $result) {
    $verdict = match (true) {
        $result['status'] === 401 => ['FAIL', 'valid_token_rejected_401'],
        $result['expected'] === 'deny' && ! in_array($result['status'], [403, 429], true) => ['FAIL', 'deny_bypassed'],
        $result['expected'] === 'allow' && $result['status'] === 403 => ['WARN', 'stricter_than_route_guard'],
        $result['status'] >= 500 => ['WARN', 'server_error'],
        $result['status'] === 429 => ['WARN', 'rate_limited'],
        default => ['OK', $result['expected'].'_ok'],
    };

    $summary[$result['role']] ??= ['requests' => 0, 'allow_ok' => 0, 'deny_ok' => 0, 'FAIL' => 0, 'WARN' => 0];
    $summary[$result['role']]['requests']++;
    if ($verdict[0] === 'OK') {
        $summary[$result['role']][$result['expected'].'_ok']++;
    } else {
        $summary[$result['role']][$verdict[0]]++;
        $findings[$verdict[0]][] = $result + ['reason' => $verdict[1]];
    }
}

fwrite(STDOUT, sprintf("Route đo: %d · vai trò: %d · request: %d\n\n", count($routes), count($accounts), count($results)));
fwrite(STDOUT, str_pad('ROLE', 22).str_pad('req', 6).str_pad('allow', 7).str_pad('deny', 6).str_pad('FAIL', 6)."WARN\n");
foreach ($summary as $role => $row) {
    fwrite(STDOUT, str_pad($role, 22).str_pad((string) $row['requests'], 6).str_pad((string) $row['allow_ok'], 7).str_pad((string) $row['deny_ok'], 6).str_pad((string) $row['FAIL'], 6).$row['WARN']."\n");
}
foreach (['FAIL' => PHP_INT_MAX, 'WARN' => 40] as $level => $limit) {
    if ($findings[$level] === []) {
        continue;
    }
    fwrite(STDOUT, "\n{$level} (".count($findings[$level]).'):'.PHP_EOL);
    foreach (array_slice($findings[$level], 0, $limit) as $finding) {
        fwrite(STDOUT, sprintf("  %-20s %-60s expected=%-5s HTTP %d %s [%s]\n", $finding['role'], $finding['uri'], $finding['expected'], $finding['status'], (string) $finding['error_code'], $finding['reason']));
    }
}

if (isset($options['json'])) {
    file_put_contents((string) $options['json'], json_encode([
        'checked_at' => gmdate(DATE_ATOM),
        'base_url' => $baseUrl,
        'routes' => count($routes),
        'roles' => array_keys($accounts),
        'summary' => $summary,
        'findings' => $findings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
}

$failed = count($findings['FAIL']);
fwrite(STDOUT, PHP_EOL.($failed === 0 ? 'PASS' : 'FAIL')." — {$failed} FAIL, ".count($findings['WARN'])." WARN\n");
exit($failed === 0 ? 0 : 1);
