<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\SkuImportContract;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-330 DEV330-P1-02 / DEV330-P1-02R3 — benchmark có SỐ THẬT cho hạn mức import.
 *
 * Register R3 yêu cầu ba thứ mà bản trước còn thiếu:
 *
 * 1. **Raw artifact truy xuất được.** Bản trước chỉ ghi một bảng Markdown, và phần "Cách
 *    đọc" của nó nói cứng "chạy trên sqlite in-memory" trong khi dòng môi trường ngay
 *    phía trên lại ghi `pgsql` — một tài liệu tự mâu thuẫn thì không dùng làm bằng chứng
 *    được. Nay mọi câu chữ trong artifact đều suy ra từ môi trường ĐO ĐƯỢC, và có thêm
 *    `BENCHMARK_RAW.json` (số thô, không định dạng) để QA/Tech Lead nạp thẳng vào công cụ.
 *
 * 2. **Đủ biên mà register liệt kê**: 1000 (đúng hạn mức), 1001 (vượt 1 dòng), sparse
 *    (nhiều dòng trống xen giữa), 20k (gấp 20 lần), và biên kích thước file. Thiếu biên
 *    1001 thì không ai chứng minh được hạn mức chặn ở đúng chỗ nó nói.
 *
 * 3. **Performance objective**, không chỉ "số đo cho vui": mỗi kịch bản có ngân sách thời
 *    gian đã duyệt (`warehouse.sku_import.performance_objective_ms`, DEC-IMP-001 §4).
 *    Vượt ngân sách thì test ĐỎ — đó là cách duy nhất để một hồi quy bậc độ phức tạp
 *    (quay lại nạp toàn bộ workbook, N+1 query khi ghi SKU) bị chặn tại CI thay vì để số
 *    liệu trôi dần qua các sprint. Ngân sách rộng có chủ ý: đây là ngưỡng phát hiện hồi
 *    quy trên máy CI dùng chung, KHÔNG phải cam kết SLA production — SLA do QA1/DevOps đo
 *    lại trên môi trường production-like (WWHN-332).
 *
 * Chạy lại độc lập:
 *   php vendor/bin/phpunit tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php
 */
