<?php

namespace Modules\Warehouse\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Batch source references are candidates only; owner event/line FKs prove links. */
final class P23SessionRelations
{
    public function resolve(array $scope, array $batches, array $events): array
    {
        if ($events === []) {
            return [];
        }
        $byId = collect($events)->keyBy('event_id');
        $result = [];
        foreach ($events as $event) {
            $result[$event->event_id] = ['relationStatus' => 'NOT_ESTABLISHED'];
        }
        foreach ([
            ['receipt', 'RECEIPT_DOCUMENT', 'inbound_scan_entries', 'inbound_line_id', 'dst_warehouse_id', 'INBOUND', 'inbound-documents', 'scanned_by'],
            ['issue', 'ISSUE_DOCUMENT', 'outbound_item_matches', 'outbound_line_id', 'src_warehouse_id', 'OUTBOUND', 'outbound-documents', 'matched_by'],
        ] as [$type, $source, $table, $line, $warehouse, $kind, $path, $actor]) {
            if (! $scope['documentsRead'] || ! ($scope['documentRights'][$type] ?? false)) {
                $this->mark($result, $events, $batches, $source, 'REDACTED_PERMISSION');

                continue;
            }
            if (! $this->supports($table, ['scan_event_id', $line, 'item_id', 'quantity'])
                || ! $this->supports('stock_document_lines', ['id', 'stock_document_id'])
                || ! $this->supports('stock_documents', ['id', 'doc_no', 'doc_type', $warehouse, 'status', 'version', 'deleted_at'])) {
                $this->mark($result, $events, $batches, $source, 'SOURCE_UNAVAILABLE');

                continue;
            }
            $q = DB::table($table.' as r')->join('stock_document_lines as l', 'l.id', '=', 'r.'.$line)
                ->join('stock_documents as d', 'd.id', '=', 'l.stock_document_id')
                ->whereIn('r.scan_event_id', $byId->keys())->where('d.doc_type', $type)->where('d.'.$warehouse, $scope['warehouse_id'])->whereNull('d.deleted_at');
            foreach ([$table => 'r', 'stock_document_lines' => 'l', 'stock_documents' => 'd'] as $name => $alias) {
                P09WarrantyScope::isolate($q, $name, $alias, $scope);
            }
            $columns = Schema::getColumnListing($table);
            if (in_array('status', $columns, true)) {
                $q->where('r.status', '!=', 'REMOVED');
            }
            $select = ['r.scan_event_id', 'r.item_id', 'r.quantity', 'd.id', 'd.doc_no', 'd.status', 'd.version'];
            $select[] = DB::raw(in_array('container_id', $columns, true) ? 'r.container_id' : 'NULL AS container_id');
            $select[] = DB::raw(in_array($actor, $columns, true) ? 'r.'.$actor.' AS actor_id' : 'NULL AS actor_id');
            foreach ($q->get($select) as $row) {
                $event = $byId[$row->scan_event_id];
                $batch = $batches[$event->owner_id];
                if ($batch->source_type !== $source || $batch->source_id !== $row->id || ! $this->targetMatches($event, $row)) {
                    continue;
                }
                $entry = ['link' => $this->link($kind, $row->id, $row->doc_no, $row->status, (int) $row->version, $scope['warehouse_id'], '/api/v1/mini-app/'.$path.'/'.$row->id),
                    'eventQuantity' => $this->positive($row->quantity), 'quantitySource' => $table.'.quantity',
                    'actorId' => $row->actor_id, 'actorSource' => $table.'.'.$actor, 'issuedStatus' => 'NOT_APPLICABLE'];
                $this->add($result, $event->event_id, $entry);
            }
        }
        if ($scope['p23_rights']['components']) {
            $this->components($scope, $batches, $byId->all(), $result);
        } else {
            $this->mark($result, $events, $batches, 'COMPONENT_ISSUE_DOCUMENT', 'REDACTED_PERMISSION');
        }

        return $result;
    }

