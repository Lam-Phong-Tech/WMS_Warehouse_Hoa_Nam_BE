<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class NotificationQueueSupervisorTest extends TestCase
{
    public static function queueConfigurations(): array
    {
        return [
            'defaults' => ['notifications', 'notification-delivery', 90, ['NOTIFICATION_QUEUE' => false, 'NOTIFICATION_DELIVERY_QUEUE' => false, 'QUEUE_WORKER_TIMEOUT' => false]],
            'overrides' => ['history-custom', 'delivery-custom', 37, ['NOTIFICATION_QUEUE' => 'history-custom', 'NOTIFICATION_DELIVERY_QUEUE' => 'delivery-custom', 'QUEUE_WORKER_TIMEOUT' => '37']],
        ];
    }

    #[DataProvider('queueConfigurations')]
    public function test_three_configured_consumers_start_and_siblings_stop_when_one_dies(string $historyQueue, string $pushQueue, int $timeout, array $environment): void
    {
        $bash = (new ExecutableFinder)->find('bash');
        if ($bash === null) {
            $this->markTestSkipped('Requires a POSIX shell (Git Bash is supported).');
        }
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir().'/hn-notification-supervisor-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $log = $dir.'/workers.log';
        file_put_contents($dir.'/php', <<<'SH'
#!/bin/sh
printf 'started:%s\n' "$*" >> "$QUEUE_TEST_LOG"
case "$*" in *--queue=default*) exit 7;; esac
trap 'printf "stopped:%s\n" "$*" >> "$QUEUE_TEST_LOG"; exit 0' TERM INT
while :; do sleep 0.1; done
SH);
        chmod($dir.'/php', 0755);
        $shellDir = str_replace('\\', '/', $dir);
        if (PHP_OS_FAMILY === 'Windows') {
            $shellDir = preg_replace_callback('/^([A-Za-z]):/', fn ($m) => '/'.strtolower($m[1]), $shellDir);
        }
        $process = new Process([$bash, '-c', 'PATH="$1:$PATH"; export PATH; exec sh "$2"', 'queue-test',
            $shellDir, str_replace('\\', '/', $root.'/docker/php/queue-supervisor.sh')], $root, [
                'APP_BASE_PATH' => str_replace('\\', '/', $root), 'QUEUE_TEST_LOG' => str_replace('\\', '/', $log),
            ] + $environment, null, 20);
        try {
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $events = file_get_contents($log);
            $this->assertStringContainsString("started:artisan queue:work --queue={$historyQueue} --tries=3 --timeout={$timeout}", $events);
            $this->assertStringContainsString("started:artisan queue:work --queue={$pushQueue} --tries=1 --timeout={$timeout}", $events);
            $this->assertStringContainsString("started:artisan queue:work --queue=default --tries=3 --timeout={$timeout}", $events);
            $this->assertStringContainsString("stopped:artisan queue:work --queue={$historyQueue}", $events);
            $this->assertStringContainsString("stopped:artisan queue:work --queue={$pushQueue}", $events);
        } finally {
            $process->stop();
            foreach ([$log, $dir.'/php'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir);
        }
    }
}
