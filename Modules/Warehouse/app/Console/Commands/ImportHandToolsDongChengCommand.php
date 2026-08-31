<?php

namespace Modules\Warehouse\Console\Commands;

use Illuminate\Console\Command;
use Modules\Warehouse\Services\HandToolsCatalogExcelMapper;
use Modules\Warehouse\Services\PvCatalogImportService;

/**
 * BA/Data Entry chạy lệnh này để import catalogue Hand Tools DongCheng từ file
 * Excel nguồn qua đúng `PvCatalogImportService` (không có đường tắt riêng nào
 * khác ghi `sku`/hồ sơ App PV) — cùng luồng validate/sanitize/atomic mà
 * `POST /api/v1/pv-admin/catalog-import` dùng.
 *
 * Mặc định là DRY-RUN. `--commit` chỉ commit đúng batch "sạch" (loại toàn bộ
 * mã SKU nguồn bị trùng, vd. D094101/D156601) — hai mã trùng đó KHÔNG BAO GIỜ
 * được lệnh này tự chọn một dòng để giữ; BA phải quyết định mã đúng rồi sửa
 * file nguồn/gọi importer riêng cho các dòng đó.
 */
class ImportHandToolsDongChengCommand extends Command
{
    protected $signature = 'pv:import-hand-tools-dongcheng
        {path : Đường dẫn file .xlsx nguồn}
        {--commit : Ghi thật (mặc định chỉ dry-run, không ghi gì)}';

    protected $description = 'Import catalogue Hand Tools DongCheng từ Excel qua PvCatalogImportService (dry-run mặc định)';

    public function handle(HandToolsCatalogExcelMapper $mapper, PvCatalogImportService $importService): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("Không tìm thấy file: {$path}");

            return self::FAILURE;
        }

        $mapped = $mapper->map($path);
        $total = count($mapped['items']);
        $cleanCount = count($mapped['clean_items']);

        $this->info("Đọc được {$total} dòng sản phẩm từ file nguồn.");

        if ($mapped['unmapped_categories'] !== []) {
            $this->warn('Category chưa có trong CATEGORY_CODE_MAP (dòng tương ứng bị BỎ QUA, không import): '.implode(', ', $mapped['unmapped_categories']));
        }

        if ($mapped['conflicted_codes'] !== []) {
            $this->warn('Mã SKU nguồn bị trùng (BA/Data Entry cần quyết định mã đúng trước khi import các dòng này):');
            foreach ($mapped['conflicted_codes'] as $code => $rows) {
                $this->line("  - {$code}: dòng Excel ".implode(', ', $rows));
            }
        }

        $this->line("Batch sạch (không mâu thuẫn): {$cleanCount}/{$total} dòng.");

        if (! $this->option('commit')) {
            $this->line('--- Dry-run TOÀN BỘ ('.$total.' dòng, bao gồm cả mã trùng — kỳ vọng thấy lỗi DUPLICATE_CODE_IN_BATCH) ---');
            $this->printResult($importService->import($mapped['items'], dryRun: true, actorId: null, requestId: null));

            $this->line('--- Dry-run BATCH SẠCH ('.$cleanCount.' dòng, loại mã trùng — kỳ vọng 0 lỗi) ---');
            $this->printResult($importService->import($mapped['clean_items'], dryRun: true, actorId: null, requestId: null));

            $this->comment('Không có gì được ghi (dry-run). Dùng --commit để ghi thật batch sạch.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Ghi thật {$cleanCount} SKU (batch sạch) vào DB? Mã trùng KHÔNG được commit.")) {
            $this->warn('Đã hủy — không ghi dữ liệu nào.');

            return self::SUCCESS;
        }

        $result = $importService->import($mapped['clean_items'], dryRun: false, actorId: null, requestId: null);
        $this->printResult($result);

        if (($result['committed'] ?? false) === true) {
            $this->info('Đã commit '.($result['inserted'] ?? 0).' SKU, published_to_app=false cho toàn bộ.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printResult(array $result): void
    {
        if (isset($result['_error_code'])) {
            $this->error("{$result['_error_code']}: {$result['_message']}");

            return;
        }

        $this->line(sprintf(
            'committed=%s dry_run=%s total_rows=%d would_insert=%d inserted=%d',
            json_encode($result['committed'] ?? false),
            json_encode($result['dry_run'] ?? true),
            $result['total_rows'] ?? 0,
            $result['would_insert'] ?? 0,
            $result['inserted'] ?? 0,
        ));

        $rowErrors = $result['row_errors'] ?? [];
        if ($rowErrors === []) {
            return;
        }

        $this->table(
            ['row', 'code', 'field', 'message', 'value'],
            array_map(static fn (array $e): array => [
                $e['row'], $e['code'], $e['field'], $e['message'], is_scalar($e['value']) ? (string) $e['value'] : json_encode($e['value']),
            ], $rowErrors)
        );
    }
}
