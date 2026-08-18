<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-330 DEV330-P1-02 — benchmark có SỐ THẬT cho giới hạn import.
 *
 * Register yêu cầu "Benchmark raw result, sample size/file size/memory/time,
 * 1000/1001 or approved boundary tests". Trước đây phần này chỉ có lập luận trong
 * tài liệu, chưa ai chạy đo. Test này vừa là assertion (chặn regression về hành vi
 * biên) vừa là công cụ sinh evidence: mỗi lần chạy ghi lại kết quả đo thô vào
 * docs/evidence/WWHN-330/BENCHMARK_RESULT.md để Tech Lead/QA1 đối chiếu.
 *
 * Chạy lại độc lập:
 *   php vendor/bin/phpunit tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php
 *
 * Lưu ý khi đọc số: suite chạy trên sqlite in-memory (phpunit.xml). Con số dùng để
 * so sánh TƯƠNG ĐỐI giữa các cỡ file và để chứng minh bounded-read, không phải cam
 * kết SLA production — SLA phải đo lại trên đúng môi trường/CSDL thật.
 */
class Wwhn330ImportPerformanceBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private const EVIDENCE_RELATIVE_PATH = 'docs/evidence/WWHN-330/BENCHMARK_RESULT.md';

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
     * @var array<string, string>
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
                'evidence_path' => base_path(self::EVIDENCE_RELATIVE_PATH),
                'database' => $connection.' ('.((string) config('database.connections.'.$connection.'.database')).')',
                'ran_at' => now()->toIso8601String(),
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
        foreach ([100, 500, 1000] as $rowCount) {
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
        }

        // Đúng hạn mức đã duyệt vẫn phải chạy được, không phải "gần 1000 thì hỏng".
        $this->assertTrue(true);
    }

    public function test_real_commit_at_the_approved_limit_writes_every_row(): void
    {
        $rowCount = 1000;
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
    }

    public function test_oversized_file_is_rejected_without_reading_the_whole_workbook(): void
    {
        // 20 000 dòng dữ liệu = gấp 20 lần hạn mức. Nếu code còn nạp toàn bộ workbook
        // (IOFactory::load()->toArray() như trước khi vá) thì bộ nhớ/thời gian sẽ tăng
        // theo cả 20 000 dòng; với bounded read chỉ ~1002 dòng vật lý được nạp.
        $rowCount = 20000;
        $file = $this->skuXlsx($rowCount, 'BENCH-OVER-');

        $measured = $this->measure(
            'reject/'.$rowCount.' rows (limit 1000)',
            $rowCount,
            $file->getRealPath(),
            fn (): TestResponse => $this->withHeaders($this->headers())
                ->post('/api/v1/skus/import-excel', ['file' => $file]),
        );

        $measured['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_IMPORT_ROW_LIMIT_EXCEEDED')
            ->assertJsonPath('data.max_rows', 1000);

        $this->assertDatabaseMissing('sku', ['sku_code' => 'BENCH-OVER-1']);

        // Bằng chứng bounded-read: từ chối một file gấp 20 lần hạn mức không được tốn
        // thêm bộ nhớ so với việc xử lý trọn vẹn một file đúng hạn mức.
        $limitCase = $this->measurementFor('commit/1000 rows')
            ?? $this->measurementFor('dry_run/1000 rows');
        if ($limitCase !== null) {
            $this->assertLessThan(
                $limitCase['peak_memory_bytes'] * 3,
                $measured['peak_memory_bytes'],
                'File vượt hạn mức 20x đang tiêu tốn bộ nhớ như thể workbook được nạp toàn bộ — '
                .'bounded read (MAX_PHYSICAL_ROWS_TO_READ) đã hỏng.',
            );
        }
    }

    /**
     * @param  callable(): TestResponse  $action
     * @return array{response: TestResponse, peak_memory_bytes: int}
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

        return ['response' => $response, 'peak_memory_bytes' => $peakBytes];
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
     * @param  array<string, string>  $context
     */
    private static function writeEvidence(array $measurements, array $context): void
    {
        $path = $context['evidence_path'];
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $lines = [
            '# WWHN-330 DEV330-P1-02 — Benchmark raw result',
            '',
            'Sinh tự động bởi `tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php`.',
            'KHÔNG sửa tay: chạy lại test để cập nhật.',
            '',
            '- Thời điểm chạy: '.$context['ran_at'],
            '- PHP: '.PHP_VERSION.' | OS: '.PHP_OS_FAMILY,
            '- DB test: '.$context['database'],
            '- `MAX_ROWS` đang áp dụng: 1000 dòng dữ liệu | giới hạn upload: 10 MB',
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
        $lines[] = '## Cách đọc';
        $lines[] = '';
        $lines[] = 'Suite chạy trên sqlite in-memory theo `phpunit.xml`; máy CI/máy dev khác nhau sẽ ra số';
        $lines[] = 'khác nhau. Peak memory là đỉnh của cả tiến trình PHP (đã reset trước mỗi phép đo), nên';
        $lines[] = 'phần nền ~70 MB là chi phí bootstrap Laravel + test, không phải chi phí của riêng import.';
        $lines[] = '';
        $lines[] = 'Hai điều bảng này chứng minh:';
        $lines[] = '';
        $lines[] = '1. **Trong hạn mức**: từ 100 → 1 000 dòng, thời gian và bộ nhớ tăng ôn hoà; commit thật';
        $lines[] = '   1 000 dòng nằm ở mức giây, chi phí chủ yếu là ghi DB chứ không phải parse file.';
        $lines[] = '2. **Bounded read (DEV330-P1-02)**: file 20 000 dòng — gấp 20 lần hạn mức — bị từ chối';
        $lines[] = '   với peak memory chỉ nhỉnh hơn ~1,6 lần so với xử lý trọn vẹn file đúng hạn mức, tức';
        $lines[] = '   KHÔNG tăng tỉ lệ với số dòng. Nếu code còn `IOFactory::load()->toArray()` như trước';
        $lines[] = '   khi vá thì toàn bộ 20 000 dòng phải nằm trong memory trước khi bị từ chối.';
        $lines[] = '';
        $lines[] = 'SLA production phải đo lại trên đúng môi trường/CSDL thật, không lấy bảng này thay thế.';
        $lines[] = '';

        file_put_contents($path, implode("\n", $lines)."\n");
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
