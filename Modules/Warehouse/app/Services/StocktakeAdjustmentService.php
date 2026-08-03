<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\StocktakeSession;
use Modules\Warehouse\Models\StocktakeVariance;

class StocktakeAdjustmentService
{
    private const SCALE = 3;

    private const EVENT_STATUS_COMMITTED = 'COMMITTED';

    public function __construct(private readonly StocktakeSessionGuard $guard) {}

    public function requestHash(string $sessionId, int $sessionVersion, array $varianceIds, string $reason): string
    {
        $varianceIds = array_values(array_unique(array_map('strval', $varianceIds)));
        sort($varianceIds, SORT_STRING);

        return hash('sha256', json_encode([
            'session_id' => $sessionId,
            'session_version' => $sessionVersion,
            'variance_ids' => $varianceIds,
            'reason' => trim($reason),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array{replay: bool, data: array<string, mixed>} */
    public function apply(
        string $sessionId,
        int $expectedVersion,
        array $varianceIds,
        string $reason,
        string $idempotencyKey,
        string $requestHash,
        User $user
    ): array {
        $operation = "stocktake_apply_adjustment_{$sessionId}";

        try {
            return DB::transaction(function () use (
                $sessionId,
                $expectedVersion,
                $varianceIds,
                $reason,
                $idempotencyKey,
                $requestHash,
                $operation,
                $user
            ): array {
                // Scope is checked before replay so a leaked key cannot expose another warehouse response.
                $session = $this->guard->lockScoped($sessionId, $user);
                $event = DB::table('posting_event')
                    ->where('operation', $operation)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($event) {
                    return $this->replayCommittedEvent($event, $requestHash);
                }

                $this->guard->assertVersion($session, $expectedVersion);
                $this->guard->assertStatus($session, [StocktakeSession::STATUS_REVIEWED], 'apply variance');

                $requestedIds = array_values(array_unique(array_map('strval', $varianceIds)));
                sort($requestedIds, SORT_STRING);
                $allVariances = StocktakeVariance::query()
                    ->where('session_id', $session->session_id)
                    ->where('status', StocktakeVariance::STATUS_APPROVED)
                    ->lockForUpdate()
                    ->get();
                $allIds = $allVariances->pluck('variance_id')->map(fn ($id) => (string) $id)->sort()->values()->all();

                if ($allIds === [] || $requestedIds !== $allIds) {
                    $foreignOrMissing = DB::table('stocktake_variance')
                        ->whereIn('variance_id', $requestedIds)
                        ->where('session_id', '<>', $session->session_id)
                        ->pluck('variance_id')
                        ->map(fn ($id) => (string) $id)
                        ->all();
                    ApiError::abort(422, 'INVALID_VARIANCE_SELECTION', 'variance_ids phải là tập đầy đủ, không trùng, thuộc đúng session và đang APPROVED.', [
                        'requested_count' => count($requestedIds),
                        'required_count' => count($allIds),
                        'missing_ids' => array_values(array_diff($allIds, $requestedIds)),
                        'unknown_or_foreign_ids' => array_values(array_unique(array_merge(
                            array_diff($requestedIds, $allIds),
                            $foreignOrMissing
                        ))),
                    ]);
                }

                foreach ($allVariances as $variance) {
                    $detected = $this->sortedIds($variance->detected_movement_ids ?? []);
                    $acknowledged = $this->sortedIds($variance->acknowledged_movement_ids ?? []);
                    if ($detected !== [] && (
                        $detected !== $acknowledged
                        || $variance->acknowledged_by === null
                        || $variance->acknowledged_at === null
                    )) {
                        ApiError::abort(428, 'MOVEMENT_ACKNOWLEDGEMENT_REQUIRED', 'Phải xác nhận đầy đủ movement phát sinh trước khi apply.', [
                            'variance_id' => $variance->variance_id,
                            'detected_movement_ids' => $detected,
                            'acknowledged_movement_ids' => $acknowledged,
                        ]);
                    }
                }

                $grouped = $allVariances->groupBy(
                    fn (StocktakeVariance $variance): string => $variance->product_id.'|'.$variance->stock_status
                );
                $movementIds = [];
                $zeroEffectDimensions = [];
                $physicalUpdates = [];

                foreach ($grouped as $dimension => $variances) {
                    /** @var StocktakeVariance $representative */
                    $representative = $variances->first();
                    $balance = InventoryBalance::query()
                        ->where('warehouse_id', $session->warehouse_id)
                        ->where('product_id', $representative->product_id)
                        ->where('stock_status', $representative->stock_status)
                        ->lockForUpdate()
                        ->first();

                    if (! $balance) {
                        ApiError::abort(409, 'STOCKTAKE_BALANCE_MISSING', 'Balance được review không còn tồn tại.', [
                            'dimension' => $dimension,
                        ]);
                    }
                    if (
                        bccomp((string) $balance->on_hand_qty, (string) $representative->balance_qty_at_review, self::SCALE) !== 0
                        || (int) $balance->version !== (int) $representative->balance_version_at_review
                    ) {
                        ApiError::abort(409, 'STALE_STOCKTAKE_BASELINE', 'Balance/version đã thay đổi sau review; phải review lại trước khi apply.', [
                            'dimension' => $dimension,
                            'reviewed_qty' => (string) $representative->balance_qty_at_review,
                            'current_qty' => (string) $balance->on_hand_qty,
                            'reviewed_version' => (int) $representative->balance_version_at_review,
                            'current_version' => (int) $balance->version,
                        ]);
                    }

                    $ledgerQty = $this->ledgerQuantity(
                        (string) $session->warehouse_id,
                        (string) $representative->product_id,
                        (string) $representative->stock_status
                    );
                    if (bccomp($ledgerQty, (string) $balance->on_hand_qty, self::SCALE) !== 0) {
                        ApiError::abort(409, 'LEDGER_BALANCE_MISMATCH', 'Signed ledger không khớp balance trước khi apply; phải reconcile trước.', [
                            'dimension' => $dimension,
                            'ledger_qty' => $ledgerQty,
                            'balance_qty' => (string) $balance->on_hand_qty,
                            'formula' => 'SUM(stock_movements.qty_delta)',
                        ]);
                    }

                    $effectQty = $variances->reduce(
                        fn (string $carry, StocktakeVariance $variance): string => bcadd(
                            $carry,
                            (string) $variance->inventory_effect_qty,
                            self::SCALE
                        ),
                        '0'
                    );
                    $qtyBefore = (string) $balance->on_hand_qty;
                    $qtyAfter = bcadd($qtyBefore, $effectQty, self::SCALE);
                    if (bccomp($qtyAfter, '0', self::SCALE) < 0) {
                        ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Stocktake adjustment không được tạo tồn âm.', [
                            'dimension' => $dimension,
                            'qty_after' => $qtyAfter,
                        ]);
                    }

                    $movementId = null;
                    if (bccomp($effectQty, '0', self::SCALE) !== 0) {
                        $businessKey = hash('sha256', "stocktake-adjust|{$session->session_id}|{$dimension}");
                        if (StockMovement::query()->where('business_key', $businessKey)->exists()) {
                            ApiError::abort(409, 'MOVEMENT_EFFECT_ALREADY_EXISTS', 'Movement effect của dimension đã tồn tại.');
                        }

                        $movement = StockMovement::query()->create([
                            'movement_no' => 'STK-ADJ-'.now()->format('Ymd-His').'-'.strtoupper(Str::random(6)),
                            'business_key' => $businessKey,
                            'movement_type' => bccomp($effectQty, '0', self::SCALE) > 0
                                ? StockMovement::ADJUST_IN
                                : StockMovement::ADJUST_OUT,
                            'warehouse_id' => $session->warehouse_id,
                            'product_id' => $representative->product_id,
                            'stock_status' => $representative->stock_status,
                            'qty_before' => $qtyBefore,
                            'qty_delta' => $effectQty,
                            'qty_after' => $qtyAfter,
                            'stocktake_variance_id' => $representative->variance_id,
                            'created_by' => $user->user_id,
                            'request_id' => request()?->attributes->get('request_id'),
                            'occurred_at' => now(),
                            'created_at' => now(),
                        ]);
                        $movementId = (string) $movement->id;

                        $affected = InventoryBalance::query()
                            ->where('id', $balance->id)
                            ->where('version', $balance->version)
                            ->update([
                                'on_hand_qty' => $qtyAfter,
                                'available_qty' => $representative->stock_status === 'AVAILABLE'
                                    ? $qtyAfter
                                    : $balance->available_qty,
                                'version' => ((int) $balance->version) + 1,
                                'updated_at' => now(),
                            ]);
                        if ($affected !== 1) {
                            ApiError::abort(409, 'BALANCE_VERSION_CONFLICT', 'Balance bị thay đổi bởi request cạnh tranh.');
                        }
                        $movementIds[] = $movementId;
                    } else {
                        $zeroEffectDimensions[] = $dimension;
                    }

                    foreach ($variances as $variance) {
                        $physicalUpdates[] = $this->applyPhysicalLayer($session, $variance);
                        $variance->update([
                            'status' => StocktakeVariance::STATUS_APPLIED,
                            'applied_movement_id' => $movementId,
                            'applied_at' => now(),
                        ]);
                    }
                }

                $updated = StocktakeSession::query()
                    ->where('session_id', $session->session_id)
                    ->where('status', StocktakeSession::STATUS_REVIEWED)
                    ->where('session_version', $expectedVersion)
                    ->update([
                        'status' => StocktakeSession::STATUS_APPLIED,
                        'applied_at' => now(),
                        'applied_by' => $user->user_id,
                        'session_version' => $expectedVersion + 1,
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    ApiError::abort(409, 'SESSION_VERSION_CONFLICT', 'Session bị thay đổi bởi request cạnh tranh.');
                }

                $response = [
                    'session_id' => $session->session_id,
                    'status' => StocktakeSession::STATUS_APPLIED,
                    'session_version' => $expectedVersion + 1,
                    'reason' => trim($reason),
                    'variance_count' => $allVariances->count(),
                    'movement_ids' => $movementIds,
                    'zero_effect_dimensions' => $zeroEffectDimensions,
                    'physical_updates' => $physicalUpdates,
                    'request_hash' => $requestHash,
                ];

                DB::table('posting_event')->insert([
                    'posting_event_id' => (string) Str::uuid(),
                    'operation' => $operation,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'status' => self::EVENT_STATUS_COMMITTED,
                    'document_id' => $session->session_id,
                    'movement_id' => $movementIds[0] ?? null,
                    'response_snapshot' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'created_by' => $user->user_id,
                    'created_at' => now(),
                ]);

                return ['replay' => false, 'data' => $response];
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return $this->replayAfterUniqueRace($operation, $idempotencyKey, $requestHash, $sessionId, $user);
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function applyPhysicalLayer(StocktakeSession $session, StocktakeVariance $variance): array
    {
        if ($variance->grain_type === StocktakeService::GRAIN_SKU) {
            return ['grain_type' => StocktakeService::GRAIN_SKU, 'grain_id' => $variance->grain_id, 'updated' => false];
        }

        if ($variance->grain_type === StocktakeService::GRAIN_ITEM) {
            $item = DB::table('inventory_serials')->where('id', $variance->grain_id)->lockForUpdate()->first();
            if (! $item
                || (string) $item->product_id !== (string) $variance->product_id
                || (string) $item->warehouse_id !== (string) $session->warehouse_id) {
                ApiError::abort(422, 'ITEM_GRAIN_INVALID', 'Item grain thiếu, sai SKU hoặc nằm ngoài kho của session.', [
                    'variance_id' => $variance->variance_id,
                    'grain_id' => $variance->grain_id,
                ]);
            }
            $actual = bcadd((string) $variance->actual_qty, '0', self::SCALE);
            if (! in_array($actual, ['0.000', '1.000'], true)) {
                ApiError::abort(422, 'ITEM_COUNT_MUST_BE_BINARY', 'Item grain chỉ chấp nhận actual_qty bằng 0 hoặc 1.');
            }
            DB::table('inventory_serials')->where('id', $item->id)->update([
                'status' => $actual === '1.000' ? 'IN_STOCK' : 'MISSING',
                'stock_condition' => $variance->stock_status,
                'updated_at' => now(),
            ]);

            return ['grain_type' => StocktakeService::GRAIN_ITEM, 'grain_id' => $variance->grain_id, 'updated' => true];
        }

        if ($variance->grain_type === StocktakeService::GRAIN_CONTAINER) {
            $containerItem = DB::table('container_item as ci')
                ->join('container as c', 'c.container_id', '=', 'ci.container_id')
                ->where('ci.container_item_id', $variance->grain_id)
                ->lockForUpdate()
                ->first(['ci.container_item_id', 'ci.product_id', 'c.warehouse_id']);
            if (! $containerItem
                || (string) $containerItem->product_id !== (string) $variance->product_id
                || (string) $containerItem->warehouse_id !== (string) $session->warehouse_id) {
                ApiError::abort(422, 'CONTAINER_GRAIN_INVALID', 'Container item grain thiếu, sai SKU hoặc nằm ngoài kho của session.', [
                    'variance_id' => $variance->variance_id,
                    'grain_id' => $variance->grain_id,
                ]);
            }
            DB::table('container_item')->where('container_item_id', $containerItem->container_item_id)->update([
                'quantity' => (string) $variance->actual_qty,
                'updated_at' => now(),
            ]);

            return ['grain_type' => StocktakeService::GRAIN_CONTAINER, 'grain_id' => $variance->grain_id, 'updated' => true];
        }

        ApiError::abort(422, 'UNSUPPORTED_STOCKTAKE_GRAIN', 'grain_type không được hỗ trợ.');
    }

    private function ledgerQuantity(string $warehouseId, string $productId, string $stockStatus): string
    {
        $quantity = DB::table('stock_movements')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('stock_status', $stockStatus)
            ->selectRaw('COALESCE(SUM(qty_delta), 0) AS ledger_qty')
            ->value('ledger_qty');

        return bcadd((string) ($quantity ?? '0'), '0', self::SCALE);
    }

    /** @return array{replay: bool, data: array<string, mixed>} */
    private function replayAfterUniqueRace(
        string $operation,
        string $idempotencyKey,
        string $requestHash,
        string $sessionId,
        User $user
    ): array {
        $this->guard->findScoped($sessionId, $user);
        $event = DB::table('posting_event')
            ->where('operation', $operation)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if (! $event) {
            ApiError::abort(409, 'CONCURRENT_STOCKTAKE_APPLY', 'Request cạnh tranh đã thay đổi session; tải lại trạng thái.');
        }

        return $this->replayCommittedEvent($event, $requestHash);
    }

    /** @return array{replay: bool, data: array<string, mixed>} */
    private function replayCommittedEvent(object $event, string $requestHash): array
    {
        if (! hash_equals((string) $event->request_hash, $requestHash)) {
            ApiError::abort(409, 'IDEMPOTENCY_ERROR', 'Idempotency-Key đã được dùng với payload khác.');
        }
        if ($event->status !== self::EVENT_STATUS_COMMITTED || $event->response_snapshot === null) {
            ApiError::abort(409, 'IDEMPOTENCY_IN_PROGRESS', 'Operation đang được xử lý hoặc chưa có commit snapshot.');
        }

        return [
            'replay' => true,
            'data' => json_decode((string) $event->response_snapshot, true, 512, JSON_THROW_ON_ERROR),
        ];
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
