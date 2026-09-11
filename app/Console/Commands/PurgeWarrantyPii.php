<?php

namespace App\Console\Commands;

use App\Support\Security\BlindIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Http\Controllers\CurrentBaselineWarrantyController as WarrantyContract;
use Modules\Warehouse\Support\WarrantyCasePii;

/**
 * SRS v1.12 §13.8 Privacy Contract: customer name/phone/address, ảnh và mô tả máy của
 * warranty case được giữ 5 năm sau khi case đóng, sau đó anonymize/purge — trừ case
 * đang legal hold.
 *
 * Ràng buộc của §13.8 mà lệnh này phải thỏa: idempotent, có dry-run/report, retry được,
 * loại legal-hold, audit số record đã anonymize/purge, và KHÔNG xóa Inventory_Movement
 * hay business evidence cần giữ (case/event/ảnh chỉ bị anonymize hoặc gỡ nội dung, bản
 * ghi nghiệp vụ và movement vẫn nguyên).
 */
class PurgeWarrantyPii extends Command
{
    protected $signature = 'warranty:purge-pii
        {--dry-run : Chỉ báo cáo số record đến hạn, không ghi gì}
        {--limit=500 : Số case xử lý tối đa mỗi lần chạy}';

    protected $description = 'Anonymize PII warranty case đã hết hạn lưu trữ (§13.8), bỏ qua legal hold';

    /** Giá trị thay thế — cố định để lần chạy thứ hai nhận ra và bỏ qua (idempotent). */
    private const ANONYMIZED = '[ANONYMIZED]';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $due = DB::table('warranty_case')
            ->whereNotNull('retention_until')
            ->where('retention_until', '<=', now())
            // §13.8: legal hold loại khỏi purge cho tới khi cờ được gỡ.
            ->where(function ($query): void {
                $query->whereNull('legal_hold_flag')->orWhere('legal_hold_flag', false);
            })
            // Idempotent: case đã anonymize ở lần chạy trước không bị đếm/ghi lại.
            //
            // WWHN-SEC-06: so trên BLIND INDEX chứ không trên cột plaintext (đã
            // xoá ở Phase 6). Vân tay là tất định nên "tên đã bằng [ANONYMIZED]"
            // vẫn nhận ra được mà không cần giải mã 500 hồ sơ chỉ để lọc.
            ->where(function ($query): void {
                $anonymizedFingerprint = BlindIndex::nameTokens(self::ANONYMIZED);

                $query->where('customer_name_lookup', '!=', $anonymizedFingerprint)
                    ->orWhereNull('customer_name_lookup');
            })
            ->orderBy('retention_until')
            ->limit($limit)
            ->get(['warranty_case_id', 'warranty_case_code', 'retention_until', 'status']);

        if ($due->isEmpty()) {
            $this->info('Không có warranty case nào đến hạn purge PII.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d case đến hạn purge PII (limit %d).', $due->count(), $limit));
        $this->table(
            ['case_no', 'status', 'retention_until'],
            $due->map(fn (object $row): array => [
                (string) $row->warranty_case_code,
                (string) $row->status,
                (string) $row->retention_until,
            ])->all()
        );

        if ($dryRun) {
            $this->warn('--dry-run: không ghi dữ liệu nào.');

            return self::SUCCESS;
        }

        $caseIds = $due->pluck('warranty_case_id')->map(fn ($id): string => (string) $id)->all();
        $attachmentsPurged = 0;

        DB::transaction(function () use ($caseIds, &$attachmentsPurged): void {
            // WWHN-SEC-06: đi qua encode() nên giá trị anonymize được ghi vào cột
            // ciphertext VÀ blind index được tính lại theo giá trị mới. Ghi thẳng
            // vào cột plaintext (đã xoá ở Phase 6) sẽ ném lỗi cột không tồn tại,
            // còn ghi ciphertext mà quên blind index thì hồ sơ đã anonymize vẫn
            // tra cứu được bằng số điện thoại CŨ — tức là purge không thật sự purge.
            DB::table('warranty_case')->whereIn('warranty_case_id', $caseIds)->update(WarrantyCasePii::encode([
                'customer_name' => self::ANONYMIZED,
                'customer_phone' => self::ANONYMIZED,
                'customer_address' => null,
                // §13.8 "Machine description/defect notes": free text có thể chứa PII
                // ngẫu nhiên nên cũng bị anonymize; defect_id/status/ngày vẫn giữ để
                // báo cáo RPT-WAR-01 không mất số liệu lịch sử.
                'manual_product_description' => self::ANONYMIZED,
                'updated_at' => now(),
            ]));

            $attachments = DB::table('warranty_attachments')
                ->whereIn('warranty_case_id', $caseIds)
                ->where('status', '!=', 'DELETED')
                ->get(['id', 'storage_key', 'storage_path', 'storage_disk']);

            foreach ($attachments as $attachment) {
                $path = (string) ($attachment->storage_key ?? $attachment->storage_path ?? '');
                // Xoá trên đúng disk đã ghi lúc upload (cột storage_disk: local / s3 / r2).
                // Gắn cứng 'local' thì ảnh/video nằm trên R2 không bao giờ bị purge dù hồ
                // sơ đã anonymize — §13.8 chỉ được thỏa trên giấy.
                $disk = Storage::disk((string) ($attachment->storage_disk ?: 'local'));
                if ($path !== '' && $disk->exists($path)) {
                    $disk->delete($path);
                }
            }

            $attachmentsPurged = DB::table('warranty_attachments')
                ->whereIn('warranty_case_id', $caseIds)
                ->where('status', '!=', 'DELETED')
                ->update([
                    'status' => 'DELETED',
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        }, 3);

        AuditLogger::record(
            action: 'WARRANTY_PII_PURGED',
            objectType: 'warranty_case',
            objectId: null,
            actorId: null,
            requestId: null,
            afterData: [
                // Chỉ ghi con số và case_id — không ghi giá trị PII vừa bị xóa.
                'cases_anonymized' => count($caseIds),
                'attachments_purged' => $attachmentsPurged,
                'retention_years' => WarrantyContract::PII_RETENTION_YEARS,
                'pii_columns' => WarrantyContract::PII_COLUMNS,
                'case_ids' => $caseIds,
            ],
            reason: 'SRS §13.8 retention: anonymize PII '.WarrantyContract::PII_RETENTION_YEARS.' năm sau khi đóng case',
        );

        $this->info(sprintf(
            'Đã anonymize %d case và purge %d tệp đính kèm. Inventory_Movement không bị tác động.',
            count($caseIds),
            $attachmentsPurged
        ));

        return self::SUCCESS;
    }
}
