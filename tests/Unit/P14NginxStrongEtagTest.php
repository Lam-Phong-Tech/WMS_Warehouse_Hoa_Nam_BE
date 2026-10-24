<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class P14NginxStrongEtagTest extends TestCase
{
    public function test_only_exact_p14_validator_paths_bypass_compression_without_rewriting_etag(): void
    {
        foreach (['default.conf.template', 'perf/default.conf.template'] as $name) {
            $source = file_get_contents(dirname(__DIR__, 2).'/docker/nginx/'.$name);
            $this->assertIsString($source);
            $this->assertSame(1, preg_match('/location ~ "(?<pattern>\^\/api\/v1\/mini-app\/work-shifts\/[^"\n]+)" \{(?<body>.*?)\n    \}/s', $source, $match));
            $body = $match['body'];
            $this->assertStringContainsString('gzip off;', $body);
            $this->assertStringContainsString('fastcgi_param HTTP_ACCEPT_ENCODING "";', $body);
            $this->assertStringContainsString('add_header Cache-Control "private, no-store, no-transform" always;', $body);
            $this->assertStringContainsString('fastcgi_hide_header Cache-Control;', $body);
            $this->assertStringNotContainsString('hide_header ETag', $body);
            $this->assertStringNotContainsString('add_header ETag', $body);
            $this->assertStringNotContainsString('non_idempotent', $body);
            $this->assertStringContainsString('fastcgi_param SCRIPT_NAME /index.php;', $body);
            $this->assertStringContainsString('fastcgi_param HTTP_X_FORWARDED_FOR   $proxy_add_x_forwarded_for;', $body);
            $this->assertStringContainsString('error_page 502 503 = @wms_backend_unavailable;', $body);
            $this->assertStringContainsString('error_page 504 = @wms_backend_timeout;', $body);
            $uuid = 'aabbccdd-1234-4234-8234-aabbccddeeff';
            foreach ([$uuid, strtoupper($uuid)] as $id) {
                foreach (['close-check', 'end'] as $action) {
                    $this->assertSame(1, preg_match('#'.$match['pattern'].'#D', '/api/v1/mini-app/work-shifts/'.$id.'/'.$action));
                }
            }
            foreach (['/api/v1/auth/me', '/api/v1/mini-app/work-shifts/not-a-uuid/close-check',
                '/api/v1/mini-app/work-shifts/'.$uuid.'/summary',
                '/api/v1/mini-app/work-shifts/'.$uuid.'/close-check/extra'] as $path) {
                $this->assertSame(0, preg_match('#'.$match['pattern'].'#D', $path), $path);
            }
            if (str_starts_with($name, 'perf/')) {
                $this->assertStringContainsString('fastcgi_pass wms_php;', $body);
                $this->assertStringContainsString('fastcgi_param SCRIPT_FILENAME ${WMS_PHP_DOCROOT}/index.php;', $body);
                $this->assertStringContainsString('fastcgi_keep_conn on;', $body);
                $this->assertStringContainsString('fastcgi_read_timeout ${WMS_NGINX_FASTCGI_TIMEOUT}s;', $body);
                $this->assertStringContainsString('fastcgi_next_upstream_tries 2;', $body);
            } else {
                $this->assertStringContainsString('fastcgi_pass $php_upstream;', $body);
                $this->assertStringContainsString('fastcgi_param SCRIPT_FILENAME $document_root/index.php;', $body);
                $this->assertStringContainsString('fastcgi_buffers 16 16k;', $body);
            }
        }
    }
}
