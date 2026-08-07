<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\StocktakeCount;
use Modules\Warehouse\Models\StocktakeSession;
use Modules\Warehouse\Models\StocktakeVariance;
use Modules\Warehouse\Models\Warehouse;

class StocktakeService
{
    private const SCALE = 3;

    public const GRAIN_SKU = 'SKU';

    public const GRAIN_ITEM = 'ITEM';

    public const GRAIN_CONTAINER = 'CONTAINER';

    public function __construct(
        private readonly StocktakeGrainService $grains,
        private readonly StocktakeSessionGuard $guard
    ) {}

    /** @return array{session: StocktakeSession} */
    public function createSession(array $data, User $user): array
    {
        $this->guard->assertWarehouseScope($user, (string) $data['warehouse_id']);
        $warehouse = Warehouse::query()->find($data['warehouse_id']);
        if (! $warehouse || $warehouse->status !== 'ACTIVE') {
            ApiError::abort(422, 'WAREHOUSE_NOT_ACTIVE', 'Kho kiểm kê không tồn tại hoặc không hoạt động.');
        }

        $session = StocktakeSession::query()->create([
            'session_id' => (string) Str::uuid(),
            'session_no' => $data['session_no'] ?? $this->generateSessionNo(),
            'warehouse_id' => $warehouse->id,
            'status' => StocktakeSession::STATUS_DRAFT,
            'stocktake_started_at' => null,
            'count_cutoff_at' => null,
            'movement_warning_flag' => false,
            'created_by' => $user->user_id,
            'session_version' => 1,
        ]);

        return ['session' => $session->fresh()];
    }

    /** @return array{session: StocktakeSession, snapshot_rows: array<string, int>} */
    public function startSession(string $sessionId, int $expectedVersion, User $user): array
    {
        return DB::transaction(function () use ($sessionId, $expectedVersion, $user): array {
            $session = $this->guard->lockScoped($sessionId, $user);
            $this->guard->assertVersion($session, $expectedVersion);
            $this->guard->assertStatus($session, [StocktakeSession::STATUS_DRAFT], 'bắt đầu kiểm kê');

            $startedAt = now();
            $updated = StocktakeSession::query()
                ->where('session_id', $session->session_id)
                ->where('status', StocktakeSession::STATUS_DRAFT)
                ->where('session_version', $expectedVersion)
                ->update([
                    'status' => StocktakeSession::STATUS_IN_PROGRESS,
                    'stocktake_started_at' => $startedAt,
                    'count_cutoff_at' => null,
                    'movement_warning_flag' => false,
                    'session_version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                ApiError::abort(409, 'SESSION_VERSION_CONFLICT', 'Stocktake session đã được thay đổi bởi request khác.');
            }

            $snapshotRows = $this->grains->createSnapshots(
                (string) $session->warehouse_id,
                (string) $session->session_id,
                $startedAt
            );

            return ['session' => $session->fresh(), 'snapshot_rows' => $snapshotRows];
        }, 3);
    }

    /** @return array{session: StocktakeSession, counts_created: int, count_ids: array<int, string>} */
    public function recordCounts(string $sessionId, array $lines, User $user): array
    {
        return DB::transaction(function () use ($sessionId, $lines, $user): array {
            $session = $this->guard->lockScoped($sessionId, $user);
            $this->guard->assertStatus($session, [StocktakeSession::STATUS_IN_PROGRESS], 'ghi nhận số đếm');

            $resolvedLines = collect($lines)->map(fn (array $line) => $this->grains->resolveCountGrain($session, $line));
            $duplicateKeys = $resolvedLines->pluck('grain_key')->duplicates()->values();
            if ($duplicateKeys->isNotEmpty()) {
                ApiError::abort(422, 'DUPLICATE_COUNT_GRAIN', 'Payload chứa cùng grain kiểm kê nhiều lần.', [
                    'grain_keys' => $duplicateKeys->all(),
                ]);
            }

            $existing = StocktakeCount::query()
                ->where('session_id', $session->session_id)
                ->whereIn('grain_key', $resolvedLines->pluck('grain_key')->all())
                ->pluck('grain_key')
                ->all();
            if ($existing !== []) {
                ApiError::abort(409, 'DUPLICATE_COUNT_GRAIN', 'Grain đã được đếm trong session này.', [
                    'grain_keys' => $existing,
                ]);
            }

            $countIds = [];
            try {
                foreach ($resolvedLines as $line) {
                    $count = StocktakeCount::query()->create([
                        'count_id' => (string) Str::uuid(),
                        'session_id' => $session->session_id,
                        'grain_type' => $line['grain_type'],
                        'grain_id' => $line['grain_id'],
                        'grain_key' => $line['grain_key'],
                        'product_id' => $line['product_id'],
                        'stock_status' => $line['stock_status'],
                        'inventory_serial_id' => $line['inventory_serial_id'],
                        'container_id' => $line['container_id'],
                        'container_item_id' => $line['container_item_id'],
                        'actual_qty' => $line['actual_qty'],
                        'count_cutoff_at' => $line['counted_at'],
                        'counted_by' => $user->user_id,
                        'counted_at' => $line['counted_at'],
                    ]);
                    $countIds[] = (string) $count->count_id;
                }
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    ApiError::abort(409, 'DUPLICATE_COUNT_GRAIN', 'Grain đã được đếm bởi request cạnh tranh.');
                }
                throw $exception;
            }

            return [
                'session' => $session->fresh(),
                'counts_created' => count($countIds),
                'count_ids' => $countIds,
            ];
        }, 3);
    }

