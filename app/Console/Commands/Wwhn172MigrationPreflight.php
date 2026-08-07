<?php

namespace App\Console\Commands;

use App\Support\Wwhn172MigrationInspector;
use Illuminate\Console\Command;

class Wwhn172MigrationPreflight extends Command
{
    protected $signature = 'wwhn172:migration-preflight {--output= : Ghi báo cáo JSON vào file}';

    protected $description = 'Kiểm tra dữ liệu legacy trước migration hardening WWHN-172';

    public function handle(Wwhn172MigrationInspector $inspector): int
    {
        $report = $inspector->inspect();
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            $directory = dirname($output);
            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                $this->error("Không tạo được thư mục {$directory}.");

                return self::FAILURE;
            }
            file_put_contents($output, $json);
        }

        $this->line($json);

        return ($report['summary']['status'] ?? null) === 'PASS'
            ? self::SUCCESS
            : self::FAILURE;
    }
}
