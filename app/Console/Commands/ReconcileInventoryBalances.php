<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\InventoryProjectionRebuildService;
use Modules\Warehouse\Services\LedgerReconciliationService;

/**
 * Đối soát chủ động inventory_balances (projection) với stock_movements (ledger nguồn sự thật).
 *
 * Khác với GET /api/v1/reports/inventory-reconciliation (on-demand, không lưu vết), lệnh này
 * ghi lại các lệch số liệu mới phát hiện vào reconciliation_incidents để có audit trail và
 * cho phép cảnh báo chủ động (cron), thay vì chỉ hiện khi có người chủ động gọi API.
 *
 * WWHN-204 (DEV204-P1-03) bổ sung `--rebuild`: sau khi đã ghi vết divergence, dựng lại
 * projection từ ledger bất biến để đóng WWHN-48 AC#2. Xem ranh giới với §7.5 / REQ-SRS-037
 * ở docs/decision-records/DISP-INV-204-projection-rebuild.md và trong
 * InventoryProjectionRebuildService.
 */
class ReconcileInventoryBalances extends Command
{
    protected $signature = 'inventory:reconcile
        {--dry-run : Chỉ báo cáo, không ghi reconciliation_incidents và không sửa projection}
        {--rebuild : Dựng lại inventory_balances từ ledger stock_movements (WWHN-48 AC#2 / DEV204-P1-03)}
        {--reason= : Lý do ghi vào audit_log và resolution_ref khi rebuild}';

    protected $description = 'Đối soát inventory_balances với stock_movements, ghi reconciliation_incidents cho lệch số liệu mới phát hiện; --rebuild đưa projection về đúng ledger authoritative';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // WWHN-231: lệnh cron và GET /reports/inventory-reconciliation dùng CHUNG một
        // aggregate ledger. Trước đây mỗi bên tự viết một biến thể GROUP BY, nên hai bên có
        // thể kết luận khác nhau trên cùng dữ liệu.
        $ledgerService = app(LedgerReconciliationService::class);
        $balances = $ledgerService->balanceTotals();
        $movements = $ledgerService->ledgerTotals();

        $openIncidentDimensions = DB::table('reconciliation_incidents')
            ->whereIn('status', ['OPEN', 'INVESTIGATING'])
            ->get(['warehouse_id', 'sku_id', 'divergence_type'])
            ->map(fn ($r) => $r->warehouse_id.'|'.$r->sku_id)
            ->unique()
            ->flip();

        $keys = $balances->keys()->merge($movements->keys())->unique()->values();

        $checked = 0;
        $created = 0;
        $newIncidentCandidates = 0;
        $rows = [];

        foreach ($keys as $key) {
            $checked++;
            [$warehouseId, $productId, $stockStatus] = explode('|', $key, 3);
            $balance = $balances->get($key);
            $movement = $movements->get($key);
            $onHand = (float) ($balance->on_hand_qty ?? 0);
            $ledger = (float) ($movement->ledger_qty ?? 0);
            $variance = bcsub((string) $onHand, (string) $ledger, 3);

            if (bccomp($variance, '0', 3) === 0) {
                continue;
            }

            $divergenceType = match (true) {
                $balance === null => 'BALANCE_MISSING_FOR_LEDGER',
                $movement === null => 'LEDGER_MISSING_FOR_BALANCE',
                default => 'QUANTITY_MISMATCH',
            };

            $rows[] = [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'stock_status' => $stockStatus,
                'on_hand_qty' => $onHand,
                'ledger_qty' => $ledger,
                'variance' => (float) $variance,
                'divergence_type' => $divergenceType,
            ];

            $incidentDimKey = $warehouseId.'|'.$productId;
            if ($openIncidentDimensions->has($incidentDimKey)) {
                continue;
            }

            $newIncidentCandidates++;

            if (! $dryRun) {
                $this->recordIncident($warehouseId, $productId, $stockStatus, $divergenceType, $onHand, $ledger, $variance);
                $openIncidentDimensions->put($incidentDimKey, true);
                $created++;
            }
        }

        $this->table(
            ['warehouse_id', 'product_id', 'stock_status', 'on_hand_qty', 'ledger_qty', 'variance', 'divergence_type'],
            $rows
        );

        if ($dryRun) {
            // $created chỉ tăng sau khi recordIncident() thực sự ghi — trong dry-run
            // không được gọi nên $created luôn là 0; dùng $newIncidentCandidates để
            // báo "sẽ tạo bao nhiêu" mà không gây hiểu nhầm là đã ghi incident (DEV219-P2-01R).
            $this->info("Đối soát hoàn tất: {$checked} dimension kiểm tra, ".count($rows)." lệch phát hiện, {$newIncidentCandidates} incident sẽ được tạo nếu chạy thật (dry-run, không ghi incident)");
        } else {
            $this->info("Đối soát hoàn tất: {$checked} dimension kiểm tra, ".count($rows)." lệch phát hiện, {$created} incident mới");
        }

        if ($this->option('rebuild')) {
            return $this->runRebuild($dryRun);
        }

        return self::SUCCESS;
    }

    /**
     * DEV204-P1-03 / WWHN-48 AC#2 — đưa projection về đúng ledger authoritative.
     *
     * Đặt SAU bước phát hiện có chủ đích: incident được ghi trước (lưu vết là đã từng lệch),
     * rồi rebuild mới đóng incident đó kèm resolution_ref. Nếu rebuild chạy trước thì §7.5
     * mất hẳn dấu vết "đã có divergence" — chỉ còn thấy hệ thống luôn khớp.
     *
     * Ranh giới nghiệp vụ (DISP-INV-204): giá trị đích lấy 100% từ SUM(qty_delta), không có
     * tham số nhập tay, không ghi ledger — nên đây KHÔNG phải "direct Balance edit" bị §7.5
     * cấm, cũng không phải capability sửa tồn trực tiếp bị REQ-SRS-037 loại bỏ.
     */
    private function runRebuild(bool $dryRun): int
    {
        $service = app(InventoryProjectionRebuildService::class);

        try {
            $result = $service->rebuild(
                dryRun: $dryRun,
                actorId: null,
                reason: $this->option('reason') ?: null,
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['warehouse_id', 'product_id', 'stock_status', 'on_hand_before', 'on_hand_after', 'divergence_type', 'action'],
            array_map(static fn (array $r): array => [
                $r['warehouse_id'], $r['product_id'], $r['stock_status'],
                $r['on_hand_before'], $r['on_hand_after'], $r['divergence_type'], $r['action'],
            ], $result['rows']),
        );

        if ($dryRun) {
            $this->info('Rebuild (dry-run): '.$result['checked'].' dimension sẽ được đưa về ledger, chưa ghi gì.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Rebuild hoàn tất: run_id=%s, %d dimension đưa về ledger, %d incident chuyển RESOLVED. Ledger bất biến: %d dòng / tổng delta %s (không đổi trước-sau).',
            $result['run_id'],
            $result['repaired'],
            $result['incidents_resolved'],
            $result['ledger_fingerprint_after']['rows'],
            $result['ledger_fingerprint_after']['sum'],
        ));

        return self::SUCCESS;
    }

    private function recordIncident(
        string $warehouseId,
        string $productId,
        string $stockStatus,
        string $divergenceType,
        float $onHand,
        float $ledger,
        string $variance,
    ): void {
        $incidentId = (string) Str::uuid();
        $incidentNo = 'REC-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
        $severity = $divergenceType === 'QUANTITY_MISMATCH' ? 'CRITICAL' : 'ERROR';
        $detectedValue = json_encode([
            'stock_status' => $stockStatus,
            'on_hand_qty' => $onHand,
            'ledger_qty' => $ledger,
            'variance' => (float) $variance,
        ]);

        // ERD bảng 32: số liệu divergence phải nằm ở cột rời (ledger_qty/balance_qty/
        // difference_qty/severity/detected_at) để §7.5 truy vấn/báo cáo được, không chỉ
        // nằm trong JSON detected_value.
        $erdColumns = [
            'incident_no' => $incidentNo,
            'divergence_type' => $divergenceType,
            'warehouse_id' => $warehouseId,
            'detected_value' => $detectedValue,
            'detected_at' => now(),
            'balance_qty' => (int) round($onHand),
            'ledger_qty' => (int) round($ledger),
            'difference_qty' => (int) round((float) $variance),
            'severity' => $severity,
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // `reconciliation_incidents` (plural) is the canonical writable table on the
        // pgsql baseline; `reconciliation_incident` (singular) there is a read-only
        // compatibility view that rejects writes via trigger. On the sqlite test baseline
        // only the singular table exists (plural is a plain view), so target it instead.
        if (DB::getDriverName() === 'pgsql') {
            DB::table('reconciliation_incidents')->insert($erdColumns + [
                'id' => $incidentId,
                'sku_id' => $productId,
            ]);
        } else {
            DB::table('reconciliation_incident')->insert($erdColumns + [
                'incident_id' => $incidentId,
                'product_id' => $productId,
            ]);
        }

        AuditLogger::record(
            action: 'RECONCILIATION_INCIDENT_DETECTED',
            objectType: 'reconciliation_incident',
            objectId: $incidentId,
            actorId: null,
            afterData: [
                'incident_no' => $incidentNo,
                'divergence_type' => $divergenceType,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'stock_status' => $stockStatus,
                'on_hand_qty' => $onHand,
                'ledger_qty' => $ledger,
                'variance' => (float) $variance,
                'severity' => $severity,
            ],
            reason: 'Phát hiện tự động qua inventory:reconcile',
        );
    }

    private function dimensionKey(?string $warehouseId, ?string $productId, ?string $stockStatus): string
    {
        return ($warehouseId ?? '').'|'.($productId ?? '').'|'.($stockStatus ?? 'AVAILABLE');
    }
}
