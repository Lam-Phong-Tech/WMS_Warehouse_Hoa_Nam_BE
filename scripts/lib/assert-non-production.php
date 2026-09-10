<?php

declare(strict_types=1);

/**
 * P0 bảo mật 10/09/2026 — chặn script dựng fixture evidence chạy trên môi trường thật.
 *
 * Các script race/concurrency (wwhn184/188/190/199) tự tạo tài khoản — có cái gắn
 * SUPER_ADMIN — với mật khẩu cố định nằm ngay trong source, để phát JWT cho worker. Trên
 * CI chúng chạy với APP_ENV=testing/local; chạy nhầm lên production là tạo ra một tài
 * khoản quản trị mà ai đọc repo cũng đăng nhập được. Require file này ngay sau khi
 * bootstrap Laravel.
 */
$wmsScriptEnvironment = (string) app()->environment();
$wmsScriptAllowedEnvironments = array_map('strval', (array) config('golive.development_environments', ['local', 'testing']));

if (! in_array($wmsScriptEnvironment, $wmsScriptAllowedEnvironments, true)) {
    fwrite(STDERR, sprintf(
        "%s từ chối chạy trên APP_ENV=%s: script tạo tài khoản fixture, chỉ dành cho %s.\n",
        basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? 'script')),
        $wmsScriptEnvironment,
        implode('/', $wmsScriptAllowedEnvironments),
    ));

    exit(2);
}
