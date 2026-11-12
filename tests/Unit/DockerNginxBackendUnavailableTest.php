<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Lỗi gateway ĐỌC ĐƯỢC khi backend chưa sẵn sàng (10/09/2026).
 *
 * Mỗi lần container backend khởi động lại, nginx tự sinh 502 không có header CORS.
 * FE khác origin bị trình duyệt chặn preflight, chỉ thấy "Network Error", và màn đăng
 * nhập báo "Kiểm tra kết nối mạng" dù mạng không lỗi. Hai template nginx nay trả JSON
 * + CORS cho đúng allow-list của config/cors.php. Test này khoá cấu hình đó và hành vi
 * khớp origin của regex — chạy bằng PCRE của PHP, cùng họ với PCRE2 mà nginx dùng.
 */
class DockerNginxBackendUnavailableTest extends TestCase
{
    private const TEMPLATES = [
        'docker/nginx/default.conf.template',
        'docker/nginx/perf/default.conf.template',
    ];

    public function test_nginx_generated_gateway_errors_are_json_with_cors(): void
    {
        foreach (self::TEMPLATES as $path) {
            $conf = $this->read($path);

            $this->assertStringContainsString('error_page 502 503 = @wms_backend_unavailable;', $conf, $path);
            $this->assertStringContainsString('error_page 504 = @wms_backend_timeout;', $conf, $path);
            $this->assertStringContainsString('"error_code":"BACKEND_UNAVAILABLE"', $conf, $path);
            $this->assertStringContainsString('"error_code":"GATEWAY_TIMEOUT"', $conf, $path);
            $this->assertStringContainsString('add_header Access-Control-Allow-Origin      $wms_gateway_cors_origin always;', $conf, $path);
            $this->assertStringContainsString('add_header Retry-After $wms_gateway_retry_after always;', $conf, $path);

            // 5xx do chính PHP trả (health 503 HEALTH_CHECK_DEGRADED) phải tới client nguyên vẹn.
            $this->assertStringNotContainsString('fastcgi_intercept_errors on', $conf, $path);
        }
    }

    public function test_both_templates_share_one_allow_list_fed_by_cors_allowed_origins(): void
    {
        $this->assertSame(
            $this->allowListMap('docker/nginx/default.conf.template'),
            $this->allowListMap('docker/nginx/perf/default.conf.template'),
        );

        $this->assertStringContainsString(
            '- WMS_CORS_ALLOWED_ORIGINS=${CORS_ALLOWED_ORIGINS:-}',
            $this->read('docker-compose.yml'),
        );
    }

    public function test_allow_list_rule_matches_whole_entries_only(): void
    {
        $block = $this->allowListMap('docker/nginx/default.conf.template');
        $this->assertSame(1, preg_match('/"~(?<regex>[^"]+)" \$http_origin;/', $block, $match));
        $pattern = '#'.$match['regex'].'#';

        // Cùng dạng .env thật: có khoảng trắng, có "/" cuối (config/cors.php tự cắt).
        $list = 'http://localhost:5173, http://127.0.0.1:5173/,https://h5.zdn.vn';
        $cases = [
            'http://localhost:5173' => true,
            'http://127.0.0.1:5173' => true,
            'https://h5.zdn.vn' => true,
            'http://localhost:5174' => false,
            'http://localhost:517' => false,
            'http://localhost:51730' => false,
            'localhost:5173' => false,
            'https://h5.zdn.vn.evil.example' => false,
            'http://evil.example' => false,
            '' => false,
        ];

        foreach ($cases as $origin => $allowed) {
            $subject = $origin.'|'.$list.',';
            $this->assertSame($allowed, preg_match($pattern, $subject) === 1, "Origin '{$origin}'");
        }

        // Allow-list rỗng (môi trường chỉ phục vụ cùng origin): không origin nào lọt.
        $this->assertSame(0, preg_match($pattern, 'http://localhost:5173|,'));
    }

    public function test_upload_body_limits_cover_warranty_video_contract(): void
    {
        foreach (self::TEMPLATES as $path) {
            $this->assertStringContainsString(
                'client_max_body_size ${WMS_NGINX_MAX_BODY};',
                $this->read($path),
                $path,
            );
        }

        $this->assertStringContainsString(
            '- WMS_NGINX_MAX_BODY=${WMS_NGINX_MAX_BODY:-320m}',
            $this->read('docker-compose.yml'),
        );

        $ini = $this->read('docker/php/uploads.ini');
        foreach ([
            'upload_max_filesize = 300M',
            'post_max_size = 320M',
            'memory_limit = 512M',
            'max_input_time = 300',
            'max_execution_time = 300',
        ] as $line) {
            $this->assertStringContainsString($line, $ini);
        }

        $this->assertStringContainsString(
            'COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-wms-00-uploads.ini',
            $this->read('docker/php/Dockerfile'),
        );
    }

    private function allowListMap(string $path): string
    {
        $this->assertSame(
            1,
            preg_match('/map "\$http_origin\|\$\{WMS_CORS_ALLOWED_ORIGINS\}," \$wms_gateway_cors_origin \{.*?\n\}/s', $this->read($path), $match),
            $path,
        );

        return $match[0];
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$relative);
        $this->assertIsString($contents, $relative);

        return $contents;
    }
}
