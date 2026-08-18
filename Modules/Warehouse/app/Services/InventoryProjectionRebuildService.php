<?php

namespace Modules\Warehouse\Services;

use App\Support\Cache\CacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;

/**
 * WWHN-204 / DEV204-P1-03 — dựng lại projection `inventory_balances` từ ledger bất biến
 * `stock_movements` (WWHN-48 AC#2: sau một intentional drift phải đưa projection về đúng
 * ledger authoritative).
 *
 * RANH GIỚI PHÁP LÝ CỦA LỚP NÀY (đọc kỹ trước khi mở rộng) — DISP-INV-204:
 *
 *  - SRS v1.12 §7.5 cấm "resolve divergence ... by direct ledger or Balance edit". Điều bị
 *    cấm là **hấp thụ chênh lệch thực tế vào số dư** (đặt balance thành một con số không có
 *    trong ledger — đó chính là stocktake/apply-adjustment đã bị REQ-SRS-037 / BR-NOSTK-01..03
 *    gỡ khỏi sản phẩm, xem DISP-STK-230/231/236/237).
 *  - Việc lớp này làm là **tái lập đẳng thức §7.5 dòng 1**: `Balance = Opening + Posted
 *    Receipt - Posted Issue + Posted Reversal`, tức `on_hand_qty := SUM(stock_movements.qty_delta)`
 *    theo đúng dimension. Không có tham số nào cho phép người vận hành đưa vào một con số
 *    tự nghĩ ra; giá trị đích **chỉ** đến từ ledger. Vì thế nó không phải "Balance edit" theo
 *    nghĩa §7.5 mà là sửa chữa một artifact dẫn xuất (§7.1: Inventory_Balance = Projection).
 *  - Bất biến bắt buộc: KHÔNG một câu lệnh nào trong lớp này ghi vào `stock_movements`.
 *    Được kiểm chứng hai tầng: trigger append-only ở DB (migration 2026_08_15_000100 trên
 *    pgsql, 2026_08_19_050100 trên sqlite) và assertion checksum ledger trước/sau ở
 *    `rebuild()` bên dưới.
 *  - Không có endpoint/permission/menu nào gọi lớp này. Bề mặt duy nhất là CLI
 *    `php artisan inventory:reconcile --rebuild`, do đó deployment v1.12 vẫn không chứa
 *    "action sửa tồn trực tiếp" theo AC-SRS-037.
 *
 * Vì sao projection luôn dựng lại được: mọi điểm ghi `inventory_balances` trong hệ
 * (`CurrentBaselineStockService::mutateBalance`, `AlignedOutboundStockService::mutateIssueBalance`,
 * `CurrentBaselineReversalService`) đều insert một dòng `stock_movements` tương ứng trong
 * CÙNG transaction (§7.1 "Updated in same transaction with ledger"). Nên ledger là tập cha
 * đầy đủ của mọi biến động tồn — không có nguồn biến động nào nằm ngoài ledger để mất đi.
 */
class InventoryProjectionRebuildService
{
    public const AVAILABLE = 'AVAILABLE';

    /** Ghi lại ở audit_log để truy vết lệnh nào đã sửa projection. */
    public const AUDIT_ACTION = 'INVENTORY_PROJECTION_REBUILT';

    public function __construct(private readonly LedgerReconciliationService $ledger) {}

