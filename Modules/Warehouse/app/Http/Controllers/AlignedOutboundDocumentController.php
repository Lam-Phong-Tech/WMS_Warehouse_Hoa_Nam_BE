<?php

namespace Modules\Warehouse\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\StockDocument;
use OpenApi\Attributes as OA;

/** Read-side alignment: Packing Label progress is 0/1 per outbound document; RFID is optional metadata. */
class AlignedOutboundDocumentController extends OutboundDocumentController
{
    #[OA\Get(
        path: '/api/v1/outbound-documents',
        operationId: 'aligned_outbound_document_index',
        summary: 'Danh sách phiếu xuất kèm tiến độ matching/packing label',
        security: [['bearerAuth' => []]],
        tags: ['10. Stock Out (Outbound)'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'keyword', in: 'query', description: 'Khớp doc_no/note', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trang danh sách phiếu xuất, mỗi item kèm required_total/matched_count/label_printed_count/packing_label_count/packing_label_applied_count/rfid_mapped_count'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền outbound.view hoặc ngoài warehouse data scope'),
        ]
    )]
    public function index(Request $request)
    {
        $query = StockDocument::query()
            ->with('lines')
            ->where('doc_type', $this->docType());

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $this->applyKeyword($query, $request, ['doc_no', 'note']);

        $paginator = $query->orderByDesc('created_at')->paginate($this->perPage($request));
        $ids = collect($paginator->items())->map(fn (StockDocument $doc): string => (string) $doc->id)->all();
        $stats = $this->alignedProgressStats($ids);

        $paginator->getCollection()->transform(function (StockDocument $doc) use ($stats): array {
            $stat = $stats[(string) $doc->id] ?? null;

            return [
                ...$doc->toArray(),
                'required_total' => (int) $doc->lines->sum('qty_planned'),
                'matched_count' => (int) ($stat->matched_count ?? 0),
                'label_printed_count' => (int) ($stat->packing_label_printed ?? 0),
                'packing_label_count' => (int) ($stat->packing_label_count ?? 0),
                'packing_label_applied_count' => (int) ($stat->packing_label_applied ?? 0),
                'rfid_mapped_count' => (int) ($stat->rfid_mapped_count ?? 0),
                'rfid_required' => false,
            ];
        });

        return $this->respondPaginated($paginator);
    }

    /** @return array<string, object> */
    private function alignedProgressStats(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $matches = DB::table('stock_document_lines as l')
            ->leftJoin('outbound_item_matches as m', function ($join): void {
                $join->on('m.outbound_line_id', '=', 'l.id')
                    ->where(function ($query): void {
                        $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
                    });
            })
            ->leftJoin('physical_codes as pc', function ($join): void {
                $join->on('pc.assigned_object_id', '=', 'm.item_id')
                    ->where('pc.assigned_object_type', 'ITEM')
                    ->where('pc.carrier_type', 'RFID')
                    ->where('pc.status', 'ACTIVE')
                    ->whereNotNull('pc.hardware_uid');
            })
            ->whereIn('l.stock_document_id', $documentIds)
            ->groupBy('l.stock_document_id')
            ->selectRaw('l.stock_document_id')
            ->selectRaw('COUNT(DISTINCT m.id) AS matched_count')
            ->selectRaw('COUNT(DISTINCT pc.physical_code_id) AS rfid_mapped_count')
            ->get()->keyBy('stock_document_id');

        $labels = DB::table('label_snapshots')
            ->whereIn('outbound_document_id', $documentIds)
            ->groupBy('outbound_document_id')
            ->selectRaw('outbound_document_id')
            ->selectRaw('COUNT(*) AS packing_label_count')
            ->selectRaw("SUM(CASE WHEN status = 'PRINTED' THEN 1 ELSE 0 END) AS packing_label_printed")
            ->selectRaw('SUM(CASE WHEN applied_at IS NOT NULL THEN 1 ELSE 0 END) AS packing_label_applied')
            ->get()->keyBy('outbound_document_id');

        $result = [];
        foreach ($documentIds as $id) {
            $m = $matches[$id] ?? null;
            $l = $labels[$id] ?? null;
            $result[$id] = (object) [
                'matched_count' => (int) ($m->matched_count ?? 0),
                'rfid_mapped_count' => (int) ($m->rfid_mapped_count ?? 0),
                'packing_label_count' => (int) ($l->packing_label_count ?? 0),
                'packing_label_printed' => (int) ($l->packing_label_printed ?? 0),
                'packing_label_applied' => (int) ($l->packing_label_applied ?? 0),
            ];
        }

        return $result;
    }
}
