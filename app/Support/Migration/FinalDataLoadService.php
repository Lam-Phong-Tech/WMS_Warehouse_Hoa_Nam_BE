<?php

namespace App\Support\Migration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Models\Agency;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\ImportBatch;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use RuntimeException;

/**
 * Lần nạp dữ liệu cuối (final data load) cho cutover — REQ-SRS-021 / AC-SRS-021,
 * DEC-MIG-API-001, SRS §13.12, NFR-SRS-017, ERD bảng 58-59.
 *
 * Nguyên tắc fail-closed đã được hiện thực hoá ở đây:
 *   - DRY_RUN không ghi bất kỳ bảng nghiệp vụ nào; chỉ ghi migration_runs/migration_errors.
 *   - COMMIT chỉ chạy khi đã có DRY_RUN PASSED cùng run code và cùng source_checksum.
 *   - COMMIT là một transaction duy nhất; reconciliation FAIL rollback toàn bộ nên
 *     không tồn tại trạng thái "một phần master/opening/movement".
 *   - Rerun cùng run code + cùng checksum là idempotent (replay báo cáo đã lưu).
 *   - File đổi sau dry-run làm checksum khác và bị chặn bằng CHECKSUM_CHANGED.
 *
 * Luồng này không có public/admin endpoint (DEC-MIG-API-001) — chỉ console job.
 */
class FinalDataLoadService
{
    private const STOCK_STATUSES = ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'];

    private const SKU_TYPES = ['PRODUCT', 'COMPONENT'];

    private const CARRIER_TYPES = ['BARCODE', 'QR', 'RFID', 'NFC', 'MANUAL'];

    private const CODE_TYPES = ['ITEM', 'CONTAINER', 'SKU'];

    private const PHYSICAL_CODE_STATUSES = ['ACTIVE', 'INACTIVE', 'LOST', 'DAMAGED'];

    private const CONTAINER_TYPES = ['CARTON', 'BOX', 'TRAY'];

    private const CONTAINER_STATUSES = ['EMPTY', 'IN_STOCK', 'PARTIAL', 'ISSUED', 'RECEIVED_PENDING_PUTAWAY', 'STORED', 'CLOSED'];

    private const ITEM_CONDITIONS = ['NORMAL', 'DEMO', 'DEFECTIVE', 'WARRANTY_RETURN', 'NON_SELLABLE'];

    private const MASTER_STATUSES = ['ACTIVE', 'INACTIVE'];

    /**
     * Dry-run: validate toàn bộ package; không đổi dữ liệu nghiệp vụ.
     *
     * @return array<string, mixed>
     */
    public function dryRun(ImportPackage $package, string $runCode, string $executedBy, ?string $note = null): array
    {
        $this->resetLookupCache();

        $existing = $this->findRun($runCode, 'DRY_RUN');
        if ($existing !== null) {
            return $this->replayGuard($existing, $package->checksum);
        }

        $run = $this->startRun($runCode, 'DRY_RUN', $package, $executedBy, $note);
        $errors = new MigrationErrorBag;
        $resolved = $this->validate($package, $errors);
        $reconciliation = $this->sourceReconciliation($package, $resolved, $errors);

        $this->persistErrors($run, $errors);
        $counts = $this->countRows($package, $errors);
        $status = $errors->hasBlocking() || $reconciliation['status'] !== 'PASS' ? 'FAILED' : 'PASSED';

        $this->finishRun($run, $status, $counts, $reconciliation);
        $this->audit('MIGRATION_DRY_RUN_COMPLETED', $run, $runCode, $executedBy, $status, $counts, $reconciliation);

        return $this->report($run, $runCode, 'DRY_RUN', $status, $package, $counts, $reconciliation, $errors->all());
    }

    /**
     * Commit: chỉ chạy khi dry-run cùng checksum đã PASSED và có approver (data owner).
     *
     * @return array<string, mixed>
     */
    public function commit(
        ImportPackage $package,
        string $runCode,
        string $executedBy,
        string $approvedBy,
        ?string $note = null,
        ?string $approvalRef = null
    ): array {
        $this->resetLookupCache();

        $existing = $this->findRun($runCode, 'COMMIT');
        if ($existing !== null) {
            return $this->replayGuard($existing, $package->checksum);
        }

        $dryRun = $this->findRun($runCode, 'DRY_RUN');
        if ($dryRun === null || $dryRun->status !== 'PASSED') {
            return $this->blockedReport($runCode, 'COMMIT', 'DRY_RUN_NOT_PASSED',
                'COMMIT bị chặn: chưa có DRY_RUN ở trạng thái PASSED cho run code này.');
        }
        if ($dryRun->source_checksum !== $package->checksum) {
            return $this->blockedReport($runCode, 'COMMIT', 'CHECKSUM_CHANGED',
                'COMMIT bị chặn: package đã thay đổi sau dry-run; phải chạy lại DRY_RUN với run code mới.');
        }

        // DEV260-P1-06: idempotency theo run code không đủ để chặn double effect.
        // Cùng một package chạy lại dưới run code KHÁC sẽ sinh business_key khác
        // và cộng tồn đầu lần thứ hai. Cutover chỉ được nạp một lần cho mỗi
        // checksum; muốn nạp lại phải rollback lần trước.
        $committed = $this->committedRunForChecksum($package->checksum, $runCode);
        if ($committed !== null) {
            return $this->blockedReport($runCode, 'COMMIT', 'PACKAGE_ALREADY_COMMITTED',
                "COMMIT bị chặn: package (checksum {$package->checksum}) đã được nạp ở run {$committed->migration_run_code} và chưa rollback.");
        }

        $run = $this->startRun($runCode, 'COMMIT', $package, $executedBy, $this->noteWithApproval($note, $approvalRef), $approvedBy);
        $errors = new MigrationErrorBag;
        $resolved = $this->validate($package, $errors);

        if ($errors->hasBlocking()) {
            $this->persistErrors($run, $errors);
            $counts = $this->countRows($package, $errors);
            $reconciliation = ['status' => 'FAIL', 'scope' => 'COMMITTED', 'checks' => [], 'reason' => 'VALIDATION_FAILED'];
            $this->finishRun($run, 'FAILED', $counts, $reconciliation);
            $this->audit('MIGRATION_COMMIT_BLOCKED', $run, $runCode, $executedBy, 'FAILED', $counts, $reconciliation);

            return $this->report($run, $runCode, 'COMMIT', 'FAILED', $package, $counts, $reconciliation, $errors->all());
        }

        try {
            $reconciliation = DB::transaction(function () use ($package, $run, $resolved, $executedBy): array {
                // DEV299-P1-01: mọi lần chạm master đều để lại snapshot trước/sau
                // trong cùng transaction, nên rollback có đường quay lại thật.
                $journal = new MasterStateJournal($run, $executedBy);
                $this->writeMasters($package, $run, $resolved, $journal);
                $this->writeOpeningBalance($package, $run, $executedBy, $journal);
                $result = $this->committedReconciliation($package, $run, $resolved);
                if ($result['status'] !== 'PASS') {
                    throw new MigrationReconciliationException($result);
                }

                return $result;
            });
        } catch (MigrationReconciliationException $failure) {
            $reconciliation = $failure->result;
            foreach ($reconciliation['checks'] as $check) {
                if (($check['status'] ?? 'PASS') === 'PASS') {
                    continue;
                }
                $errors->add('OPENING_BALANCE', null, null, 'RECON_MISMATCH', 'CRITICAL',
                    (string) $check['message'], null,
                    'Đối chiếu lại nguồn opening balance đã duyệt rồi chạy lại dry-run.');
            }
            $this->persistErrors($run, $errors);
            $counts = $this->countRows($package, $errors);
            $this->finishRun($run, 'FAILED', $counts, $reconciliation);
            $this->audit('MIGRATION_COMMIT_ROLLED_BACK', $run, $runCode, $executedBy, 'FAILED', $counts, $reconciliation);

            return $this->report($run, $runCode, 'COMMIT', 'FAILED', $package, $counts, $reconciliation, $errors->all());
        }

        $counts = $this->countRows($package, $errors);
        $this->persistErrors($run, $errors);
        $this->finishRun($run, 'COMMITTED', $counts, $reconciliation);
        $this->audit('MIGRATION_COMMIT_COMPLETED', $run, $runCode, $executedBy, 'COMMITTED', $counts, $reconciliation);

        return $this->report($run, $runCode, 'COMMIT', 'COMMITTED', $package, $counts, $reconciliation, $errors->all());
    }