    private function components(array $scope, array $batches, array $events, array &$result): void
    {
        foreach ([
            'component_issue_items' => ['id', 'component_issue_line_id', 'scan_event_id', 'item_id', 'container_id', 'quantity', 'status', 'selected_by'],
            'component_issue_lines' => ['id', 'component_issue_document_id', 'sku_id', 'scanned_qty', 'required_qty'],
            'component_issue_documents' => ['id', 'warehouse_id', 'warranty_case_id', 'component_issue_no', 'status', 'version', 'posted_at'],
            'stock_movements' => ['id', 'warehouse_id', 'source_type', 'source_id', 'source_line_id', 'movement_type', 'qty_delta', 'item_id', 'container_id', 'sku_id', 'product_id', 'stock_document_id'],
        ] as $table => $columns) {
            if (! $this->supports($table, $columns)) {
                $this->mark($result, $events, $batches, 'COMPONENT_ISSUE_DOCUMENT', 'SOURCE_UNAVAILABLE');

                return;
            }
        }
        $cases = P09WarrantyScope::cases($scope)->select('c.warranty_case_id', 'c.warranty_case_code', 'c.status as case_status', 'c.version as case_version');
        $q = DB::table('component_issue_items as r')->join('component_issue_lines as l', 'l.id', '=', 'r.component_issue_line_id')
            ->join('component_issue_documents as d', 'd.id', '=', 'l.component_issue_document_id')
            ->joinSub($cases, 'owned_case', 'owned_case.warranty_case_id', '=', 'd.warranty_case_id')
            ->whereIn('r.scan_event_id', array_keys($events))->where('r.status', '!=', 'REMOVED')->where('d.warehouse_id', $scope['warehouse_id']);
        foreach (['component_issue_items' => 'r', 'component_issue_lines' => 'l', 'component_issue_documents' => 'd'] as $table => $alias) {
            P09WarrantyScope::isolate($q, $table, $alias, $scope);
        }
        $rows = $q->get(['r.id as evidence_id', 'r.scan_event_id', 'r.item_id', 'r.container_id', 'r.quantity', 'r.selected_by', 'r.status as item_status',
            'l.id as line_id', 'l.sku_id', 'l.scanned_qty', 'l.required_qty', 'd.id', 'd.component_issue_no', 'd.warranty_case_id', 'd.status', 'd.version', 'd.posted_at',
            'owned_case.warranty_case_code', 'owned_case.case_status', 'owned_case.case_version']);
        $ledger = DB::table('stock_movements as m')->whereIn('m.source_id', $rows->pluck('id')->unique())->where('m.warehouse_id', $scope['warehouse_id'])
            ->where('m.source_type', 'COMPONENT_ISSUE_DOCUMENT')->where('m.movement_type', 'COMPONENT_ISSUE');
        P09WarrantyScope::isolate($ledger, 'stock_movements', 'm', $scope);
        $movements = $rows->isEmpty() ? collect() : $ledger->get(['m.id', 'm.source_id', 'm.source_line_id', 'm.item_id', 'm.container_id', 'm.qty_delta', 'm.sku_id', 'm.product_id', 'm.stock_document_id']);
        $itemQuery = DB::table('component_issue_items as p')->whereIn('p.component_issue_line_id', $rows->pluck('line_id')->unique());
        P09WarrantyScope::isolate($itemQuery, 'component_issue_items', 'p', $scope);
        $allItems = $rows->isEmpty() ? collect() : $itemQuery->get(['p.component_issue_line_id', 'p.item_id', 'p.container_id', 'p.quantity', 'p.status']);
        foreach ($rows as $row) {
            $event = $events[$row->scan_event_id];
            $batch = $batches[$event->owner_id];
            if ($batch->source_type !== 'COMPONENT_ISSUE_DOCUMENT' || $batch->source_id !== $row->id || ! $this->targetMatches($event, $row)) {
                continue;
            }
            $issued = null;
            $status = 'NOT_POSTED';
            if ($row->status === 'POSTED' && $row->posted_at !== null && $row->item_status === 'POSTED') {
                // The canonical writer posts one movement per line, not per BOX.
                // Reconcile that complete line first, then attribute only this
                // event's persisted posted item quantity, never the whole line.
                $effects = $movements->filter(fn ($m) => $m->source_id === $row->id && $m->source_line_id === $row->line_id);
                $lineItems = $allItems->where('component_issue_line_id', $row->line_id);
                $postedItems = $lineItems->where('status', 'POSTED');
                $itemEvidence = $postedItems->isNotEmpty() && $postedItems->every(fn ($i) => $this->positive($i->quantity) !== null
                    && (($i->item_id !== null && $i->container_id === null && (int) $i->quantity === 1)
                        || ($i->item_id === null && $i->container_id !== null)))
                    && $lineItems->whereNotIn('status', ['POSTED', 'REMOVED'])->isEmpty();
                $qty = $this->positive($row->quantity);
                $negative = $effects->every(fn ($m) => is_numeric($m->qty_delta) && (float) $m->qty_delta < 0
                    && $m->sku_id === $row->sku_id && $m->product_id === $row->sku_id && $m->stock_document_id === null);
                $total = -$effects->sum('qty_delta');
                $status = $qty !== null && $itemEvidence && $negative && $effects->isNotEmpty()
                    && (float) $total === (float) $postedItems->sum('quantity')
                    && (float) $total === (float) $row->scanned_qty && (float) $total === (float) $row->required_qty
                    ? 'VERIFIED_POSTED_LEDGER' : 'LEDGER_EVIDENCE_MISMATCH';
                if ($status === 'VERIFIED_POSTED_LEDGER') {
                    $issued = $qty;
                }
            }
            $entry = ['link' => $this->link('COMPONENT_ISSUE', $row->id, $row->component_issue_no, $row->status, (int) $row->version,
                $scope['warehouse_id'], '/api/v1/mini-app/warranty-component-issues/'.$row->id),
                'additionalLinks' => [$this->link('WARRANTY', $row->warranty_case_id, $row->warranty_case_code, $row->case_status,
                    (int) $row->case_version, $scope['warehouse_id'], '/api/v1/mini-app/warranty-cases/'.$row->warranty_case_id)],
                'eventQuantity' => $this->positive($row->quantity), 'quantitySource' => 'component_issue_items.quantity',
                'actorId' => $row->selected_by, 'actorSource' => 'component_issue_items.selected_by',
                'evidenceId' => $row->evidence_id, 'issuedQuantity' => $issued, 'issuedStatus' => $status];
            $this->add($result, $event->event_id, $entry);
        }
    }

