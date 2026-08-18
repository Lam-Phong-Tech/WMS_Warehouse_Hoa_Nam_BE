<?php

namespace Modules\Warehouse\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Exceptions\ImportRowLimitExceededException;
use Modules\Warehouse\Models\ImportReport;
use Modules\Warehouse\Models\ProductMaster;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Repositories\Contracts\ProductRepositoryInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class ImportService
{
    public function __construct(private readonly ProductRepositoryInterface $products) {}

    public const TEMPLATE_VERSION = 'WWHN-172-SKU-IMPORT-v1.1.0';

    /**
     * WWHN-330 P1-02: giới hạn số dòng dữ liệu mỗi lần import (không tính header).
     * Test hiện có (tests/Feature/ImportBatchTest.php, Wwhn174SkuImportValidationTest,
     * Wwhn224SkuImportDryRunTest) không cố định giá trị này bằng số, nên giữ nguyên 1000
     * để không đổi hành vi nghiệp vụ hiện tại; đổi giá trị cần BA/Tech Lead chốt chính thức.
     */
    private const MAX_ROWS = 1000;

    /**
     * WWHN-330 P1-03: idempotent replay window — 2 lần import cùng nội dung file
     * (cùng sha256) và cùng actor trong khoảng thời gian này được coi là "đã xử lý",
     * trả lại kết quả Import báo cáo cũ thay vì chạy lại. Giá trị 24h là lựa chọn tạm,
     * cần BA/Tech Lead chốt chính thức phạm vi (theo actor hay theo toàn hệ thống, và
     * cửa sổ thời gian bao lâu).
     */
    private const IDEMPOTENCY_WINDOW_HOURS = 24;

    /** WWHN-330 residual-02: TTL của lock chống 2 request COMMIT trùng nhau chạy song song. */
    private const LOCK_TTL_SECONDS = 120;

    /** WWHN-330 residual-02: thời gian chờ tối đa để giành lock trước khi fail-open. */
    private const LOCK_WAIT_SECONDS = 15;

    /**
     * WWHN-330 P1-02: số dòng vật lý tối đa thực sự đọc vào memory qua PhpSpreadsheet
     * read filter (header + MAX_ROWS + 1 dòng dư để phát hiện vượt hạn mức), tránh nạp
     * toàn bộ workbook lớn (vd. file lỗi 100k dòng) vào memory chỉ để rồi từ chối.
     */
    private const MAX_PHYSICAL_ROWS_TO_READ = self::MAX_ROWS + 2;

    /**
     * WWHN-330 residual-01: trần số dòng VẬT LÝ được quét (kể cả dòng trống) trước khi
     * dừng và báo lỗi tường minh. Bảo đảm file "bloated" (nhiều dòng trống xen giữa) vẫn
     * đọc được tới đủ MAX_ROWS dòng dữ liệu thật, nhưng không quét vô hạn.
     */
    private const MAX_PHYSICAL_ROWS_TO_SCAN = 20 * self::MAX_PHYSICAL_ROWS_TO_READ;

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
    ): array {
        $this->assertFile($filePath);
        $checksum = hash_file('sha256', $filePath);
        if (! is_string($checksum)) {
            throw new InvalidArgumentException('Không tính được SHA-256 của file import.');
        }

        // WWHN-330 P1-03: file trùng sha256 với 1 lần COMMIT gần đây của cùng actor thì
        // coi là replay — trả lại kết quả cũ, không đọc/parse lại file, không ghi SKU lần 2.
        //
        // WWHN-330 residual-02: bọc toàn bộ "kiểm tra trùng + chạy import" trong 1 atomic
        // lock theo (actor, sha256) để 2 request COMMIT giống hệt nhau gửi gần như đồng thời
        // không cùng lọt qua bước kiểm tra (race window cũ tạo 2 dòng import_reports COMMITTED
        // cho cùng 1 file). Lock cấp ứng dụng, không đổi schema và không đổi ngữ nghĩa cửa sổ
        // IDEMPOTENCY_WINDOW_HOURS (unique index DB sẽ chặn vĩnh viễn, sai nghiệp vụ).
        if (! $dryRun && $actorId !== null) {
            return $this->withImportLock($checksum, $actorId, function () use (
                $checksum, $filePath, $updateExisting, $actorId, $requestId, $originalName, $dryRun
            ): array {
                $duplicate = $this->findRecentDuplicateReport($checksum, $actorId);
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
     * WWHN-330 residual-02: serialize các lần import COMMIT cùng (actor, sha256).
     * Chờ tối đa LOCK_WAIT_SECONDS; hết thời gian chờ vẫn chạy tiếp (fail-open) để một
     * sự cố cache không chặn nghiệp vụ import — mất tính atomic ở đúng ca hiếm đó, đã ghi
     * rõ thay vì ném lỗi mà người dùng không hiểu.
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function withImportLock(string $checksum, string $actorId, callable $callback): array
    {
        $lock = Cache::lock('sku-import:'.$actorId.':'.$checksum, self::LOCK_TTL_SECONDS);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return $callback();
        }

        try {
            return $callback();
        } finally {
            $lock->release();
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
        if (count($rows) > self::MAX_ROWS) {
            // WWHN-330 P1-02: exception riêng -> controller map error code chuẩn
            // 'SKU_IMPORT_ROW_LIMIT_EXCEEDED', không lộ stack trace, không 500.
            throw new ImportRowLimitExceededException(
                'File import vượt quá '.self::MAX_ROWS.' dòng dữ liệu.',
                self::MAX_ROWS,
                count($rows),
            );
        }

        [$records, $errors] = $this->validateRows($rows, $map);
        if ($errors !== []) {
            return $this->result(count($rows), count($records), $errors, 0, 0, false, $checksum, $map, $dryRun);
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
                return $this->result(count($rows), count($records) - count($dbErrors), $dbErrors, 0, 0, false, $checksum, $map, $dryRun);
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

                return $this->result(count($rows), count($records), [], $wouldInsert, $wouldUpdate, false, $checksum, $map, true);
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

            return $this->result(count($rows), count($records), [], $inserted, $updated, true, $checksum, $map, false);
        }, 3);
    }

    /**
     * WWHN-330 P1-03: tìm Import báo cáo COMMIT gần nhất cùng sha256 + cùng actor trong
     * cửa sổ IDEMPOTENCY_WINDOW_HOURS. Scope theo actor (không phải toàn hệ thống) vì
     * request_id/actor_id là ranh giới quyền hạn sẵn có của import hiện tại — 2 actor khác
     * nhau cùng nộp 1 file giống hệt nhau (vd. cùng nhận từ NCC) vẫn được coi là 2 lần import
     * độc lập, tránh 1 actor "mượn" kết quả import của actor khác. Actor null (hệ thống/CLI)
     * không được coi là trùng lặp để tránh false-positive giữa các job không định danh actor.
     */
    private function findRecentDuplicateReport(string $checksum, ?string $actorId): ?ImportReport
    {
        if ($actorId === null) {
            return null;
        }

        return ImportReport::query()
            ->where('import_type', 'SKU')
            ->where('mode', 'COMMIT')
            ->where('status', 'COMMITTED')
            ->where('file_sha256', $checksum)
            ->where('actor_id', $actorId)
            ->where('created_at', '>=', now()->subHours(self::IDEMPOTENCY_WINDOW_HOURS))
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
            ? min($totalPhysicalRows, self::MAX_PHYSICAL_ROWS_TO_SCAN)
            : self::MAX_PHYSICAL_ROWS_TO_SCAN;

        $header = null;
        $dataRows = [];
        $scannedTo = 0;

        for ($start = 1; $start <= $lastRowToScan; $start += self::MAX_PHYSICAL_ROWS_TO_READ) {
            $end = min($start + self::MAX_PHYSICAL_ROWS_TO_READ - 1, $lastRowToScan);

            $reader->setReadFilter(new class($start, $end) implements IReadFilter
            {
                public function __construct(private readonly int $firstRow, private readonly int $lastRow) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row >= $this->firstRow && $row <= $this->lastRow;
                }
            });

            $sheet = $reader->load($filePath)->getActiveSheet();
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
                if (count($dataRows) > self::MAX_ROWS) {
                    throw new ImportRowLimitExceededException(
                        'File import vượt quá '.self::MAX_ROWS.' dòng dữ liệu.',
                        self::MAX_ROWS,
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
                'File import trải dài quá '.self::MAX_PHYSICAL_ROWS_TO_SCAN
                .' dòng bảng tính (kể cả dòng trống); vui lòng xoá bớt dòng trống thừa rồi import lại.',
                self::MAX_ROWS,
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

    private function assertFile(string $filePath): void
    {
        if (! is_file($filePath)) {
            throw new InvalidArgumentException('Không tìm thấy file import.');
        }
        if (! in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
            throw new InvalidArgumentException('Chỉ chấp nhận file xlsx hoặc xls.');
        }
        if (($size = filesize($filePath)) === false || $size > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('File import vượt quá 10MB.');
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
