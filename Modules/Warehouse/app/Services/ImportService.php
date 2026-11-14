<?php

namespace Modules\Warehouse\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Exceptions\ImportConcurrencyException;
use Modules\Warehouse\Exceptions\ImportReportPersistenceException;
use Modules\Warehouse\Exceptions\ImportRowLimitExceededException;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Models\ProductMaster;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Warehouse\Support\SkuImportContract;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class ImportService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ImportReportWriter $reportWriter,
    ) {}

    public const TEMPLATE_VERSION = 'WWHN-172-SKU-IMPORT-v1.1.0';

    /**
     * Tên sheet vừa đọc, để file lỗi CSV điền được cột `sheet` mà SRS §13.12 yêu cầu.
     * Đặt lại ở đầu mỗi lần `runImport()` nên không mang trạng thái sang lần chạy sau.
     */
    private ?string $sheetName = null;

    private array $reportContext = [];

    /**
     * WWHN-331 FE331-P1-03R3 — bốn hạn mức của import SKU (số dòng, cỡ file, cửa sổ
     * idempotent, hạn file lỗi) trước đây là hằng số riêng trong lớp này, mỗi cái kèm
     * ghi chú "giá trị tạm, cần BA/Tech Lead chốt", rồi FE chép lại đúng những con số
     * đó vào một Alert cho người dùng đọc. Nay tất cả đọc từ một nguồn duy nhất
     * (`config/warehouse.php` → `warehouse.sku_import`, chốt tại DEC-IMP-001) và chính
     * khối giá trị đó được API trả ra qua `constraints`, nên hạn mức máy chủ áp dụng và
     * hạn mức UI hiển thị không thể lệch nhau, và không còn hằng số nào chờ duyệt bị
     * đưa thẳng ra màn hình người dùng.
     */
    private function maxRows(): int
    {
        return SkuImportContract::maxRows();
    }

    /** Header + maxRows + 1 dòng dư để phát hiện vượt hạn mức mà vẫn bounded-read. */
    private function maxPhysicalRowsToRead(): int
    {
        return SkuImportContract::maxPhysicalRowsToRead();
    }

    /**
     * Trần số dòng VẬT LÝ được quét trước khi dừng và báo lỗi tường minh.
     *
     * DEV330-P1-02R3: bội số quét trước đây là số `20` viết thẳng ở đây — một chính sách
     * chống DoS (file vài KB vẫn ép server quét hàng triệu dòng vật lý) chưa ai duyệt,
     * nằm lẫn trong code như hằng số kỹ thuật. Nay đọc từ hợp đồng đã chốt
     * (`warehouse.sku_import.max_physical_scan_multiplier`, DEC-IMP-001 §1) và được công
     * bố trong `constraints` để người dùng biết vì sao file thưa của mình bị từ chối.
     */
    private function maxPhysicalRowsToScan(): int
    {
        return SkuImportContract::maxPhysicalRowsToScan();
    }

    /** Cửa sổ coi 2 lần COMMIT trùng ĐỦ định danh là replay (idempotent). */
    private function idempotencyWindowHours(): int
    {
        return SkuImportContract::idempotencyWindowHours();
    }

    /** Current export template. Legacy code/name/version headers remain accepted on import. */
    private const TEMPLATE_COLUMNS = [
        'sku_code', 'sku_name', 'unit', 'sku_type', 'brand_code', 'category_code',
        'model_code', 'power_or_voltage', 'package_variant', 'version_code',
        'published_to_app', 'is_serial_tracked', 'status',
    ];

    private const ACCEPTED_COLUMNS = [
        'code', 'sku_code', 'name', 'sku_name', 'unit', 'sku_type', 'brand_code',
        'category_code', 'model_code', 'power_or_voltage', 'package_variant',
        'version', 'version_code', 'published_to_app', 'is_serial_tracked', 'status',
    ];

    /** @return array<string, mixed> */
    public function import(
        string $filePath,
        bool $updateExisting = false,
        ?string $actorId = null,
        ?string $requestId = null,
        ?string $originalName = null,
        bool $dryRun = false,
        ?string $sourceFileId = null,
    ): array {
        $this->reportContext = [
            '_source_path' => $filePath,
            '_source_file_id' => $sourceFileId,
            '_started_at' => now(),
            '_started_clock' => hrtime(true),
        ];
        $this->assertFile($filePath);
        $checksum = hash_file('sha256', $filePath);
        if (! is_string($checksum)) {
            throw new InvalidArgumentException('Không tính được SHA-256 của file import.');
        }

        // WWHN-330 P1-03: file trùng sha256 với 1 lần COMMIT gần đây của cùng actor thì
        // coi là replay — trả lại kết quả cũ, không đọc/parse lại file, không ghi SKU lần 2.
        //
        // WWHN-331 FE331-P1-02R3: định danh replay phải gồm CẢ tùy chọn import, không chỉ
        // (file, actor, thời gian). `update_existing` đổi hẳn hiệu lực nghiệp vụ của cùng
        // một file: false = chỉ tạo mới, SKU đã tồn tại thì báo DUPLICATE_CODE; true = cho
        // phép ghi đè SKU đang tồn tại. Coi hai lần gửi đó là "cùng một thao tác" sẽ nuốt
        // mất lần chạy mà người dùng thực sự muốn — người dùng bật công tắc lên rồi bấm
        // Import và hệ thống lặng lẽ trả lại kết quả cũ, không cập nhật gì.
        //
        // WWHN-330 residual-02: bọc toàn bộ "kiểm tra trùng + chạy import" trong 1 atomic
        // lock theo đúng bộ định danh đó để 2 request COMMIT giống hệt nhau gửi gần như
        // đồng thời không cùng lọt qua bước kiểm tra (race window cũ tạo 2 dòng
        // import_reports COMMITTED cho cùng 1 file). Lock cấp ứng dụng, không đổi ngữ nghĩa
        // cửa sổ idempotent (unique index DB sẽ chặn vĩnh viễn, sai nghiệp vụ).
        $optionsFingerprint = SkuImportContract::optionsFingerprint($updateExisting);

        if (! $dryRun && $actorId !== null) {
            return $this->withImportLock($checksum, $actorId, $optionsFingerprint, $requestId, function () use (
                $checksum, $filePath, $updateExisting, $actorId, $requestId, $originalName, $dryRun, $optionsFingerprint
            ): array {
                $duplicate = $this->findRecentDuplicateReport($checksum, $actorId, $optionsFingerprint);
                if ($duplicate !== null) {
                    return $this->duplicateResult($duplicate);
                }

                return $this->runImport(
                    $filePath, $updateExisting, $actorId, $requestId, $originalName, $dryRun, $checksum
                );
            });
        }

        return $this->runImport(
            $filePath, $updateExisting, $actorId, $requestId, $originalName, $dryRun, $checksum
        );
    }

    /**
     * WWHN-330 residual-02 + DEV330-P1-07R3: serialize các lần import COMMIT trùng ĐỦ
     * định danh (actor, sha256, vân tay tùy chọn).
     *
     * **Hết thời gian chờ = TỪ CHỐI, không chạy tiếp.** Bản trước fail-open: hết
     * `wait_seconds` thì gọi thẳng `$callback()` mà không giữ khoá. Điều đó vô hiệu hoá
     * đúng cái bất biến khoá sinh ra để giữ — "kiểm tra đã import chưa" và "ghi SKU + tạo
     * báo cáo" phải là một thao tác nguyên tử — và nó vô hiệu hoá đúng vào lúc nguy hiểm
     * nhất: hết thời gian chờ nghĩa là ĐANG CÓ một request khác giữ khoá cho cùng bộ định
     * danh, tức đúng kịch bản gửi trùng mà cơ chế idempotent phải chặn. Kết quả cũ: hai
     * dòng `import_reports` COMMITTED cho cùng một file, cả hai request đều trả 200, không
     * ai biết tính idempotent đã bị phá.
     *
     * Nay ném `ImportConcurrencyException` → 409 `CONCURRENCY_CONFLICT` (§8.2), người dùng
     * nhận một thông điệp đúng sự thật và thử lại được. Đánh đổi đã cân nhắc: nếu Redis
     * hỏng thì import bị chặn thay vì chạy mất an toàn — đúng thứ tự ưu tiên cho một hạng
     * mục Data Integrity, và sự cố hạ tầng sẽ lộ ra ngay thay vì âm thầm sinh dữ liệu sai.
     * Hướng ngược lại vẫn cấu hình được (`lock.on_timeout = FAIL_OPEN`) nhưng phải là một
     * quyết định được ghi ra, không phải mặc định ẩn.
     *
     * ── FE331-P1-08R4: KHOÁ CACHE LÀ HÀNG CHỜ, KHÔNG PHẢI BẢO ĐẢM ─────────────────────
     *
     * Vòng trước sửa đúng nhánh hết-thời-gian-chờ, nhưng bất biến "kiểm tra trùng + ghi
     * là một thao tác nguyên tử" vẫn treo trên MỘT sợi dây duy nhất là `Cache::lock`, và
     * sợi dây đó đứt LẶNG LẼ — không exception, không log — trong ba hoàn cảnh có thật:
     *
     * 1. Cache store không khoá được liên tiến trình: `array`/`null`, hoặc `file` khi
     *    chạy nhiều node. `block()` "thành công" cho CẢ HAI request; cả hai cùng thấy
     *    "chưa import lần nào" và cùng ghi → hai `import_reports` COMMITTED một file.
     * 2. Redis/Valkey hỏng: khoá không dựng được.
     * 3. `lock.on_timeout = FAIL_OPEN`: chính hợp đồng cho phép bỏ qua khoá.
     *
     * Trong cả ba, đoạn mã cũ vẫn chạy tiếp và vẫn trả 200 — tức tính idempotent bị phá
     * mà không ai biết, đúng dạng lỗi mà vòng trước tưởng đã đóng.
     *
     * Nên chốt chặn thật được hạ xuống nơi kết quả đằng nào cũng phải ghi vào: một CHỖ
     * GIỮ CHÍNH DANH trong bảng `sku_import_claims`, UNIQUE theo định danh import. Cơ sở
     * dữ liệu là trọng tài duy nhất, dùng chung cho mọi node và mọi driver, nên không tồn
     * tại kịch bản "khoá im lặng không khoá gì", và `FAIL_OPEN` KHÔNG vô hiệu hoá được
     * nó: cấu hình đó chỉ còn quyền đổi cách XẾP HÀNG, không còn quyền hạ chuẩn TOÀN VẸN.
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function withImportLock(
        string $checksum,
        string $actorId,
        string $optionsFingerprint,
        ?string $requestId,
        callable $callback,
    ): array {
        return $this->withCacheQueue(
            $checksum,
            $actorId,
            $optionsFingerprint,
            fn (): array => $this->withDurableClaim($checksum, $actorId, $optionsFingerprint, $requestId, $callback),
        );
    }

    /**
     * Tầng 1 — hàng chờ cấp ứng dụng. Giữ nguyên ngữ nghĩa DEV330-P1-07R3.
     *
     * Mục đích ở đây là TRẢI NGHIỆM, không phải bảo đảm: người dùng bấm đúp hoặc mạng
     * gửi lại thì request thứ hai chờ vài giây rồi đi tiếp thay vì bị từ chối ngay. Vì
     * nó không phải bảo đảm nên khi bản thân hạ tầng cache hỏng (`Cache::lock` ném lỗi),
     * ta KHÔNG biến sự cố đó thành 500 cho một request hợp lệ — ghi log rồi đi tiếp, vì
     * tầng 2 mới là nơi bất biến được giữ.
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function withCacheQueue(string $checksum, string $actorId, string $optionsFingerprint, callable $callback): array
    {
        $waitSeconds = SkuImportContract::lockWaitSeconds();

        try {
            $lock = Cache::lock(
                'sku-import:'.$actorId.':'.$checksum.':'.$optionsFingerprint,
                SkuImportContract::lockTtlSeconds(),
            );
        } catch (Throwable $e) {
            // Hạ tầng cache không dùng được. Trước đây điều này hoặc thành 500, hoặc
            // (tệ hơn) thành một khoá giả vờ. Nay nói thẳng ra trong log và để chỗ giữ
            // chính danh làm việc của nó.
            Log::warning('SKU_IMPORT_CACHE_LOCK_UNAVAILABLE_FALLING_BACK_TO_DB_CLAIM', [
                'actor_id' => $actorId,
                'file_sha256' => $checksum,
                'exception' => $e::class,
            ]);

            return $callback();
        }

        try {
            $lock->block($waitSeconds);
        } catch (LockTimeoutException $e) {
            if (SkuImportContract::lockFailsClosed()) {
                throw new ImportConcurrencyException(
                    'Cùng file này đang được import với cùng tùy chọn; vui lòng đợi lần chạy hiện tại kết thúc rồi thử lại.',
                    $waitSeconds,
                );
            }

            Log::warning('SKU_IMPORT_LOCK_TIMEOUT_FAIL_OPEN', [
                'actor_id' => $actorId,
                'file_sha256' => $checksum,
                'waited_seconds' => $waitSeconds,
                'exception' => $e::class,
            ]);

            // Cấu hình FAIL_OPEN chỉ bỏ qua HÀNG CHỜ. Chỗ giữ chính danh bên dưới vẫn
            // chạy, nên hai request trùng định danh vẫn không thể cùng ghi.
            return $callback();
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Tầng 2 — chỗ giữ chính danh trong cơ sở dữ liệu (durable claim). Đây là nơi bất
     * biến thật sự được giữ.
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function withDurableClaim(
        string $checksum,
        string $actorId,
        string $optionsFingerprint,
        ?string $requestId,
        callable $callback,
    ): array {
        $identityHash = SkuImportContract::identityHash($actorId, $checksum, $optionsFingerprint);
        $ownerToken = bin2hex(random_bytes(32));

        $this->claimImportIdentity($identityHash, $ownerToken, $actorId, $checksum, $optionsFingerprint, $requestId);

        try {
            return $callback();
        } finally {
            $this->releaseImportIdentity($identityHash, $ownerToken);
        }
    }

    /**
     * Giành chỗ giữ, hoặc từ chối. Không có nhánh thứ ba.
     *
     * `insertOrIgnore` là một câu lệnh nguyên tử của chính cơ sở dữ liệu (`ON CONFLICT DO
     * NOTHING` / `INSERT OR IGNORE`): hai request cùng định danh gửi cùng lúc thì đúng
     * một request nhận `1`, không cần đọc trước rồi ghi sau (đọc-rồi-ghi mới là cái ca
     * đua đang phải đóng).
     *
     * Tiếp quản chỗ giữ đã hết hạn cũng phải là một câu lệnh nguyên tử vì cùng lý do:
     * `UPDATE ... WHERE identity_hash = ? AND expires_at <= now()` là một phép so-sánh-
     * rồi-đổi (compare-and-swap); hai request cùng thấy "đã hết hạn" thì vẫn chỉ một
     * request nhận được `1` dòng bị ảnh hưởng.
     */
    private function claimImportIdentity(
        string $identityHash,
        string $ownerToken,
        string $actorId,
        string $checksum,
        string $optionsFingerprint,
        ?string $requestId,
    ): void {
        $now = now();
        $expiresAt = $now->copy()->addSeconds(SkuImportContract::claimTtlSeconds());
        $takenOver = 0;

        try {
            $claimed = DB::table('sku_import_claims')->insertOrIgnore([
                'identity_hash' => $identityHash,
                'owner_token' => $ownerToken,
                'actor_id' => $actorId,
                'file_sha256' => $checksum,
                'options_fingerprint' => $optionsFingerprint,
                'request_id' => $requestId,
                'claimed_at' => $now,
                'expires_at' => $expiresAt,
            ]);

            if ($claimed === 1) {
                return;
            }

            $takenOver = DB::table('sku_import_claims')
                ->where('identity_hash', $identityHash)
                ->where('expires_at', '<=', $now)
                ->update([
                    'owner_token' => $ownerToken,
                    'actor_id' => $actorId,
                    'file_sha256' => $checksum,
                    'options_fingerprint' => $optionsFingerprint,
                    'request_id' => $requestId,
                    'claimed_at' => $now,
                    'expires_at' => $expiresAt,
                ]);
        } catch (Throwable $e) {
            // Không vận hành được chốt chặn thì KHÔNG được chạy tiếp — đúng nguyên tắc đã
            // áp cho `findRecentDuplicateReport()`: mất phương tiện bảo đảm bất biến là
            // sự cố hạ tầng phía máy chủ (503, đã rollback), không phải tranh chấp khoá
            // (409, "đợi rồi thử lại"). Nói sai loại sự cố là đẩy nhầm việc cho người dùng.
            Log::error('SKU_IMPORT_CLAIM_STORE_UNAVAILABLE_FAIL_CLOSED', [
                'actor_id' => $actorId,
                'file_sha256' => $checksum,
                'identity_hash' => $identityHash,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw new ImportReportPersistenceException(
                'Không giành được chỗ giữ chống ghi trùng cho lần import này; từ chối để không ghi trùng dữ liệu.',
                previous: $e,
            );
        }

        if ($takenOver === 1) {
            // Tiếp quản KHÔNG BAO GIỜ im lặng: nó chỉ xảy ra khi một tiến trình chết giữa
            // chừng, và đó là thứ vận hành phải nhìn thấy.
            Log::warning('SKU_IMPORT_CLAIM_TAKEOVER', [
                'actor_id' => $actorId,
                'file_sha256' => $checksum,
                'identity_hash' => $identityHash,
                'claim_ttl_seconds' => SkuImportContract::claimTtlSeconds(),
            ]);

            return;
        }

        throw new ImportConcurrencyException(
            'Cùng file này đang được import với cùng tùy chọn; vui lòng đợi lần chạy hiện tại kết thúc rồi thử lại.',
            SkuImportContract::lockWaitSeconds(),
        );
    }

    /**
     * Trả chỗ giữ — chỉ trả CHỖ CỦA MÌNH (`owner_token`), để một request đã bị tiếp quản
     * không xoá mất chỗ giữ của người kế nhiệm.
     *
     * Lỗi ở bước này không được che mất kết quả thật của lần import (kể cả khi lần import
     * đang ném exception): chỗ giữ còn sót lại tự hết hạn, còn một exception ném ra từ
     * `finally` sẽ nuốt nguyên nhân gốc.
     */
    private function releaseImportIdentity(string $identityHash, string $ownerToken): void
    {
        try {
            DB::table('sku_import_claims')
                ->where('identity_hash', $identityHash)
                ->where('owner_token', $ownerToken)
                ->delete();
        } catch (Throwable $e) {
            Log::error('SKU_IMPORT_CLAIM_RELEASE_FAILED', [
                'identity_hash' => $identityHash,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function runImport(
        string $filePath,
        bool $updateExisting,
        ?string $actorId,
        ?string $requestId,
        ?string $originalName,
        bool $dryRun,
        string $checksum,
    ): array {
        $this->sheetName = null;

        try {
            $rows = $this->readRows($filePath);
        } catch (ImportRowLimitExceededException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Workbook Excel không đọc được hoặc đã hỏng.', previous: $e);
        }

        $header = array_shift($rows) ?? [];
        $map = $this->headerMap($header);
        $rows = array_values(array_filter($rows, fn (array $row): bool => collect($row)
            ->contains(fn (mixed $value): bool => trim((string) $value) !== '')));

        if ($rows === []) {
            throw new InvalidArgumentException('File import không có dòng dữ liệu.');
        }
        if (count($rows) > $this->maxRows()) {
            // WWHN-330 P1-02: exception riêng -> controller map error code chuẩn
            // 'SKU_IMPORT_ROW_LIMIT_EXCEEDED', không lộ stack trace, không 500.
            throw new ImportRowLimitExceededException(
                'File import vượt quá '.$this->maxRows().' dòng dữ liệu.',
                $this->maxRows(),
                count($rows),
            );
        }

        [$records, $errors] = $this->validateRows($rows, $map);
        if ($errors !== []) {
            // Lỗi ở tầng file/dòng phát hiện TRƯỚC transaction: không có gì để rollback,
            // nhưng Import báo cáo REJECTED vẫn là output bắt buộc — nó là thứ neo file
            // lỗi CSV mà người dùng tải về.
            return $this->withReport(
                $this->result(count($rows), count($records), $errors, 0, 0, false, $checksum, $map, $dryRun),
                $actorId,
                $requestId,
                $originalName,
                $updateExisting,
            );
        }

        return DB::transaction(function () use (
            $records, $rows, $updateExisting, $actorId, $requestId, $originalName, $checksum, $map, $dryRun
        ): array {
            $skuCodes = collect($records)->pluck('sku_code')->all();
            $existing = Sku::query()
                ->whereIn('sku_code', $skuCodes)
                ->lockForUpdate()
                ->get();
            $bySku = $existing->keyBy(fn (Sku $sku): string => strtoupper((string) $sku->sku_code));

            $dbErrors = [];
            foreach ($records as $index => $record) {
                /** @var Sku|null $matched */
                $matched = $bySku->get($record['sku_code']);
                if (! $matched) {
                    continue;
                }
                if (strtoupper((string) $matched->status) !== 'ACTIVE') {
                    $dbErrors[] = $this->error(
                        $index + 2,
                        'INACTIVE_SKU_CONFLICT',
                        'sku_code',
                        'SKU không ACTIVE, import không được tự kích hoạt.',
                        $record['sku_code'],
                    );
                } elseif (! $updateExisting) {
                    $dbErrors[] = $this->error(
                        $index + 2,
                        'DUPLICATE_CODE',
                        'sku_code',
                        'SKU đã tồn tại; cần bật update_existing.',
                        $record['sku_code'],
                    );
                }
            }

            if ($dbErrors !== []) {
                return $this->withReport(
                    $this->result(count($rows), count($records) - count($dbErrors), $dbErrors, 0, 0, false, $checksum, $map, $dryRun),
                    $actorId,
                    $requestId,
                    $originalName,
                    $updateExisting,
                );
            }

            $wouldInsert = 0;
            $wouldUpdate = 0;
            foreach ($records as $record) {
                if ($bySku->get($record['sku_code'])) {
                    $wouldUpdate++;
                } else {
                    $wouldInsert++;
                }
            }

            if ($dryRun) {
                AuditLogger::record(
                    'SKU_IMPORT_DRY_RUN_CHECK',
                    'sku_import',
                    null,
                    $actorId,
                    $requestId,
                    afterData: [
                        'file_name' => $originalName,
                        'file_sha256' => $checksum,
                        'template_version' => self::TEMPLATE_VERSION,
                        'update_existing' => $updateExisting,
                        'would_insert_rows' => $wouldInsert,
                        'would_update_rows' => $wouldUpdate,
                        'transaction_mode' => 'DRY_RUN_NO_WRITE',
                    ],
                    reason: 'WWHN-224 dry-run check, no business row written',
                );

                return $this->withReport(
                    $this->result(count($rows), count($records), [], $wouldInsert, $wouldUpdate, false, $checksum, $map, true),
                    $actorId,
                    $requestId,
                    $originalName,
                    $updateExisting,
                );
            }

            $inserted = 0;
            $updated = 0;
            foreach ($records as $record) {
                /** @var Sku|null $sku */
                $sku = $bySku->get($record['sku_code']);
                $before = $sku?->toArray() ?? [];
                $attributes = $record;
                if ($attributes['version_code'] === null) {
                    unset($attributes['version_code']);
                }

                if ($sku) {
                    $sku->fill($attributes)->save();
                    $updated++;
                    $action = 'SKU_IMPORT_UPDATE';
                } else {
                    $sku = Sku::query()->create($attributes);
                    $inserted++;
                    $action = 'SKU_IMPORT_CREATE';
                }

                AuditLogger::record(
                    $action,
                    'sku',
                    (string) $sku->sku_id,
                    $actorId,
                    $requestId,
                    beforeData: $before,
                    afterData: $sku->fresh()->toArray(),
                    reason: 'WWHN-172 atomic import '.$checksum,
                );

                // Ghi qua Eloquent trực tiếp trong service này, không qua
                // ProductRepository::create()/update() — tự invalidate detail + bump
                // version list cho mỗi dòng (mục 7: import xong API không được trả SKU
                // cũ). Bump lặp lại trong 1 transaction là an toàn (idempotent với
                // CacheVersion::bump — INCR nguyên tử), chỉ tốn thêm vài round-trip
                // Redis cho 1 thao tác admin không thường xuyên (tối đa 1000 dòng/lần).
                $this->products->forgetCache((string) $sku->sku_id);
            }

            AuditLogger::record(
                'SKU_IMPORT_COMMIT',
                'sku_import',
                null,
                $actorId,
                $requestId,
                afterData: [
                    'file_name' => $originalName,
                    'file_sha256' => $checksum,
                    'template_version' => self::TEMPLATE_VERSION,
                    'update_existing' => $updateExisting,
                    'inserted_rows' => $inserted,
                    'updated_rows' => $updated,
                    'transaction_mode' => 'ALL_OR_NOTHING',
                ],
                reason: 'WWHN-172 import summary in same transaction',
            );

            // DEV224-P1-05R — Import báo cáo được ghi Ở ĐÂY, tức bên trong đúng
            // transaction vừa ghi SKU + audit, và là hành động cuối cùng trước khi
            // commit. Nhờ vậy hai mệnh đề sau không bao giờ tách rời nhau được:
            // "SKU đã ghi" và "có báo cáo truy vết của lần ghi đó". Ghi báo cáo hỏng →
            // ném exception → transaction rollback → SKU/audit biến mất cùng nó, và
            // controller trả 503 thay vì 200 thiếu output.
            return $this->withReport(
                $this->result(count($rows), count($records), [], $inserted, $updated, true, $checksum, $map, false),
                $actorId,
                $requestId,
                $originalName,
                $updateExisting,
            );
        }, 3);
    }

    /**
     * DEV224-P1-05R — gắn Import báo cáo (output bắt buộc) vào kết quả import.
     *
     * Trả về chính `$result` kèm `import_report_id`. Không có nhánh "ghi không được thì
     * thôi": `ImportReportWriter::write()` ném `ImportReportPersistenceException`, và vì
     * mọi lời gọi ở nhánh có ghi dữ liệu đều nằm TRONG `DB::transaction()`, exception đó
     * kéo theo rollback toàn bộ SKU/audit của lần chạy.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withReport(
        array $result,
        ?string $actorId,
        ?string $requestId,
        ?string $originalName,
        bool $updateExisting,
    ): array {
        $report = $this->reportWriter->write($result + $this->reportContext, $actorId, $requestId, $originalName, $updateExisting);
        $result['import_report_id'] = (string) $report->import_report_id;
        $result['source_file_id'] = $report->source_file_id;
        $result['result_version'] = $report->result_version;

        return $result;
    }

    /**
     * WWHN-330 P1-03 + WWHN-331 FE331-P1-02R3: tìm Import báo cáo COMMIT gần nhất TRÙNG
     * ĐỦ ĐỊNH DANH trong cửa sổ idempotent. Định danh = `file_sha256 + actor_id +
     * options_fingerprint` (xem `warehouse.sku_import.idempotency_identity`, chốt tại
     * DEC-IMP-001).
     *
     * Scope theo actor (không phải toàn hệ thống) vì actor_id là ranh giới quyền hạn sẵn
     * có của import hiện tại — 2 actor khác nhau cùng nộp 1 file giống hệt nhau (vd. cùng
     * nhận từ NCC) vẫn là 2 lần import độc lập, tránh 1 actor "mượn" kết quả của actor
     * khác. Actor null (hệ thống/CLI) không được coi là trùng lặp để tránh false-positive
     * giữa các job không định danh actor.
     *
     * `options_fingerprint IS NULL` là bản ghi tạo TRƯỚC khi tùy chọn tham gia định danh
     * (migration 2026_08_18_190000). Với các bản ghi đó không thể biết lúc ấy chạy với
     * `update_existing` nào, nên giữ nguyên hành vi cũ — vẫn coi là trùng — thay vì đoán:
     * đoán sai theo hướng "không trùng" sẽ chạy lại một lần commit mà trước đó hệ thống đã
     * hứa là idempotent. Sau cửa sổ idempotent kể từ lúc deploy thì mọi bản ghi trong tầm
     * so sánh đều đã có fingerprint và nhánh này tự hết hiệu lực.
     */
    private function findRecentDuplicateReport(string $checksum, ?string $actorId, string $optionsFingerprint): ?ImportReport
    {
        if ($actorId === null) {
            return null;
        }

        try {
            return $this->queryRecentDuplicateReport($checksum, $actorId, $optionsFingerprint);
        } catch (Throwable $e) {
            // DEV224-P1-05R — `import_reports` không đọc được thì KHÔNG được chạy tiếp.
            //
            // Bảng này chính là bộ nhớ của cơ chế idempotent: mất nó thì "file này đã
            // import chưa" luôn trả lời là "chưa", nên một lần gửi lại bình thường của
            // người dùng sẽ import lần hai trên dữ liệu đã ghi đúng. Đây đúng là dạng
            // fail-open mà DEV330-P1-07R3 đã phải bỏ ở phía khoá — không được để nó
            // quay lại qua cửa sau là truy vấn báo cáo.
            Log::error('IMPORT_REPORT_LOOKUP_FAILED_FAIL_CLOSED', [
                'actor_id' => $actorId,
                'file_sha256' => $checksum,
                'exception' => $e::class,
            ]);

            throw new ImportReportPersistenceException(
                'Không truy vấn được Import báo cáo để kiểm tra trùng lặp; từ chối import để không ghi trùng.',
                previous: $e,
            );
        }
    }

    private function queryRecentDuplicateReport(string $checksum, string $actorId, string $optionsFingerprint): ?ImportReport
    {
        return ImportReport::query()
            ->where('import_type', 'SKU')
            ->where('mode', 'COMMIT')
            ->where('status', 'COMMITTED')
            ->where('file_sha256', $checksum)
            ->where('actor_id', $actorId)
            ->where(fn ($query) => $query
                ->where('options_fingerprint', $optionsFingerprint)
                ->orWhereNull('options_fingerprint'))
            ->where('created_at', '>=', now()->subHours($this->idempotencyWindowHours()))
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * WWHN-330 P1-03: dựng response cho một lần import bị coi là replay.
     *
     * Đồng thời đánh dấu bản ghi GỐC là đã từng bị phát lại (`duplicate_of_replay`).
     * Cột này được thêm ở migration 2026_08_18_180000 nhưng trước đó không nơi nào
     * ghi/đọc — schema chết. Nó chính là bằng chứng reconcile mà register yêu cầu
     * ("same file repeat ... counts/checksum/reconciliation"): QA/BA truy vấn
     * import_reports là biết ngay báo cáo nào đã được trả lại cho một lần gửi trùng,
     * không phải đối chiếu log ứng dụng. Đây là marker truy vết, KHÔNG đổi số liệu
     * nghiệp vụ (rows/SKU) của lần import gốc.
     *
     * @return array<string, mixed>
     */
    private function duplicateResult(ImportReport $report): array
    {
        if ($report->duplicate_of_replay !== true) {
            $report->forceFill(['duplicate_of_replay' => true])->save();
        }

        return [
            'template_version' => $report->template_version ?? self::TEMPLATE_VERSION,
            'transaction_mode' => 'ALL_OR_NOTHING',
            'dry_run' => false,
            'committed' => true,
            'partial_write' => false,
            'duplicate' => true,
            'duplicate_of_import_report_id' => $report->import_report_id,
            // FE331-P1-02R3: vân tay tùy chọn của LẦN IMPORT GỐC, để QA/BA đối soát được
            // rằng lần gửi này trùng đúng cả tùy chọn chứ không chỉ trùng nội dung file.
            'options_fingerprint' => $report->options_fingerprint,
            'file_sha256' => $report->file_sha256,
            'header_map' => [],
            'total_rows' => $report->total_rows,
            'valid_rows' => $report->valid_rows,
            'error_rows' => 0,
            'inserted_rows' => $report->inserted_rows,
            'updated_rows' => $report->updated_rows,
            'would_insert_rows' => 0,
            'would_update_rows' => 0,
            'skipped_rows' => 0,
            'row_errors' => [],
        ];
    }

    /**
     * WWHN-330 P1-02: đọc workbook theo LÔ dòng vật lý (mỗi lô MAX_PHYSICAL_ROWS_TO_READ)
     * qua read filter, thay vì IOFactory::load()->toArray() nạp toàn bộ sheet vào memory
     * không điều kiện. Với file vượt hạn mức rất nhiều (vd. 100k dòng), chỉ lô đầu được
     * nạp rồi ném ImportRowLimitExceededException ngay.
     *
     * WWHN-330 residual-01: lọc dòng trống NGAY trong vòng lặp đọc nên hạn mức được đếm
     * theo dòng DỮ LIỆU thật, không theo dòng vật lý — file hợp lệ (<= MAX_ROWS dòng dữ
     * liệu) nhưng có nhiều dòng trống xen giữa không còn bị cắt âm thầm ở dòng vật lý
     * thứ 1002 như trước. Phần đuôi file vượt MAX_PHYSICAL_ROWS_TO_SCAN được báo lỗi
     * tường minh chứ không bỏ qua im lặng.
     *
     * @return array<int, array<int, mixed>>
     */
    private function readRows(string $filePath): array
    {
        $reader = IOFactory::createReaderForFile($filePath);

        // Dimension của sheet CHỈ dùng để biết khi nào dừng quét, KHÔNG dùng để từ chối file:
        // Excel thường ghi totalRows phình to hơn số dòng có dữ liệu thật (dimension "ma" do
        // định dạng cũ để lại), nên chặn theo giá trị này sẽ từ chối nhầm file hợp lệ.
        try {
            $info = $reader->listWorksheetInfo($filePath);
            $totalPhysicalRows = (int) ($info[0]['totalRows'] ?? 0);
        } catch (Throwable) {
            $totalPhysicalRows = 0;
        }
        $lastRowToScan = $totalPhysicalRows > 0
            ? min($totalPhysicalRows, $this->maxPhysicalRowsToScan())
            : $this->maxPhysicalRowsToScan();

        $header = null;
        $dataRows = [];
        $scannedTo = 0;

        for ($start = 1; $start <= $lastRowToScan; $start += $this->maxPhysicalRowsToRead()) {
            $end = min($start + $this->maxPhysicalRowsToRead() - 1, $lastRowToScan);

            $reader->setReadFilter(new class($start, $end) implements IReadFilter
            {
                public function __construct(private readonly int $firstRow, private readonly int $lastRow) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row >= $this->firstRow && $row <= $this->lastRow;
                }
            });

            $sheet = $reader->load($filePath)->getActiveSheet();
            $this->sheetName ??= $sheet->getTitle();
            $highestColumn = $sheet->getHighestDataColumn();
            /** @var array<int, array<int, mixed>> $chunk */
            $chunk = $sheet->rangeToArray('A'.$start.':'.$highestColumn.$end, null, true, true, false);
            unset($sheet);

            foreach ($chunk as $offset => $row) {
                if ($start + $offset === 1) {
                    $header = $row;

                    continue;
                }
                $isBlank = ! collect($row)
                    ->contains(fn (mixed $value): bool => trim((string) $value) !== '');
                if ($isBlank) {
                    continue;
                }
                $dataRows[] = $row;
                // Dừng ngay khi vượt hạn mức: memory chỉ giữ tối đa MAX_ROWS + 1 dòng dữ liệu.
                if (count($dataRows) > $this->maxRows()) {
                    throw new ImportRowLimitExceededException(
                        'File import vượt quá '.$this->maxRows().' dòng dữ liệu.',
                        $this->maxRows(),
                        count($dataRows),
                    );
                }
            }
            unset($chunk);

            $scannedTo = $end;
        }

        // Không cắt âm thầm: còn dòng vật lý chưa quét tới thì báo lỗi rõ ràng.
        if ($totalPhysicalRows > $scannedTo) {
            throw new ImportRowLimitExceededException(
                'File import trải dài quá '.$this->maxPhysicalRowsToScan()
                .' dòng bảng tính (kể cả dòng trống); vui lòng xoá bớt dòng trống thừa rồi import lại.',
                $this->maxRows(),
                count($dataRows),
            );
        }

        return array_merge([$header ?? []], $dataRows);
    }

    public function createTemplate(string $path): void
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray(self::TEMPLATE_COLUMNS, null, 'A1');
        $sheet->fromArray([
            'SKU-DEMO-001', 'Sản phẩm mẫu', 'cái', 'PRODUCT',
            null, null, null, '220V', 'BARE', 'V1', 'false', 'false', 'ACTIVE',
        ], null, 'A2');
        (new Xlsx($sheet->getParent()))->save($path);
    }

    /**
     * DEV330-P1-02R3 — hàng rào thứ hai cho cùng một hạn mức, đọc từ cùng một hợp đồng.
     *
     * Trước đây lớp này giữ bản sao riêng của hạn mức: `10 * 1024 * 1024` và
     * `['xlsx', 'xls']` viết cứng, trong khi validator của controller đã chuyển sang đọc
     * `warehouse.sku_import`. Hai bản sao của cùng một hạn mức luôn là một lỗi chờ xảy
     * ra: nâng `max_file_size_kb` lên 20 MB thì HTTP cho file qua còn service vẫn chặn ở
     * 10 MB, và thông điệp lỗi nói một con số không còn đúng. Nay cả hai tầng đọc đúng
     * một nguồn, nên không thể nói khác nhau.
     *
     * Vẫn giữ kiểm tra ở tầng service (không chỉ dựa vào validator HTTP) vì `import()`
     * còn được gọi từ CLI/job — nơi không đi qua request validation nào.
     */
    private function assertFile(string $filePath): void
    {
        if (! is_file($filePath)) {
            throw new InvalidArgumentException('Không tìm thấy file import.');
        }

        $allowed = SkuImportContract::allowedExtensions();
        if (! in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), $allowed, true)) {
            throw new InvalidArgumentException('Chỉ chấp nhận file '.implode(' hoặc ', $allowed).'.');
        }

        $maxBytes = SkuImportContract::maxFileSizeBytes();
        if (($size = filesize($filePath)) === false || $size > $maxBytes) {
            throw new InvalidArgumentException(
                'File import vượt quá '.round($maxBytes / (1024 * 1024), 2).'MB.'
            );
        }
    }

    /** @return array<string, int> */
    private function headerMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $name = strtolower(trim((string) $header));
            if ($name === '') {
                continue;
            }
            if (! in_array($name, self::ACCEPTED_COLUMNS, true) || isset($map[$name])) {
                throw new InvalidArgumentException(isset($map[$name]) ? "Cột bị lặp: {$name}." : "Cột không hỗ trợ: {$name}.");
            }
            $map[$name] = (int) $index;
        }

        if (! isset($map['sku_code']) && ! isset($map['code'])) {
            throw new InvalidArgumentException('Thiếu cột bắt buộc: sku_code (hoặc alias legacy code).');
        }
        if (! isset($map['sku_name']) && ! isset($map['name'])) {
            throw new InvalidArgumentException('Thiếu cột bắt buộc: sku_name (hoặc alias legacy name).');
        }

        return $map;
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    private function validateRows(array $rows, array $map): array
    {
        $records = [];
        $errors = [];
        $seenSku = [];

        foreach ($rows as $offset => $row) {
            $number = $offset + 2;
            $get = fn (string $column): mixed => isset($map[$column]) ? ($row[$map[$column]] ?? null) : null;
            $skuCode = $this->upper($get('sku_code')) ?? $this->upper($get('code')) ?? '';
            $skuName = $this->text($get('sku_name')) ?? $this->text($get('name')) ?? '';
            $rowErrors = [];

            if ($skuCode === '' || mb_strlen($skuCode) > 100 || ! preg_match('/^[A-Z0-9](?:[A-Z0-9._-]*[A-Z0-9])?$/', $skuCode)) {
                $rowErrors[] = $this->error($number, 'INVALID_CODE_FORMAT', 'sku_code', 'sku_code không hợp lệ.', $skuCode);
            }
            if ($skuName === '' || mb_strlen($skuName) > 255) {
                $rowErrors[] = $this->error($number, 'INVALID_NAME', 'sku_name', 'sku_name bắt buộc và tối đa 255 ký tự.', $skuName);
            }
            if (isset($seenSku[$skuCode])) {
                $rowErrors[] = $this->error($number, 'DUPLICATE_SKU_CODE_IN_FILE', 'sku_code', "Trùng dòng {$seenSku[$skuCode]}.", $skuCode);
            }

            $skuType = $this->enum($get('sku_type'), ['PRODUCT', 'COMPONENT'], 'PRODUCT', $number, 'sku_type', $rowErrors);
            $status = $this->enum($get('status'), ['ACTIVE', 'INACTIVE'], 'ACTIVE', $number, 'status', $rowErrors);
            $package = $this->nullableEnum($get('package_variant'), $this->allowedPackageVariants(), $number, 'package_variant', $rowErrors);
            $published = $this->boolean($get('published_to_app'), $number, 'published_to_app', $rowErrors);
            $serial = $this->boolean($get('is_serial_tracked'), $number, 'is_serial_tracked', $rowErrors);
            $versionCode = $this->text($get('version_code')) ?? $this->text($get('version'));
            if ($versionCode !== null && mb_strlen($versionCode) > 30) {
                $rowErrors[] = $this->error($number, 'INVALID_VERSION_CODE', 'version_code', 'version_code tối đa 30 ký tự.', $versionCode);
            }

            $brandId = $this->resolveMasterId($get('brand_code'), 'BRAND', $number, 'brand_code', $rowErrors);
            $categoryId = $this->resolveMasterId($get('category_code'), 'CATEGORY', $number, 'category_code', $rowErrors);
            $modelId = $this->resolveMasterId($get('model_code'), 'MODEL', $number, 'model_code', $rowErrors);

            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);

                continue;
            }

            $seenSku[$skuCode] = $number;
            $records[] = [
                'sku_code' => $skuCode,
                'sku_name' => $skuName,
                'unit' => $this->text($get('unit')) ?? 'cái',
                'sku_type' => $skuType,
                'brand_id' => $brandId,
                'category_id' => $categoryId,
                'model_id' => $modelId,
                'power_or_voltage' => $this->text($get('power_or_voltage')),
                'package_variant' => $package,
                'version_code' => $versionCode,
                'published_to_app' => $published,
                'is_serial_tracked' => $serial,
                'status' => $status,
            ];
        }

        return [$records, $errors];
    }

    private function resolveMasterId(mixed $value, string $type, int $row, string $field, array &$errors): ?string
    {
        $code = $this->upper($value);
        if ($code === null) {
            return null;
        }

        $master = ProductMaster::query()
            ->where('master_type', $type)
            ->where('code', $code)
            ->where('status', 'ACTIVE')
            ->first();

        if (! $master) {
            $errors[] = $this->error($row, 'UNKNOWN_MASTER_CODE', $field, "Không tìm thấy {$type} ACTIVE với code {$code}.", $code);

            return null;
        }

        return (string) $master->master_id;
    }

    private function enum(mixed $value, array $allowed, string $default, int $row, string $field, array &$errors): string
    {
        $value = strtoupper(trim((string) $value));
        if ($value === '') {
            return $default;
        }
        if (! in_array($value, $allowed, true)) {
            $errors[] = $this->error($row, 'INVALID_ENUM', $field, $field.' phải thuộc '.implode(', ', $allowed).'.', $value);

            return $default;
        }

        return $value;
    }

    /**
     * AC-SRS-029 "thêm loại không cần code change": quy cách đóng gói hợp lệ đọc
     * từ master package_variants. Master rỗng (fixture test tối giản) thì rơi về
     * bộ mã baseline để import cũ không vỡ.
     *
     * @return array<int, string>
     */
    private function allowedPackageVariants(): array
    {
        $codes = DB::table('package_variants')
            ->where('status', 'ACTIVE')
            ->pluck('package_code')
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $codes === [] ? ['BARE', 'KIT', 'SET', 'BODY_ONLY'] : $codes;
    }

    private function nullableEnum(mixed $value, array $allowed, int $row, string $field, array &$errors): ?string
    {
        $value = strtoupper(trim((string) $value));
        if ($value === '') {
            return null;
        }
        if (! in_array($value, $allowed, true)) {
            $errors[] = $this->error($row, 'INVALID_ENUM', $field, $field.' phải thuộc '.implode(', ', $allowed).'.', $value);

            return null;
        }

        return $value;
    }

    private function boolean(mixed $value, int $row, string $field, array &$errors): bool
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || in_array($value, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }
        if (in_array($value, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }
        $errors[] = $this->error($row, 'INVALID_BOOLEAN', $field, $field.' không hợp lệ.', $value);

        return false;
    }

    private function upper(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function result(int $total, int $valid, array $errors, int $inserted, int $updated, bool $committed, string $checksum, array $map, bool $dryRun): array
    {
        return [
            'template_version' => self::TEMPLATE_VERSION,
            'transaction_mode' => $dryRun ? 'DRY_RUN_NO_WRITE' : 'ALL_OR_NOTHING',
            'dry_run' => $dryRun,
            'committed' => $committed,
            'partial_write' => false,
            'duplicate' => false,
            'file_sha256' => $checksum,
            // SRS §13.12: file lỗi phải nêu được sheet nguồn của từng dòng sai.
            'sheet_name' => $this->sheetName,
            'header_map' => $map,
            'total_rows' => $total,
            'valid_rows' => $valid,
            'error_rows' => count($errors),
            'inserted_rows' => $dryRun ? 0 : $inserted,
            'updated_rows' => $dryRun ? 0 : $updated,
            'would_insert_rows' => $dryRun ? $inserted : 0,
            'would_update_rows' => $dryRun ? $updated : 0,
            'skipped_rows' => count($errors),
            'row_errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    private function error(int $row, string $code, string $field, string $message, mixed $value): array
    {
        return compact('row', 'code', 'field', 'message', 'value');
    }
}