    /**
     * Rollback: đảo hiệu ứng tồn kho của một COMMIT bằng REVERSAL append-only
     * (DEC-REV-001 — stock_movements không được sửa/xoá) và đóng master do run tạo ra.
     *
     * @return array<string, mixed>
     */
    public function rollback(string $runCode, string $executedBy, ?string $note = null): array
    {
        $target = $this->findRun($runCode, 'COMMIT');
        if ($target === null || $target->status !== 'COMMITTED') {
            return $this->blockedReport($runCode, 'ROLLBACK', 'COMMIT_NOT_FOUND',
                'ROLLBACK bị chặn: không có COMMIT ở trạng thái COMMITTED cho run code này.');
        }

        $existing = $this->findRun($runCode, 'ROLLBACK');
        if ($existing !== null && $existing->status === 'ROLLED_BACK') {
            return [
                'migration_run_id' => $existing->id,
                'migration_run_code' => $existing->migration_run_code,
                'mode' => 'ROLLBACK',
                'status' => 'ROLLED_BACK',
                'reconciliation' => json_decode((string) $existing->reconciliation_result, true) ?: [],
                'replayed' => true,
            ];
        }

        $runId = (string) Str::uuid();
        DB::table('migration_runs')->insert([
            'id' => $runId,
            'migration_run_code' => $this->runCode($runCode, 'ROLLBACK'),
            'package_version' => $target->package_version,
            'source_file_name' => $target->source_file_name,
            'source_checksum' => $target->source_checksum,
            'mode' => 'ROLLBACK',
            'status' => 'RUNNING',
            'total_rows' => 0,
            'valid_rows' => 0,
            'error_rows' => 0,
            'reconciliation_status' => 'PENDING',
            'started_at' => now(),
            'executed_by' => $executedBy,
            'rollback_of_run_id' => $target->id,
            'note' => $note,
            'created_at' => now(),
        ]);

        $result = DB::transaction(function () use ($target, $runId, $executedBy): array {
            $reversed = $this->reverseOpeningMovements((string) $target->id, $runId, $executedBy);
            // DEV299-P1-01: khôi phục master bị package ghi đè và loại bỏ master
            // do run tạo mới. SRS §13.12 đòi chứng minh "no subset master/
            // opening/movement remains", không phải chỉ đóng trạng thái.
            $masters = MasterStateJournal::restore((string) $target->id);
            $disposed = array_map(
                static fn (array $row): string => $row['table'].'|'.$row['id'],
                $masters['disposed_rows']
            );
            $residual = $this->residualOpeningQuantity((string) $target->id);
            $residualMasters = MasterStateJournal::residualCreatedRows((string) $target->id, $disposed)
                + $this->residualMigrationTaggedRows((string) $target->id, $disposed);
            $clean = $residual === 0
                && $residualMasters === 0
                && $masters['retained_rows'] === []
                && $masters['missing_rows'] === [];

            return [
                'status' => $clean ? 'PASS' : 'FAIL',
                'scope' => 'ROLLBACK',
                'reversed_movements' => $reversed,
                'master_rollback' => $masters,
                'residual_opening_qty' => $residual,
                'residual_master_rows' => $residualMasters,
            ];
        });

        $status = $result['status'] === 'PASS' ? 'ROLLED_BACK' : 'FAILED';
        DB::table('migration_runs')->where('id', $runId)->update([
            'status' => $status,
            'reconciliation_status' => $result['status'],
            'reconciliation_result' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'finished_at' => now(),
        ]);
        if ($status === 'ROLLED_BACK') {
            DB::table('migration_runs')->where('id', $target->id)->update(['status' => 'ROLLED_BACK']);
        }

        AuditLogger::record(
            action: 'MIGRATION_ROLLBACK_COMPLETED',
            objectType: 'migration_run',
            objectId: $runId,
            actorId: $executedBy,
            afterData: ['migration_run_code' => $this->runCode($runCode, 'ROLLBACK'), 'status' => $status] + $result,
            reason: 'Rollback lần nạp dữ liệu cuối theo REQ-SRS-021.',
        );

        return [
            'migration_run_id' => $runId,
            'migration_run_code' => $this->runCode($runCode, 'ROLLBACK'),
            'mode' => 'ROLLBACK',
            'status' => $status,
            'reconciliation' => $result,
            'replayed' => false,
        ];
    }

    // ------------------------------------------------------------------ validate

    /**
     * @return array<string, array<string, mixed>>
     */
    private function validate(ImportPackage $package, MigrationErrorBag $errors): array
    {
        $resolved = ['agency' => [], 'sku' => [], 'sku_serial' => [], 'physical_code' => [], 'container' => [], 'item' => []];

        foreach ($package->datasets() as $dataset) {
            $seen = [];
            foreach ($package->rows($dataset) as $row) {
                $this->validateRow($dataset, $row['row_no'], $row['data'], $errors, $resolved, $seen);
            }
        }

        return $resolved;
    }

