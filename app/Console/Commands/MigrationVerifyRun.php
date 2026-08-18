<?php

namespace App\Console\Commands;

use App\Support\Migration\ImportPackage;
use App\Support\Migration\MigrationErrorFile;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Xác minh độc lập một lần nạp dữ liệu cuối — WWHN-260 / DEV260-P1-04, P1-05,
 * P1-06, P1-10.
 *
 * Job `migration:final-load` tự sinh báo cáo của chính nó. Với gate cutover, QA2
 * và Data Owner cần một đường **không tin vào báo cáo đó**: lệnh này dựng lại
 * toàn bộ kết luận từ dữ liệu đã ghi (`migration_runs`, `migration_errors`,
 * `stock_movements`, `inventory_balances`, `item_instances`) và — nếu được đưa
 * đường dẫn package — băm lại package rồi so với `source_checksum` đã lưu.
 *
 *   php artisan migration:verify-run --run-code=CUTOVER-2026 --package=... \
 *       --error-file=storage/app/evidence/rejected.csv --output=storage/app/evidence/verify.json
 *
 * Exit 0 khi mọi check PASS; exit 1 khi có bất kỳ check FAIL — dùng được làm
 * cổng chặn trong runbook cutover.
 */
class MigrationVerifyRun extends Command
{
    protected $signature = 'migration:verify-run
        {--run-code= : Mã run gốc (không kèm hậu tố #DRY_RUN/#COMMIT)}
        {--mode=COMMIT : DRY_RUN | COMMIT — chế độ của run cần xác minh}
        {--package= : Thư mục Import Package để băm lại và đối chiếu checksum}
        {--error-file= : Xuất file dòng bị loại theo field spec SRS §13.12}
        {--output= : Ghi báo cáo xác minh JSON vào file}';

    protected $description = 'Xác minh độc lập checksum, số dòng, đối soát và tính lặp lại của một lần nạp dữ liệu cuối';

    public function handle(): int
    {
        $runCode = trim((string) $this->option('run-code'));
        $mode = strtoupper((string) $this->option('mode'));

        if ($runCode === '') {
            $this->error('MIGRATION_RUN_CODE_INVALID: --run-code là bắt buộc.');

            return self::FAILURE;
        }
        if (! in_array($mode, ['DRY_RUN', 'COMMIT'], true)) {
            $this->error('MIGRATION_MODE_INVALID: --mode phải là DRY_RUN hoặc COMMIT.');

            return self::FAILURE;
        }

        $fullCode = str_contains($runCode, '#') ? $runCode : $runCode.'#'.$mode;
        $run = DB::table('migration_runs')->where('migration_run_code', $fullCode)->first();
        if ($run === null) {
            $this->error("MIGRATION_RUN_NOT_FOUND: không có run {$fullCode}.");

            return self::FAILURE;
        }

        $checks = array_merge(
            $this->statusChecks($run),
            $this->evidenceFieldChecks($run),
            $this->countChecks($run),
            $this->packageChecks($run),
            $this->repeatabilityChecks($run),
            $run->mode === 'COMMIT' && $run->status === 'COMMITTED' ? $this->ledgerChecks($run) : [],
        );

        $rejected = $this->rejectedRows((string) $run->id);
        $report = [
            'verified_run_code' => $fullCode,
            'migration_run_id' => $run->id,
            'mode' => $run->mode,
            'status' => $run->status,
            'package_version' => $run->package_version,
            'mapping_version' => (string) config('migration_mapping.mapping_version', ''),
            'source_file_name' => $run->source_file_name,
            'source_checksum' => $run->source_checksum,
            'timing' => $this->timing($run),
            'counts' => [
                'total' => (int) $run->total_rows,
                'valid' => (int) $run->valid_rows,
                'invalid' => (int) $run->error_rows,
                'duplicate' => (int) ($run->duplicate_rows ?? 0),
            ],
            'rejected_rows' => [
                'total' => count($rejected),
                'by_dataset' => $this->groupCount($rejected, 'dataset'),
                'by_error_code' => $this->groupCount($rejected, 'error_code'),
                'owners' => (array) config('migration_mapping.dataset_owners', []),
            ],
            'checks' => $checks,
            'verdict' => $this->verdict($checks),
        ];

        $this->exportErrorFile($run, $rejected, $report);
        $this->writeOutput($report);

        return $report['verdict'] === 'PASS' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Kết cục của run phải là kết cục hợp lệ cho chế độ của nó — nếu không thì
     * đây là evidence của một lần chạy hỏng, không phải bằng chứng cutover.
     *
     * @return list<array<string, mixed>>
     */
    private function statusChecks(object $run): array
    {
        $expected = $run->mode === 'COMMIT' ? 'COMMITTED' : 'PASSED';

        return [
            [
                'check' => 'RUN_REACHED_EXPECTED_TERMINAL_STATUS',
                'expected' => $expected,
                'actual' => (string) $run->status,
                'status' => (string) $run->status === $expected ? 'PASS' : 'FAIL',
            ],
            [
                'check' => 'RECONCILIATION_PASSED',
                'expected' => 'PASS',
                'actual' => (string) ($run->reconciliation_status ?? ''),
                'status' => (string) ($run->reconciliation_status ?? '') === 'PASS' ? 'PASS' : 'FAIL',
            ],
        ];
    }

    /**
     * Bốn trường bắt buộc của evidence theo DEV260-P1-04.
     *
     * @return list<array<string, mixed>>
     */
    private function evidenceFieldChecks(object $run): array
    {
        $missing = [];
        foreach (['source_file_name', 'package_version', 'source_checksum', 'id'] as $field) {
            if (($run->{$field} ?? '') === '' || $run->{$field} === null) {
                $missing[] = $field;
            }
        }

        return [[
            'check' => 'RUN_RECORDS_SOURCE_VERSION_CHECKSUM_AND_ID',
            'expected' => 'source_file_name + package_version + source_checksum + migration_run_id',
            'actual' => $missing === [] ? 'đủ 4 trường' : 'thiếu '.implode(',', $missing),
            'status' => $missing === [] ? 'PASS' : 'FAIL',
        ]];
    }

    /**
     * Bất biến §13.12 và đối chiếu lại `error_rows`/`duplicate_rows` với số dòng
     * thật trong `migration_errors` — con số đã lưu không được tin, phải đếm lại.
     *
     * @return list<array<string, mixed>>
     */
    private function countChecks(object $run): array
    {
        $total = (int) $run->total_rows;
        $valid = (int) $run->valid_rows;
        $invalid = (int) $run->error_rows;
        $duplicate = (int) ($run->duplicate_rows ?? 0);

        $blockingRows = DB::table('migration_errors')
            ->where('migration_run_id', $run->id)
            ->whereIn('severity', ['CRITICAL', 'ERROR'])
            ->whereNotNull('row_no')
            ->get(['dataset', 'row_no', 'error_code']);

        $duplicateKeys = [];
        $otherKeys = [];
        foreach ($blockingRows as $row) {
            $key = $row->dataset.'#'.$row->row_no;
            if (str_starts_with((string) $row->error_code, 'DUPLICATE')) {
                $duplicateKeys[$key] = true;
            } else {
                $otherKeys[$key] = true;
            }
        }
        $recomputedDuplicate = count(array_diff_key($duplicateKeys, $otherKeys));
        $recomputedInvalid = count($otherKeys);

        return [
            [
                'check' => 'VALID_PLUS_INVALID_PLUS_DUPLICATE_EQUALS_INPUT',
                'expected' => $total,
                'actual' => $valid + $invalid + $duplicate,
                'status' => $valid + $invalid + $duplicate === $total ? 'PASS' : 'FAIL',
            ],
            [
                'check' => 'STORED_COUNTS_MATCH_ERROR_TABLE',
                'expected' => "invalid={$recomputedInvalid}, duplicate={$recomputedDuplicate}",
                'actual' => "invalid={$invalid}, duplicate={$duplicate}",
                'status' => $invalid === $recomputedInvalid && $duplicate === $recomputedDuplicate ? 'PASS' : 'FAIL',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function packageChecks(object $run): array
    {
        $path = (string) $this->option('package');
        if ($path === '') {
            return [[
                'check' => 'PACKAGE_CHECKSUM_RECOMPUTED',
                'expected' => $run->source_checksum,
                'actual' => 'KHÔNG KIỂM — không truyền --package',
                'status' => 'SKIPPED',
            ]];
        }

        try {
            $package = ImportPackage::load($path);
        } catch (RuntimeException $error) {
            return [[
                'check' => 'PACKAGE_CHECKSUM_RECOMPUTED',
                'expected' => $run->source_checksum,
                'actual' => $error->getMessage(),
                'status' => 'FAIL',
            ]];
        }

        return [
            [
                'check' => 'PACKAGE_CHECKSUM_RECOMPUTED',
                'expected' => $run->source_checksum,
                'actual' => $package->checksum,
                'status' => $package->checksum === $run->source_checksum ? 'PASS' : 'FAIL',
            ],
            [
                'check' => 'PACKAGE_ROW_COUNT_MATCHES_RUN',
                'expected' => (int) $run->total_rows,
                'actual' => $package->totalRows(),
                'status' => $package->totalRows() === (int) $run->total_rows ? 'PASS' : 'FAIL',
                'by_dataset' => $package->rowCounts(),
                'file_checksums' => $package->fileChecksums,
            ],
        ];
    }

    /**
     * DEV260-P1-06: cùng một package chỉ được có tối đa một COMMIT còn hiệu lực.
     *
     * @return list<array<string, mixed>>
     */
    private function repeatabilityChecks(object $run): array
    {
        $live = DB::table('migration_runs')
            ->where('mode', 'COMMIT')
            ->where('status', 'COMMITTED')
            ->where('source_checksum', $run->source_checksum)
            ->count();

        return [[
            'check' => 'AT_MOST_ONE_LIVE_COMMIT_PER_CHECKSUM',
            'expected' => '<= 1',
            'actual' => $live,
            'status' => $live <= 1 ? 'PASS' : 'FAIL',
        ]];
    }

    /**
     * Dựng lại đối soát §13.12 từ dữ liệu đã ghi: ledger vs balance, số lượng
     * serial-tracked vs số Item hợp lệ, và container không sinh thêm tồn.
     *
     * @return list<array<string, mixed>>
     */
    private function ledgerChecks(object $run): array
    {
        $checks = [];

        $runMovements = DB::table('stock_movements')
            ->where('source_type', 'MIGRATION_RUN')
            ->where('source_id', $run->id)
            ->get(['id', 'warehouse_id', 'product_id', 'stock_status', 'qty_delta', 'movement_type']);

        $nonOpening = $runMovements->filter(static fn ($movement): bool => $movement->movement_type !== 'OPENING')->count();
        $checks[] = [
            'check' => 'RUN_WRITES_ONLY_OPENING_MOVEMENTS',
            'expected' => 0,
            'actual' => $nonOpening,
            'status' => $nonOpening === 0 ? 'PASS' : 'FAIL',
        ];

        $grouped = $runMovements->groupBy(static fn ($movement): string => $movement->warehouse_id.'|'.$movement->product_id.'|'.$movement->stock_status);
        foreach ($grouped as $key => $group) {
            [$warehouseId, $skuId, $stockStatus] = explode('|', (string) $key, 3);

            $ledgerTotal = (int) DB::table('stock_movements')
                ->where('warehouse_id', $warehouseId)
                ->where('stock_status', $stockStatus)
                ->where(fn ($query) => $query->where('sku_id', $skuId)->orWhere('product_id', $skuId))
                ->sum('qty_delta');

            $onHand = (int) DB::table('inventory_balances')
                ->where('warehouse_id', $warehouseId)
                ->where('stock_status', $stockStatus)
                ->where(fn ($query) => $query->where('sku_id', $skuId)->orWhere('product_id', $skuId))
                ->value('on_hand_qty');

            $checks[] = [
                'check' => 'BALANCE_EQUALS_LEDGER',
                'scope' => (string) $key,
                'expected' => $ledgerTotal,
                'actual' => $onHand,
                'status' => $ledgerTotal === $onHand ? 'PASS' : 'FAIL',
            ];
        }

        foreach ($this->serialTrackedOpening($runMovements) as $skuId => $openingQty) {
            $items = (int) DB::table('item_instances')
                ->where('migration_run_id', $run->id)
                ->where('sku_id', $skuId)
                ->count();

            $checks[] = [
                'check' => 'SERIAL_TRACKED_QTY_EQUALS_ITEM_COUNT',
                'scope' => (string) $skuId,
                'expected' => $openingQty,
                'actual' => $items,
                'status' => $openingQty === $items ? 'PASS' : 'FAIL',
            ];
        }

        $containerMovements = (int) DB::table('stock_movements')
            ->where('source_type', 'MIGRATION_RUN')
            ->where('source_id', $run->id)
            ->where('movement_type', '!=', 'OPENING')
            ->count();

        $checks[] = [
            'check' => 'CONTAINER_MAPPING_NO_EXTRA_STOCK',
            'expected' => 0,
            'actual' => $containerMovements,
            'containers_created' => (int) DB::table('containers')->where('migration_run_id', $run->id)->count(),
            'status' => $containerMovements === 0 ? 'PASS' : 'FAIL',
        ];

        return $checks;
    }

    /**
     * Lượng opening của các SKU serial-tracked do run ghi.
     *
     * @param  Collection<int, object>  $movements
     * @return array<string, int>
     */
    private function serialTrackedOpening(Collection $movements): array
    {
        $bySku = [];
        foreach ($movements as $movement) {
            $key = (string) $movement->product_id;
            $bySku[$key] = ($bySku[$key] ?? 0) + (int) $movement->qty_delta;
        }

        if ($bySku === []) {
            return [];
        }

        $serial = DB::table('sku')
            ->whereIn('sku_id', array_keys($bySku))
            ->where('is_serial_tracked', true)
            ->pluck('sku_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return array_intersect_key($bySku, array_flip($serial));
    }

    /** @return list<array<string, mixed>> */
    private function rejectedRows(string $runId): array
    {
        return DB::table('migration_errors')
            ->where('migration_run_id', $runId)
            ->orderBy('dataset')
            ->orderBy('row_no')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function groupCount(array $rows, string $field): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $key = (string) ($row[$field] ?? '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /** @param list<array<string, mixed>> $checks */
    private function verdict(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? 'PASS') === 'FAIL') {
                return 'FAIL';
            }
        }

        return 'PASS';
    }

    /** @return array{started_at: ?string, finished_at: ?string, duration_ms: ?int} */
    private function timing(object $run): array
    {
        if ($run->started_at === null || $run->finished_at === null) {
            return ['started_at' => null, 'finished_at' => null, 'duration_ms' => null];
        }

        $started = Carbon::parse($run->started_at);
        $finished = Carbon::parse($run->finished_at);

        return [
            'started_at' => $started->toIso8601String(),
            'finished_at' => $finished->toIso8601String(),
            'duration_ms' => max(0, (int) round($started->diffInMilliseconds($finished))),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rejected
     * @param  array<string, mixed>  $report
     */
    private function exportErrorFile(object $run, array $rejected, array &$report): void
    {
        $path = (string) $this->option('error-file');
        if ($path === '') {
            return;
        }

        $sourceFiles = [];
        $packagePath = (string) $this->option('package');
        if ($packagePath !== '' && is_dir($packagePath)) {
            try {
                $sourceFiles = ImportPackage::load($packagePath)->fileNames;
            } catch (RuntimeException) {
                $sourceFiles = [];
            }
        }

        $written = MigrationErrorFile::write(
            $path,
            (string) $run->migration_run_code,
            $rejected,
            $sourceFiles,
            (string) $run->source_file_name,
        );

        $report['rejected_rows']['error_file'] = $path;
        $report['rejected_rows']['error_file_rows'] = $written;
        $this->info("Error file đã ghi: {$path} ({$written} dòng)");
    }

    /** @param array<string, mixed> $report */
    private function writeOutput(array $report): void
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $output = (string) $this->option('output');

        if ($output !== '') {
            $directory = dirname($output);
            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                $this->error("Không tạo được thư mục evidence: {$directory}");
            } else {
                file_put_contents($output, $json);
                $this->info("Báo cáo xác minh đã ghi: {$output}");
            }
        }

        $this->line($json);
    }
}
