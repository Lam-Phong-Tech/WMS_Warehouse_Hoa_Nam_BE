<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Exceptions\ImportReportPersistenceException;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Services\ImportReportWriter;
use Modules\Warehouse\Services\ImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-224 re-review 18/08 (72/100) — đóng phần CODE của register:
 *
 * - **DEV224-P1-05R**: `persistReport()` cũ nuốt lỗi ghi Import báo cáo rồi vẫn trả 200
 *   với `import_report_id=null`. Yêu cầu: fail-closed / nguyên tử, bơm lỗi phải chứng
 *   minh 503 + không ghi dở.
 * - **DEV224-P1-04R**: annotation OpenAPI của ImportController ghi `product.manage`
 *   trong khi route thực thi `sku.manage` — spec sinh ra và nguồn sinh ra nó nói khác
 *   nhau, nên lần regenerate kế tiếp sẽ âm thầm nhả lại chuỗi sai.
 * - **DEV224-P1-01R**: phạm vi SKU-only và bảng mapping phải được KHOÁ ở một register
 *   máy đọc được, không nằm rải rác trong mô tả task.
 *
 * Các test ở đây cố tình kiểm tra bất biến chứ không kiểm tra lối đi hạnh phúc — lối đi
 * hạnh phúc đã có `Wwhn224SkuImportDryRunTest` và `Wwhn330*` phủ.
 */