    /**
     * Dựng lại projection cho các dimension đang lệch.
     *
     * @param  array{warehouse_id?:string|null,product_id?:string|null,stock_status?:string|null}  $filters
     * @return array{
     *     run_id:string,
     *     dry_run:bool,
     *     checked:int,
     *     repaired:int,
     *     rows:list<array<string,mixed>>,
     *     incidents_resolved:int,
     *     ledger_fingerprint_before:array{rows:int,sum:string},
     *     ledger_fingerprint_after:array{rows:int,sum:string}
     * }
     */
    public function rebuild(
        array $filters = [],
        bool $dryRun = false,
        ?string $actorId = null,
        ?string $reason = null,
    ): array {
        $runId = (string) Str::uuid();
        $fingerprintBefore = $this->ledgerFingerprint();

        $plan = $this->plan($filters);

        if ($dryRun || $plan === []) {
            return [
                'run_id' => $runId,
                'dry_run' => $dryRun,
                'checked' => count($plan),
                'repaired' => 0,
                'rows' => $plan,
                'incidents_resolved' => 0,
                'ledger_fingerprint_before' => $fingerprintBefore,
                'ledger_fingerprint_after' => $fingerprintBefore,
            ];
        }

        $applied = DB::transaction(function () use ($plan, $runId, $actorId, $reason): array {
            $rows = [];
            foreach ($plan as $row) {
                $rows[] = $this->applyOne($row, $runId, $actorId, $reason);
            }

            return $rows;
        });

        $incidentsResolved = $this->resolveIncidents($applied, $runId, $actorId, $reason);

        $fingerprintAfter = $this->ledgerFingerprint();

        // Bất biến cứng: rebuild là thao tác CHỈ ĐỌC đối với ledger. Nếu số dòng hoặc tổng
        // qty_delta của `stock_movements` đổi thì đã có ai đó ghi ledger trong lúc chạy —
        // bằng chứng "projection được dựng từ ledger bất biến" không còn giá trị, phải dừng
        // thật ồn ào thay vì trả PASS im lặng.
        if ($fingerprintAfter !== $fingerprintBefore) {
            throw new \RuntimeException(
                'LEDGER_MUTATED_DURING_REBUILD: stock_movements thay đổi trong lúc rebuild ('
                .json_encode($fingerprintBefore).' -> '.json_encode($fingerprintAfter)
                .'). Rebuild không được ghi ledger; hãy chạy lại khi không có posting đồng thời.'
            );
        }

        // Báo cáo/tồn đọc qua CacheService dùng namespace `stock:version`; projection vừa đổi
        // nên phải bump, nếu không /reports/inventory-balance sẽ trả số cũ sau khi sửa xong.
        app(CacheService::class)->bumpVersion('stock:version');

        return [
            'run_id' => $runId,
            'dry_run' => false,
            'checked' => count($plan),
            'repaired' => count($applied),
            'rows' => $applied,
            'incidents_resolved' => $incidentsResolved,
            'ledger_fingerprint_before' => $fingerprintBefore,
            'ledger_fingerprint_after' => $fingerprintAfter,
        ];
    }

    /**
     * Tập dimension cần sửa + giá trị đích, tính hoàn toàn từ ledger.
     *
     * @return list<array<string,mixed>>
     */
    public function plan(array $filters = []): array
    {
        $balances = $this->ledger->balanceTotals($filters);
        $ledgerTotals = $this->ledger->ledgerTotals($filters);

        $rows = [];
        foreach ($balances->keys()->merge($ledgerTotals->keys())->unique()->values() as $key) {
            [$warehouseId, $productId, $stockStatus] = array_pad(explode('|', (string) $key, 3), 3, '');
            $balance = $balances->get($key);
            $movement = $ledgerTotals->get($key);

            $current = (float) ($balance->on_hand_qty ?? 0);
            $target = (float) ($movement->ledger_qty ?? 0);

            if (bccomp((string) $current, (string) $target, 3) === 0) {
                continue;
            }

            $rows[] = [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'stock_status' => $stockStatus,
                'on_hand_before' => $current,
                'on_hand_after' => $target,
                'variance' => (float) bcsub((string) $current, (string) $target, 3),
                'divergence_type' => match (true) {
                    $balance === null => 'BALANCE_MISSING_FOR_LEDGER',
                    $movement === null => 'LEDGER_MISSING_FOR_BALANCE',
                    default => 'QUANTITY_MISMATCH',
                },
                'action' => $balance === null ? 'INSERT' : 'UPDATE',
            ];
        }

        return $rows;
    }

