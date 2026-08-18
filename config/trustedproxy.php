<?php

/**
 * WWHN-295 — Trusted proxy boundary cho BE runtime.
 *
 * Topology thực tế (docker-compose.yml + docker/nginx/default.conf, và outer TLS
 * terminator do DevOps/WWHN-294 sở hữu): browser -> [TLS terminator] -> nginx ->
 * php-fpm:9000. PHP-FPM KHÔNG bao giờ được expose trực tiếp ra internet.
 *
 * Nếu không khai báo trusted proxy, `Illuminate\Http\Middleware\TrustProxies` bỏ
 * qua toàn bộ X-Forwarded-* nên:
 *  - `$request->ip()` trả về IP container nginx. Đây là giá trị đang được ghi vào
 *    `refresh_tokens.ip_address` (Modules/Auth/app/Support/RefreshTokenService.php)
 *    -> bằng chứng phiên đăng nhập/thiết bị mất giá trị điều tra.
 *  - `$request->secure()` / scheme trả về http sau TLS terminator.
 *
 * Giá trị mặc định là các dải private RFC1918 + loopback: đúng bằng network nội bộ
 * của docker/K8s và KHÔNG BAO GIỜ là địa chỉ của một client public, nên header
 * X-Forwarded-* do người dùng internet tự bịa vẫn bị bỏ qua (chống IP spoofing).
 * Deploy có reverse proxy ở dải khác thì override bằng TRUSTED_PROXIES.
 *
 * Đọc bởi Illuminate\Http\Middleware\TrustProxies::setTrustedProxyIpAddresses()
 * qua config('trustedproxy.proxies'). Dùng config (không dùng env() trực tiếp ở
 * middleware) để `php artisan config:cache` không làm rỗng giá trị.
 */
return [
    // Chuỗi CIDR/IP ngăn cách bởi dấu phẩy, 'REMOTE_ADDR' để tin peer trực tiếp,
    // hoặc '*' để tin mọi hop (CHỈ dùng khi hạ tầng đã chặn truy cập trực tiếp).
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7'),
];