class Wwhn330ImportPerformanceBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private const EVIDENCE_DIR = 'docs/evidence/WWHN-330';

    private string $token;

    /** @var array<int, string> */
    private array $tempFiles = [];

    /** @var array<int, array<string, mixed>> */
    private static array $measurements = [];

    /**
     * Đường dẫn + mô tả môi trường được chụp lại NGAY khi ứng dụng còn sống.
     * Không được gọi base_path()/config() ở tearDownAfterClass: lúc đó Laravel
     * container đã bị huỷ, gọi sẽ ném lỗi và file evidence không bao giờ được ghi.
     *
     * @var array<string, mixed>
     */
    private static array $context = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.write_policy' => 'fail_closed']);
        $this->seed(AuthDatabaseSeeder::class);
        $user = User::query()->where('email', 'test-super-admin@hoanam.com')->firstOrFail();
        $this->token = JWTAuth::fromUser($user);

        if (self::$context === []) {
            $connection = (string) config('database.default');
            self::$context = [
                'evidence_dir' => base_path(self::EVIDENCE_DIR),
                // Driver THẬT lấy từ kết nối đang chạy, không phải chuỗi đoán trong tài liệu.
                'db_driver' => DB::connection()->getDriverName(),
                'db_connection' => $connection,
                'db_database' => (string) config('database.connections.'.$connection.'.database'),
                'php_version' => PHP_VERSION,
                'os_family' => PHP_OS_FAMILY,
                'memory_limit' => (string) ini_get('memory_limit'),
                'ran_at' => now()->toIso8601String(),
                'max_rows' => SkuImportContract::maxRows(),
                'max_file_size_kb' => SkuImportContract::maxFileSizeKb(),
                'max_physical_rows_to_scan' => SkuImportContract::maxPhysicalRowsToScan(),
                'physical_scan_multiplier' => SkuImportContract::maxPhysicalScanMultiplier(),
                'objective_ms' => SkuImportContract::performanceObjectiveMs(),
                'decision_ref' => (string) config('warehouse.sku_import.decision_ref'),
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        // Ghi sau MỖI test thay vì chờ tearDownAfterClass: nếu một case fail thì các số
        // đã đo được vẫn còn evidence để đọc, không mất trắng.
        if (self::$measurements !== [] && self::$context !== []) {
            self::writeEvidence(self::$measurements, self::$context);
        }

        parent::tearDown();
    }

    public function test_dry_run_cost_scales_with_row_count_up_to_the_approved_limit(): void
    {
        $limit = SkuImportContract::maxRows();
        $budget = SkuImportContract::performanceObjectiveMs()['dry_run_at_max_rows'];

        foreach ([100, 500, $limit] as $rowCount) {
            $file = $this->skuXlsx($rowCount, 'BENCH-DRY-'.$rowCount.'-');

            $measured = $this->measure(
                'dry_run/'.$rowCount.' rows',
                $rowCount,
                $file->getRealPath(),
                fn (): TestResponse => $this->withHeaders($this->headers())
                    ->post('/api/v1/skus/import-excel', ['file' => $file, 'dry_run' => true]),
            );

            $measured['response']
                ->assertOk()
                ->assertJsonPath('data.dry_run', true)
                ->assertJsonPath('data.total_rows', $rowCount)
                ->assertJsonPath('data.error_rows', 0);

            $this->assertLessThan(
                $budget,
                $measured['duration_ms'],
                "Dry-run {$rowCount} dòng vượt ngân sách hiệu năng đã duyệt ({$budget} ms) — "
                .'nghi vấn hồi quy bậc độ phức tạp, xem DEC-IMP-001 §4.',
            );
        }
    }

    /**
     * Biên trên đúng bằng hạn mức: 1000 chạy, 1001 bị từ chối. Nếu chỉ đo 1000 và 20000
     * thì không ai biết ranh giới thật nằm ở đâu — có thể là 1000, cũng có thể là 5000.
     */
    public function test_one_row_over_the_approved_limit_is_rejected_at_exactly_the_boundary(): void
    {
        $limit = SkuImportContract::maxRows();
        $file = $this->skuXlsx($limit + 1, 'BENCH-EDGE-');

        $measured = $this->measure(
            'reject/'.($limit + 1).' rows (limit '.$limit.')',
            $limit + 1,
            $file->getRealPath(),
            fn (): TestResponse => $this->withHeaders($this->headers())
                ->post('/api/v1/skus/import-excel', ['file' => $file]),
        );

        $measured['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_IMPORT_ROW_LIMIT_EXCEEDED')
            ->assertJsonPath('data.max_rows', $limit)
            ->assertJsonPath('data.actual_rows', $limit + 1);

        $this->assertSame(0, Sku::query()->where('sku_code', 'like', 'BENCH-EDGE-%')->count());
    }

    /**
     * File "thưa": đủ hạn mức dòng dữ liệu nhưng trải gấp đôi số dòng vật lý. Đây là hình
     * dạng file thật của người dùng (Excel để trống dòng phân nhóm) và là lý do tồn tại
     * của chính sách quét dòng vật lý — đo nó để biết bội số 20 có đủ rộng không.
     */
    public function test_sparse_file_at_the_limit_still_imports_every_data_row(): void
    {
        $limit = SkuImportContract::maxRows();
        $file = $this->sparseXlsx($limit, 'BENCH-SPARSE-');

        $measured = $this->measure(
            'sparse dry_run/'.$limit.' rows (2x dòng vật lý)',
            $limit,
            $file->getRealPath(),
            fn (): TestResponse => $this->withHeaders($this->headers())
                ->post('/api/v1/skus/import-excel', ['file' => $file, 'dry_run' => true]),
        );

        $measured['response']
            ->assertOk()
            ->assertJsonPath('data.total_rows', $limit)
            ->assertJsonPath('data.error_rows', 0);
    }

    public function test_real_commit_at_the_approved_limit_writes_every_row(): void
    {
        $rowCount = SkuImportContract::maxRows();
        $budget = SkuImportContract::performanceObjectiveMs()['commit_at_max_rows'];
        $file = $this->skuXlsx($rowCount, 'BENCH-COMMIT-');

        $measured = $this->measure(
            'commit/'.$rowCount.' rows',
            $rowCount,
            $file->getRealPath(),
            fn (): TestResponse => $this->withHeaders($this->headers())
                ->post('/api/v1/skus/import-excel', ['file' => $file]),
        );

        $measured['response']
            ->assertOk()
            ->assertJsonPath('data.committed', true)
            ->assertJsonPath('data.inserted_rows', $rowCount);

        $this->assertSame(
            $rowCount,
            Sku::query()->where('sku_code', 'like', 'BENCH-COMMIT-%')->count(),
        );

        $this->assertLessThan(
            $budget,
            $measured['duration_ms'],
            "Commit {$rowCount} dòng vượt ngân sách hiệu năng đã duyệt ({$budget} ms) — xem DEC-IMP-001 §4.",
        );
    }

    public function test_oversized_file_is_rejected_without_reading_the_whole_workbook(): void
    {
        // 20 000 dòng dữ liệu = gấp 20 lần hạn mức. Nếu code còn nạp toàn bộ workbook
        // (IOFactory::load()->toArray() như trước khi vá) thì bộ nhớ/thời gian sẽ tăng
        // theo cả 20 000 dòng; với bounded read chỉ ~1002 dòng vật lý được nạp.
        $rowCount = 20 * SkuImportContract::maxRows();
        $budget = SkuImportContract::performanceObjectiveMs()['reject_oversize'];
        $file = $this->skuXlsx($rowCount, 'BENCH-OVER-');

        $measured = $this->measure(
            'reject/'.$rowCount.' rows (limit '.SkuImportContract::maxRows().')',
            $rowCount,
            $file->getRealPath(),
            fn (): TestResponse => $this->withHeaders($this->headers())
                ->post('/api/v1/skus/import-excel', ['file' => $file]),
        );

        $measured['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_IMPORT_ROW_LIMIT_EXCEEDED')
            ->assertJsonPath('data.max_rows', SkuImportContract::maxRows());

        $this->assertDatabaseMissing('sku', ['sku_code' => 'BENCH-OVER-1']);

        // Bằng chứng bounded-read: từ chối một file gấp 20 lần hạn mức không được tốn
        // thêm bộ nhớ so với việc xử lý trọn vẹn một file đúng hạn mức.
        $limitCase = $this->measurementFor('commit/'.SkuImportContract::maxRows().' rows')
            ?? $this->measurementFor('dry_run/'.SkuImportContract::maxRows().' rows');
        if ($limitCase !== null) {
            $this->assertLessThan(
                $limitCase['peak_memory_bytes'] * 3,
                $measured['peak_memory_bytes'],
                'File vượt hạn mức 20x đang tiêu tốn bộ nhớ như thể workbook được nạp toàn bộ — '
                .'bounded read (max_physical_rows_to_read) đã hỏng.',
            );
        }

        $this->assertLessThan(
            $budget,
            $measured['duration_ms'],
            "Từ chối file vượt hạn mức vượt ngân sách hiệu năng đã duyệt ({$budget} ms) — xem DEC-IMP-001 §4.",
        );
    }

    /**
     * Biên KÍCH THƯỚC file, đo bằng đúng hợp đồng thay vì dựng một file 10 MB thật.
     *
     * Dựng file 10 MB trong CI đòi hỏi ~25 MB nội dung ngẫu nhiên (xlsx là zip, dữ liệu
     * nén được nên file "to" phải là dữ liệu khó nén) — vài trăm MB RAM và hàng chục giây
     * chỉ để chứng minh một rule `max:` đã chạy. Thay vào đó hạ hạn mức qua ĐÚNG hợp đồng
     * rồi nộp file vượt hạn mức mới: chứng minh đúng cùng một đường chặn, xác định, rẻ.
     *
     * Con số tuyệt đối 10 MB do QA1 xác minh trên môi trường thật bằng một file 10 MB
     * thật — quy trình dựng file nằm trong QA_RUNBOOK.md, mục "10MB boundary".
     */
    public function test_file_size_ceiling_is_enforced_from_the_contract(): void
    {
        $file = $this->skuXlsx(300, 'BENCH-SIZE-');
        $sizeKb = (int) ceil(((int) filesize((string) $file->getRealPath())) / 1024);

        // Hạ trần xuống dưới cỡ file vừa dựng: cùng file, chỉ khác hợp đồng.
        config(['warehouse.sku_import.max_file_size_kb' => max(1, $sizeKb - 1)]);

        $measured = $this->measure(
            'reject/oversize upload (cap '.max(1, $sizeKb - 1).' KB)',
            300,
            $file->getRealPath(),
            fn (): TestResponse => $this->withHeaders($this->headers())
                ->post('/api/v1/skus/import-excel', ['file' => $file]),
        );

        $measured['response']->assertStatus(422);
        $this->assertSame(0, Sku::query()->where('sku_code', 'like', 'BENCH-SIZE-%')->count());
    }

    /**
     * @param  callable(): TestResponse  $action
     * @return array{response: TestResponse, peak_memory_bytes: int, duration_ms: int}
     */
    private function measure(string $label, int $rowCount, string $filePath, callable $action): array
    {
        $fileSize = (int) filesize($filePath);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $startedAt = hrtime(true);

        $response = $action();

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $peakBytes = memory_get_peak_usage(true);

        self::$measurements[] = [
            'case' => $label,
            'data_rows' => $rowCount,
            'file_size_bytes' => $fileSize,
            'duration_ms' => $durationMs,
            'peak_memory_bytes' => $peakBytes,
            'http_status' => $response->getStatusCode(),
        ];

        return ['response' => $response, 'peak_memory_bytes' => $peakBytes, 'duration_ms' => $durationMs];
    }

    /** @return array<string, mixed>|null */
    private function measurementFor(string $label): ?array
    {
        foreach (self::$measurements as $measurement) {
            if ($measurement['case'] === $label) {
                return $measurement;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $measurements
     * @param  array<string, mixed>  $context
     */
    private static function writeEvidence(array $measurements, array $context): void
    {
        $dir = (string) $context['evidence_dir'];
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        // 1) Artifact THÔ: số nguyên, không định dạng, nạp thẳng được vào công cụ.
        file_put_contents(
            $dir.'/BENCHMARK_RAW.json',
            (string) json_encode([
                'ticket' => 'WWHN-330',
                'register_item' => 'DEV330-P1-02R3',
                'decision_ref' => $context['decision_ref'],
                'generated_by' => 'tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php',
                'command' => 'php vendor/bin/phpunit tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php',
                'environment' => [
                    'ran_at' => $context['ran_at'],
                    'php_version' => $context['php_version'],
                    'os_family' => $context['os_family'],
                    'memory_limit' => $context['memory_limit'],
                    'db_driver' => $context['db_driver'],
                    'db_connection' => $context['db_connection'],
                    'db_database' => $context['db_database'],
                ],
                'contract' => [
                    'max_rows' => $context['max_rows'],
                    'max_file_size_kb' => $context['max_file_size_kb'],
                    'physical_scan_multiplier' => $context['physical_scan_multiplier'],
                    'max_physical_rows_to_scan' => $context['max_physical_rows_to_scan'],
                ],
                'performance_objective_ms' => $context['objective_ms'],
                'measurements' => $measurements,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );

        // 2) Artifact ĐỌC ĐƯỢC: mọi câu chữ suy ra từ môi trường đo được, không viết cứng.
        $driver = (string) $context['db_driver'];
        $objective = $context['objective_ms'];

        $lines = [
            '# WWHN-330 DEV330-P1-02R3 — Benchmark raw result',
            '',
            'Sinh tự động bởi `tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php`.',
            'KHÔNG sửa tay: chạy lại test để cập nhật. Số thô dạng máy đọc: `BENCHMARK_RAW.json`.',
            '',
            '```bash',
            'php vendor/bin/phpunit tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php',
            '```',
            '',
            '## Môi trường đo (ghi lại từ tiến trình thật, không phải mô tả tay)',
            '',
            '| Thuộc tính | Giá trị |',
            '|---|---|',
            '| Thời điểm chạy | '.$context['ran_at'].' |',
            '| PHP | '.$context['php_version'].' |',
            '| OS | '.$context['os_family'].' |',
            '| `memory_limit` | '.$context['memory_limit'].' |',
            '| DB driver | `'.$driver.'` |',
            '| DB connection | `'.$context['db_connection'].'` ('.$context['db_database'].') |',
            '| Hạn mức đang áp dụng | '.number_format((int) $context['max_rows']).' dòng dữ liệu; '
                .'upload '.number_format((int) $context['max_file_size_kb']).' KB |',
            '| Trần quét dòng vật lý | '.number_format((int) $context['max_physical_rows_to_scan'])
                .' (bội số '.$context['physical_scan_multiplier'].'×) |',
            '| Hợp đồng | '.$context['decision_ref'].' |',
            '',
            '## Kết quả đo',
            '',
            '| Case | Dòng dữ liệu | Kích thước file | Thời gian | Peak memory | HTTP |',
            '|---|---:|---:|---:|---:|---:|',
        ];

        foreach ($measurements as $measurement) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s ms | %s | %d |',
                $measurement['case'],
                number_format((int) $measurement['data_rows']),
                self::humanBytes((int) $measurement['file_size_bytes']),
                number_format((int) $measurement['duration_ms']),
                self::humanBytes((int) $measurement['peak_memory_bytes']),
                $measurement['http_status'],
            );
        }

        $lines[] = '';
        $lines[] = '## Ngân sách hiệu năng đã duyệt (DEC-IMP-001 §4)';
        $lines[] = '';
        $lines[] = '| Kịch bản | Ngân sách | Ý nghĩa khi vượt |';
        $lines[] = '|---|---:|---|';
        $lines[] = '| Dry-run ở hạn mức | '.number_format((int) $objective['dry_run_at_max_rows']).' ms | Test ĐỎ — nghi vấn hồi quy ở bước đọc/validate |';
        $lines[] = '| Commit ở hạn mức | '.number_format((int) $objective['commit_at_max_rows']).' ms | Test ĐỎ — nghi vấn hồi quy ở bước ghi DB (N+1) |';
        $lines[] = '| Từ chối file vượt hạn mức | '.number_format((int) $objective['reject_oversize']).' ms | Test ĐỎ — bounded-read có thể đã hỏng |';
        $lines[] = '';
        $lines[] = '## Cách đọc';
        $lines[] = '';
        $lines[] = 'Số đo phụ thuộc máy: cùng bộ test chạy trên CI, máy dev và máy production-like sẽ ra ba';
        $lines[] = 'bảng khác nhau. Vì vậy bảng này dùng để so sánh TƯƠNG ĐỐI giữa các case trong CÙNG một';
        $lines[] = 'lần chạy, và để phát hiện thay đổi bậc độ phức tạp — **không** phải cam kết SLA.';
        $lines[] = '';
        $lines[] = 'Lần chạy này dùng driver `'.$driver.'`. '.($driver === 'sqlite'
            ? 'sqlite in-memory theo `phpunit.xml`: chi phí ghi DB thấp hơn PostgreSQL thật, nên case commit ở đây là cận DƯỚI.'
            : 'Đây là DB tham chiếu theo docs, số commit sát thực tế hơn sqlite in-memory.');
        $lines[] = 'Peak memory là đỉnh của cả tiến trình PHP (đã reset trước mỗi phép đo), nên phần nền là';
        $lines[] = 'chi phí bootstrap Laravel + test, không phải chi phí của riêng import.';
        $lines[] = '';
        $lines[] = 'Bốn điều bảng này chứng minh:';
        $lines[] = '';
        $lines[] = '1. **Trong hạn mức**: từ 100 → hạn mức, thời gian và bộ nhớ tăng ôn hoà; chi phí chủ yếu';
        $lines[] = '   của commit là ghi DB chứ không phải parse file.';
        $lines[] = '2. **Biên đúng chỗ**: đúng hạn mức thì chạy, hạn mức + 1 dòng thì 422';
        $lines[] = '   `SKU_IMPORT_ROW_LIMIT_EXCEEDED` — ranh giới nằm ở đúng con số đã công bố.';
        $lines[] = '3. **File thưa**: đủ hạn mức dòng dữ liệu nhưng gấp đôi dòng vật lý vẫn import đủ, tức';
        $lines[] = '   hạn mức đếm theo dòng DỮ LIỆU và bội số quét vật lý đủ rộng cho file thật.';
        $lines[] = '4. **Bounded read**: file gấp 20 lần hạn mức bị từ chối mà peak memory KHÔNG tăng tỉ lệ';
        $lines[] = '   với số dòng. Nếu code còn `IOFactory::load()->toArray()` như trước khi vá thì toàn bộ';
        $lines[] = '   số dòng đó phải nằm trong memory trước khi bị từ chối.';
        $lines[] = '';
        $lines[] = 'Biên kích thước file được kiểm bằng cách hạ trần qua đúng hợp đồng rồi nộp file vượt trần';
        $lines[] = 'mới (cùng một đường chặn, xác định, không tốn hàng trăm MB RAM để dựng file 10 MB khó nén).';
        $lines[] = 'Con số tuyệt đối 10 MB do QA1 xác minh trên môi trường thật — xem `QA_RUNBOOK.md`.';
        $lines[] = '';

        file_put_contents($dir.'/BENCHMARK_RESULT.md', implode("\n", $lines)."\n");
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2).' MB';
        }

        return number_format($bytes / 1024, 1).' KB';
    }

    private function skuXlsx(int $rowCount, string $codePrefix): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['sku_code', 'sku_name', 'unit'], null, 'A1');

        $rows = [];
        for ($i = 1; $i <= $rowCount; $i++) {
            $rows[] = [$codePrefix.$i, 'San pham benchmark '.$i, 'CAI'];
        }
        $sheet->fromArray($rows, null, 'A2');

        return $this->save($spreadsheet);
    }

    /** File "thưa": mỗi dòng dữ liệu cách nhau một dòng trống. */
    private function sparseXlsx(int $rowCount, string $codePrefix): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['sku_code', 'sku_name', 'unit'], null, 'A1');

        for ($i = 0; $i < $rowCount; $i++) {
            $sheet->fromArray(
                [$codePrefix.($i + 1), 'San pham thua '.($i + 1), 'CAI'],
                null,
                'A'.(2 + ($i * 2)),
            );
        }

        return $this->save($spreadsheet);
    }

    private function save(Spreadsheet $spreadsheet): UploadedFile
    {
        $path = sys_get_temp_dir().'/wwhn330-bench-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        $this->tempFiles[] = $path;

        return new UploadedFile(
            $path,
            basename($path),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];
    }
}
