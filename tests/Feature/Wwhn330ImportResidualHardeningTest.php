<?php

namespace Tests\Feature;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Services\ImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-330 — 2 residual finding còn treo trong EVIDENCE_PACK sau đợt review 47/100:
 *
 * residual-01: `readRows()` cắt theo dòng VẬT LÝ (1002) trước khi lọc dòng trống → file
 *              hợp lệ có dòng trống xen giữa bị cắt bớt dữ liệu ÂM THẦM.
 * residual-02: `findRecentDuplicateReport()` có race window → 2 request COMMIT trùng nhau
 *              gửi đồng thời cùng lọt qua, tạo 2 `import_reports` COMMITTED cho 1 file.
 */
class Wwhn330ImportResidualHardeningTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.write_policy' => 'fail_closed']);
        $this->seed(AuthDatabaseSeeder::class);
        $user = User::query()->where('email', 'test-super-admin@hoanam.com')->firstOrFail();
        $this->token = JWTAuth::fromUser($user);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // ── residual-01: không cắt âm thầm dòng dữ liệu nằm sau dòng vật lý thứ 1002 ────

    public function test_blank_rows_interleaved_do_not_silently_truncate_valid_data_rows(): void
    {
        // 520 dòng dữ liệu, mỗi dòng cách nhau 1 dòng trống → dòng dữ liệu cuối nằm ở
        // dòng vật lý 1040, tức SAU trần đọc vật lý cũ (1002). Trước khi vá, ~500 dòng
        // cuối bị bỏ im lặng và import vẫn trả 200.
        $expected = 520;
        $file = $this->sparseXlsx($expected, spacing: 2);

        $response = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file]);

        $response->assertOk();
        $this->assertSame($expected, $response->json('data.total_rows'));
        $this->assertSame($expected, $response->json('data.inserted_rows'));
        $this->assertSame($expected, Sku::query()->where('sku_code', 'LIKE', 'SPARSE-%')->count());
    }

    public function test_rows_beyond_physical_scan_cap_are_rejected_explicitly_not_dropped(): void
    {
        // File chỉ có vài dòng dữ liệu nhưng trải dài quá trần quét vật lý
        // (MAX_PHYSICAL_ROWS_TO_SCAN = 20040) → phải báo lỗi tường minh, không im lặng bỏ đuôi.
        $file = $this->xlsxWithFarRow(20100);

        $response = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_IMPORT_ROW_LIMIT_EXCEEDED');
        $this->assertSame(0, Sku::query()->where('sku_code', 'LIKE', 'FAR-%')->count());
    }

    // ── residual-02: import COMMIT được serialize theo (actor, sha256) ──────────────

    public function test_commit_import_is_serialized_by_actor_and_file_checksum_lock(): void
    {
        $path = $this->plainXlsxPath([['LOCK-1', 'San pham khoa']]);

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once()->andReturnTrue();

        $actorId = (string) User::query()->where('email', 'test-super-admin@hoanam.com')->value('user_id');
        $checksum = hash_file('sha256', $path);

        Cache::shouldReceive('lock')
            ->once()
            ->with('sku-import:'.$actorId.':'.$checksum, 120)
            ->andReturn($lock);

        $result = app(ImportService::class)->import($path, false, $actorId, null, 'lock.xlsx', false);

        $this->assertTrue($result['committed']);
        $this->assertSame(1, Sku::query()->where('sku_code', 'LOCK-1')->count());
    }

    public function test_dry_run_does_not_take_the_commit_lock(): void
    {
        $path = $this->plainXlsxPath([['LOCK-DRY', 'San pham dry run']]);
        $actorId = (string) User::query()->where('email', 'test-super-admin@hoanam.com')->value('user_id');

        Cache::shouldReceive('lock')->never();

        $result = app(ImportService::class)->import($path, false, $actorId, null, 'dry.xlsx', true);

        $this->assertFalse($result['committed']);
        $this->assertSame(0, Sku::query()->where('sku_code', 'LOCK-DRY')->count());
    }

    // ── helpers ────────────────────────────────────────────────────────────────────

    private function sparseXlsx(int $dataRows, int $spacing): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['code', 'name'], null, 'A1');
        for ($i = 0; $i < $dataRows; $i++) {
            $sheet->fromArray(
                ['SPARSE-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'San pham '.$i],
                null,
                'A'.(2 + ($i * $spacing)),
            );
        }

        return $this->save($spreadsheet);
    }

    private function xlsxWithFarRow(int $farRow): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['code', 'name'], null, 'A1');
        $sheet->fromArray(['FAR-1', 'Gan dau file'], null, 'A2');
        $sheet->fromArray(['FAR-2', 'Cuoi file rat xa'], null, 'A'.$farRow);

        return $this->save($spreadsheet);
    }

    /** @param  array<int, array<int, string>>  $rows */
    private function plainXlsxPath(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['code', 'name'], null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }

        $path = sys_get_temp_dir().'/wwhn330-residual-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->tempFiles[] = $path;

        return $path;
    }

    private function save(Spreadsheet $spreadsheet): UploadedFile
    {
        $path = sys_get_temp_dir().'/wwhn330-residual-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
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
