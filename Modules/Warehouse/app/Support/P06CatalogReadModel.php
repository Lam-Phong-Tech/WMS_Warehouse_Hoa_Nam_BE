<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;

/** Allowlisted P06 projections. No mutation, external fetch or inferred location allocation. */
final class P06CatalogReadModel
{
    public function stocks(array $skuIds, string $warehouseId, array $snapshot): array
    {
        if ($skuIds === []) {
            return [];
        }
        $balances = DB::table('inventory_balances')->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $skuIds)->orderBy('id')->get()->groupBy('product_id');
        $ledger = DB::table('stock_movements')->where('warehouse_id', $warehouseId)->whereIn('product_id', $skuIds)
            ->selectRaw('product_id, COUNT(*) AS event_count, COUNT(qty_delta) AS signed_count, SUM(qty_delta) AS signed_total, MAX(occurred_at) AS last_event_at')
            ->groupBy('product_id')->get()->keyBy('product_id');
        $result = [];
        foreach ($skuIds as $id) {
            $rows = $balances->get($id, collect());
            $events = $ledger->get($id);
            $missing = $rows->contains(fn (object $r): bool => $r->on_hand_qty === null || $r->available_qty === null || $r->reserved_qty === null);
            $noProjection = $rows->isEmpty() && $events !== null;
            $signedKnown = $events === null || (int) $events->event_count === (int) $events->signed_count;
            $ledgerTotal = $signedKnown ? ($events->signed_total ?? 0) + 0 : null;
            $known = ! $missing && ! $noProjection;
            $total = $known ? $rows->sum('on_hand_qty') : null;
            $available = $known ? $rows->where('stock_status', 'AVAILABLE')->sum('available_qty') : null;
            $reserved = $known ? $rows->sum('reserved_qty') : null;
            $remainder = $known ? $total - $available - $reserved : null;
            $unavailable = $remainder !== null && $remainder >= 0 ? $remainder : null;
            $validBuckets = $known && $total >= 0 && $available >= 0 && $reserved >= 0 && $unavailable !== null;
            $matchesLedger = $known && $signedKnown && bccomp((string) $total, (string) $ledgerTotal, 3) === 0;
            $status = ! $known || ! $signedKnown ? 'INCOMPLETE' :
                (! $validBuckets || ! $matchesLedger ? 'MISMATCH' : ($rows->isEmpty() ? 'EMPTY' : 'CONFIRMED'));
            $result[$id] = [
                'sku_id' => $id, 'warehouse_id' => $warehouseId, 'status' => $status,
                'total_qty' => $total, 'available_qty' => $available, 'reserved_qty' => $reserved,
                'unavailable_qty' => $unavailable, 'ledger_total' => $ledgerTotal,
                'quantity_source' => 'INVENTORY_BALANCE_PROJECTION',
                'reservation_basis' => 'BALANCE_RESERVED_QTY_NOT_LIVE_SCAN_MATCH_COUNT',
                'version' => hash('sha256', json_encode([$rows->all(), $events], JSON_THROW_ON_ERROR)), 'version_kind' => 'CONTENT_FINGERPRINT',
                'updated_at' => $this->instant($rows->max('updated_at')), 'ledger_event_at' => $this->instant($events->last_event_at ?? null),
                'snapshot_id' => $snapshot['id'], 'observed_at' => $snapshot['observed_at'],
                'location_level' => 'WAREHOUSE', 'location_breakdown' => null, 'location_breakdown_status' => 'NOT_TRACKED',
                'by_stock_status' => $rows->map(fn (object $r): array => [
                    'balance_id' => $r->id, 'stock_status' => $r->stock_status,
                    'on_hand_qty' => $r->on_hand_qty === null ? null : $r->on_hand_qty + 0,
                    'available_qty' => $r->available_qty === null ? null : $r->available_qty + 0,
                    'reserved_qty' => $r->reserved_qty === null ? null : $r->reserved_qty + 0,
                    'version' => $r->version === null ? null : (int) $r->version, 'updated_at' => $this->instant($r->updated_at),
                ])->values()->all(),
            ];
        }