    private function validateRow(
        string $dataset,
        int $rowNo,
        array $data,
        MigrationErrorBag $errors,
        array &$resolved,
        array &$seen
    ): void {
        $required = fn (string $field): bool => $this->requireField($dataset, $rowNo, $data, $field, $errors);
        $enum = fn (string $field, array $allowed): bool => $this->requireEnum($dataset, $rowNo, $data, $field, $allowed, $errors);
        $duplicate = function (string $field, string $key) use ($dataset, $rowNo, $data, $errors, &$seen): bool {
            if ($key === '') {
                return false;
            }
            if (! isset($seen[$field][$key])) {
                $seen[$field][$key] = $rowNo;

                return false;
            }
            $errors->add($dataset, $rowNo, $field, 'DUPLICATE_CODE', 'CRITICAL',
                "Giá trị {$field} bị lặp trong package (đã xuất hiện ở dòng {$seen[$field][$key]}).",
                $data[$field] ?? null, 'Loại bỏ dòng trùng hoặc hợp nhất trước khi chạy lại dry-run.');

            return true;
        };
        $reference = fn (string $field, string $code, callable $exists, bool $mandatory): bool => $this->requireReference(
            $dataset, $rowNo, $data, $field, $code, $exists, $errors, $mandatory
        );

        switch ($dataset) {
            case 'AGENCY':
                $ok = $required('agency_code');
                $ok = $required('agency_name') && $ok;
                $ok = $enum('status', self::MASTER_STATUSES) && $ok;
                $ok = ! $duplicate('agency_code', $data['agency_code'] ?? '') && $ok;
                if ($ok) {
                    $resolved['agency'][$data['agency_code']] = true;
                }
                break;

            case 'SKU':
                $ok = $required('sku_code');
                $ok = $required('sku_name') && $ok;
                $ok = $required('unit') && $ok;
                $ok = $required('version_code') && $ok;
                $ok = $enum('sku_type', self::SKU_TYPES) && $ok;
                $ok = $enum('status', self::MASTER_STATUSES) && $ok;
                $ok = $this->requireBoolean($dataset, $rowNo, $data, 'is_serial_tracked', $errors) && $ok;
                $ok = ! $duplicate('sku_code', $data['sku_code'] ?? '') && $ok;
                // Cột phân loại là tuỳ chọn, nhưng nếu package khai thì mã phải
                // tồn tại trong master: nạp SKU với brand/category/model không
                // giải được sẽ tạo catalog câm sau cutover (DEV260-P1-03).
                $ok = $reference('brand_code', 'UNKNOWN_FK',
                    fn (string $v): bool => $this->masterId('brands', 'brand_code', $v) !== null, false) && $ok;
                $ok = $reference('category_code', 'UNKNOWN_FK',
                    fn (string $v): bool => $this->masterId('product_categories', 'category_code', $v) !== null, false) && $ok;
                $ok = $reference('model_code', 'UNKNOWN_FK',
                    fn (string $v): bool => $this->masterId('product_models', 'model_code', $v) !== null, false) && $ok;
                if ($ok) {
                    $resolved['sku'][$data['sku_code']] = true;
                    $resolved['sku_serial'][$data['sku_code']] = $this->boolean($data['is_serial_tracked']);
                }
                break;

            case 'PHYSICAL_CODE':
                $ok = $required('code_value');
                $ok = $enum('code_type', self::CODE_TYPES) && $ok;
                $ok = $enum('carrier_type', self::CARRIER_TYPES) && $ok;
                $ok = $enum('status', self::PHYSICAL_CODE_STATUSES) && $ok;
                if (($data['carrier_type'] ?? '') === 'RFID' && ($data['hardware_uid'] ?? '') === '') {
                    $errors->add($dataset, $rowNo, 'hardware_uid', 'REQUIRED', 'ERROR',
                        'carrier_type=RFID bắt buộc có hardware_uid.', null,
                        'Bổ sung hardware_uid hoặc đổi carrier_type.');
                    $ok = false;
                }
                $ok = ! $duplicate('code_value', $data['code_value'] ?? '') && $ok;
                $ok = ! $duplicate('hardware_uid', $data['hardware_uid'] ?? '') && $ok;
                if ($ok) {
                    $resolved['physical_code'][$data['code_value']] = $data['code_type'];
                }
                break;

            case 'CONTAINER':
                $ok = $required('container_code');
                $ok = $enum('type', self::CONTAINER_TYPES) && $ok;
                $ok = $enum('status', self::CONTAINER_STATUSES) && $ok;
                $ok = ! $duplicate('container_code', $data['container_code'] ?? '') && $ok;
                $ok = $reference('physical_code', 'UNKNOWN_FK',
                    fn (string $v): bool => isset($resolved['physical_code'][$v]) || $this->physicalCodeId($v) !== null, false) && $ok;
                $ok = $reference('primary_sku', 'UNKNOWN_SKU',
                    fn (string $v): bool => isset($resolved['sku'][$v]) || $this->skuId($v) !== null, false) && $ok;
                $ok = $reference('parent_tray', 'UNKNOWN_FK',
                    fn (string $v): bool => isset($resolved['container'][$v]) || $this->containerId($v) !== null, false) && $ok;
                if ($ok) {
                    $resolved['container'][$data['container_code']] = true;
                }
                break;

            case 'ITEM':
                $ok = $required('legacy_item_key');
                $ok = $required('sku_code') && $ok;
                $ok = $enum('condition', self::ITEM_CONDITIONS) && $ok;
                $ok = $reference('sku_code', 'UNKNOWN_SKU',
                    fn (string $v): bool => isset($resolved['sku'][$v]) || $this->skuId($v) !== null, true) && $ok;
                $ok = $reference('code_value', 'UNKNOWN_FK',
                    fn (string $v): bool => isset($resolved['physical_code'][$v]) || $this->physicalCodeId($v) !== null, false) && $ok;
                $serialTracked = $resolved['sku_serial'][$data['sku_code'] ?? ''] ?? $this->skuIsSerialTracked($data['sku_code'] ?? '');
                if ($serialTracked && ($data['serial_number'] ?? '') === '') {
                    $errors->add($dataset, $rowNo, 'serial_number', 'REQUIRED', 'ERROR',
                        'SKU serial-tracked bắt buộc có serial_number.', null,
                        'Bổ sung serial_number hoặc sửa cấu hình tracking của SKU.');
                    $ok = false;
                }
                $ok = ! $duplicate('legacy_item_key', $data['legacy_item_key'] ?? '') && $ok;
                $ok = ! $duplicate('serial_number', $data['serial_number'] ?? '') && $ok;
                $ok = ! $duplicate('code_value', $data['code_value'] ?? '') && $ok;
                if ($ok) {
                    $resolved['item'][$data['legacy_item_key']] = $data['sku_code'];
                }
                break;

            case 'OPENING_BALANCE':
                $ok = $required('warehouse_code');
                $ok = $required('sku_code') && $ok;
                $ok = $required('source') && $ok;
                $ok = $required('owner_confirmation') && $ok;
                $ok = $enum('stock_status', self::STOCK_STATUSES) && $ok;
                $ok = $this->requirePositiveInteger($dataset, $rowNo, $data, 'quantity', $errors) && $ok;
                $ok = $reference('warehouse_code', 'UNKNOWN_FK',
                    fn (string $v): bool => $this->warehouseId($v) !== null, true) && $ok;
                $ok = $reference('sku_code', 'UNKNOWN_SKU',
                    fn (string $v): bool => isset($resolved['sku'][$v]) || $this->skuId($v) !== null, true) && $ok;
                $dimension = ($data['warehouse_code'] ?? '').'|'.($data['sku_code'] ?? '').'|'.($data['stock_status'] ?? '');
                $ok = ! $duplicate('opening_dimension', $dimension) && $ok;
                break;
        }
    }

    private function requireField(string $dataset, int $rowNo, array $data, string $field, MigrationErrorBag $errors): bool
    {
        if (($data[$field] ?? '') !== '') {
            return true;
        }
        $errors->add($dataset, $rowNo, $field, 'REQUIRED', 'ERROR', "Thiếu giá trị bắt buộc cho {$field}.",
            null, 'Bổ sung giá trị trong Import Package rồi chạy lại dry-run.');

        return false;
    }

