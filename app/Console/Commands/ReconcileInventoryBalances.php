<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;

/**
 * Đối soát chủ động inventory_balances (projection) với stock_movements (ledger nguồn sự thật).
 *
 * Khác với GET /api/v1/reports/inventory-reconciliation (on-demand, không lưu vết), lệnh này
 * ghi lại các lệch số liệu mới phát hiện vào reconciliation_incidents để có audit trail và
 * cho phép cảnh báo chủ động (cron), thay vì chỉ hiện khi có người chủ động gọi API.
 */
class ReconcileInventoryBalances extends Command
{
    protected $signature = 'inventory:reconcile
        {--dry-run : Chỉ báo cáo, không ghi reconciliation_incidents}';

    protected $description = 'Đối soát inventory_balances với stock_movements, ghi reconciliation_incidents cho lệch số liệu mới phát hiện';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $balances = DB::table('inventory_balances')
            ->select('warehouse_id', 'product_id', 'stock_status', 'on_hand_qty')
            ->get()
            ->keyBy(fn ($r) => $this->dimensionKey($r->warehouse_id, $r->product_id, $r->stock_status));

        $movements = DB::table('stock_movements')
            ->select('warehouse_id', 'product_id', 'stock_status')
            ->selectRaw('SUM(qty_delta) AS ledger_qty')
            ->groupBy('warehouse_id', 'product_id', 'stock_status')
            ->get()
            ->keyBy(fn ($r) => $this->dimensionKey($r->warehouse_id, $r->product_id, $r->stock_status));

        $openIncidentDimensions = DB::table('reconciliation_incidents')
            ->whereIn('status', ['OPEN', 'INVESTIGATING'])
            ->get(['warehouse_id', 'sku_id', 'divergence_type'])
            ->map(fn ($r) => $r->warehouse_id.'|'.$r->sku_id)
            ->unique()
            ->flip();

        $keys = $balances->keys()->merge($movements->keys())->unique()->values();

        $checked = 0;
        $created = 0;
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

            if (! $dryRun) {
                $this->recordIncident($warehouseId, $productId, $stockStatus, $divergenceType, $onHand, $ledger, $variance);
                $openIncidentDimensions->put($incidentDimKey, true);
            }
            $created++;
        }

        $this->table(
            ['warehouse_id', 'product_id', 'stock_status', 'on_hand_qty', 'ledger_qty', 'variance', 'divergence_type'],
            $rows
        );

        $mode = $dryRun ? '(dry-run, không ghi incident)' : '';
        $this->info("Đối soát hoàn tất: {$checked} dimension kiểm tra, ".count($rows)." lệch phát hiện, {$created} incident mới {$mode}");

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