class Wwhn224P1ClosureTest extends TestCase
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
        $user = User::query()->where('email', 'test-super-admin@gmail.com')->firstOrFail();
        $this->token = JWTAuth::fromUser($user);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // DEV224-P1-05R — Import báo cáo là output bắt buộc, ghi trong cùng transaction
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Kịch bản nguy hiểm nhất: SKU đã được ghi trong transaction, ĐÚNG LÚC ĐÓ việc ghi
     * Import báo cáo hỏng.
     *
     * Bản mock dưới đây tự khẳng định vị trí của nó trong dòng thời gian: khi `write()`
     * được gọi, SKU đã nhìn thấy được (nghĩa là lời gọi nằm SAU phần ghi SKU và vẫn ở
     * TRONG transaction). Sau khi request kết thúc, SKU đó phải biến mất — đó chính là
     * bằng chứng rollback mà register đòi, chứ không phải một lời hứa trong tài liệu.
     */
    public function test_report_write_failure_after_sku_write_returns_503_and_rolls_back_everything(): void
    {
        $writer = Mockery::mock(ImportReportWriter::class);
        $writer->shouldReceive('write')
            ->once()
            ->andReturnUsing(function (): never {
                $this->assertSame(
                    1,
                    Sku::query()->where('sku_code', 'ATOMIC-224-001')->count(),
                    'Import báo cáo phải được ghi SAU phần ghi SKU và trong cùng transaction; '
                    .'nếu SKU chưa nhìn thấy được ở đây thì thứ tự đã sai và bằng chứng rollback vô nghĩa.'
                );

                throw new ImportReportPersistenceException('injected: import report store unavailable');
            });
        $this->app->instance(ImportReportWriter::class, $writer);

        $file = $this->xlsx(['code', 'name', 'status'], [['ATOMIC-224-001', 'SKU nguyen tu', 'ACTIVE']]);

        $response = $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file]);

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'SKU_IMPORT_REPORT_PERSISTENCE_FAILED')
            ->assertJsonPath('data.committed', false)
            ->assertJsonPath('data.partial_write', false)
            ->assertJsonPath('data.import_report_id', null)
            ->assertJsonPath('data.retryable', true);

        $this->assertNotNull($response->headers->get('Retry-After'), 'Lỗi khả dụng phải nói được khi nào thử lại.');

        // Không còn dấu vết nào của lần chạy: SKU, audit nghiệp vụ, và cả báo cáo.
        $this->assertSame(0, Sku::query()->where('sku_code', 'ATOMIC-224-001')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'SKU_IMPORT_CREATE')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'SKU_IMPORT_COMMIT')->count());
        $this->assertSame(0, ImportReport::query()->count());
    }

    /** Dry-run cũng phải rollback dấu vết audit của chính nó, không để lại "đã kiểm tra thử" ma. */
    public function test_report_write_failure_in_dry_run_returns_503_and_rolls_back_the_dry_run_audit(): void
    {
        $writer = Mockery::mock(ImportReportWriter::class);
        $writer->shouldReceive('write')
            ->once()
            ->andThrow(new ImportReportPersistenceException('injected: import report store unavailable'));
        $this->app->instance(ImportReportWriter::class, $writer);

        $file = $this->xlsx(['code', 'name', 'status'], [['ATOMIC-224-DRY', 'SKU kiem tra thu', 'ACTIVE']]);

        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file, 'dry_run' => true])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SKU_IMPORT_REPORT_PERSISTENCE_FAILED')
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.import_report_id', null);

        $this->assertSame(0, Sku::query()->where('sku_code', 'ATOMIC-224-DRY')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'SKU_IMPORT_DRY_RUN_CHECK')->count());
        $this->assertSame(0, ImportReport::query()->count());
    }

    /**
     * Nhánh 422 lỗi dòng cũng phải fail-closed: không có báo cáo REJECTED thì không có
     * chỗ neo file lỗi CSV, nên trả 422 kèm `import_report_id=null` là đưa cho client
     * một cái phiếu tra cứu rỗng.
     */
    public function test_report_write_failure_on_row_error_path_returns_503_not_a_silent_422(): void
    {
        $writer = Mockery::mock(ImportReportWriter::class);
        $writer->shouldReceive('write')
            ->once()
            ->andThrow(new ImportReportPersistenceException('injected: import report store unavailable'));
        $this->app->instance(ImportReportWriter::class, $writer);

        $file = $this->xlsx(
            ['code', 'name', 'status'],
            [['DUP-224-P1', 'Dong 1', 'ACTIVE'], ['DUP-224-P1', 'Dong trung', 'ACTIVE']]
        );

        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file, 'dry_run' => true])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SKU_IMPORT_REPORT_PERSISTENCE_FAILED');

        $this->assertSame(0, ImportReport::query()->count());
        $this->assertSame(0, Sku::query()->where('sku_code', 'DUP-224-P1')->count());
    }

    /**
     * Fail-closed cả ở chiều ĐỌC. `import_reports` chính là bộ nhớ của cơ chế idempotent:
     * không truy vấn được thì câu trả lời "file này đã import chưa" luôn là "chưa", và
     * lần gửi lại bình thường của người dùng sẽ ghi lần hai. Đây đúng là dạng fail-open
     * mà DEV330-P1-07R3 đã bỏ ở phía khoá — không được để nó quay lại qua cửa sau.
     */
    public function test_report_lookup_failure_refuses_the_import_instead_of_losing_the_idempotency_guard(): void
    {
        $file = $this->xlsx(['code', 'name', 'status'], [['LOOKUP-224-001', 'SKU tra cuu hong', 'ACTIVE']]);

        Schema::drop('import_reports');

        $this->withHeaders($this->headers())
            ->post('/api/v1/skus/import-excel', ['file' => $file])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SKU_IMPORT_REPORT_PERSISTENCE_FAILED')
            ->assertJsonPath('data.partial_write', false);

        $this->assertSame(0, Sku::query()->where('sku_code', 'LOOKUP-224-001')->count());
    }

    /** Bất biến chiều ngược lại: mọi response 200/422-dòng đều phải có report id thật. */
    public function test_every_processed_run_returns_a_real_report_id(): void
    {
        $commit = $this->withHeaders($this->headers())->post('/api/v1/skus/import-excel', [
            'file' => $this->xlsx(['code', 'name', 'status'], [['REPORTID-224-OK', 'SKU commit', 'ACTIVE']]),
        ])->assertOk();

        $rejected = $this->withHeaders($this->headers())->post('/api/v1/skus/import-excel', [
            'file' => $this->xlsx(['code', 'name'], [['REJ-224-A', 'Dong 1'], ['REJ-224-A', 'Dong trung']]),
        ])->assertStatus(422);

        foreach ([$commit, $rejected] as $response) {
            $reportId = $response->json('data.import_report_id');
            $this->assertIsString($reportId);
            $this->assertNotNull(ImportReport::query()->find($reportId));
        }

        $this->assertSame('COMMITTED', ImportReport::query()->find($commit->json('data.import_report_id'))->status);
        $this->assertSame('REJECTED', ImportReport::query()->find($rejected->json('data.import_report_id'))->status);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // DEV224-P1-04R — quyền trong tài liệu phải là quyền route thực thi
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Guard chống tái diễn, không chỉ vá một chuỗi.
     *
     * Lỗi gốc không phải "gõ nhầm product.manage" mà là "không có gì bắt tài liệu và
     * middleware phải khớp nhau". Test này đọc quyền THẬT từ bảng route rồi soi lại
     * chính artifact OpenAPI mà FE/QA/Postman dùng — nên bất kỳ endpoint import nào đổi
     * middleware mà quên regenerate spec đều đỏ ngay tại CI.
     */
    public function test_generated_openapi_documents_the_permission_the_route_actually_enforces(): void
    {
        $spec = json_decode((string) file_get_contents(base_path('storage/api-docs/api-docs.json')), true);
        $this->assertIsArray($spec);

        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            if (! str_contains($uri, 'skus/import')) {
                continue;
            }

            $permission = null;
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    $permission = explode(':', $middleware, 2)[1];
                }
            }
            $this->assertNotNull($permission, "Route {$uri} phải gate bằng permission middleware.");

            foreach ($route->methods() as $method) {
                $method = strtolower($method);
                if (! isset($spec['paths'][$uri][$method]['responses']['403']['description'])) {
                    continue;
                }

                $description = (string) $spec['paths'][$uri][$method]['responses']['403']['description'];
                $this->assertStringContainsString(
                    $permission,
                    $description,
                    "OpenAPI của {$method} {$uri} mô tả quyền khác với quyền route thực thi ({$permission})."
                );
                $this->assertStringNotContainsString(
                    'product.manage',
                    $description,
                    "OpenAPI của {$method} {$uri} còn giữ chuỗi quyền cũ `product.manage` (DEV224-P1-04R)."
                );
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(5, $checked, 'Phải soi đủ 5 endpoint nhóm import SKU.');
    }

    /** 503 phải nằm trong hợp đồng công bố, không phải mã lỗi chỉ tồn tại trong code. */
    public function test_generated_openapi_publishes_the_mandatory_report_failure_contract(): void
    {
        $spec = json_decode((string) file_get_contents(base_path('storage/api-docs/api-docs.json')), true);
        $responses = $spec['paths']['/api/v1/skus/import-excel']['post']['responses'] ?? [];

        $this->assertArrayHasKey('503', $responses, 'DEV224-P1-05R: hợp đồng phải công bố 503.');
        $this->assertStringContainsString(
            'SKU_IMPORT_REPORT_PERSISTENCE_FAILED',
            (string) ($responses['503']['description'] ?? ''),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // DEV224-P1-01R — phạm vi SKU-only và mapping bị khoá bằng register máy đọc được
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Register `DISP-IMP-224` là bản disposition đang chờ BA/Data Owner duyệt. Test này
     * không thay BA duyệt; nó chỉ bảo đảm code KHÔNG ÂM THẦM TRÔI khỏi đúng bản đã trình:
     * thêm/bớt một cột nhận vào mà không sửa register là đỏ CI.
     */
    public function test_sku_import_mapping_matches_the_locked_disposition_register(): void
    {
        $register = json_decode(
            (string) file_get_contents(base_path('docs/decision-records/DISP-IMP-224-sku-import-scope-mapping.json')),
            true,
        );
        $this->assertIsArray($register);

        $this->assertSame(ImportService::TEMPLATE_VERSION, $register['template_version']);
        $this->assertSame('SKU', $register['in_scope_dataset']);

        $reflection = new \ReflectionClass(ImportService::class);
        $accepted = $reflection->getConstant('ACCEPTED_COLUMNS');
        $template = $reflection->getConstant('TEMPLATE_COLUMNS');

        $this->assertSame(
            array_values((array) $register['template_columns']),
            array_values((array) $template),
            'Cột template thực tế đã trôi khỏi register DISP-IMP-224.'
        );

        sort($accepted);
        $registeredAccepted = array_values((array) $register['accepted_columns']);
        sort($registeredAccepted);
        $this->assertSame(
            $registeredAccepted,
            $accepted,
            'Tập cột được chấp nhận đã trôi khỏi register DISP-IMP-224.'
        );

        // Năm dataset còn lại của SRS §13.12 phải ở trạng thái "ngoài phạm vi endpoint",
        // để không ai đọc WWHN-56 rồi tưởng endpoint này nhập được master/item.
        foreach (['PHYSICAL_CODE', 'ITEM', 'CONTAINER', 'OPENING_BALANCE', 'AGENCY'] as $dataset) {
            $this->assertArrayHasKey($dataset, $register['out_of_scope_datasets']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /** @param array<int, string> $headers @param array<int, array<int, string>> $rows */
    private function xlsx(array $headers, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }

        $path = sys_get_temp_dir().'/wwhn224-p1-'.bin2hex(random_bytes(8)).'.xlsx';
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
