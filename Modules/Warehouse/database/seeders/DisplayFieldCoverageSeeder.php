<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Support\SeedEnvironment;
use Modules\Warehouse\Services\ImportErrorFileService;
use Modules\Warehouse\Support\SeedRunContext;
use RuntimeException;

/**
 * Fixture display enrichment inside the isolated default chain only.
 * Missing location/note/catalog membership is never historical ownership proof.
 * Immutable document/label history is not rewritten to improve display coverage.
 */
class DisplayFieldCoverageSeeder extends Seeder
{
    private const ZONES = ['ZONE-A', 'ZONE-B', 'ZONE-C', 'ZONE-D'];

    private const MIN_TRAYS = 10;

    public function run(): void
    {
        if ((bool) config('deployment_safety.protect_database', true)) {
            throw new RuntimeException('DISPLAY_FIXTURE_SEED_PROTECTED_DATABASE');
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        SeedEnvironment::assertFixtureAccountsAllowed(self::class);
        if (! app(SeedRunContext::class)->active()) {
            throw new RuntimeException('DISPLAY_FIXTURE_PROVENANCE_REQUIRED: use the isolated default fixture chain.');
        }
        DB::transaction(function (): void {
            $trays = app(SeedRunContext::class)->freshUnmixedBalanceIds() === []
                ? [] : $this->ensureStorageTrays();
            $this->placeSkusIntoTrays($trays);
        });
        $this->backfillImportErrorFiles();
        $this->backfillCancelReasons();
        $this->backfillLineNotes();
        $this->reportCoverage();
    }

    /** Read-only coverage counts, not proof that a seed was complete or idempotent. */
    private function reportCoverage(): void
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
              (SELECT count(DISTINCT sku_id) FROM container_items
                WHERE sku_id IS NOT NULL AND status = 'ACTIVE') AS sku_placed,
              (SELECT count(*) FROM import_reports
                WHERE error_rows > 0 AND error_file_path IS NOT NULL) AS import_files,
              (SELECT count(*) FROM stock_documents
                WHERE status = 'CANCELLED' AND reject_reason IS NOT NULL) AS cancel_reasons,
              (SELECT count(*) FROM inbound_lines WHERE note IS NOT NULL)
              + (SELECT count(*) FROM outbound_lines WHERE note IS NOT NULL)
              + (SELECT count(*) FROM component_issue_lines WHERE note IS NOT NULL)
              + (SELECT count(*) FROM outbound_request_lines WHERE note IS NOT NULL) AS line_notes
        SQL);
        $this->command?->info(sprintf(
            '[DisplayFieldCoverage] độ phủ hiện tại — SKU có vị trí: %d · lô import có tệp lỗi: %d · chứng từ huỷ có lý do: %d · dòng có ghi chú: %d',
            (int) $row->sku_placed,
            (int) $row->import_files,
            (int) $row->cancel_reasons,
            (int) $row->line_notes,
        ));
    }

    /** @return list<string> Exact fresh fixture trays, never arbitrary operational trays. */
    private function ensureStorageTrays(): array
    {
        $owned = app(SeedRunContext::class)->freshIds('containers');
        $existing = DB::table('containers')->whereIn('container_id', $owned)
            ->where('container_type', 'TRAY')->whereNull('primary_sku_id')
            ->whereNotNull('zone_code')->orderBy('container_code')
            ->pluck('container_id')->map(static fn ($id) => (string) $id)->all();
        for ($index = count($existing); $index < self::MIN_TRAYS; $index++) {
            $code = sprintf('TRAY-COV-%02d', $index + 1);
            $already = DB::table('containers')->where('container_code', $code)->value('container_id');
            if ($already !== null) {
                if (! in_array((string) $already, $owned, true)) {
                    throw new RuntimeException('FIXTURE_TRAY_PROVENANCE_REQUIRED: existing code is not ownership.');
                }
                $existing[] = (string) $already;

                continue;
            }
            $id = (string) Str::uuid();
            DB::table('containers')->insert([
                'container_id' => $id, 'id' => $id, 'container_code' => $code,
                'container_type' => 'TRAY', 'primary_sku_id' => null,
                'zone_code' => self::ZONES[$index % count(self::ZONES)],
                'location_hint' => sprintf('%s · Kệ %02d', self::ZONES[$index % count(self::ZONES)], $index + 1),
                'status' => 'STORED', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $existing[] = $id;
        }

        return $existing;
    }

    /** New fixture stock only; no inferred mapping for pre-existing stock of the same SKU. */
    private function placeSkusIntoTrays(array $trays): int
    {
        $balanceIds = app(SeedRunContext::class)->freshUnmixedBalanceIds();
        if ($trays === [] || $balanceIds === []) {
            return 0;
        }
        $rows = DB::table('inventory_balances as b')->whereIn('b.id', $balanceIds)
            ->whereNotNull('b.product_id')->groupBy('b.product_id')->havingRaw('SUM(b.quantity) > 0')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('container_items as ci')
                ->whereColumn('ci.sku_id', 'b.product_id')->where('ci.status', 'ACTIVE'))
            ->orderBy('b.product_id')->selectRaw('b.product_id AS sku_id, SUM(b.quantity)::bigint AS qty')->get();
        $actor = DB::table('app_user')->where('username', 'keeper01')->value('user_id')
            ?? DB::table('app_user')->where('username', 'admin')->value('user_id');
        if ($actor === null && $rows->isNotEmpty()) {
            throw new RuntimeException('FIXTURE_STORAGE_ACTOR_REQUIRED');
        }
        $placed = 0;
        foreach ($rows as $index => $row) {
            DB::table('container_items')->insert([
                'container_item_id' => (string) Str::uuid(),
                'container_id' => $trays[$index % count($trays)], 'item_id' => null,
                'sku_id' => (string) $row->sku_id, 'quantity' => (int) $row->qty,
                'status' => 'ACTIVE', 'is_active' => true, 'source_type' => 'SEED_COVERAGE',
                'placed_at' => now()->subDays(($index % 45) + 1), 'placed_by' => $actor,
                'created_by' => $actor, 'updated_by' => $actor, 'version' => 1,
                'created_at' => now()->subDays(($index % 45) + 1), 'updated_at' => now(),
            ]);
            $placed++;
        }

        return $placed;
    }

    private function backfillImportErrorFiles(): int
    {
        $ids = app(SeedRunContext::class)->freshIds('import_reports');
        if ($ids === []) {
            return 0;
        }
        $service = app(ImportErrorFileService::class);
        $reports = DB::table('import_reports')->whereIn('import_report_id', $ids)
            ->where('error_rows', '>', 0)->whereNull('error_file_path')
            ->get(['import_report_id', 'file_name', 'row_errors']);
        $placed = 0;
        foreach ($reports as $report) {
            $rowErrors = json_decode((string) $report->row_errors, true);
            if (! is_array($rowErrors) || $rowErrors === []) {
                throw new RuntimeException('FIXTURE_IMPORT_ERROR_DETAILS_REQUIRED');
            }
            $generated = $service->generate((string) $report->import_report_id, $rowErrors, (string) $report->file_name, null);
            if ($generated === null) {
                throw new RuntimeException('FIXTURE_IMPORT_ERROR_FILE_GENERATION_FAILED');
            }
            DB::table('import_reports')->where('import_report_id', $report->import_report_id)
                ->whereNull('error_file_path')->update([
                    'error_file_path' => $generated['path'],
                    'error_file_expires_at' => $generated['expires_at'], 'updated_at' => now(),
                ]);
            $placed++;
        }

        return $placed;
    }

    /** Cancellation evidence must be written by its owner during cancellation, not invented afterward. */
    private function backfillCancelReasons(): int
    {
        $this->command?->info('Cancelled reasons remain unchanged; missing historical evidence is not synthesized.');

        return 0;
    }

    /** Historical inbound/outbound/component lines remain immutable here. */
    private function backfillLineNotes(): int
    {
        $ids = app(SeedRunContext::class)->freshIds('outbound_request_lines');
        if ($ids === []) {
            return 0;
        }
        $notes = [
            'Ưu tiên giao trước cho đại lý khu vực',
            'Chờ xác nhận số lượng cuối từ khách',
            'Gộp chung chuyến với yêu cầu cùng tuyến',
        ];
        $rows = DB::table('outbound_request_lines as l')->whereIn('l.id', $ids)->whereNull('l.note')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('outbound_requests as r')
                ->whereColumn('r.id', 'l.outbound_request_id')->where('r.status', 'OPEN'))
            ->orderBy('l.id')->pluck('l.id');
        $total = 0;
        foreach ($rows as $id) {
            $index = crc32((string) $id) % (count($notes) + 1);
            if ($index < count($notes)) {
                $total += DB::table('outbound_request_lines')->where('id', $id)->whereNull('note')
                    ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('outbound_requests as r')
                        ->whereColumn('r.id', 'outbound_request_lines.outbound_request_id')->where('r.status', 'OPEN'))
                    ->update(['note' => $notes[$index]]);
            }
        }

        return $total;
    }
}