    /**
     * Ghi một dimension về đúng ledger.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function applyOne(array $row, string $runId, ?string $actorId, ?string $reason): array
    {
        $warehouseId = (string) $row['warehouse_id'];
        $productId = (string) $row['product_id'];
        $stockStatus = (string) $row['stock_status'];
        $target = (float) $row['on_hand_after'];

        // Khoá dòng projection để không đè lên một posting đang chạy song song; posting cũng
        // lockForUpdate đúng dòng này nên hai bên xếp hàng thay vì mất update.
        $balance = DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where(function ($query) use ($productId): void {
                $query->where('sku_id', $productId)->orWhere('product_id', $productId);
            })
            ->where('stock_status', $stockStatus)
            ->lockForUpdate()
            ->first();

        $reserved = (float) ($balance->reserved_qty ?? 0);
        // Cùng công thức mà đường ghi nghiệp vụ đang dùng: bucket AVAILABLE mới có
        // available_qty chạy theo on_hand; bucket khác giữ 0 (xem mutateBalance()).
        $available = $stockStatus === self::AVAILABLE ? max($target - $reserved, 0) : 0.0;

        $before = [
            'on_hand_qty' => (float) ($balance->on_hand_qty ?? 0),
            'available_qty' => (float) ($balance->available_qty ?? 0),
            'quantity' => (float) ($balance->quantity ?? 0),
            'version' => (int) ($balance->version ?? 0),
        ];

        if ($balance === null) {
            $balanceId = (string) Str::uuid();
            DB::table('inventory_balances')->insert([
                'id' => $balanceId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'sku_id' => $productId,
                'stock_status' => $stockStatus,
                'quantity' => $target,
                'on_hand_qty' => $target,
                'available_qty' => $available,
                'reserved_qty' => 0,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $after = ['on_hand_qty' => $target, 'available_qty' => $available, 'quantity' => $target, 'version' => 1];
        } else {
            $balanceId = (string) $balance->id;
            DB::table('inventory_balances')->where('id', $balanceId)->update([
                'quantity' => $target,
                'on_hand_qty' => $target,
                'available_qty' => $available,
                'version' => ((int) $balance->version) + 1,
                'updated_at' => now(),
            ]);
            $after = [
                'on_hand_qty' => $target,
                'available_qty' => $available,
                'quantity' => $target,
                'version' => ((int) $balance->version) + 1,
            ];
        }

        AuditLogger::record(
            action: self::AUDIT_ACTION,
            objectType: 'inventory_balance',
            objectId: $balanceId,
            actorId: $actorId,
            beforeData: $before,
            afterData: $after + [
                'rebuild_run_id' => $runId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'stock_status' => $stockStatus,
                'divergence_type' => $row['divergence_type'],
                'derived_from' => 'stock_movements.SUM(qty_delta)',
            ],
            reason: $reason ?? 'inventory:reconcile --rebuild (WWHN-204 DEV204-P1-03)',
        );

        return $row + ['balance_id' => $balanceId];
    }

    /**
     * Đóng các incident đang mở của những dimension vừa được đưa về đúng ledger.
     *
     * Không đóng bừa: chỉ đóng incident khớp warehouse+product đã thực sự sửa, và ghi
     * `resolution_ref` trỏ về run id để §7.5 truy được ai/khi nào đã xử lý.
     *
     * @param  list<array<string,mixed>>  $applied
     */
    private function resolveIncidents(array $applied, string $runId, ?string $actorId, ?string $reason): int
    {
        if ($applied === []) {
            return 0;
        }

        $pgsql = DB::getDriverName() === 'pgsql';
        $table = $pgsql ? 'reconciliation_incidents' : 'reconciliation_incident';
        $productColumn = $pgsql ? 'sku_id' : 'product_id';

        $resolved = 0;
        foreach ($applied as $row) {
            $affected = DB::table($table)
                ->where('warehouse_id', $row['warehouse_id'])
                ->where($productColumn, $row['product_id'])
                ->whereIn('status', ['OPEN', 'INVESTIGATING'])
                ->update([
                    'status' => 'RESOLVED',
                    'resolution_ref' => 'REBUILD:'.$runId,
                    'reason' => $reason ?? 'Projection dựng lại từ ledger bất biến (inventory:reconcile --rebuild)',
                    'resolved_at' => now(),
                    'resolved_by' => $actorId,
                ]);
            $resolved += $affected;
        }

        return $resolved;
    }

    /**
     * Dấu vân tay của ledger: số dòng + tổng qty_delta.
     *
     * Dùng để chứng minh rebuild không hề ghi vào `stock_movements` — đây là bằng chứng
     * chạy được, không phải lời cam kết trong tài liệu.
     *
     * @return array{rows:int,sum:string}
     */
    public function ledgerFingerprint(): array
    {
        $row = DB::table('stock_movements')
            ->selectRaw('COUNT(*) AS row_count, COALESCE(SUM(qty_delta), 0) AS delta_sum')
            ->first();

        return [
            'rows' => (int) ($row->row_count ?? 0),
            // ép chuỗi số cố định để so sánh không phụ thuộc kiểu trả về của từng driver
            'sum' => number_format((float) ($row->delta_sum ?? 0), 3, '.', ''),
        ];
    }
}