    private function requireEnum(string $dataset, int $rowNo, array $data, string $field, array $allowed, MigrationErrorBag $errors): bool
    {
        $value = (string) ($data[$field] ?? '');
        if ($value !== '' && in_array($value, $allowed, true)) {
            return true;
        }
        $errors->add($dataset, $rowNo, $field, $value === '' ? 'REQUIRED' : 'INVALID_ENUM', 'ERROR',
            "{$field} phải thuộc: ".implode('/', $allowed).'.', $value,
            'Sửa giá trị về đúng enum baseline rồi chạy lại dry-run.');

        return false;
    }

    private function requireBoolean(string $dataset, int $rowNo, array $data, string $field, MigrationErrorBag $errors): bool
    {
        if (in_array(strtoupper((string) ($data[$field] ?? '')), ['TRUE', 'FALSE', '1', '0'], true)) {
            return true;
        }
        $errors->add($dataset, $rowNo, $field, 'INVALID_TYPE', 'ERROR', "{$field} phải là TRUE/FALSE.",
            (string) ($data[$field] ?? ''), 'Sửa giá trị boolean rồi chạy lại dry-run.');

        return false;
    }

    private function requirePositiveInteger(string $dataset, int $rowNo, array $data, string $field, MigrationErrorBag $errors): bool
    {
        $value = (string) ($data[$field] ?? '');
        if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
            return true;
        }
        $errors->add($dataset, $rowNo, $field, 'INVALID_TYPE', 'ERROR',
            "{$field} phải là số nguyên dương (tồn kho là integer theo ERD).", $value,
            'Sửa số lượng về số nguyên dương rồi chạy lại dry-run.');

