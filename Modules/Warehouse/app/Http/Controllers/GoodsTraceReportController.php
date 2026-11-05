<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\User;

/** Read-only trace assembled from ledger and operational evidence, never from inferred balances. */
class GoodsTraceReportController extends Controller
{
    use ApiResponse;

    private array $actorNames = [];

    public function index(Request $request)
    {
        $v = $request->validate([
            'code' => ['nullable', 'string', 'max:512'], 'code_type' => ['nullable', 'in:all,serial,box,tray,document,sku'],
            'from_date' => ['nullable', 'date_format:Y-m-d'], 'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'warehouse_id' => ['nullable', 'uuid'], 'activity_type' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'string', 'max:120'], 'actor' => ['nullable', 'string', 'max:120'],
            'relation' => ['nullable', 'in:all,original,reversal'], 'stock_only' => ['nullable', 'boolean'],
        ]);
        $user = auth('api')->user();
        $scope = $user instanceof User ? $user->warehouseScopeIds() : [];
        if (! empty($v['warehouse_id'])) {
            if ($scope !== null && ! in_array($v['warehouse_id'], $scope, true)) {
                ApiError::abort(403, 'ACCESS_DENIED', 'Kho yêu cầu nằm ngoài data scope hiện hành.');
            }
            $scope = [$v['warehouse_id']];
        }
        $code = trim($v['code'] ?? '');
        $type = $v['code_type'] ?? 'all';
        $target = null;
        if ($code !== '') {
            if (in_array($type, ['all', 'document'], true)) {
                $d = DB::table('stock_documents')->whereRaw('LOWER(doc_no) = ?', [mb_strtolower($code)])
                    ->when($scope !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('src_warehouse_id', $scope)->orWhereIn('dst_warehouse_id', $scope)))->first();
                if ($d) {
                    $target = ['DOCUMENT', (string) $d->id, null, 'document'];
                }
            }
            if (! $target && in_array($type, ['all', 'document'], true)) {
                $case = DB::table('warranty_case as c')->leftJoin('warranty_case_warehouse_owners as o', 'o.warranty_case_id', '=', 'c.warranty_case_id')->whereRaw('LOWER(c.warranty_case_code) = ?', [mb_strtolower($code)])->whereNull('c.deleted_at')->when($scope !== null, fn ($q) => $q->whereIn('o.warehouse_id', $scope))->value('c.warranty_case_id');
                if ($case) {
                    $target = ['WARRANTY', (string) $case, null, 'document'];
                }
            }
            if (! $target && $type !== 'document') {
                $target = app(StockMovementController::class)->resolveTraceTarget($code);
            }
            if ($target) {
                $wanted = ['serial' => 'ITEM', 'box' => 'CONTAINER', 'tray' => 'CONTAINER', 'sku' => 'SKU'];
                if (isset($wanted[$type]) && $target[0] !== $wanted[$type]) {
                    $target = null;
                }
                if ($target && in_array($type, ['box', 'tray'], true)) {
                    $containerType = DB::table('containers')->where('container_id', $target[1])->value('container_type');
                    if (($type === 'tray' && $containerType !== 'TRAY') || ($type === 'box' && ! in_array($containerType, ['BOX', 'CARTON'], true))) {
                        $target = null;
                    }
                }
            }
        }
        $kind = $target[0] ?? null;
        $id = $target[1] ?? null;
        $itemIds = collect();
        $containerIds = collect();
        $lineIds = collect();
        if ($kind === 'ITEM') {
            $itemIds = collect([$id]);
        }
        if ($kind === 'CONTAINER') {
            // Follow actual active containment edges, including nested trays.
            $containerIds = collect([$id]);
            do {
                $before = $containerIds->count();
                $containerIds = $containerIds->merge(DB::table('containers')->whereIn('parent_container_id', $containerIds)->pluck('container_id'))->unique()->values();
            } while ($before !== $containerIds->count());
            $itemIds = DB::table('container_items')->whereIn('container_id', $containerIds)->where('is_active', true)->whereNotNull('item_id')->pluck('item_id')->unique();
        }
        if ($itemIds->isNotEmpty() || $containerIds->isNotEmpty()) {
            $lineIds = DB::table('inbound_scan_entries')->where(fn ($q) => $q->whereIn('item_id', $itemIds)->orWhereIn('container_id', $containerIds))->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'REMOVED'))->pluck('inbound_line_id')
                ->merge(DB::table('outbound_item_matches')->where(fn ($q) => $q->whereIn('item_id', $itemIds)->orWhereIn('container_id', $containerIds))->pluck('outbound_line_id'))->unique();
        }
        $m = DB::table('stock_movements as m')->leftJoin('stock_documents as d', 'd.id', '=', 'm.stock_document_id')->leftJoin('sku as s', 's.sku_id', '=', 'm.product_id')->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->when($scope !== null, fn ($q) => $q->whereIn('m.warehouse_id', $scope));
        if ($code !== '') {
            $m->where(function ($q) use ($kind, $id, $itemIds, $containerIds, $lineIds) {
                if ($kind === 'SKU') {
                    $q->where('m.product_id', $id);
                } elseif ($kind === 'DOCUMENT') {
                    $q->where('m.stock_document_id', $id);
                } elseif (in_array($kind, ['ITEM', 'CONTAINER'], true)) {
                    $q->whereIn('m.item_id', $itemIds)->orWhereIn('m.container_id', $containerIds)->orWhereIn('m.stock_document_line_id', $lineIds);
                } else {
                    $q->whereRaw('1=0');
                }
            });
        }
        $movements = $m->select('m.*', 'd.doc_no', 'd.doc_type', 's.sku_code', 's.sku_name', 'w.code as warehouse_code')->get();
        // Ledger running balances are SKU/warehouse balances even when item_id is populated.
        $events = $movements->map(function ($r) use ($kind, $id) {
            $physical = in_array($kind, ['ITEM', 'CONTAINER'], true) || $r->item_id !== null || $r->container_id !== null;
            $subject = $r->item_id ? 'ITEM:'.$r->item_id : ($r->container_id ? 'CONTAINER:'.$r->container_id : 'SKU:'.$r->product_id.':'.$r->warehouse_id.':'.$r->stock_status);
            $related = collect([$subject]);
            if ($r->stock_document_line_id) {
                foreach (DB::table('inbound_scan_entries')->where('inbound_line_id', $r->stock_document_line_id)->get(['item_id', 'container_id']) as $scan) {
                    if ($scan->item_id) {
                        $related->push('ITEM:'.$scan->item_id);
                    }
                    if ($scan->container_id) {
                        $related->push('CONTAINER:'.$scan->container_id);
                    }
                }
                foreach (DB::table('outbound_item_matches')->where('outbound_line_id', $r->stock_document_line_id)->get(['item_id', 'container_id']) as $scan) {
                    if ($scan->item_id) {
                        $related->push('ITEM:'.$scan->item_id);
                    }
                    if ($scan->container_id) {
                        $related->push('CONTAINER:'.$scan->container_id);
                    }
                }
            }
            if (in_array($kind, ['ITEM', 'CONTAINER'], true)) {
                $related->push($kind.':'.$id);
            }
            $sourceType = $r->source_type === 'REVERSAL_DOCUMENT' ? 'REVERSAL' : $this->documentType($r->doc_type, $r->source_type);
            $e = $this->event('stock:'.$r->id, $r->occurred_at, $r->created_at, $r->movement_type, $r->sku_code, $r->sku_code, $r->sku_name, $r->warehouse_code, $r->source_type === 'REVERSAL_DOCUMENT' ? $r->source_id : ($r->stock_document_id ?? $r->source_id), $r->doc_no, $sourceType, $r->created_by);

            return array_merge($e, ['quantity' => $physical ? null : abs((float) $r->qty_delta), 'qty_delta' => $physical ? null : (float) $r->qty_delta, 'source_qty_delta' => (float) $r->qty_delta, 'qty_before' => $physical || $r->qty_before === null ? null : (float) $r->qty_before, 'qty_after' => $physical || $r->qty_after === null ? null : (float) $r->qty_after, 'source_quantity' => abs((float) $r->qty_delta), 'entity_quantity' => $physical ? null : abs((float) $r->qty_delta), 'status' => $r->stock_status, 'entity_key' => $subject, 'related_entity_keys' => $related->unique()->values()->all()]);
        });
        $visibleItemIds = $movements->pluck('item_id')->filter()->merge(DB::table('inbound_scan_entries')->whereIn('inbound_line_id', $movements->pluck('stock_document_line_id')->filter())->pluck('item_id')->filter())->merge(DB::table('outbound_item_matches')->whereIn('outbound_line_id', $movements->pluck('stock_document_line_id')->filter())->pluck('item_id')->filter())->unique();
        $visibleContainerIds = $movements->pluck('container_id')->filter()->merge(DB::table('inbound_scan_entries')->whereIn('inbound_line_id', $movements->pluck('stock_document_line_id')->filter())->pluck('container_id')->filter())->unique();
        if (! $request->boolean('stock_only')) {
            $p = DB::table('putaway_events as p')->leftJoin('containers as c', 'c.container_id', '=', 'p.container_id')->leftJoin('stock_documents as d', 'd.id', '=', 'p.stock_document_id')->leftJoin('warehouses as w', 'w.id', '=', 'p.warehouse_id')->where('p.status', 'CONFIRMED')->when($scope !== null, fn ($q) => $q->whereIn('p.warehouse_id', $scope));
            if ($code !== '') {
                if ($kind === 'CONTAINER') {
                    $p->whereIn('p.container_id', $containerIds);
                } elseif ($kind === 'DOCUMENT') {
                    $p->where('p.stock_document_id', $id);
                } elseif ($kind === 'SKU') {
                    $p->where('c.primary_sku_id', $id);
                } elseif ($kind === 'ITEM') {
                    $p->whereIn('p.container_id', DB::table('container_items')->whereIn('item_id', $itemIds)->pluck('container_id'));
                } else {
                    $p->whereRaw('1=0');
                }
            }
            foreach ($p->select('p.*', 'c.container_code', 'd.doc_no', 'd.doc_type', 'w.code as warehouse_code')->get() as $r) {
                $visibleContainerIds->push($r->container_id);
                $events->push(array_merge($this->event('putaway:'.$r->id, $r->confirmed_at, $r->created_at, 'PUTAWAY', $r->container_code, null, null, $r->warehouse_code, $r->stock_document_id, $r->doc_no, $this->documentType($r->doc_type, null), $r->created_by), ['location_before' => $r->from_location_hint, 'location_after' => $r->to_location_hint, 'status' => $r->status, 'entity_key' => 'CONTAINER:'.$r->container_id, 'related_entity_keys' => array_values(array_unique(array_filter(['CONTAINER:'.$r->container_id, $r->from_parent_container_id ? 'CONTAINER:'.$r->from_parent_container_id : null, $r->to_parent_container_id ? 'CONTAINER:'.$r->to_parent_container_id : null, in_array($kind, ['ITEM', 'CONTAINER'], true) ? $kind.':'.$id : null])))]));
            }
            $l = DB::table('label_print_events as l')->join('label_snapshots as s', 's.id', '=', 'l.label_snapshot_id')->leftJoin('outbound_item_matches as x', 'x.id', '=', 's.outbound_item_match_id')->leftJoin('stock_document_lines as dl', 'dl.id', '=', 'x.outbound_line_id')->join('stock_documents as d', 'd.id', '=', DB::raw('COALESCE(s.outbound_document_id, dl.stock_document_id)'))->leftJoin('sku as k', 'k.sku_id', '=', 's.sku_id')->leftJoin('warehouses as w', 'w.id', '=', 'd.src_warehouse_id')->when($scope !== null, fn ($q) => $q->whereIn('d.src_warehouse_id', $scope));
            if ($code !== '') {
                if ($kind === 'SKU') {
                    $l->where('s.sku_id', $id);
                } elseif ($kind === 'DOCUMENT') {
                    $l->where('d.id', $id);
                } elseif ($kind === 'ITEM') {
                    $l->whereIn('s.item_id', $itemIds);
                } elseif ($kind === 'CONTAINER') {
                    $l->whereIn('x.container_id', $containerIds);
                } else {
                    $l->whereRaw('1=0');
                }
            }
            foreach ($l->select('l.*', 's.item_id', 'k.sku_code', 'k.sku_name', 'd.id as doc_id', 'd.doc_no', 'd.doc_type', 'w.code as warehouse_code')->get() as $r) {
                if ($r->item_id) {
                    $visibleItemIds->push($r->item_id);
                }
                $events->push(array_merge($this->event('print:'.$r->id, $r->printed_at ?? $r->created_at, $r->created_at, 'LABEL_PRINT', $code ?: $r->sku_code, $r->sku_code, $r->sku_name, $r->warehouse_code, $r->doc_id, $r->doc_no, $this->documentType($r->doc_type, 'ISSUE_DOCUMENT'), $r->created_by), ['status' => $r->status, 'entity_key' => $r->item_id ? 'ITEM:'.$r->item_id : null, 'related_entity_keys' => $r->item_id ? ['ITEM:'.$r->item_id] : []]));
            }
            $w = DB::table('warranty_case as c')->leftJoin('warranty_case_warehouse_owners as o', 'o.warranty_case_id', '=', 'c.warranty_case_id')->leftJoin('warehouses as w', 'w.id', '=', 'o.warehouse_id')->leftJoin('sku as s', 's.sku_id', '=', 'c.product_id')->when($scope !== null, fn ($q) => $q->whereIn('o.warehouse_id', $scope))->whereNull('c.deleted_at');
            if ($code !== '') {
                if ($kind === 'SKU') {
                    $w->where('c.product_id', $id);
                } elseif ($kind === 'WARRANTY') {
                    $w->where('c.warranty_case_id', $id);
                } elseif (in_array($kind, ['ITEM', 'CONTAINER'], true)) {
                    $w->whereIn('c.item_id', $itemIds);
                } else {
                    $w->whereRaw('1=0');
                }
            }
            foreach ($w->select('c.warranty_case_id', 'c.warranty_case_code', 'c.item_id', 'c.received_at', 'c.created_at', 'c.created_by', 'c.status', 's.sku_code', 's.sku_name', 'w.code as warehouse_code')->get() as $r) {
                if ($r->item_id) {
                    $visibleItemIds->push($r->item_id);
                }
                $events->push(array_merge($this->event('warranty:'.$r->warranty_case_id, $r->received_at ?? $r->created_at, $r->created_at, 'WARRANTY_INTAKE', $code ?: $r->sku_code, $r->sku_code, $r->sku_name, $r->warehouse_code, $r->warranty_case_id, $r->warranty_case_code, 'WARRANTY', $r->created_by), ['status' => $r->status, 'entity_key' => $r->item_id ? 'ITEM:'.$r->item_id : null, 'related_entity_keys' => $r->item_id ? ['ITEM:'.$r->item_id] : []]));
            }
        }
        $entities = collect();
        $relations = collect();
        // Historical access never authorizes current location, containment or quantity. Fail closed without an explicit current warehouse owner.
        $items = DB::table('item_instances as i')->leftJoin('sku as s', 's.sku_id', '=', 'i.sku_id')->leftJoin('containers as c', 'c.container_id', '=', 'i.container_id');
        if ($code === '') {
            $items->whereIn('i.item_id', $visibleItemIds);
        } elseif ($kind === 'SKU') {
            $items->where('i.sku_id', $id);
        } else {
            $items->whereIn('i.item_id', $itemIds);
        }
        if ($scope !== null) {
            if (Schema::hasColumn('item_instances', 'warehouse_id')) {
                $items->whereIn('i.warehouse_id', $scope);
            } else {
                $items->whereRaw('1=0');
            }
        }
        foreach ($items->select('i.*', 's.sku_code', 's.sku_name', 's.unit', 'c.container_code', 'c.container_type', 'c.location_hint')->get() as $r) {
            $entities->push($this->entity('ITEM:'.$r->item_id, $r->item_code ?? $r->serial_number, 'serial', $r->sku_code, $r->sku_name, $r->unit, $r->item_status, $r->location_hint, null, $r->container_code, $r->container_type));
        }
        $cs = DB::table('containers as c')->leftJoin('sku as s', 's.sku_id', '=', 'c.primary_sku_id')->leftJoin('containers as p', 'p.container_id', '=', 'c.parent_container_id');
        if ($kind === 'CONTAINER') {
            $cs->whereIn('c.container_id', $containerIds);
        } elseif ($kind === 'SKU') {
            $cs->where('c.primary_sku_id', $id);
        } else {
            $cs->whereIn('c.container_id', $visibleContainerIds);
        }
        if ($scope !== null) {
            if (Schema::hasColumn('containers', 'warehouse_id')) {
                $cs->whereIn('c.warehouse_id', $scope);
            } else {
                $cs->whereRaw('1=0');
            }
        }
        foreach ($cs->select('c.*', 's.sku_code', 's.sku_name', 's.unit', 'p.container_code as parent_code', 'p.container_type as parent_type')->get() as $r) {
            $members = DB::table('container_items')->where('container_id', $r->container_id)->where('is_active', true)->get();
            $qty = $members->isEmpty() ? null : $members->sum(fn ($x) => $x->item_id ? 1 : (float) $x->quantity);
            $entities->push($this->entity('CONTAINER:'.$r->container_id, $r->container_code, $r->container_type === 'TRAY' ? 'tray' : 'box', $r->sku_code, $r->sku_name, $r->unit, $r->status, $r->location_hint, $qty, $r->parent_code, $r->parent_type));
            if ($r->parent_code) {
                $relations->push(['parent_code' => $r->parent_code, 'child_code' => $r->container_code, 'sku_code' => $r->sku_code, 'quantity' => $qty, 'location' => $r->location_hint]);
            }
        }
        if ($kind === 'SKU' || $code === '' || $kind === 'DOCUMENT') {
            $balanceSkuIds = $kind === 'SKU' ? collect([$id]) : $movements->pluck('product_id')->unique();
            $balances = DB::table('inventory_balances as b')->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')->join('sku as s', 's.sku_id', '=', 'b.product_id')->whereIn('b.product_id', $balanceSkuIds)->when($scope !== null, fn ($q) => $q->whereIn('b.warehouse_id', $scope))->select('b.*', 'w.code as warehouse_code', 's.sku_code', 's.sku_name', 's.unit')->get();
            foreach ($balances as $b) {
                $entities->push(array_merge($this->entity('SKU:'.$b->product_id.':'.$b->warehouse_id.':'.$b->stock_status, $b->sku_code, 'sku', $b->sku_code, $b->sku_name, $b->unit, $b->stock_status, null, (float) $b->on_hand_qty, null, null), ['warehouse_code' => $b->warehouse_code]));
            }
        }
        $resolved = $code === '' ? null : $target !== null;
        if ($scope !== null && in_array($kind, ['ITEM', 'CONTAINER'], true) && $entities->isEmpty() && $events->isEmpty()) {
            $resolved = false;
        }
        $events = $events->unique('id')->filter(function ($e) use ($v) {
            if (($v['relation'] ?? 'all') === 'reversal' && $e['event_type'] !== 'REVERSAL') {
                return false;
            }
            if (($v['relation'] ?? 'all') === 'original' && $e['event_type'] === 'REVERSAL') {
                return false;
            }
            if (! empty($v['from_date']) && substr((string) $e['occurred_at'], 0, 10) < $v['from_date']) {
                return false;
            }
            if (! empty($v['to_date']) && substr((string) $e['occurred_at'], 0, 10) > $v['to_date']) {
                return false;
            }
            foreach (['activity_type' => 'event_type', 'source' => 'source_type', 'actor' => 'actor'] as $f => $field) {
                if (! empty($v[$f]) && strcasecmp((string) ($f === 'actor' ? ($e['actor_id'] ?? $e[$field]) : $e[$field]), $v[$f]) !== 0) {
                    return false;
                }
            }

            return true;
        })->sortByDesc(fn ($e) => ($e['occurred_at'] ?? '').'|'.$e['id'])->values();
        $warnings = [];
        if ($scope !== null && ($visibleItemIds->isNotEmpty() || $visibleContainerIds->isNotEmpty())) {
            $warnings[] = 'CURRENT_PHYSICAL_STATE_REQUIRES_CURRENT_WAREHOUSE_OWNERSHIP';
        }

        if ($events->contains(fn ($e) => $e['source_quantity'] !== null && $e['entity_quantity'] === null)) {
            $warnings[] = 'SOURCE_LINE_QUANTITIES_ARE_NOT_ENTITY_QUANTITIES';
        }

        return $this->respondSuccess(['events' => $events, 'entities' => $entities->unique('key')->values(), 'relations' => $relations->values(), 'resolved' => $resolved, 'complete' => true, 'loaded_count' => $events->count(), 'total_count' => $events->count(), 'data_as_of' => now()->toIso8601String(), 'warnings' => $warnings, 'data_scope' => $scope === null ? 'GLOBAL' : 'WAREHOUSE_SCOPED', 'scope_warehouse_ids' => $scope]);
    }

    private function event($id, $at, $recorded, $type, $code, $sku, $name, $warehouse, $sourceId, $sourceNo, $sourceType, $actor): array
    {
        $actorId = $actor;
        if ($actorId && ! array_key_exists($actorId, $this->actorNames)) {
            $this->actorNames[$actorId] = DB::table('app_user')->where('user_id', $actorId)->value('display_name');
        }
        $actor = $actorId ? ($this->actorNames[$actorId] ?? null) : null;

        return ['id' => $id, 'occurred_at' => $at, 'recorded_at' => $recorded, 'event_type' => $type, 'event_label' => $type, 'code' => $code, 'sku_code' => $sku, 'sku_name' => $name, 'warehouse_code' => $warehouse, 'location_before' => null, 'location_after' => null, 'quantity' => null, 'qty_delta' => 0, 'qty_before' => null, 'qty_after' => null, 'source_id' => $sourceId, 'source_no' => $sourceNo, 'source_type' => $sourceType, 'actor' => $actor, 'actor_id' => $actorId, 'related_documents' => $sourceId ? [['id' => $sourceId, 'code' => $sourceNo, 'type' => $sourceType]] : [], 'source_quantity' => null, 'entity_quantity' => null, 'status' => null, 'entity_key' => null, 'related_entity_keys' => [], 'source_qty_delta' => null];
    }

    private function documentType(?string $docType, ?string $fallback): ?string
    {
        return match (strtolower($docType ?? '')) {
            'receipt' => 'INBOUND', 'issue' => 'OUTBOUND',
            default => match (strtoupper($fallback ?? '')) {
                'RECEIPT_DOCUMENT' => 'INBOUND','ISSUE_DOCUMENT' => 'OUTBOUND','REVERSAL_DOCUMENT' => 'REVERSAL',default => $fallback
            },
        };
    }

    private function entity($key, $code, $type, $sku, $name, $unit, $status, $location, $quantity, $parent, $parentType): array
    {
        return ['key' => $key, 'code' => $code, 'code_type' => $type, 'sku_code' => $sku, 'sku_name' => $name, 'unit' => $unit, 'warehouse_code' => null, 'current_status' => $status, 'current_location' => $location, 'current_quantity' => $quantity, 'current_source_no' => null, 'parent_code' => $parent, 'parent_type' => $parentType];
    }
}
