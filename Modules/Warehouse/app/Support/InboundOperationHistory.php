<?php

namespace Modules\Warehouse\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\StockDocument;

/** Receipt-scoped read model; never changes audit records or inventory. */
final class InboundOperationHistory
{
    public function forDocument(StockDocument $document): array
    {
        $events = [];
        foreach ($document->statusHistory as $history) {
            $events[] = [
                'id' => 'status-'.$history->id,
                'action' => match (strtoupper((string) $history->to_status)) {
                    'DRAFT' => 'CREATE', 'SCANNING' => 'SCAN_START',
                    'POSTED' => 'POST', 'CANCELLED' => 'CANCEL', default => 'STATUS',
                },
                'actor_id' => $history->changed_by,
                'occurred_at' => $history->created_at?->toIso8601String(),
                'note' => $history->note ?? '',
            ];
        }
        // Some scan-driven receipts have no status row for their creation.
        if (! collect($events)->contains('action', 'CREATE') && $document->created_at !== null) {
            $events[] = ['id' => 'created-'.$document->id, 'action' => 'CREATE',
                'actor_id' => $document->created_by, 'occurred_at' => $document->created_at->toIso8601String(), 'note' => ''];
        }
        foreach (['POST' => ['POSTED', 'posted_at', 'posted_by'], 'CANCEL' => ['CANCELLED', 'cancelled_at', 'cancelled_by']] as $action => [$status, $time, $actor]) {
            if ($document->status === $status && ! collect($events)->contains('action', $action) && $document->{$time}) {
                $events[] = ['id' => strtolower($action).'-'.$document->id, 'action' => $action,
                    'actor_id' => $document->{$actor}, 'occurred_at' => $this->timestamp($document->{$time}),
                    'note' => $action === 'CANCEL' ? ($document->cancel_reason ?? '') : ''];
            }
        }
        $entries = DB::table('inbound_scan_entries as e')
            ->join('stock_document_lines as l', 'l.id', '=', 'e.inbound_line_id')
            ->leftJoin('sku as s', 's.sku_id', '=', 'l.product_id')
            ->where('l.stock_document_id', $document->id)
            ->select('e.*', 's.sku_code')->get();
        foreach ($entries as $entry) {
            $description = trim(($entry->sku_code ?? '').' · SL '.$entry->quantity);
            $events[] = ['id' => 'scan-'.$entry->id, 'action' => 'SCAN',
                'actor_id' => $entry->captured_by ?? $entry->created_by,
                'occurred_at' => $this->timestamp($entry->captured_at ?? $entry->created_at), 'note' => $description];
            if ($entry->status === 'REMOVED' && $entry->removed_at !== null) {
                $events[] = ['id' => 'remove-'.$entry->id, 'action' => 'REMOVE',
                    'actor_id' => $entry->removed_by, 'occurred_at' => $this->timestamp($entry->removed_at), 'note' => $description];
            }
        }
        // Edits/carton completion are audit-only; do not expose raw before/after payloads.
        $audits = DB::table('audit_log')->where(function ($query) use ($document): void {
            $query->where(function ($headers) use ($document): void {
                $headers->where('object_type', 'stock_document')->where('object_id', $document->id)
                    ->where('action', 'INBOUND_HEADER_UPDATE_SCAN_DRIVEN');
            })->orWhere(function ($cartons) use ($document): void {
                $cartons->where('object_type', 'container')->where('action', 'INBOUND_CARTON_SCAN_COMPLETED')
                    ->where('after_data->document_id', (string) $document->id);
            })->orWhere(function ($skus) use ($document): void {
                $skus->where('object_type', 'sku')->where('action', 'INBOUND_SKU_INFO_COMPLETED')
                    ->where('after_data->stock_document_id', (string) $document->id);
            });
        })
            ->get(['id', 'action', 'actor_id', 'created_at']);
        foreach ($audits as $audit) {
            $events[] = ['id' => 'audit-'.$audit->id,
                'action' => match ($audit->action) {
                    'INBOUND_HEADER_UPDATE_SCAN_DRIVEN' => 'EDIT',
                    'INBOUND_SKU_INFO_COMPLETED' => 'SKU_INFO',
                    default => 'CARTON_COMPLETE',
                },
                'actor_id' => $audit->actor_id, 'occurred_at' => $this->timestamp($audit->created_at), 'note' => ''];
        }
        $actors = DB::table('app_user')->whereIn('user_id', array_filter(array_column($events, 'actor_id')))
            ->pluck('display_name', 'user_id');

        return collect($events)->map(function (array $event) use ($actors): array {
            $event['actor_name'] = $actors->get($event['actor_id'] ?? '') ?? '';
            unset($event['actor_id']);

            return $event;
        })->sort(function (array $left, array $right): int {
            return strcmp($left['occurred_at'] ?? '', $right['occurred_at'] ?? '')
                ?: strcmp($left['id'], $right['id']);
        })->values()->all();
    }

    private function timestamp(mixed $value): ?string
    {
        return $value ? Carbon::parse($value, 'UTC')->toIso8601String() : null;
    }
}