        return false;
    }

    private function requireReference(
        string $dataset,
        int $rowNo,
        array $data,
        string $field,
        string $errorCode,
        callable $exists,
        MigrationErrorBag $errors,
        bool $mandatory
    ): bool {
        $value = (string) ($data[$field] ?? '');
        if ($value === '') {
            // Thiếu giá trị bắt buộc đã được requireField báo; không nhân đôi error row.
            return ! $mandatory;
        }
        if ($exists($value)) {
            return true;
        }
        $errors->add($dataset, $rowNo, $field, $errorCode, 'CRITICAL',
            "Không tìm thấy tham chiếu {$field}={$value} trong package hoặc baseline hiện tại.", $value,
            'Bổ sung bản ghi nguồn hoặc sửa mã tham chiếu rồi chạy lại dry-run.');

        return false;
    }

    // ------------------------------------------------------------ reconciliation

    /**
     * Đối soát ở dry-run: nguồn opening đã duyệt phải nhất quán nội tại —
     * số lượng của SKU serial-tracked bằng số Item hợp lệ duy nhất (SRS §13.12).
     *
     * @return array<string, mixed>
     */
    private function sourceReconciliation(ImportPackage $package, array $resolved, MigrationErrorBag $errors): array
    {
        $openingBySku = [];
        foreach ($package->rows('OPENING_BALANCE') as $row) {
            $sku = (string) ($row['data']['sku_code'] ?? '');
            $quantity = ctype_digit((string) ($row['data']['quantity'] ?? '')) ? (int) $row['data']['quantity'] : 0;
            $openingBySku[$sku] = ($openingBySku[$sku] ?? 0) + $quantity;
        }

        $itemsBySku = [];
        foreach ($package->rows('ITEM') as $row) {
            $sku = (string) ($row['data']['sku_code'] ?? '');
            $itemsBySku[$sku] = ($itemsBySku[$sku] ?? 0) + 1;
        }

        $checks = [];
        foreach ($openingBySku as $sku => $quantity) {
            $serialTracked = $resolved['sku_serial'][$sku] ?? $this->skuIsSerialTracked($sku);
            if (! $serialTracked) {
                continue;
            }
            $itemCount = $itemsBySku[$sku] ?? 0;
            $pass = $itemCount === $quantity;
            $checks[] = [
                'check' => 'SERIAL_TRACKED_QTY_EQUALS_ITEM_COUNT',
                'sku_code' => $sku,
                'expected' => $quantity,
                'actual' => $itemCount,
                'status' => $pass ? 'PASS' : 'FAIL',
                'message' => "SKU serial-tracked {$sku}: opening {$quantity} vs số Item {$itemCount}.",
            ];
            if (! $pass) {
                $errors->add('OPENING_BALANCE', null, 'quantity', 'RECON_MISMATCH', 'CRITICAL',
                    "SKU serial-tracked {$sku}: opening {$quantity} không khớp số Item {$itemCount}.", null,
                    'Đối chiếu lại nguồn opening balance đã duyệt với danh sách Item.');
            }
        }

        return ['status' => $this->aggregate($checks), 'scope' => 'SOURCE', 'checks' => $checks];
    }

    /**
     * Đối soát sau commit: 100% số lượng nguồn khớp ledger, và balance khớp ledger.
     *
     * @return array<string, mixed>
     */
    private function committedReconciliation(ImportPackage $package, string $runId, array $resolved): array
    {
        $ledger = DB::table('stock_movements')
            ->where('source_type', 'MIGRATION_RUN')
            ->where('source_id', $runId)
            ->select('warehouse_id', 'product_id', 'stock_status')
            ->selectRaw('SUM(qty_delta) AS qty')
            ->groupBy('warehouse_id', 'product_id', 'stock_status')
            ->get()
            ->keyBy(fn ($row): string => $row->warehouse_id.'|'.$row->product_id.'|'.$row->stock_status);

        $checks = [];
        foreach ($package->rows('OPENING_BALANCE') as $row) {
            $data = $row['data'];
            $key = $this->warehouseId($data['warehouse_code']).'|'.$this->skuId($data['sku_code']).'|'.$data['stock_status'];
            $expected = (int) $data['quantity'];
            $actual = (int) ($ledger[$key]->qty ?? 0);
            $checks[] = [
                'check' => 'SOURCE_QTY_EQUALS_LEDGER',
                'warehouse_code' => $data['warehouse_code'],
                'sku_code' => $data['sku_code'],
                'stock_status' => $data['stock_status'],
                'expected' => $expected,
                'actual' => $actual,
                'status' => $expected === $actual ? 'PASS' : 'FAIL',
                'message' => "Opening {$data['warehouse_code']}/{$data['sku_code']}/{$data['stock_status']}: nguồn {$expected} vs ledger {$actual}.",
            ];
        }

        foreach ($ledger as $key => $unused) {
            [$warehouseId, $skuId, $stockStatus] = explode('|', (string) $key, 3);
            $balance = DB::table('inventory_balances')
                ->where('warehouse_id', $warehouseId)
                ->where('stock_status', $stockStatus)
                ->where(fn ($query) => $query->where('sku_id', $skuId)->orWhere('product_id', $skuId))
                ->first();
            $ledgerTotal = (int) DB::table('stock_movements')
                ->where('warehouse_id', $warehouseId)
                ->where('stock_status', $stockStatus)
                ->where(fn ($query) => $query->where('sku_id', $skuId)->orWhere('product_id', $skuId))
                ->sum('qty_delta');
            $onHand = (int) ($balance->on_hand_qty ?? 0);
            $checks[] = [
                'check' => 'BALANCE_EQUALS_LEDGER',
                'warehouse_id' => $warehouseId,
                'sku_id' => $skuId,
                'stock_status' => $stockStatus,
                'expected' => $ledgerTotal,
                'actual' => $onHand,
                'status' => $ledgerTotal === $onHand ? 'PASS' : 'FAIL',
                'message' => "Balance vs ledger {$warehouseId}/{$skuId}/{$stockStatus}: {$onHand} vs {$ledgerTotal}.",
            ];
        }

        $checks = array_merge($checks, $this->sourceReconciliation($package, $resolved, new MigrationErrorBag)['checks']);
        $checks = array_merge($checks, $this->containerMappingChecks($package, $runId));

        return ['status' => $this->aggregate($checks), 'scope' => 'COMMITTED', 'checks' => $checks];
    }

    /**
     * SRS §13.12 — "container mapping creates no extra stock".
     *
     * Container/Item của package chỉ là subledger định danh; toàn bộ hiệu ứng tồn
     * của run phải đúng bằng tổng lượng của dataset OPENING_BALANCE, và mọi
     * movement của run phải là `OPENING`. Nếu một ngày nào đó mapping container
     * sinh thêm movement, hai check này đỏ ngay trong transaction COMMIT nên
     * cutover bị chặn thay vì phát hiện sau khi đã ghi tồn.
     *
     * @return list<array<string, mixed>>
     */
    private function containerMappingChecks(ImportPackage $package, string $runId): array
    {
        $sourceTotal = 0;
        foreach ($package->rows('OPENING_BALANCE') as $row) {
            $quantity = (string) ($row['data']['quantity'] ?? '');
            $sourceTotal += ctype_digit($quantity) ? (int) $quantity : 0;
        }

        $runMovements = DB::table('stock_movements')
            ->where('source_type', 'MIGRATION_RUN')
            ->where('source_id', $runId);

        $ledgerTotal = (int) (clone $runMovements)->sum('qty_delta');
        $nonOpening = (int) (clone $runMovements)->where('movement_type', '!=', 'OPENING')->count();
        $containerCount = count($package->rows('CONTAINER'));

        return [
            [
                'check' => 'CONTAINER_MAPPING_NO_EXTRA_STOCK',
                'container_rows' => $containerCount,
                'expected' => $sourceTotal,
                'actual' => $ledgerTotal,
                'status' => $sourceTotal === $ledgerTotal ? 'PASS' : 'FAIL',
                'message' => "Tổng tồn ghi bởi run {$ledgerTotal} vs tổng lượng nguồn {$sourceTotal} ({$containerCount} dòng CONTAINER không được sinh thêm tồn).",
            ],
            [
                'check' => 'RUN_WRITES_ONLY_OPENING_MOVEMENTS',
                'expected' => 0,
                'actual' => $nonOpening,
                'status' => $nonOpening === 0 ? 'PASS' : 'FAIL',
                'message' => "Số movement khác OPENING do run tạo: {$nonOpening} (phải bằng 0).",
            ],
        ];
    }

    /** @param list<array<string, mixed>> $checks */
    private function aggregate(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? 'PASS') !== 'PASS') {
                return 'FAIL';
            }
        }

        return 'PASS';
    }

    // -------------------------------------------------------------------- writes

    private function writeMasters(ImportPackage $package, string $runId, array $resolved, MasterStateJournal $journal): void
    {
        foreach ($package->rows('AGENCY') as $row) {
            $data = $row['data'];
            $this->upsertMaster(Agency::class, ['agency_code' => $data['agency_code']], [
                'agency_name' => $data['agency_name'],
                'phone' => $data['phone'] !== '' ? $data['phone'] : null,
                'address' => $data['address'] !== '' ? $data['address'] : null,
                'status' => $data['status'],
            ], $journal);
        }

        foreach ($package->rows('SKU') as $row) {
            $data = $row['data'];
            $attributes = [
                'sku_name' => $data['sku_name'],
                'sku_type' => $data['sku_type'],
                'unit' => $data['unit'],
                'is_serial_tracked' => $this->boolean($data['is_serial_tracked']),
                'version_code' => $data['version_code'],
                'status' => $data['status'],
            ];
            // Cột phân loại của §13.12 chỉ được ghi khi package có khai; không
            // khai thì giữ nguyên giá trị hiện hữu thay vì xoá trắng.
            foreach ([
                'brand_id' => ['brands', 'brand_code', 'brand_code'],
                'category_id' => ['product_categories', 'category_code', 'category_code'],
                'model_id' => ['product_models', 'model_code', 'model_code'],
            ] as $target => [$table, $column, $source]) {
                if (($data[$source] ?? '') !== '') {
                    $attributes[$target] = $this->masterId($table, $column, $data[$source]);
                }
            }

            $this->upsertMaster(Sku::class, ['sku_code' => $data['sku_code']], $attributes, $journal);
        }

        foreach ($package->rows('PHYSICAL_CODE') as $row) {
            $data = $row['data'];
            $this->upsertMaster(PhysicalCode::class, ['code_value' => $data['code_value']], [
                'code_type' => $data['code_type'],
                'carrier_type' => $data['carrier_type'],
                'hardware_uid' => $data['hardware_uid'] !== '' ? $data['hardware_uid'] : null,
                'status' => $data['status'],
            ], $journal);
        }

        foreach ($package->rows('CONTAINER') as $row) {
            $data = $row['data'];
            $this->upsertMaster(Container::class, ['container_code' => $data['container_code']], [
                'container_type' => $data['type'],
                'physical_code_id' => $data['physical_code'] !== '' ? $this->physicalCodeId($data['physical_code']) : null,
                'primary_sku_id' => $data['primary_sku'] !== '' ? $this->skuId($data['primary_sku']) : null,
                'parent_container_id' => $data['parent_tray'] !== '' ? $this->containerId($data['parent_tray']) : null,
                'status' => $data['status'],
            ], $journal, ['migration_run_id' => $runId]);
        }

        foreach ($package->rows('ITEM') as $row) {
            $data = $row['data'];
            $this->upsertMaster(ItemInstance::class, [
                'serial_number' => $data['serial_number'] !== '' ? $data['serial_number'] : $data['legacy_item_key'],
            ], [
                'sku_id' => $this->skuId($data['sku_code']),
                'physical_code_id' => $data['code_value'] !== '' ? $this->physicalCodeId($data['code_value']) : null,
                'item_status' => 'IN_STOCK',
                'item_condition' => $data['condition'],
                'import_batch_id' => $data['import_batch'] !== '' ? $this->ensureImportBatch($data['import_batch'], $runId, $journal) : null,
            ], $journal, ['migration_run_id' => $runId]);
        }
    }

    /**
     * Ghi một master theo khoá tự nhiên, chụp nguyên trạng trước đó vào nhật ký
     * rồi mới ghi đè (DEV299-P1-01).
     *
     * `$extra` là các cột chỉ hệ thống mới đặt được (`migration_run_id`) nên
     * chúng đi qua `forceFill` chứ không nằm trong `$fillable`.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $extra
     */
    private function upsertMaster(
        string $modelClass,
        array $keys,
        array $values,
        MasterStateJournal $journal,
        array $extra = []
    ): Model {
        $existing = $modelClass::query()->where($keys)->first();
        $before = $existing === null ? null : $existing->getAttributes();

        $model = $modelClass::updateOrCreate($keys, $values);
        if ($extra !== []) {
            $model->forceFill($extra)->save();
        }

        $journal->recordModel($model, $before);

        return $model;
    }

    private function writeOpeningBalance(ImportPackage $package, string $runId, string $executedBy, MasterStateJournal $journal): void
    {
        foreach ($package->rows('OPENING_BALANCE') as $row) {
            $data = $row['data'];
            $warehouseId = $this->warehouseId($data['warehouse_code']);
            $skuId = $this->skuId($data['sku_code']);
            $stockStatus = $data['stock_status'];
            $quantity = (int) $data['quantity'];
            $businessKey = 'OPENING|'.$runId.'|'.$warehouseId.'|'.$skuId.'|'.$stockStatus;

            // Rerun idempotent theo migration_run_id (AC-SRS-021).
            if (DB::table('stock_movements')->where('business_key', $businessKey)->exists()) {
                continue;
            }

            $balance = $this->lockBalance($warehouseId, $skuId, $stockStatus, true, $journal);
            $before = (int) $balance->quantity;
            $after = $before + $quantity;

            $affected = DB::table('inventory_balances')
                ->where('id', $balance->id)
                ->where('version', $balance->version)
                ->update([
                    'quantity' => $after,
                    'on_hand_qty' => $after,
                    'available_qty' => (int) $balance->available_qty + ($stockStatus === 'AVAILABLE' ? $quantity : 0),
                    'version' => ((int) $balance->version) + 1,
                    'updated_at' => now(),
                ]);
            if ($affected !== 1) {
                throw new RuntimeException('MIGRATION_BALANCE_VERSION_CONFLICT: tồn kho bị thay đổi đồng thời trong cutover window.');
            }

            $this->insertMovement([
                'business_key' => $businessKey,
                'movement_type' => 'OPENING',
                'warehouse_id' => $warehouseId,
                'product_id' => $skuId,
                'stock_status' => $stockStatus,
                'quantity' => $quantity,
                'qty_before' => $before,
                'qty_delta' => $quantity,
                'qty_after' => $after,
                'source_id' => $runId,
                'created_by' => $executedBy,
            ]);
        }
    }

    private function reverseOpeningMovements(string $targetRunId, string $rollbackRunId, string $executedBy): int
    {
        $movements = DB::table('stock_movements')
            ->where('source_type', 'MIGRATION_RUN')
            ->where('source_id', $targetRunId)
            ->orderBy('created_at')
            ->get();

        $reversed = 0;
        foreach ($movements as $movement) {
            $businessKey = 'REVERSAL|'.$rollbackRunId.'|'.$movement->id;
            if (DB::table('stock_movements')->where('business_key', $businessKey)->exists()) {
                continue;
            }

            $balance = $this->lockBalance($movement->warehouse_id, $movement->product_id, $movement->stock_status, false);
            $delta = -1 * (int) $movement->qty_delta;
            $before = (int) $balance->quantity;
            $after = $before + $delta;
            if ($after < 0) {
                throw new RuntimeException('MIGRATION_ROLLBACK_NEGATIVE_STOCK: tồn đã bị tiêu thụ sau commit, không thể rollback tự động.');
            }

            DB::table('inventory_balances')->where('id', $balance->id)->update([
                'quantity' => $after,
                'on_hand_qty' => $after,
                'available_qty' => (int) $balance->available_qty + ($movement->stock_status === 'AVAILABLE' ? $delta : 0),
                'version' => ((int) $balance->version) + 1,
                'updated_at' => now(),
            ]);

            $this->insertMovement([
                'business_key' => $businessKey,
                'movement_type' => 'REVERSAL',
                'warehouse_id' => $movement->warehouse_id,
                'product_id' => $movement->product_id,
                'stock_status' => $movement->stock_status,
                'quantity' => abs($delta),
                'qty_before' => $before,
                'qty_delta' => $delta,
                'qty_after' => $after,
                'source_id' => $rollbackRunId,
                'created_by' => $executedBy,
                'original_movement_id' => $movement->id,
            ]);
            $reversed++;
        }

        return $reversed;
    }

    /**
     * Số bản ghi còn mang dấu vết `migration_run_id` của run sau khi rollback.
     * Đây là kênh kiểm tra độc lập với nhật ký snapshot: nhật ký sai hoặc thiếu
     * thì cột trace vẫn tố cáo phần tồn dư.
     */
    private function residualMigrationTaggedRows(string $targetRunId, array $disposedKeys = []): int
    {
        $disposedKeys = array_flip($disposedKeys);
        $total = 0;

        foreach (['containers', 'item_instances', 'import_batches'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'migration_run_id')) {
                continue;
            }

            $primaryKey = match ($table) {
                'containers' => 'container_id',
                'item_instances' => 'item_id',
                default => 'import_batch_id',
            };

            $ids = DB::table($table)->where('migration_run_id', $targetRunId)->pluck($primaryKey);
            foreach ($ids as $id) {
                if (! isset($disposedKeys[$table.'|'.$id])) {
                    $total++;
                }
            }
        }

        return $total;
    }

    /** Tổng hiệu ứng tồn kho còn lại của run: 0 nghĩa là không còn opening tồn dư. */
    private function residualOpeningQuantity(string $targetRunId): int
    {
        $openingIds = DB::table('stock_movements')
            ->where('source_type', 'MIGRATION_RUN')
            ->where('source_id', $targetRunId)
            ->pluck('id');

        $opening = (int) DB::table('stock_movements')->whereIn('id', $openingIds)->sum('qty_delta');
        $reversed = (int) DB::table('stock_movements')->whereIn('original_movement_id', $openingIds)->sum('qty_delta');

        return $opening + $reversed;
    }

    private function lockBalance(
        ?string $warehouseId,
        ?string $skuId,
        string $stockStatus,
        bool $createIfMissing,
        ?MasterStateJournal $journal = null
    ): object {
        $query = fn () => DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('stock_status', $stockStatus)
            ->where(fn ($inner) => $inner->where('sku_id', $skuId)->orWhere('product_id', $skuId))
            ->lockForUpdate();

        $balance = $query()->first();
        if ($balance !== null) {
            return $balance;
        }
        if (! $createIfMissing) {
            throw new RuntimeException('MIGRATION_BALANCE_MISSING: không tìm thấy inventory_balances để đảo.');
        }

        $balanceId = (string) Str::uuid();
        DB::table('inventory_balances')->insert([
            'id' => $balanceId,
            'warehouse_id' => $warehouseId,
            'product_id' => $skuId,
            'sku_id' => $skuId,
            'stock_status' => $stockStatus,
            'quantity' => 0,
            'on_hand_qty' => 0,
            'available_qty' => 0,
            'reserved_qty' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = $query()->first();
        // Dòng balance do chính cutover tạo ra cũng vào nhật ký, để rollback
        // không để lại bản ghi tồn mồ côi (SRS §13.12).
        $journal?->record('inventory_balances', 'id', $balanceId, null, (array) $created);

        return $created;
    }

    private function insertMovement(array $attributes): void
    {
        DB::table('stock_movements')->insert($attributes + [
            'id' => (string) Str::uuid(),
            'movement_no' => 'MV-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
            'sku_id' => $attributes['product_id'],
            'source_type' => 'MIGRATION_RUN',
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function ensureImportBatch(string $code, string $runId, MasterStateJournal $journal): string
    {
        $existing = ImportBatch::query()->where('import_batch_code', $code)->first();
        $before = $existing === null ? null : $existing->getAttributes();

        $batch = $existing ?? ImportBatch::firstOrCreate(['import_batch_code' => $code], ['status' => 'ACTIVE']);
        $batch->forceFill(['migration_run_id' => $runId])->save();

        $journal->recordModel($batch, $before);

        return (string) $batch->import_batch_id;
    }

    // ------------------------------------------------------------------ plumbing

    private function startRun(
        string $runCode,
        string $mode,
        ImportPackage $package,
        string $executedBy,
        ?string $note,
        ?string $approvedBy = null
    ): string {
        $id = (string) Str::uuid();
        DB::table('migration_runs')->insert([
            'id' => $id,
            'migration_run_code' => $this->runCode($runCode, $mode),
            'package_version' => $package->packageVersion,
            'source_file_name' => $package->sourceFileName,
            'source_checksum' => $package->checksum,
            'mode' => $mode,
            'status' => 'RUNNING',
            'total_rows' => $package->totalRows(),
            'valid_rows' => 0,
            'error_rows' => 0,
            'reconciliation_status' => 'PENDING',
            'started_at' => now(),
            'executed_by' => $executedBy,
            'approved_by' => $approvedBy,
            'approved_at' => $approvedBy === null ? null : now(),
            'note' => $note,
            'created_at' => now(),
        ]);

        $this->runStartedAt[$id] = hrtime(true);

        return $id;
    }

    private function finishRun(string $runId, string $status, array $counts, array $reconciliation): void
    {
        DB::table('migration_runs')->where('id', $runId)->update([
            'status' => $status,
            'total_rows' => $counts['total'],
            'valid_rows' => $counts['valid'],
            'error_rows' => $counts['invalid'],
            // ERD-AMD-007: dòng trùng có ô riêng nên bất biến §13.12
            // (valid + invalid + duplicate = total) kiểm lại được từ DB.
            'duplicate_rows' => $counts['duplicate'] ?? 0,
            'reconciliation_status' => $reconciliation['status'],
            'reconciliation_result' => json_encode($reconciliation, JSON_UNESCAPED_UNICODE),
            'finished_at' => now(),
        ]);
    }

    private function persistErrors(string $runId, MigrationErrorBag $errors): void
    {
        DB::table('migration_errors')->where('migration_run_id', $runId)->delete();
        foreach (array_chunk($errors->all(), 200) as $chunk) {
            DB::table('migration_errors')->insert(array_map(static fn (array $error): array => $error + [
                'id' => (string) Str::uuid(),
                'migration_run_id' => $runId,
                'created_at' => now(),
            ], $chunk));
        }
    }

    /**
     * Đếm dòng theo bất biến của SRS §13.12: `valid + invalid + duplicate` phải
     * bằng đúng số dòng input. Trước WWHN-260, dòng trùng bị gộp vào `invalid`
     * nên bất biến ba số không kiểm chứng lại được từ báo cáo (DEV260-P1-04).
     *
     * @return array{total:int, valid:int, invalid:int, duplicate:int, invariant_holds:bool, by_dataset:array<string,int>}
     */
    private function countRows(ImportPackage $package, MigrationErrorBag $errors): array
    {
        $total = $package->totalRows();
        $duplicate = count($errors->duplicateRowKeys());
        $invalid = count($errors->invalidRowKeys());
        $valid = max(0, $total - $invalid - $duplicate);

        return [
            'total' => $total,
            'valid' => $valid,
            'invalid' => $invalid,
            'duplicate' => $duplicate,
            'invariant_holds' => $valid + $invalid + $duplicate === $total,
            'by_dataset' => $package->rowCounts(),
        ];
    }

    private function findRun(string $runCode, string $mode): ?object
    {
        return DB::table('migration_runs')->where('migration_run_code', $this->runCode($runCode, $mode))->first();
    }

    /**
     * Run COMMIT khác đã nạp đúng package này và chưa bị rollback — cơ sở chặn
     * double effect khi ai đó chạy lại cùng package dưới run code mới.
     */
    private function committedRunForChecksum(string $checksum, string $runCode): ?object
    {
        return DB::table('migration_runs')
            ->where('mode', 'COMMIT')
            ->where('status', 'COMMITTED')
            ->where('source_checksum', $checksum)
            ->where('migration_run_code', '!=', $this->runCode($runCode, 'COMMIT'))
            ->first();
    }

    /** Replay idempotent nếu run đã kết thúc với cùng checksum; chặn nếu checksum khác. */
    private function replayGuard(object $existing, string $checksum): array
    {
        if ($existing->source_checksum !== $checksum) {
            return $this->blockedReport((string) $existing->migration_run_code, (string) $existing->mode, 'CHECKSUM_CHANGED',
                'Run code đã tồn tại với checksum khác — package đã đổi thì phải dùng run code mới.');
        }

        if (in_array($existing->status, ['RUNNING', 'CREATED'], true)) {
            return $this->blockedReport((string) $existing->migration_run_code, (string) $existing->mode, 'RUN_IN_PROGRESS',
                'Run trước chưa kết thúc; không chạy song song trên cùng run code.');
        }

        return [
            'migration_run_id' => $existing->id,
            'migration_run_code' => $existing->migration_run_code,
            'mode' => $existing->mode,
            'status' => $existing->status,
            'source_checksum' => $existing->source_checksum,
            'counts' => [
                'total' => (int) $existing->total_rows,
                'valid' => (int) $existing->valid_rows,
                'invalid' => (int) $existing->error_rows,
                'duplicate' => (int) ($existing->duplicate_rows ?? 0),
            ],
            'reconciliation' => json_decode((string) $existing->reconciliation_result, true) ?: ['status' => $existing->reconciliation_status],
            'errors' => [],
            'replayed' => true,
        ];
    }

    /**
     * Thời lượng thực của run. Mốc thời gian lấy đúng cái đã ghi ở
     * `migration_runs`; riêng thời lượng đo bằng đồng hồ đơn điệu vì cột
     * timestamptz chỉ có độ phân giải giây.
     *
     * @return array{started_at: ?string, finished_at: ?string, duration_ms: ?int, duration_source: string}
     */
    private function timing(string $runId): array
    {
        $run = DB::table('migration_runs')->where('id', $runId)->first(['started_at', 'finished_at']);
        if ($run === null || $run->started_at === null || $run->finished_at === null) {
            return ['started_at' => null, 'finished_at' => null, 'duration_ms' => null, 'duration_source' => 'NONE'];
        }

        $started = Carbon::parse($run->started_at);
        $finished = Carbon::parse($run->finished_at);

        // Cột timestamptz chỉ có độ phân giải giây, nên một dry-run 300 ms sẽ báo
        // duration_ms = 0 và evidence "runtime phù hợp cutover window"
        // (DEV260-P1-06) mất ý nghĩa. Thời lượng vì thế đo bằng đồng hồ đơn điệu
        // trong tiến trình; mốc thời gian vẫn lấy đúng cái đã lưu ở DB.
        $measured = isset($this->runStartedAt[$runId])
            ? (int) round((hrtime(true) - $this->runStartedAt[$runId]) / 1000000)
            : null;

        return [
            'started_at' => $started->toIso8601String(),
            'finished_at' => $finished->toIso8601String(),
            'duration_ms' => $measured ?? max(0, (int) round($started->diffInMilliseconds($finished))),
            'duration_source' => $measured === null ? 'DB_TIMESTAMP_SECONDS' : 'MONOTONIC_CLOCK',
        ];
    }

    private function blockedReport(string $runCode, string $mode, string $errorCode, string $message): array
    {
        return [
            'migration_run_id' => null,
            'migration_run_code' => $runCode,
            'mode' => $mode,
            'status' => 'BLOCKED',
            'error_code' => $errorCode,
            'message' => $message,
            'replayed' => false,
        ];
    }

    private function report(
        string $runId,
        string $runCode,
        string $mode,
        string $status,
        ImportPackage $package,
        array $counts,
        array $reconciliation,
        array $errors
    ): array {
        return [
            'migration_run_id' => $runId,
            'migration_run_code' => $this->runCode($runCode, $mode),
            'mode' => $mode,
            'status' => $status,
            'package_version' => $package->packageVersion,
            // DEV260-P1-02: evidence phải nói rõ nó được sinh dưới mapping nào.
            'mapping_version' => (string) config('migration_mapping.mapping_version', ''),
            'source_file_name' => $package->sourceFileName,
            'source_checksum' => $package->checksum,
            // DEV299-P1-03: manifest nằm trong checksum tổng, và được nêu riêng
            // để reviewer đối chiếu được identity nguồn chứ không chỉ nội dung.
            'manifest_checksum' => $package->manifestChecksum,
            'manifest' => $package->manifest,
            'file_checksums' => $package->fileChecksums,
            'source_files' => $package->fileNames,
            'counts' => $counts,
            // DEV260-P1-06: thời lượng thực đo để đối chiếu với cutover window.
            'timing' => $this->timing($runId),
            'reconciliation' => $reconciliation,
            'errors' => $errors,
            'replayed' => false,
        ];
    }

    private function audit(
        string $action,
        string $runId,
        string $runCode,
        string $executedBy,
        string $status,
        array $counts,
        array $reconciliation
    ): void {
        AuditLogger::record(
            action: $action,
            objectType: 'migration_run',
            objectId: $runId,
            actorId: $executedBy,
            afterData: [
                'migration_run_code' => $runCode,
                'status' => $status,
                'counts' => $counts,
                'reconciliation_status' => $reconciliation['status'] ?? null,
            ],
            reason: 'Lần nạp dữ liệu cuối theo REQ-SRS-021 (operational-only job).',
        );
    }

    /**
     * Pin mã quyết định/biên bản duyệt opening balance vào evidence của run
     * (DEV299-P1-02). `approved_by` chỉ trả lời "ai duyệt"; trước đây không có
     * chỗ nào trả lời "duyệt theo văn bản nào".
     */
    private function noteWithApproval(?string $note, ?string $approvalRef): ?string
    {
        $approvalRef = trim((string) $approvalRef);
        if ($approvalRef === '') {
            return $note;
        }

        $note = trim((string) $note);

        return 'APPROVAL_REF='.$approvalRef.($note === '' ? '' : ' | '.$note);
    }

    private function runCode(string $base, string $mode): string
    {
        return str_contains($base, '#') ? $base : $base.'#'.$mode;
    }

    private function boolean(string $value): bool
    {
        return in_array(strtoupper($value), ['TRUE', '1'], true);
    }

    // ------------------------------------------------------------------- lookups

    /**
     * Cache tra cứu trong phạm vi một run.
     *
     * NFR-SRS-017 đòi dry-run chịu được ≥10.000 dòng/file; trước đây mỗi dòng
     * opening/item gọi lại `SELECT` cho cùng một mã kho/SKU nên chi phí là O(số
     * dòng) truy vấn. Chỉ cache **kết quả tìm thấy**: một mã chưa tồn tại lúc
     * validate có thể tồn tại sau khi masters được ghi, nên cache miss không
     * được ghi nhớ.
     *
     * @var array<string, string>
     */
    private array $lookupCache = [];

    /**
     * Mốc đồng hồ đơn điệu theo run — nguồn của `timing.duration_ms` khi báo cáo
     * được sinh trong cùng tiến trình đã chạy run (DEV260-P1-06).
     *
     * @var array<string, int|float>
     */
    private array $runStartedAt = [];

    private function resetLookupCache(): void
    {
        $this->lookupCache = [];
    }

    private function cachedId(string $bucket, string $key, callable $resolver): ?string
    {
        if ($key === '') {
            return null;
        }

        $cacheKey = $bucket.'|'.$key;
        if (isset($this->lookupCache[$cacheKey])) {
            return $this->lookupCache[$cacheKey];
        }

        $id = $resolver($key);
        if ($id !== null) {
            $this->lookupCache[$cacheKey] = $id;
        }

        return $id;
    }

    private function warehouseId(string $code): ?string
    {
        return $this->cachedId('warehouse', $code, static function (string $value): ?string {
            $warehouse = Warehouse::query()->where('code', $value)->first();

            return $warehouse === null ? null : (string) $warehouse->getKey();
        });
    }

    private function skuId(string $code): ?string
    {
        return $this->cachedId('sku', $code, static function (string $value): ?string {
            $sku = Sku::query()->where('sku_code', $value)->first();

            return $sku === null ? null : (string) $sku->sku_id;
        });
    }

    private function skuIsSerialTracked(string $code): bool
    {
        $sku = Sku::query()->where('sku_code', $code)->first();

        return $sku !== null && (bool) $sku->is_serial_tracked;
    }

    private function physicalCodeId(string $codeValue): ?string
    {
        return $this->cachedId('physical_code', $codeValue, static function (string $value): ?string {
            $code = PhysicalCode::query()->where('code_value', $value)->first();

            return $code === null ? null : (string) $code->physical_code_id;
        });
    }

    private function containerId(string $code): ?string
    {
        return $this->cachedId('container', $code, static function (string $value): ?string {
            $container = Container::query()->where('container_code', $value)->first();

            return $container === null ? null : (string) $container->container_id;
        });
    }

    /** Tra cứu master phân loại (brands / product_categories / product_models). */
    private function masterId(string $table, string $column, string $code): ?string
    {
        return $this->cachedId($table, $code, static function (string $value) use ($table, $column): ?string {
            $id = DB::table($table)->where($column, $value)->value('id');

            return $id === null ? null : (string) $id;
        });
    }
}
