<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mockery;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Exceptions\ImportConcurrencyException;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Services\ImportReportWriter;
use Modules\Warehouse\Services\ImportService;
use Modules\Warehouse\Support\SkuImportContract;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Throwable;

/**
 * WWHN-331 FE331-P1-08R4 — CHỖ GIỮ CHÍNH DANH (durable claim) CHO IMPORT COMMIT.
 *
 * Vòng R4 bắt đúng chỗ mà vòng R3 tưởng đã đóng: `withImportLock()` mới chỉ sửa nhánh
 * "hết thời gian chờ", trong khi bất biến "kiểm tra trùng + ghi là một thao tác nguyên
 * tử" vẫn treo trên MỘT sợi dây là `Cache::lock` — và sợi dây đó đứt LẶNG LẼ khi cache
 * store không khoá được liên tiến trình, khi Redis/Valkey hỏng, hoặc khi ai đó bật
 * `lock.on_timeout=FAIL_OPEN`. Trong cả ba, mã cũ vẫn chạy tiếp và vẫn trả 200.
 *
 * ── Vì sao bộ test này KHÔNG giả lập chốt chặn ───────────────────────────────────────
 *
 * Bộ test cũ chứng minh fail-closed bằng `Mockery` trên chính `Cache::lock` — tức là nó
 * kiểm "code có rẽ nhánh đúng khi khoá báo hết giờ không", chứ không kiểm "hệ thống có
 * thật sự ngăn được hai lần ghi không". Đúng ba hoàn cảnh nguy hiểm ở trên đều nằm ngoài
 * tầm của một mock như vậy, vì ở đó khoá KHÔNG báo lỗi gì cả.
 *
 * Nên ở đây chốt chặn luôn là hàng thật trong cơ sở dữ liệu thật, và điểm quan trọng
 * nhất — `test_a_second_commit_is_refused_while_the_first_is_still_inside_its_import` —
 * dựng chồng lấn THẬT: lần import thứ hai được gọi từ bên trong lần import thứ nhất,
 * đúng thời điểm lần thứ nhất đang ở giữa transaction ghi dữ liệu của nó. Không mock
 * khoá, không mock chỗ giữ, không tự dựng lại race window bằng giả định.
 */
