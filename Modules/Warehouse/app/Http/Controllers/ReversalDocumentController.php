<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Services\CurrentBaselineReversalService;

class ReversalDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CurrentBaselineReversalService $reversalService) {}

    public function index(Request $request)
    {
        $query = DB::table('reversal_documents')
            ->leftJoin('warehouses', 'reversal_documents.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('stock_documents', 'reversal_documents.original_document_id', '=', 'stock_documents.id')
            ->leftJoin('app_user', 'reversal_documents.created_by', '=', 'app_user.user_id')
            ->select([
                'reversal_documents.id',
                'reversal_documents.reversal_no as reversal_code',
                'reversal_documents.original_document_type as reference_document_type',
                'reversal_documents.original_document_id as reference_document_id',
                'stock_documents.doc_no as reference_document_code',
                'reversal_documents.status',
                'reversal_documents.reason_code',
                'reversal_documents.reason_text as reason',
                'reversal_documents.warehouse_id',
                'reversal_documents.correction_chain_id',
                'reversal_documents.posted_at as reversed_at',
                'reversal_documents.posted_by',
                'reversal_documents.created_at',
                'reversal_documents.created_by',
                'app_user.display_name as created_by_name',
                'reversal_documents.version',
                'warehouses.name as warehouse_name',
                'warehouses.code as warehouse_code',
                DB::raw('(SELECT COUNT(*) FROM reversal_lines rl WHERE rl.reversal_document_id = reversal_documents.id) as total_lines'),
                DB::raw('(SELECT COALESCE(SUM(rl.quantity), 0) FROM reversal_lines rl WHERE rl.reversal_document_id = reversal_documents.id) as total_qty'),
            ])
            ->orderByDesc('reversal_documents.created_at');

        if ($request->filled('status')) {
            $query->where('reversal_documents.status', $request->input('status'));
        }
        if ($request->filled('keyword')) {
            $keyword = '%'.$request->input('keyword').'%';
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('CAST(reversal_documents.reversal_no AS TEXT) ILIKE ?', [$keyword])
                    ->orWhereRaw('CAST(reversal_documents.reason_text AS TEXT) ILIKE ?', [$keyword]);
            });
        }

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    public function show(string $id)
    {
        $doc = DB::table('reversal_documents')
            ->leftJoin('warehouses', 'reversal_documents.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('stock_documents', 'reversal_documents.original_document_id', '=', 'stock_documents.id')
            ->leftJoin('app_user', 'reversal_documents.created_by', '=', 'app_user.user_id')
            ->select([
                'reversal_documents.id',
                'reversal_documents.reversal_no as reversal_code',
                'reversal_documents.original_document_type as reference_document_type',
                'reversal_documents.original_document_id as reference_document_id',
                'stock_documents.doc_no as reference_document_code',
                'reversal_documents.status',
                'reversal_documents.reason_code',
                'reversal_documents.reason_text as reason',
                'reversal_documents.warehouse_id',
                'reversal_documents.correction_chain_id',
                'reversal_documents.posted_at as reversed_at',
                'reversal_documents.posted_by',
                'reversal_documents.created_at',
                'reversal_documents.created_by',
                'app_user.display_name as created_by_name',
                'reversal_documents.version',
                'warehouses.name as warehouse_name',
                'warehouses.code as warehouse_code',
                DB::raw('(SELECT COUNT(*) FROM reversal_lines rl WHERE rl.reversal_document_id = reversal_documents.id) as total_lines'),
                DB::raw('(SELECT COALESCE(SUM(rl.quantity), 0) FROM reversal_lines rl WHERE rl.reversal_document_id = reversal_documents.id) as total_qty'),
            ])
            ->where('reversal_documents.id', $id)
            ->first();

        if (! $doc) {
            return $this->respondNotFound('Không tìm thấy reversal document.');
        }

        return $this->respondSuccess($doc);
    }

    public function lines(string $id)
    {
        $doc = DB::table('reversal_documents')->where('id', $id)->first();
        if (! $doc) {
            return $this->respondNotFound('Không tìm thấy reversal document.');
        }

        $lines = DB::table('reversal_lines')
            ->leftJoin('products', 'reversal_lines.sku_id', '=', 'products.id')
            ->where('reversal_lines.reversal_document_id', $id)
            ->select([
                'reversal_lines.id',
                'reversal_lines.reversal_document_id',
                'reversal_lines.line_no',
                'reversal_lines.original_movement_id',
                'reversal_lines.quantity as reversal_qty',
                'reversal_lines.quantity_to_reverse as original_qty',
                'reversal_lines.stock_status',
                'reversal_lines.reason',
                'reversal_lines.posted_movement_id',
                'reversal_lines.version',
                'reversal_lines.created_at',
                'products.code as sku',
                'products.name as sku_name',
                'products.unit as uom',
            ])
            ->orderBy('reversal_lines.line_no')
            ->get();

        return $this->respondSuccess($lines);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reversal_type' => ['nullable', 'string'],
            'reference_document_id' => ['required', 'string'],
            'reference_document_type' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            // BR-REV-06/07: Reverse Issue phải khai tình trạng hàng quay về;
            // chỉ SELLABLE mới cộng available_qty. Mặc định giữ hành vi cũ.
            'return_condition' => ['nullable', 'in:SELLABLE,DEFECTIVE,NON_SELLABLE'],
            'physical_return_confirmed' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string'],
            'lines.*.reversal_qty' => ['required', 'integer', 'min:1'],
            'lines.*.item_id' => ['nullable', 'uuid'],
            'lines.*.reason' => ['nullable', 'string'],
        ]);

        $idempotencyKey = IdempotencyKeyGuard::require($request);

        $expectedVersion = 1;
        if ($request->hasHeader('If-Match')) {
            $raw = preg_replace('/^W\//', '', trim((string) $request->header('If-Match'))) ?? '';
            $expectedVersion = (int) trim($raw, '" ');
        }

        $refId = $validated['reference_document_id'];

        $movements = DB::table('stock_movements')
            ->leftJoin('products', 'stock_movements.product_id', '=', 'products.id')
            ->where(function ($q) use ($refId) {
                $q->where('stock_movements.stock_document_id', $refId)
                    ->orWhere('stock_movements.source_id', $refId)
                    ->orWhere('stock_movements.id', $refId);
            })
            ->whereIn('stock_movements.movement_type', ['RECEIPT', 'ISSUE'])
            ->select('stock_movements.*', 'products.code as product_code')
            ->get();

        if ($movements->isEmpty()) {
            ApiError::abort(404, 'MOVEMENT_NOT_FOUND', 'Không tìm thấy movement cho reference document.');
        }

        $reversalLines = [];
        $totalQty = 0;
        foreach ($validated['lines'] as $line) {
            $mv = $movements->first(fn ($m) => $m->product_code === $line['sku']);
            if (! $mv) {
                ApiError::abort(422, 'SKU_NOT_FOUND', "Không tìm thấy movement cho SKU '{$line['sku']}'.");
            }
            $reversalLines[] = [
                'movement_id' => $mv->id,
                'quantity' => $line['reversal_qty'],
                'item_id' => $line['item_id'] ?? null,
            ];
            $totalQty += $line['reversal_qty'];
        }

        $firstMv = $movements->first();
        $type = $firstMv->movement_type === 'RECEIPT' ? 'receipt' : 'issue';
        $stockDocId = $firstMv->stock_document_id;

        if (! $stockDocId) {
            ApiError::abort(422, 'NO_STOCK_DOCUMENT', 'Movement không liên kết stock document.');
        }

        $servicePayload = [
            'reason' => $validated['reason'],
            'reason_code' => 'CORRECTION',
            'lines' => $reversalLines,
        ];
        if ($type === 'issue') {
            $servicePayload['condition_status'] = strtoupper((string) ($validated['return_condition'] ?? 'SELLABLE'));
            $servicePayload['physical_return_confirmed'] = (bool) ($validated['physical_return_confirmed'] ?? true);
        }

        $actorId = $this->actorId();
        $hash = IdempotencyStore::requestHash($request);
        $operation = 'create_reversal_'.$stockDocId;
        $lineCount = count($reversalLines);

        [$code, $body, $replay] = IdempotencyStore::handle(
            $idempotencyKey,
            $operation,
            $actorId,
            $hash,
            function () use ($stockDocId, $type, $servicePayload, $actorId, $idempotencyKey, $hash, $expectedVersion, $totalQty, $lineCount): array {
                $result = $this->reversalService->execute(
                    $stockDocId, $type, $servicePayload, $actorId, $idempotencyKey, $hash, $expectedVersion
                );

                $reversalId = $result['reversal_document_id'];
                $doc = DB::table('reversal_documents')->where('id', $reversalId)->first();

                $response = [
                    'id' => $reversalId,
                    'reversal_code' => $doc?->reversal_no ?? $reversalId,
                    'status' => $result['reversal_status'] ?? 'POSTED',
                    'total_qty' => $totalQty,
                    'reversed_lines' => $lineCount,
                ];

                return [201, [
                    'success' => true,
                    'message' => 'Reversal document created.',
                    'data' => $response,
                    'meta' => [
                        'request_id' => request()?->attributes->get('request_id'),
                        'timestamp' => now()->toIso8601String(),
                    ],
                ]];
            }
        );

        return response()->json($body, $code)
            ->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    private function actorId(): string
    {
        $id = auth('api')->id();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Authentication required.');
        }

        return (string) $id;
    }
}
