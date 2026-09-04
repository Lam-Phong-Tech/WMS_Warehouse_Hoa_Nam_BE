<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Khoá hợp đồng quyền ghi runtime của container.
 *
 * Lỗi gốc: entrypoint chạy bằng `root` (composer install, key:generate, migrate,
 * storage:link, optimize) trong khi php-fpm phục vụ request bằng `www-data`
 * (pool www — docker/php/www.conf). Mọi file root sinh ra trong storage/ hay
 * bootstrap/cache là root:root mode 644; www-data mở ghi không được. Với
 * storage/logs/laravel.log thì Monolog ném UnexpectedValueException ngay trong
 * lúc ghi log, mà RBAC/cache đều có ghi log — nên POST /api/v1/auth/login trả
 * 500. Nhìn như "lỗi login" nhưng là lỗi quyền file, vì vậy clear cache hay
 * chown thủ công chỉ chữa tạm và deploy/rebuild kế tiếp lại tái phát.
 *
 * Không test nào của tầng nghiệp vụ nhìn thấy lớp này (test chạy bằng CLI, cùng
 * một user), nên chốt bằng hợp đồng file hạ tầng ở đây.
 */
class DockerRuntimePermissionsGuardTest extends TestCase
{
    private function read(string $relativePath): string
    {
        $path = base_path($relativePath);
        $this->assertTrue(File::exists($path), "Thiếu file hạ tầng {$relativePath}.");

        return File::get($path);
    }

    public function test_entrypoint_normalises_permissions_before_and_after_startup_commands(): void
    {
        $entrypoint = $this->read('docker/php/entrypoint.sh');

        $calls = preg_split('/\R/', $entrypoint);
        $callLines = [];
        foreach ($calls as $index => $line) {
            if (preg_match('/^\s*ensure_runtime_permissions\s*$/', $line) === 1) {
                $callLines[] = $index;
            }
        }

        $this->assertGreaterThanOrEqual(
            2,
            count($callLines),
            'entrypoint.sh phải gọi ensure_runtime_permissions HAI lần: trước các lệnh artisan (để có chỗ ghi) và sau (để nhận lại ownership của file do root vừa sinh).'
        );

        $execLine = null;
        foreach ($calls as $index => $line) {
            if (preg_match('/^\s*exec\s+"\$@"\s*$/', $line) === 1) {
                $execLine = $index;
            }
        }

        $this->assertNotNull($execLine, 'entrypoint.sh phải kết thúc bằng exec "$@".');
        $this->assertLessThan(
            $execLine,
            max($callLines),
            'Lần chuẩn hoá quyền CUỐI CÙNG phải nằm trước exec "$@", nếu không file do migrate/optimize sinh ra vẫn là root:root khi php-fpm bắt đầu nhận request.'
        );
    }

    public function test_permission_script_hands_ownership_to_the_php_fpm_user_and_never_uses_chmod_777(): void
    {
        $script = $this->read('docker/php/ensure-runtime-permissions.sh');

        $this->assertMatchesRegularExpression(
            '/chown -R "\$\{RUNTIME_USER\}:\$\{RUNTIME_USER\}" storage bootstrap\/cache/',
            $script,
            'Script phải chown storage và bootstrap/cache về user của php-fpm.'
        );
        $this->assertMatchesRegularExpression(
            '/RUNTIME_USER="\$\{RUNTIME_USER:-www-data\}"/',
            $script,
            'Mặc định phải là www-data — đúng user trong docker/php/www.conf.'
        );
        // Bỏ dòng chú thích trước khi kiểm: chính header của script có nhắc tới
        // "chmod 777" để giải thích vì sao KHÔNG dùng nó.
        $executableLines = implode("\n", array_filter(
            preg_split('/\R/', $script),
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
        ));
        $this->assertStringNotContainsString(
            'chmod 777',
            $executableLines,
            'Không chữa bằng chmod 777: nó giấu bug ownership và mở quyền ghi cho mọi user trong container.'
        );
        $this->assertStringContainsString(
            'storage/logs/laravel.log',
            $script,
            'Phải tạo sẵn laravel.log bằng root rồi giao cho www-data; để trống thì tiến trình root nào chạm log trước sẽ chiếm ownership.'
        );
        $this->assertMatchesRegularExpression(
            '/if \[ "\$\(id -u\)" != "0" \]/',
            $script,
            'Chạy bằng non-root (docker compose exec -u www-data) phải no-op, không được làm chết container.'
        );
    }

    public function test_image_ships_the_permission_script_as_executable(): void
    {
        $dockerfile = $this->read('docker/php/Dockerfile');

        $this->assertStringContainsString(
            'COPY docker/php/ensure-runtime-permissions.sh /usr/local/bin/ensure-runtime-permissions.sh',
            $dockerfile
        );
        $this->assertMatchesRegularExpression(
            '/RUN chmod \+x .*\/usr\/local\/bin\/ensure-runtime-permissions\.sh/',
            $dockerfile
        );
    }

    /**
     * perf-entrypoint chạy config:cache/route:cache/event:cache bằng root SAU
     * lần chuẩn hoá cuối của entrypoint gốc, nên phải tự gọi lại.
     */
    public function test_perf_entrypoint_renormalises_after_warming_the_cache(): void
    {
        $perf = $this->read('docker/php/perf-entrypoint.sh');

        $warmPosition = strpos($perf, 'php artisan event:cache');
        $fixPosition = strpos($perf, '/usr/local/bin/ensure-runtime-permissions.sh');

        $this->assertNotFalse($warmPosition);
        $this->assertNotFalse($fixPosition, 'perf-entrypoint.sh phải gọi lại ensure-runtime-permissions.sh sau khối hâm nóng cache.');
        $this->assertGreaterThan($warmPosition, $fixPosition);
    }

    /**
     * Đường tái phát còn lại là chính script deploy: `docker compose exec` mặc
     * định chạy bằng root.
     */
    public function test_deploy_workflow_runs_artisan_as_the_php_fpm_user(): void
    {
        $workflow = $this->read('.github/workflows/hn-devops-ci-cd.yml');

        preg_match_all('/docker compose exec [^\n]*backend php artisan [^\n]*/', $workflow, $matches);

        $this->assertNotEmpty($matches[0], 'Không tìm thấy lệnh artisan nào trong workflow deploy — regex đã lệch, sửa test thay vì bỏ qua.');

        foreach ($matches[0] as $command) {
            $this->assertStringContainsString(
                '-u www-data',
                $command,
                "Lệnh artisan trong deploy phải chạy bằng www-data, nếu không file cache/log vừa sinh ra là root:root: {$command}"
            );
        }
    }

    public function test_deploy_workflow_asserts_runtime_ownership_after_restart(): void
    {
        $workflow = $this->read('.github/workflows/hn-devops-ci-cd.yml');

        $this->assertStringContainsString(
            'stat -c %U storage/logs/laravel.log',
            $workflow,
            'Deploy phải tự kiểm ownership của laravel.log sau khi restart, thay vì để người dùng phát hiện bằng login 500.'
        );
    }
}