    private function targetMatches(object $event, object $row): bool
    {
        $type = $event->evidence->resolved_object_type ?? null;
        $id = $event->evidence->resolved_object_id ?? null;

        return $id !== null && match ($type) {
            'ITEM', 'ITEM_INSTANCE' => $row->item_id !== null && $id === $row->item_id,
            'BOX', 'CONTAINER' => $row->container_id !== null && $id === $row->container_id,
            default => false,
        };
    }

    private function add(array &$result, string $id, array $entry): void
    {
        if (isset($result[$id]['link']) || ($result[$id]['relationStatus'] ?? null) === 'AMBIGUOUS_OWNER_EVIDENCE') {
            $result[$id] = ['issuedStatus' => 'AMBIGUOUS_OWNER_EVIDENCE', 'relationStatus' => 'AMBIGUOUS_OWNER_EVIDENCE'];
        } else {
            $result[$id] = $entry + ['relationStatus' => 'VERIFIED_OWNER_RELATION'];
        }
    }

    private function mark(array &$result, array $events, array $batches, string $source, string $status): void
    {
        foreach ($events as $event) {
            if ($batches[$event->owner_id]->source_type === $source) {
                $result[$event->event_id] = ['relationStatus' => $status, 'issuedStatus' => $status];
            }
        }
    }

    private function link(string $kind, string $id, ?string $number, string $status, int $version, string $warehouse, string $path): array
    {
        return ['kind' => $kind, 'id' => $id, 'number' => $number, 'status' => $status, 'version' => $version,
            'warehouseId' => $warehouse, 'statusSource' => 'CURRENT_OWNER_DOCUMENT', 'relationStatus' => 'VERIFIED_OWNER_RELATION',
            'readTarget' => ['method' => 'GET', 'path' => $path, 'query' => ['warehouse_id' => $warehouse], 'ownerPermissionRechecked' => true],
            'fieldSources' => ['id' => P23SessionV4::field($id, 'VERIFIED_EVENT_OWNER_LINE_DOCUMENT_FK'),
                'number' => P23SessionV4::field($number, 'CURRENT_OWNER_DOCUMENT_NUMBER'),
                'status' => P23SessionV4::field($status, 'CURRENT_OWNER_DOCUMENT_STATUS'),
                'version' => P23SessionV4::field($version, 'CURRENT_OWNER_DOCUMENT_VERSION')]];
    }

    private function supports(string $table, array $columns): bool
    {
        return array_diff($columns, Schema::getColumnListing($table)) === [];
    }

    private function positive(mixed $value): ?int
    {
        return is_numeric($value) && (float) $value > 0 && (float) $value === (float) (int) $value ? (int) $value : null;
    }
}
