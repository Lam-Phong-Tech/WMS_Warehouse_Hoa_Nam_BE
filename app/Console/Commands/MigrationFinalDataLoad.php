<?php

namespace App\Console\Commands;

use App\Support\Migration\FinalDataLoadService;
use App\Support\Migration\ImportPackage;
use Illuminate\Console\Command;
use Modules\Auth\Models\User;
use RuntimeException;
use Throwable;

/**
 * Controlled job cho lần nạp dữ liệu cuối (WWHN-299 / REQ-SRS-021).
 *
 * Không có endpoint public/admin tương ứng (DEC-MIG-API-001): cutover operator
 * chạy job này trong window đã duyệt và nộp file evidence sinh ra ở --output.
 *
 *   php artisan migration:final-load --mode=DRY_RUN  --package=... --run-code=... --executed-by=admin
 *   php artisan migration:final-load --mode=COMMIT   --package=... --run-code=... --executed-by=admin --approved-by=manager01
 *   php artisan migration:final-load --mode=ROLLBACK --run-code=... --executed-by=admin
 */
class MigrationFinalDataLoad extends Command
{
    protected $signature = 'migration:final-load
        {--mode=DRY_RUN : DRY_RUN | COMMIT | ROLLBACK}
        {--package= : Thư mục Import Package (bắt buộc cho DRY_RUN/COMMIT)}
        {--run-code= : Mã run của lần nạp cuối; DRY_RUN/COMMIT/ROLLBACK dùng chung mã này}
        {--executed-by= : username của cutover operator}
        {--approved-by= : username của Data Owner đã ký duyệt (bắt buộc cho COMMIT)}
        {--note= : Ghi chú cutover}
        {--output= : Ghi báo cáo JSON evidence vào file}';

    protected $description = 'Thực hiện/xác minh lần nạp dữ liệu cuối: dry-run, commit, reconcile và rollback có kiểm soát';

    public function handle(FinalDataLoadService $service): int
    {
        $mode = strtoupper((string) $this->option('mode'));
        $runCode = trim((string) $this->option('run-code'));

        if (! in_array($mode, ['DRY_RUN', 'COMMIT', 'ROLLBACK'], true)) {
            return $this->failWith('MIGRATION_MODE_INVALID', 'mode phải là DRY_RUN, COMMIT hoặc ROLLBACK.');
        }
        if ($runCode === '' || mb_strlen($runCode) > 60 || str_contains($runCode, '#')) {
            return $this->failWith('MIGRATION_RUN_CODE_INVALID', '--run-code bắt buộc, tối đa 60 ký tự và không chứa ký tự "#".');
        }

        $executedBy = $this->resolveUser((string) $this->option('executed-by'));
        if ($executedBy === null) {
            return $this->failWith('MIGRATION_OPERATOR_UNKNOWN', '--executed-by phải là username hợp lệ và đang ACTIVE.');
        }

        try {
            $report = match ($mode) {
                'ROLLBACK' => $service->rollback($runCode, $executedBy, $this->option('note')),
                default => $this->runPackageMode($service, $mode, $runCode, $executedBy),
            };
        } catch (RuntimeException $error) {
            return $this->failWith('MIGRATION_PACKAGE_INVALID', $error->getMessage());
        } catch (Throwable $error) {
            // Không lộ stack trace/secret ra console evidence.
            return $this->failWith('MIGRATION_EXECUTION_FAILED', 'Job dừng do lỗi hệ thống; xem log ứng dụng theo migration_run_code.');
        }

        if (is_int($report)) {
            return $report;
        }

        $this->writeReport($report);

        return in_array($report['status'], ['PASSED', 'COMMITTED', 'ROLLED_BACK'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @return array<string, mixed>|int */
    private function runPackageMode(FinalDataLoadService $service, string $mode, string $runCode, string $executedBy): array|int
    {
        $packagePath = (string) $this->option('package');
        if ($packagePath === '' || ! is_dir($packagePath)) {
            return $this->failWith('MIGRATION_PACKAGE_MISSING', '--package phải trỏ tới thư mục Import Package đã duyệt.');
        }

        $package = ImportPackage::load($packagePath);

        if ($mode === 'DRY_RUN') {
            return $service->dryRun($package, $runCode, $executedBy, $this->option('note'));
        }

        $approvedBy = $this->resolveUser((string) $this->option('approved-by'));
        if ($approvedBy === null) {
            return $this->failWith('MIGRATION_APPROVER_REQUIRED', 'COMMIT bắt buộc --approved-by là username Data Owner hợp lệ.');
        }

        return $service->commit($package, $runCode, $executedBy, $approvedBy, $this->option('note'));
    }

    private function resolveUser(string $username): ?string
    {
        if ($username === '') {
            return null;
        }

        $user = User::query()->where('username', $username)->where('status', 'ACTIVE')->first();

        return $user === null ? null : (string) $user->getKey();
    }

    private function writeReport(array $report): void
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $output = (string) $this->option('output');

        if ($output !== '') {
            $directory = dirname($output);
            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                $this->error("Không tạo được thư mục evidence: {$directory}");
            } else {
                file_put_contents($output, $json);
                $this->info("Evidence đã ghi: {$output}");
            }
        }

        $this->line($json);
    }

    private function failWith(string $errorCode, string $message): int
    {
        $this->error("{$errorCode}: {$message}");

        return self::FAILURE;
    }
}