    /** @return array{session: StocktakeSession, variance_count: int, movement_warning_count: int, variances: mixed} */
    public function reviewSession(string $sessionId, int $expectedVersion, User $user): array
    {
        return DB::transaction(function () use ($sessionId, $expectedVersion, $user): array {
            $session = $this->guard->lockScoped($sessionId, $user);
            $this->guard->assertVersion($session, $expectedVersion);
            $this->guard->assertStatus(
                $session,
                [StocktakeSession::STATUS_IN_PROGRESS, StocktakeSession::STATUS_COUNTED],
                'review kiểm kê'
            );

            $counts = StocktakeCount::query()
                ->where('session_id', $session->session_id)
                ->orderBy('counted_at')
                ->orderBy('count_id')
                ->lockForUpdate()
                ->get();
            if ($counts->isEmpty()) {
                ApiError::abort(409, 'STOCKTAKE_COUNT_REQUIRED', 'Session chưa có count để review.');
            }

            $dimensions = $counts->groupBy(fn (StocktakeCount $count): string => $count->product_id.'|'.$count->stock_status);
            $mixedDimensions = $dimensions
                ->filter(fn ($rows): bool => $rows->pluck('grain_type')->unique()->count() > 1)
                ->keys()
                ->values();
            if ($mixedDimensions->isNotEmpty()) {
                ApiError::abort(409, 'MIXED_GRAIN_COUNT_NOT_ALLOWED', 'Một product-stock_status chỉ được review theo một grain_type để tránh double count.', [
                    'dimensions' => $mixedDimensions->all(),
                ]);
            }

            $reviewedAt = now();
            $movementWarningCount = 0;
            $varianceIds = [];

            foreach ($dimensions as $rows) {
                /** @var StocktakeCount $firstCount */
                $firstCount = $rows->first();
                $dimensionCutoff = $rows->max('counted_at') ?? $reviewedAt;
                $snapshots = DB::table('stocktake_snapshot')
                    ->where('session_id', $session->session_id)
                    ->whereIn('grain_key', $rows->pluck('grain_key')->all())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('grain_key');
                if ($snapshots->count() !== $rows->count()) {
                    ApiError::abort(409, 'STOCKTAKE_SNAPSHOT_MISSING', 'Có count không có snapshot tương ứng trong session.');
                }

                $snapshotTotal = $rows->reduce(
                    fn (string $carry, StocktakeCount $count): string => bcadd(
                        $carry,
                        (string) $snapshots->get($count->grain_key)->snapshot_qty,
                        self::SCALE
                    ),
                    '0'
                );
                $actualTotal = $rows->reduce(
                    fn (string $carry, StocktakeCount $count): string => bcadd($carry, (string) $count->actual_qty, self::SCALE),
                    '0'
                );

                $movementQuery = DB::table('stock_movements')
                    ->where('warehouse_id', $session->warehouse_id)
                    ->where('product_id', $firstCount->product_id)
                    ->where('stock_status', $firstCount->stock_status)
                    ->where('occurred_at', '>=', $session->stocktake_started_at);
                $movementsAtCount = (clone $movementQuery)
                    ->where('occurred_at', '<=', $dimensionCutoff)
                    ->orderBy('occurred_at')
                    ->orderBy('id')
                    ->get(['id', 'qty_delta']);
                $detectedMovements = (clone $movementQuery)
                    ->where('occurred_at', '<=', $reviewedAt)
                    ->orderBy('occurred_at')
                    ->orderBy('id')
                    ->get(['id', 'qty_delta']);

                // Signed convention: receipt/adjust-in positive; issue/adjust-out negative.
                // Therefore canonical net movement is SUM(qty_delta) without movement_type remapping.
                $netMovement = $movementsAtCount->reduce(
                    fn (string $carry, object $movement): string => bcadd($carry, (string) $movement->qty_delta, self::SCALE),
                    '0'
                );
                $expectedDimensionAtCount = bcadd($snapshotTotal, $netMovement, self::SCALE);
                $inventoryEffect = bcsub($actualTotal, $expectedDimensionAtCount, self::SCALE);
                $detectedIds = $detectedMovements->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
                if ($detectedIds !== []) {
                    $movementWarningCount++;
                }

                $balanceAtReview = DB::table('inventory_balances')
                    ->where('warehouse_id', $session->warehouse_id)
                    ->where('product_id', $firstCount->product_id)
                    ->where('stock_status', $firstCount->stock_status)
                    ->lockForUpdate()
                    ->first(['on_hand_qty', 'version']);
                if (! $balanceAtReview) {
                    ApiError::abort(409, 'STOCKTAKE_BALANCE_MISSING', 'Không có balance cho dimension được review.');
                }

                foreach ($rows->values() as $index => $count) {
                    $snapshot = $snapshots->get($count->grain_key);
                    $isSku = $count->grain_type === self::GRAIN_SKU;
                    $expectedAtCount = $isSku ? $expectedDimensionAtCount : (string) $snapshot->snapshot_qty;
                    $varianceQty = $isSku
                        ? $inventoryEffect
                        : bcsub((string) $count->actual_qty, (string) $snapshot->snapshot_qty, self::SCALE);
                    $effectForRow = $index === 0 ? $inventoryEffect : '0.000';
                    $detectedForRow = $index === 0 ? $detectedIds : [];

                    $existing = StocktakeVariance::query()
                        ->where('session_id', $session->session_id)
                        ->where('grain_key', $count->grain_key)
                        ->lockForUpdate()
                        ->first();
                    if ($existing && $existing->status === StocktakeVariance::STATUS_APPLIED) {
                        ApiError::abort(409, 'VARIANCE_ALREADY_APPLIED', 'Không thể review lại variance đã apply.');
                    }

                    $acknowledgedIds = $existing?->acknowledged_movement_ids ?? [];
                    if ($this->sortedIds($acknowledgedIds) !== $this->sortedIds($detectedForRow)) {
                        $acknowledgedIds = [];
                    }

                    $variance = StocktakeVariance::query()->updateOrCreate(
                        ['session_id' => $session->session_id, 'grain_key' => $count->grain_key],
                        [
                            'variance_id' => $existing?->variance_id ?? (string) Str::uuid(),
                            'grain_type' => $count->grain_type,
                            'grain_id' => $count->grain_id,
                            'product_id' => $count->product_id,
                            'stock_status' => $count->stock_status,
                            'inventory_serial_id' => $count->inventory_serial_id,
                            'container_id' => $count->container_id,
                            'container_item_id' => $count->container_item_id,
                            'expected_qty' => (string) $snapshot->snapshot_qty,
                            'expected_at_count' => $expectedAtCount,
                            'actual_qty' => (string) $count->actual_qty,
                            'variance_qty' => $varianceQty,
                            'inventory_effect_qty' => $effectForRow,
                            'balance_qty_at_review' => (string) $balanceAtReview->on_hand_qty,
                            'balance_version_at_review' => (int) $balanceAtReview->version,
                            'detected_movement_ids' => $detectedForRow,
                            'acknowledged_movement_ids' => $acknowledgedIds,
                            'acknowledged_by' => $acknowledgedIds !== [] ? $existing?->acknowledged_by : null,
                            'acknowledged_at' => $acknowledgedIds !== [] ? $existing?->acknowledged_at : null,
                            'decision' => 'APPLY',
                            'status' => StocktakeVariance::STATUS_APPROVED,
                            'approved_by' => $user->user_id,
                            'applied_movement_id' => null,
                            'applied_at' => null,
                        ]
                    );
                    $varianceIds[] = (string) $variance->variance_id;
                }
            }

            $countCutoff = $counts->max('counted_at') ?? $reviewedAt;
            $updated = StocktakeSession::query()
                ->where('session_id', $session->session_id)
                ->where('session_version', $expectedVersion)
                ->whereIn('status', [StocktakeSession::STATUS_IN_PROGRESS, StocktakeSession::STATUS_COUNTED])
                ->update([
                    'status' => StocktakeSession::STATUS_REVIEWED,
                    'count_cutoff_at' => $countCutoff,
                    'reviewed_at' => $reviewedAt,
                    'reviewed_by' => $user->user_id,
                    'movement_warning_flag' => $movementWarningCount > 0,
                    'session_version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                ApiError::abort(409, 'SESSION_VERSION_CONFLICT', 'Stocktake session đã được thay đổi bởi request khác.');
            }

            return [
                'session' => $session->fresh(),
                'variance_count' => count($varianceIds),
                'movement_warning_count' => $movementWarningCount,
                'variances' => StocktakeVariance::query()
                    ->whereIn('variance_id', $varianceIds)
                    ->orderBy('grain_type')
                    ->orderBy('grain_key')
                    ->get(),
            ];
        }, 3);
    }

    /** @return array{session: StocktakeSession, acknowledged_variances: int} */
    public function acknowledgeMovements(string $sessionId, int $expectedVersion, array $lines, User $user): array
    {
        return DB::transaction(function () use ($sessionId, $expectedVersion, $lines, $user): array {
            $session = $this->guard->lockScoped($sessionId, $user);
            $this->guard->assertVersion($session, $expectedVersion);
            $this->guard->assertStatus($session, [StocktakeSession::STATUS_REVIEWED], 'xác nhận movement kiểm kê');

            $varianceIds = collect($lines)->pluck('variance_id')->unique()->values()->all();
            if (count($varianceIds) !== count($lines)) {
                ApiError::abort(422, 'DUPLICATE_VARIANCE_ID', 'variance_id không được lặp trong payload.');
            }

            $variances = StocktakeVariance::query()
                ->where('session_id', $session->session_id)
                ->whereIn('variance_id', $varianceIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('variance_id');
            if ($variances->count() !== count($varianceIds)) {
                ApiError::abort(422, 'INVALID_VARIANCE_SELECTION', 'Có variance thiếu hoặc thuộc session khác.');
            }

            foreach ($lines as $line) {
                /** @var StocktakeVariance $variance */
                $variance = $variances->get($line['variance_id']);
                $detected = $this->sortedIds($variance->detected_movement_ids ?? []);
                $acknowledged = $this->sortedIds($line['movement_ids'] ?? []);
                if ($detected !== $acknowledged) {
                    ApiError::abort(409, 'MOVEMENT_ACKNOWLEDGEMENT_MISMATCH', 'Danh sách movement xác nhận phải khớp hoàn toàn movement backend phát hiện.', [
                        'variance_id' => $variance->variance_id,
                        'detected_movement_ids' => $detected,
                        'submitted_movement_ids' => $acknowledged,
                    ]);
                }

                $variance->update([
                    'acknowledged_movement_ids' => $acknowledged,
                    'acknowledged_by' => $user->user_id,
                    'acknowledged_at' => now(),
                ]);
            }

            $updated = StocktakeSession::query()
                ->where('session_id', $session->session_id)
                ->where('status', StocktakeSession::STATUS_REVIEWED)
                ->where('session_version', $expectedVersion)
                ->update(['session_version' => $expectedVersion + 1, 'updated_at' => now()]);
            if ($updated !== 1) {
                ApiError::abort(409, 'SESSION_VERSION_CONFLICT', 'Stocktake session đã được thay đổi bởi request khác.');
            }

            return ['session' => $session->fresh(), 'acknowledged_variances' => count($varianceIds)];
        }, 3);
    }

    public function findScopedSession(string $sessionId, User $user): StocktakeSession
    {
        return $this->guard->findScoped($sessionId, $user);
    }

    private function generateSessionNo(): string
    {
        return 'STK-'.now()->format('Ymd-His').'-'.strtoupper(Str::random(4));
    }

    private function sortedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        sort($ids, SORT_STRING);

        return $ids;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505', '19'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
