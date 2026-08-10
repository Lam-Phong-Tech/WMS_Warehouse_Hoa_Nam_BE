<?php

namespace Modules\Warehouse\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CurrentBaselineStockEvidenceController extends CurrentBaselineStockFlowController
{
    public function inboundScan(Request $request, string $id)
    {
        $response = parent::inboundScan($request, $id);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $payload = $response->getData(true);
            $data = $payload['data'] ?? [];
            $row = DB::table('inbound_scan_entries')
                ->where('inbound_line_id', $data['line_id'] ?? null)
                ->when(! empty($data['item_id']), fn ($query) => $query->where('item_id', $data['item_id']))
                ->orderByDesc('created_at')
                ->first();
            if ($row !== null) {
                DB::table('inbound_scan_entries')->where('id', $row->id)->update([
                    'status' => 'ACTIVE',
                    'captured_at' => $row->captured_at ?? now(),
                    'captured_by' => $row->captured_by ?? $this->actorIdForEvidence(),
                    'version' => ((int) ($row->version ?? 0)) + 1,
                    'updated_by' => $this->actorIdForEvidence(),
                    'updated_at' => now(),
                ]);
            }
        }
        return $response;
    }

    public function outboundScan(Request $request, string $id)
    {
        $response = parent::outboundScan($request, $id);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $payload = $response->getData(true);
            $data = $payload['data'] ?? [];
            $row = DB::table('outbound_item_matches')
                ->where('outbound_line_id', $data['line_id'] ?? null)
                ->when(! empty($data['item_id']), fn ($query) => $query->where('item_id', $data['item_id']))
                ->orderByDesc('created_at')
                ->first();
            if ($row !== null) {
                DB::table('outbound_item_matches')->where('id', $row->id)->update([
                    'status' => 'ACTIVE',
                    'matched_at' => $row->matched_at ?? now(),
                    'matched_by' => $row->matched_by ?? $this->actorIdForEvidence(),
                    'version' => ((int) ($row->version ?? 0)) + 1,
                    'updated_by' => $this->actorIdForEvidence(),
                    'updated_at' => now(),
                ]);
            }
        }
        return $response;
    }

    public function postIssue(Request $request, string $id)
    {
        $response = parent::postIssue($request, $id);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $lineIds = DB::table('stock_document_lines')->where('stock_document_id', $id)->pluck('id');
            if ($lineIds->isNotEmpty()) {
                $matches = DB::table('outbound_item_matches')->whereIn('outbound_line_id', $lineIds)->where('status', 'ACTIVE')->get();
                foreach ($matches as $match) {
                    DB::table('outbound_item_matches')->where('id', $match->id)->update([
                        'status' => 'POSTED',
                        'version' => ((int) ($match->version ?? 0)) + 1,
                        'updated_by' => $this->actorIdForEvidence(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
        return $response;
    }

    private function actorIdForEvidence(): ?string
    {
        $id = auth('api')->id();
        return $id === null ? null : (string) $id;
    }
}
