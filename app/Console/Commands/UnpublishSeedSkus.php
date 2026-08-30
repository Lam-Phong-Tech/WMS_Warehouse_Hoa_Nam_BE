<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\AuditLog\Services\AuditLogger;

/**
 * FE App PV (storefront `/api/v1/public/*`) báo cáo dữ liệu fixture QA — sinh bởi
 * WarehouseBusinessDataSeeder/UatScenarioSeeder/OperationalReportRangeSeeder/
 * WarehouseSupplementalDataSeeder (sku_code dạng HN-PRD-SEED-*, HN-COMP-SEED-*,
 * HN-PV-BASE-*, HN-COMP-BASE-*) — đang lộ ra public catalogue vì các seeder đó set
 * `published_to_app = true` để phục vụ test E2E/QA nội bộ, không phải vì đó là
 * catalogue DongCheng thật.
 *
 * Lệnh này KHÔNG xóa record (các luồng inbound/outbound/report QA vẫn cần chúng
 * tồn tại) — chỉ tắt `published_to_app` để chúng biến mất khỏi /api/v1/public/*
 * và /api/v1/pv/*, đúng bằng thao tác PATCH published mà admin panel đã hỗ trợ
 * (ProductController::update, CurrentBaselineSkuController).
 */
class UnpublishSeedSkus extends Command
{
    protected $signature = 'pv:unpublish-seed-skus
        {--dry-run : Chỉ liệt kê SKU khớp pattern, không ghi gì}
        {--force : Bỏ qua xác nhận tương tác (dùng khi chạy trong script/CI)}
        {--pattern=* : Pattern LIKE bổ sung cho sku_code (mặc định %-SEED-%, %-BASE-%)}';

    protected $description = 'Unpublish (published_to_app=false) các SKU fixture QA khỏi App PV public catalogue';

    /** @var array<int, string> */
    private const DEFAULT_PATTERNS = ['%-SEED-%', '%-BASE-%'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $patterns = $this->option('pattern') !== [] ? $this->option('pattern') : self::DEFAULT_PATTERNS;

        $query = DB::table('sku')->where('published_to_app', true);
        $query->where(function ($nested) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $nested->orWhere('sku_code', 'like', $pattern);
            }
        });

        $rows = $query->orderBy('sku_code')->get(['sku_id', 'sku_code', 'sku_name', 'status']);

        if ($rows->isEmpty()) {
            $this->info('Không có SKU published nào khớp pattern: '.implode(', ', $patterns));

            return self::SUCCESS;
        }

        $this->line(sprintf('%d SKU published khớp pattern (%s):', $rows->count(), implode(', ', $patterns)));
        $this->table(
            ['sku_code', 'sku_name', 'status'],
            $rows->map(fn (object $row): array => [
                (string) $row->sku_code,
                (string) $row->sku_name,
                (string) $row->status,
            ])->all()
        );

        if ($dryRun) {
            $this->warn('--dry-run: không ghi dữ liệu nào.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('Unpublish '.$rows->count().' SKU trên khỏi App PV public catalogue?')) {
            $this->warn('Đã hủy — không ghi dữ liệu nào.');

            return self::SUCCESS;
        }

        $skuIds = $rows->pluck('sku_id')->map(fn ($id): string => (string) $id)->all();
        $skuCodes = $rows->pluck('sku_code')->map(fn ($code): string => (string) $code)->all();

        DB::transaction(function () use ($skuIds): void {
            DB::table('sku')->whereIn('sku_id', $skuIds)->update([
                'published_to_app' => false,
                'updated_at' => now(),
            ]);
        });

        AuditLogger::record(
            action: 'PV_SEED_SKU_UNPUBLISHED',
            objectType: 'sku',
            objectId: null,
            actorId: null,
            requestId: null,
            afterData: [
                'patterns' => $patterns,
                'sku_ids' => $skuIds,
                'sku_codes' => $skuCodes,
            ],
            reason: 'FE App PV báo cáo dữ liệu fixture QA lộ ra public catalogue — unpublish theo pattern sku_code',
            module: AuditLogger::MODULE_SYSTEM,
        );

        $this->info(sprintf('Đã unpublish %d SKU. Record vẫn còn nguyên cho luồng inbound/outbound/report QA.', count($skuIds)));

        return self::SUCCESS;
    }
}