class Wwhn331ImportDurableClaimTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.write_policy' => 'fail_closed']);
        $this->seed(AuthDatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // ── 1. Chồng lấn THẬT: request thứ hai gọi vào giữa lần chạy thứ nhất ────────────

    /**
     * Đây là bằng chứng chính của FE331-P1-08R4.
     *
     * `ImportReportWriter::write()` được `ImportService` gọi TRONG transaction ghi dữ
     * liệu, tức đúng lúc lần import thứ nhất đã đi qua bước kiểm tra trùng nhưng CHƯA
     * commit — chính xác là khoảng thời gian mà một request trùng định danh gửi song song
     * sẽ rơi vào, và cũng chính là khoảng mà bản cũ để lọt.
     *
     * Bọc writer đó lại và gọi `import()` lần hai từ bên trong, ta có chồng lấn thật với
     * hạ tầng thật. Kỳ vọng: lần hai bị TỪ CHỐI (409), và khi tất cả lắng xuống thì chỉ
     * có ĐÚNG MỘT `import_reports` COMMITTED cùng đúng một bộ SKU.
     */
    public function test_a_second_commit_is_refused_while_the_first_is_still_inside_its_import(): void
    {
        $path = $this->plainXlsxPath([['CLAIM-OVERLAP-1', 'San pham chong lan']]);
        $actorId = $this->superAdminId();
        $secondAttempt = null;

        $this->reenterImportWhileWriting(function () use ($path, $actorId) {
            // Lần gửi thứ hai: CÙNG file, CÙNG actor, CÙNG tùy chọn — trùng đủ định danh.
            app(ImportService::class)->import($path, false, $actorId, null, 'overlap.xlsx', false);
        }, $secondAttempt);

        $result = app(ImportService::class)->import($path, false, $actorId, null, 'overlap.xlsx', false);

        $this->assertTrue($result['committed'], 'Lần import thứ nhất phải hoàn tất bình thường.');
        $this->assertInstanceOf(
            ImportConcurrencyException::class,
            $secondAttempt,
            'Lần gửi thứ hai chồng lên lần thứ nhất phải bị từ chối, không được cùng chạy.',
        );

        // Bất biến thật sự cần giữ: đúng MỘT lần ghi.
        $this->assertSame(1, ImportReport::query()->where('import_type', 'SKU')->where('status', 'COMMITTED')->count());
        $this->assertSame(1, Sku::query()->where('sku_code', 'CLAIM-OVERLAP-1')->count());
    }

    // ── 2. Chốt chặn không phụ thuộc cache ──────────────────────────────────────────

    /**
     * Hoàn cảnh 1 của R4: cache store không khoá được liên tiến trình (`array`/`null`,
     * hoặc `file` khi chạy nhiều node). Khoá vẫn "giành được" cho cả hai request và không
     * ném lỗi gì — nên mọi test dựa trên `LockTimeoutException` đều mù ở đây.
     *
     * Ở test này khoá cache giành được BÌNH THƯỜNG (không mock, không hết giờ). Thứ duy
     * nhất chặn được là hàng trong `sku_import_claims` mà tiến trình kia để lại.
     */
    public function test_a_live_claim_left_by_another_process_refuses_the_import_even_when_the_cache_lock_succeeds(): void
    {
        $path = $this->plainXlsxPath([['CLAIM-LIVE-1', 'San pham bi giu cho']]);
        $actorId = $this->superAdminId();

        $this->seedForeignClaim($path, $actorId, updateExisting: false, expiresInSeconds: 600);

        try {
            app(ImportService::class)->import($path, false, $actorId, null, 'live-claim.xlsx', false);
            $this->fail('Đang có tiến trình khác giữ chỗ cho đúng định danh này mà import vẫn chạy.');
        } catch (ImportConcurrencyException $e) {
            $this->assertSame(SkuImportContract::lockWaitSeconds(), $e->waitedSeconds);
        }

        // §8.2 cho 409 không phải replay: "State, stock and movement unchanged".
        $this->assertSame(0, Sku::query()->where('sku_code', 'CLAIM-LIVE-1')->count());
        $this->assertSame(0, ImportReport::query()->where('import_type', 'SKU')->count());

        // Và chỗ giữ của người khác không bị request bị từ chối xoá mất.
        $this->assertSame(1, DB::table('sku_import_claims')->count());
    }

    /**
     * Hoàn cảnh 3 của R4, và là mục mà register nêu đích danh: `FAIL_OPEN` được phép đổi
     * cách XẾP HÀNG, nhưng KHÔNG được phép hạ chuẩn toàn vẹn dữ liệu.
     *
     * Ở đây khoá cache bị ép hết giờ (đúng kịch bản đã review) VÀ hợp đồng đang bật
     * FAIL_OPEN — tức là mọi lớp bảo vệ cũ đều đã bị vô hiệu hoá. Import vẫn phải bị từ
     * chối, vì chỗ giữ chính danh không đọc cấu hình đó.
     */
    public function test_fail_open_configuration_cannot_bypass_the_durable_claim(): void
    {
        config(['warehouse.sku_import.lock.on_timeout' => 'FAIL_OPEN']);
        $this->assertFalse(SkuImportContract::lockFailsClosed());

        $path = $this->plainXlsxPath([['CLAIM-FAILOPEN-1', 'San pham fail open']]);
        $actorId = $this->superAdminId();

        $this->seedForeignClaim($path, $actorId, updateExisting: false, expiresInSeconds: 600);

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        $this->expectException(ImportConcurrencyException::class);

        try {
            app(ImportService::class)->import($path, false, $actorId, null, 'failopen.xlsx', false);
        } finally {
            $this->assertSame(0, Sku::query()->where('sku_code', 'CLAIM-FAILOPEN-1')->count());
            $this->assertSame(0, ImportReport::query()->where('import_type', 'SKU')->count());
        }
    }

    /**
     * Hoàn cảnh 2 của R4: Redis/Valkey hỏng nên `Cache::lock` ném lỗi ngay lúc dựng khoá.
     *
     * Hai hành vi sai đều phải loại: biến sự cố hạ tầng cache thành 500 cho một request
     * hợp lệ, và (tệ hơn) nuốt lỗi rồi chạy như thể đã có khoá. Đúng là: đi tiếp xuống
     * chỗ giữ chính danh — nên một import bình thường vẫn chạy được, còn một import trùng
     * định danh vẫn bị chặn.
     */
    public function test_a_broken_cache_backend_neither_fails_the_request_nor_removes_the_guarantee(): void
    {
        $actorId = $this->superAdminId();
        Cache::shouldReceive('lock')->andThrow(new \RuntimeException('Connection refused [tcp://valkey:6379]'));

        $lonePath = $this->plainXlsxPath([['CLAIM-NOCACHE-1', 'San pham khong cache']]);
        $result = app(ImportService::class)->import($lonePath, false, $actorId, null, 'nocache.xlsx', false);
        $this->assertTrue($result['committed'], 'Cache hỏng không được biến một import hợp lệ thành lỗi.');

        $heldPath = $this->plainXlsxPath([['CLAIM-NOCACHE-2', 'San pham bi giu cho']]);
        $this->seedForeignClaim($heldPath, $actorId, updateExisting: false, expiresInSeconds: 600);

        $this->expectException(ImportConcurrencyException::class);

        try {
            app(ImportService::class)->import($heldPath, false, $actorId, null, 'nocache-2.xlsx', false);
        } finally {
            $this->assertSame(0, Sku::query()->where('sku_code', 'CLAIM-NOCACHE-2')->count());
        }
    }

    // ── 3. Chỗ giữ phải nói về ĐÚNG bộ định danh của DEC-IMP-001 §2 ─────────────────

    /**
     * Chỗ giữ và phép kiểm tra trùng phải cùng một định nghĩa "một lần import". Nếu chỗ
     * giữ rộng hơn (chỉ theo file + actor) thì bật `update_existing` lên rồi bấm Import
     * sẽ bị chặn oan bởi một lần chạy khác tùy chọn — tức là tái tạo lại đúng lỗi
     * FE331-P1-02R3 vừa đóng, chỉ đổi triệu chứng từ "nuốt lặng" sang "chặn oan".
     */
    public function test_a_claim_only_blocks_the_same_options_fingerprint(): void
    {
        $path = $this->plainXlsxPath([['CLAIM-OPT-1', 'San pham doi tuy chon']]);
        $actorId = $this->superAdminId();

        // Tiến trình khác đang giữ chỗ cho update_existing=FALSE.
        $this->seedForeignClaim($path, $actorId, updateExisting: false, expiresInSeconds: 600);

        // update_existing=TRUE là một thao tác KHÁC → phải chạy được.
        $result = app(ImportService::class)->import($path, true, $actorId, null, 'options.xlsx', false);

        $this->assertTrue($result['committed']);
        $this->assertSame(1, Sku::query()->where('sku_code', 'CLAIM-OPT-1')->count());
    }

    // ── 4. Van cứu hộ: tiếp quản chỗ giữ đã chết, và không bao giờ im lặng ──────────

    public function test_an_expired_claim_is_taken_over_and_the_takeover_is_logged(): void
    {
        $path = $this->plainXlsxPath([['CLAIM-STALE-1', 'San pham cho giu chet']]);
        $actorId = $this->superAdminId();

        // Chỗ giữ của một tiến trình đã chết: quá hạn.
        $this->seedForeignClaim($path, $actorId, updateExisting: false, expiresInSeconds: -60);

        Log::spy();

        $result = app(ImportService::class)->import($path, false, $actorId, null, 'stale.xlsx', false);

        $this->assertTrue($result['committed'], 'Chỗ giữ đã hết hạn không được khoá chết luồng import.');
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => $message === 'SKU_IMPORT_CLAIM_TAKEOVER'
        )->once();

        // Tiếp quản xong thì chính request này trả chỗ, không để lại rác.
        $this->assertSame(0, DB::table('sku_import_claims')->count());
    }

    /**
     * Hạn của chỗ giữ chỉ là van cứu hộ cho tiến trình chết, nên nó phải lớn hơn hẳn
     * ngân sách commit đã duyệt. Một giá trị cấu hình quá thấp (vô tình hoặc để "cho
     * nhanh") sẽ mở lại đúng ca đua mà chỗ giữ sinh ra để đóng — nên có sàn cứng.
     */
    public function test_claim_ttl_can_never_be_configured_below_twice_the_approved_commit_budget(): void
    {
        config([
            'warehouse.sku_import.lock.claim_ttl_seconds' => 1,
            'warehouse.sku_import.performance_objective_ms.commit_at_max_rows' => 60000,
        ]);

        $this->assertGreaterThanOrEqual(120, SkuImportContract::claimTtlSeconds());
        $this->assertGreaterThanOrEqual(SkuImportContract::lockTtlSeconds(), SkuImportContract::claimTtlSeconds());
    }

    // ── 5. Chỗ giữ luôn được trả lại — kể cả khi import hỏng ────────────────────────

    public function test_the_claim_is_released_after_success_and_after_failure(): void
    {
        $actorId = $this->superAdminId();

        $okPath = $this->plainXlsxPath([['CLAIM-REL-1', 'San pham thanh cong']]);
        app(ImportService::class)->import($okPath, false, $actorId, null, 'ok.xlsx', false);
        $this->assertSame(0, DB::table('sku_import_claims')->count(), 'Import thành công phải trả lại chỗ giữ.');

        // File hỏng → InvalidArgumentException ném ra từ trong callback.
        $brokenPath = sys_get_temp_dir().'/wwhn331-broken-'.bin2hex(random_bytes(8)).'.xlsx';
        file_put_contents($brokenPath, 'khong-phai-workbook');
        $this->tempFiles[] = $brokenPath;

        try {
            app(ImportService::class)->import($brokenPath, false, $actorId, null, 'broken.xlsx', false);
            $this->fail('File hỏng phải ném lỗi.');
        } catch (InvalidArgumentException) {
            // mong đợi
        }

        $this->assertSame(
            0,
            DB::table('sku_import_claims')->count(),
            'Import hỏng cũng phải trả lại chỗ giữ, nếu không một lỗi nhất thời sẽ khoá chết file đó tới khi hết hạn.',
        );
    }

    /**
     * Một request ĐÃ BỊ TIẾP QUẢN không được xoá chỗ giữ của người kế nhiệm khi nó kết
     * thúc — đó là cách một "van cứu hộ" biến thành chính lỗ hổng nó vá.
     */
    public function test_releasing_only_removes_the_claim_this_request_owns(): void
    {
        $path = $this->plainXlsxPath([['CLAIM-OWNER-1', 'San pham chu so huu']]);
        $actorId = $this->superAdminId();
        $identityHash = $this->identityHashFor($path, $actorId, false);
        $ignored = null;

        // Chỗ giữ đã hết hạn → request của ta tiếp quản. Ngay khi ta đang chạy, một tiến
        // trình thứ ba "tiếp quản tiếp" (đổi owner_token) — mô phỏng bằng cách ghi đè
        // token trong lúc lần import của ta chưa kết thúc.
        $this->seedForeignClaim($path, $actorId, updateExisting: false, expiresInSeconds: -60);

        $this->reenterImportWhileWriting(function () use ($identityHash) {
            DB::table('sku_import_claims')
                ->where('identity_hash', $identityHash)
                ->update(['owner_token' => str_repeat('f', 64)]);
        }, $ignored);

        app(ImportService::class)->import($path, false, $actorId, null, 'owner.xlsx', false);

        $survivor = DB::table('sku_import_claims')->where('identity_hash', $identityHash)->first();
        $this->assertNotNull($survivor, 'Chỗ giữ của người kế nhiệm không được bị xoá bởi request đã bị tiếp quản.');
        $this->assertSame(str_repeat('f', 64), $survivor->owner_token);
    }

    // ── 6. Hợp đồng công bố ra ngoài đúng thứ đang bảo vệ ───────────────────────────

    public function test_the_published_contract_names_the_durable_guard_not_just_the_timeout_policy(): void
    {
        $constraints = SkuImportContract::toArray();

        $this->assertSame('DB_DURABLE_CLAIM', $constraints['concurrency_guard']);
        $this->assertSame('sku_import_claims', $constraints['concurrency_guard_table']);
        $this->assertSame(SkuImportContract::claimTtlSeconds(), $constraints['claim_ttl_seconds']);

        // Định danh chỗ giữ phải suy ra được từ hợp đồng công bố, để QA dựng lại được mà
        // không phải đọc mã nguồn.
        $this->assertSame(['file_sha256', 'actor_id', 'update_existing'], $constraints['idempotency_identity']);
        $this->assertSame(
            hash('sha256', 'actor-1|'.str_repeat('a', 64).'|'.SkuImportContract::optionsFingerprint(true)),
            SkuImportContract::identityHash('actor-1', str_repeat('a', 64), SkuImportContract::optionsFingerprint(true)),
        );
    }

    // ── Trợ giúp ───────────────────────────────────────────────────────────────────

    /**
     * Bọc `ImportReportWriter` để chạy `$duringWrite` ĐÚNG lúc lần import đang dở: đã qua
     * bước kiểm tra trùng, đang ở trong transaction, chưa commit. Chỉ chạy một lần.
     *
     * Exception mà `$duringWrite` ném ra được bắt lại và trả về qua `$captured` thay vì
     * làm hỏng lần import thứ nhất — ta cần cả hai kết quả để đối chiếu.
     */
    private function reenterImportWhileWriting(callable $duringWrite, mixed &$captured): void
    {
        $captured = null;
        $fired = false;
        $inner = app(ImportReportWriter::class);

        // Trạng thái nằm ở closure (bắt theo tham chiếu) chứ không ở thuộc tính: PHP không
        // cho khai báo promoted property by-reference, và ta cần đọc lại `$captured` ở
        // ngoài sau khi lần import kết thúc.
        $hook = function () use ($duringWrite, &$fired, &$captured): void {
            if ($fired) {
                return;
            }
            $fired = true;

            try {
                $duringWrite();
            } catch (Throwable $e) {
                $captured = $e;
            }
        };

        $this->app->instance(ImportReportWriter::class, new class($inner, $hook) extends ImportReportWriter
        {
            public function __construct(
                private readonly ImportReportWriter $inner,
                private readonly Closure $hook,
            ) {}

            public function write(array $result, ?string $actorId, ?string $requestId, ?string $fileName, bool $updateExisting): ImportReport
            {
                ($this->hook)();

                return $this->inner->write($result, $actorId, $requestId, $fileName, $updateExisting);
            }
        });
    }

    /** Chỗ giữ do "một tiến trình khác" để lại, ghi thẳng vào bảng như tiến trình đó sẽ làm. */
    private function seedForeignClaim(string $path, string $actorId, bool $updateExisting, int $expiresInSeconds): void
    {
        DB::table('sku_import_claims')->insert([
            'identity_hash' => $this->identityHashFor($path, $actorId, $updateExisting),
            'owner_token' => str_repeat('9', 64),
            'actor_id' => $actorId,
            'file_sha256' => hash_file('sha256', $path),
            'options_fingerprint' => SkuImportContract::optionsFingerprint($updateExisting),
            'request_id' => 'tien-trinh-khac',
            'claimed_at' => now(),
            'expires_at' => now()->addSeconds($expiresInSeconds),
        ]);
    }

    private function identityHashFor(string $path, string $actorId, bool $updateExisting): string
    {
        return SkuImportContract::identityHash(
            $actorId,
            (string) hash_file('sha256', $path),
            SkuImportContract::optionsFingerprint($updateExisting),
        );
    }

    private function superAdminId(): string
    {
        return (string) User::query()->where('email', 'test-super-admin@hoanam.com')->value('user_id');
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

        $path = sys_get_temp_dir().'/wwhn331-claim-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->tempFiles[] = $path;

        return $path;
    }
}