        return $result;
    }

    public function gallery(string $skuId): array
    {
        $rows = DB::table('pv_product_images as img')->join(PvRelations::READ_PROFILE.' as p', 'p.profile_id', '=', 'img.profile_id')
            ->where('p.product_id', $skuId)->where('img.status', 'ACTIVE')->orderBy('img.sort_order')->orderBy('img.id')
            ->get(['img.id', 'img.image_url', 'img.storage_url', 'img.alt_text', 'img.is_primary', 'img.sort_order', 'img.version', 'img.updated_at']);

        return $rows->map(function (object $row): array {
            $url = $row->image_url ?: $row->storage_url;
            $parts = is_string($url) ? parse_url($url) : false;
            $valid = is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']);
            $public = false;
            if ($valid) {
                $ownHost = parse_url(url('/'), PHP_URL_HOST);
                $public = strcasecmp($parts['host'], (string) $ownHost) === 0
                    && preg_match('~^/api/v1/pv/product-images/[0-9a-f-]{36}\.(jpg|jpeg|png|webp)$~iD', $parts['path'] ?? '') === 1;
                $base = rtrim((string) config('pv.image_public_base', ''), '/');
                $public = $public || ($base !== '' && str_starts_with($url, $base.'/'.trim((string) config('pv.image_prefix', 'pv-product-images'), '/').'/'));
            }

            return [
                'image_id' => $row->id, 'owner_type' => 'SKU', 'full_url' => $valid ? $url : null,
                'thumbnail_url' => null, 'thumbnail_status' => 'NOT_GENERATED', 'order' => (int) $row->sort_order,
                'is_primary' => (bool) $row->is_primary, 'alt_text' => $row->alt_text,
                'version' => $row->version === null ? null : (int) $row->version, 'updated_at' => $this->instant($row->updated_at),
                'access' => ! $valid ? 'UNAVAILABLE' : ($public ? 'PUBLIC' : 'UNKNOWN'),
                'expires_at' => null, 'expiry_policy' => $public ? 'NO_SIGNED_EXPIRY' : 'UNKNOWN',
            ];
        })->all();
    }

    /** Each source has independent read permission and exact persisted warehouse ownership. */
    public function timeline(User $actor, string $warehouse, string $skuId, ?string $itemId, array $input): array
    {
        $rights = ['LEDGER' => $actor->hasPermission('inventory.trace'),
            'WARRANTY' => $actor->hasPermission('warranty.view') || $actor->hasPermission('warranty.manage')];
        $source = $input['source'] ?? 'ALL';
        if (($source !== 'ALL' && ! $rights[$source]) || ! in_array(true, $rights, true)) {
            ApiError::abort(403, 'P06_TIMELINE_FORBIDDEN', 'Không có quyền đọc nguồn lịch sử được yêu cầu.');
        }
        $queries = [];
        $sources = [];
        foreach ($rights as $name => $allowed) {
            $sources[$name] = ! $allowed ? 'FORBIDDEN' : ($source === 'ALL' || $source === $name ? 'INCLUDED' : 'NOT_REQUESTED');
        }
        if ($sources['LEDGER'] === 'INCLUDED') {
            $queries[] = DB::table('stock_movements as m')->leftJoin('stock_documents as d', 'd.id', '=', 'm.stock_document_id')
                ->where('m.warehouse_id', $warehouse)->where('m.product_id', $skuId)
                ->when($itemId !== null, fn (Builder $q): Builder => $q->where('m.item_id', $itemId))
                ->selectRaw("'LEDGER' AS source, m.id AS source_event_id, COALESCE(m.occurred_at,m.created_at) AS event_time, m.movement_type AS event_type, m.qty_delta AS signed_quantity, m.item_id, m.stock_document_id AS linked_id, d.doc_type AS linked_type, d.doc_no AS linked_no, d.status AS linked_status, CAST(NULL AS VARCHAR(40)) AS from_status, CAST(NULL AS VARCHAR(40)) AS to_status");
        }
        if ($sources['WARRANTY'] === 'INCLUDED') {
            $queries[] = DB::table('warranty_event as e')->join('warranty_case as c', 'c.warranty_case_id', '=', 'e.warranty_case_id')
                ->join(WarrantyWarehouseScope::TABLE.' as o', 'o.warranty_case_id', '=', 'c.warranty_case_id')
                ->where('o.warehouse_id', $warehouse)->where('c.product_id', $skuId)
                ->where('c.identification_status', 'IDENTIFIED')->whereNotNull('c.item_id')
                ->when($itemId !== null, fn (Builder $q): Builder => $q->where('c.item_id', $itemId))
                ->selectRaw("'WARRANTY' AS source, e.warranty_event_id AS source_event_id, COALESCE(e.event_at,e.created_at) AS event_time, e.event_type, CAST(NULL AS DECIMAL(18,3)) AS signed_quantity, c.item_id, c.warranty_case_id AS linked_id, 'WARRANTY_CASE' AS linked_type, c.warranty_case_code AS linked_no, c.status AS linked_status, e.from_status, e.to_status");
        }
        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }
        $query = DB::query()->fromSub($union, 'events');
        $total = (clone $query)->count();
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 20);
        $events = $query->orderByRaw('CASE WHEN event_time IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('event_time')->orderBy('source')->orderByDesc('source_event_id')->forPage($page, $perPage)->get();
        $showItems = $actor->hasPermission('item_instance.view');
        $data = $events->map(function (object $event) use ($actor, $showItems): array {
            $open = match ($event->linked_type) {
                'receipt' => $actor->hasPermission('inbound.view'), 'issue' => $actor->hasPermission('outbound.view'),
                'WARRANTY_CASE' => true, default => false,
            };

            return [
                'event_id' => $event->source.':'.$event->source_event_id, 'source_event_id' => $event->source_event_id,
                'source' => $event->source, 'occurred_at' => $this->instant($event->event_time), 'event_type' => $event->event_type,
                'signed_quantity' => $event->signed_quantity === null ? null : $event->signed_quantity + 0,
                'quantity_applicability' => $event->source === 'LEDGER' ? 'STOCK_DELTA' : 'NOT_APPLICABLE',
                'item_id' => $showItems ? $event->item_id : null,
                'document' => $event->linked_id === null ? null : [
                    'id' => $event->linked_id, 'type' => $event->linked_type, 'number' => $event->linked_no,
                    'current_status' => $event->linked_status, 'can_open' => $open,
                ],
                'from_status' => $event->from_status, 'to_status' => $event->to_status,
            ];
        })->all();

        return ['data' => $data, 'meta' => [
            'page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage)),
            'sources' => $sources, 'linkage_policy' => 'EXPLICIT_IDS_ONLY',
            'unlinked_warranty_policy' => 'EXCLUDED_NO_INFERRED_SKU_OR_ITEM', 'quantity_policy' => 'LEDGER_QTY_DELTA_WARRANTY_NULL',
        ]];
    }

    public function instant(?string $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value, config('app.timezone'))->utc()->toISOString();
    }
}
