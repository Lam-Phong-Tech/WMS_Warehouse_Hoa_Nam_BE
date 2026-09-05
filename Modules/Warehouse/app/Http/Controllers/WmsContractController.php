<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class WmsContractController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/product-categories',
        operationId: 'wms_product_categories',
        summary: 'List product category master data',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated product categories'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing product.view permission'),
        ]
    )]
    public function productCategories(Request $request)
    {
        return $this->productMaster($request, 'CATEGORY');
    }

    #[OA\Get(
        path: '/api/v1/uoms',
        operationId: 'wms_uoms',
        summary: 'List unit-of-measure options',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated UOM options'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing product.view permission'),
        ]
    )]
    public function uoms(Request $request)
    {
        return $this->staticPage($request, [
            ['id' => 'cai', 'code' => 'cai', 'name' => 'cái'],
            ['id' => 'bo', 'code' => 'bo', 'name' => 'bộ'],
            ['id' => 'hop', 'code' => 'hop', 'name' => 'hộp'],
            ['id' => 'thung', 'code' => 'thung', 'name' => 'thùng'],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/suppliers',
        operationId: 'wms_suppliers',
        summary: 'List supplier options',
        security: [['bearerAuth' => []]],
        tags: ['05. Product'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated supplier options'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing product.view permission'),
        ]
    )]
    public function suppliers(Request $request)
    {
        return $this->staticPage($request, [
            ['id' => 'HOA_NAM', 'code' => 'HOA_NAM', 'name' => 'Hoa Nam'],
        ]);
    }

    public function physicalCodes(Request $request)
    {
        $query = DB::table('physical_codes')->orderByDesc('created_at');
        $this->keyword($query, $request, ['code_value', 'code_type', 'carrier_type', 'status']);

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    #[OA\Get(
        path: '/api/v1/items',
        operationId: 'wms_items',
        summary: 'List inventory serial items with product information',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated inventory items'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing inventory.view permission'),
        ]
    )]
    public function items(Request $request)
    {
        $skuSelect = 'p.sku_code';
        $query = DB::table('inventory_serials as s')
            ->leftJoin('sku as p', 'p.sku_id', '=', 's.product_id')
            ->select('s.*', 'p.sku_code as product_code', DB::raw("{$skuSelect} as sku_code"), 'p.sku_name as product_name')
            ->orderByDesc('s.created_at');
        $this->keyword($query, $request, ['s.serial_no', 's.status', 'p.sku_code', 'p.sku_name']);

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    #[OA\Get(
        path: '/api/v1/putaway/tasks',
        operationId: 'wms_putaway_tasks',
        summary: 'List putaway tasks',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated putaway tasks'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing inventory.view permission'),
        ]
    )]
    public function putawayTasks(Request $request)
    {
        $query = DB::table('putaway_tasks as t')
            ->leftJoin('warehouses as w', 'w.id', '=', 't.warehouse_id')
            ->select('t.*', 'w.code as warehouse_code', 'w.name as warehouse_name')
            ->orderByDesc('t.created_at');

        if (Schema::hasColumn('putaway_tasks', 'stock_document_id')) {
            $query->leftJoin('stock_documents as d', 'd.id', '=', 't.stock_document_id')
                ->addSelect('d.doc_no as document_no');
            $keywordColumns = ['t.task_no', 't.status', 'w.code', 'w.name', 'd.doc_no'];
        } else {
            $keywordColumns = ['t.task_no', 't.status', 'w.code', 'w.name'];
        }

        $this->keyword($query, $request, $keywordColumns);

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    #[OA\Get(
        path: '/api/v1/putaway/history',
        operationId: 'wms_putaway_history',
        summary: 'List completed putaway tasks',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated putaway history'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing inventory.view permission'),
        ]
    )]
    public function putawayHistory(Request $request)
    {
        $query = DB::table('putaway_tasks as t')
            ->leftJoin('warehouses as w', 'w.id', '=', 't.warehouse_id')
            ->select('t.*', 'w.code as warehouse_code', 'w.name as warehouse_name')
            ->whereIn('t.status', ['DONE', 'COMPLETED', 'CLOSED'])
            ->orderByDesc('t.completed_at')
            ->orderByDesc('t.updated_at');

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    public function documentStatusHistory(Request $request)
    {
        $query = DB::table('document_status_history as h')
            ->leftJoin('stock_documents as d', 'd.id', '=', 'h.stock_document_id')
            ->select('h.*', 'd.doc_no', 'd.doc_type')
            ->orderByDesc('h.created_at');

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    public function inventorySnapshots(Request $request)
    {
        $openIncidents = DB::table('reconciliation_incident')->where('status', 'OPEN')->count();
        $balances = DB::table('inventory_balances')->count();
        $movements = DB::table('stock_movements')->count();

        return $this->respondSuccess([
            'status' => $openIncidents > 0 ? 'WARNING' : 'OK',
            'open_incidents' => $openIncidents,
            'inventory_balance_rows' => $balances,
            'stock_movement_rows' => $movements,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    // REQ-SRS-037 (Must, SRS/FRD v1.12 adopt tại WWHN-334; nhãn DEC-NO-STOCKTAKE-001
    // CHƯA có formal approval — Decision Record WWHN-25 vẫn pending, nên không trích
    // như Decision ID standalone đã duyệt): không expose Stocktake/Variance ở bất kỳ
    // lớp nào, kể cả OpenAPI document. Handler + OA path đã được gỡ khỏi baseline.

    #[OA\Get(
        path: '/api/v1/reversal-documents',
        operationId: 'wms_reversal_documents',
        summary: 'List reversal documents',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated reversal documents'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing stock_document.reverse permission'),
        ]
    )]
    public function reversalDocuments(Request $request)
    {
        return $this->tablePage($request, 'reversal_documents', 'created_at');
    }

    #[OA\Get(
        path: '/api/v1/warranty/cases',
        operationId: 'wms_warranty_cases',
        summary: 'List warranty cases',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated warranty cases'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing inventory.view permission'),
        ]
    )]
    public function warrantyCases(Request $request)
    {
        return $this->tablePage($request, 'warranty_case', 'created_at');
    }

    #[OA\Get(
        path: '/api/v1/warranty/cases/{caseId}/events',
        operationId: 'wms_warranty_case_events',
        summary: 'List events for a warranty case',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'caseId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated warranty events'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing inventory.view permission'),
        ]
    )]
    public function warrantyEvents(Request $request, string $caseId)
    {
        $query = DB::table('warranty_event')
            ->where('warranty_case_id', $caseId)
            ->orderByDesc('event_at');

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    #[OA\Get(
        path: '/api/v1/scan/batches',
        operationId: 'wms_scan_batches',
        summary: 'List scan batches',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated scan batches'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing inventory.view permission'),
        ]
    )]
    public function scanBatches(Request $request)
    {
        $query = DB::table('scan_batches')->orderByDesc('created_at');
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    #[OA\Get(
        path: '/api/v1/scan/events',
        operationId: 'wms_scan_events',
        summary: 'List scan events',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'result_status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated scan events'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing inventory.view permission'),
        ]
    )]
    public function scanEvents(Request $request)
    {
        // NFR-SRS-004 scan evidence. Hệ thống có hai kho evidence song song:
        // `scan_record_events` (ScanRecordingService, gắn với scan_batches) và
        // `scan_events` canonical do luồng chứng từ ghi qua recordScanEvidence.
        // Truy vết phải nhìn được cả hai nên hợp nhất về một contract chung.
        $resultStatus = $request->filled('result_status') ? (string) $request->input('result_status') : null;

        $recordEvents = DB::table('scan_record_events')
            ->select([
                'scan_event_id as id',
                'scan_batch_id',
                DB::raw("'scan_record_events' AS evidence_source"),
                'raw_code',
                'result_status',
                'physical_code_id',
                'resolved_object_type',
                'resolved_object_id',
                'created_at',
            ]);
        if ($resultStatus !== null) {
            $recordEvents->where('result_status', $resultStatus);
        }

        if (Schema::hasTable('scan_events') || Schema::hasView('scan_events')) {
            $canonical = DB::table('scan_events')
                ->select([
                    'id',
                    'scan_batch_id',
                    DB::raw("'scan_events' AS evidence_source"),
                    'raw_code',
                    'result_status',
                    'physical_code_id',
                    'resolved_object_type',
                    'resolved_object_id',
                    'created_at',
                ]);
            if ($resultStatus !== null) {
                $canonical->where('result_status', $resultStatus);
            }
            $recordEvents->unionAll($canonical);
        }

        $page = DB::query()
            ->fromSub($recordEvents, 'evidence')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return $this->respondPaginated($page);
    }

    #[OA\Post(
        path: '/api/v1/scan/batches',
        operationId: 'wms_scan_batch_create',
        summary: 'Create a scan batch and optional scan events',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(required: ['warehouse_id'], properties: [
                new OA\Property(property: 'source_type', type: 'string', maxLength: 50, nullable: true, example: 'MANUAL'),
                new OA\Property(property: 'source_id', type: 'string', format: 'uuid', nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                new OA\Property(property: 'codes', type: 'array', nullable: true, items: new OA\Items(type: 'string', maxLength: 100, example: 'HN1|SKU001|ITEM0001')),
            ])
        ),
        responses: [
            new OA\Response(response: 201, description: 'Scan batch created successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing inventory.view permission'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeScanBatch(Request $request)
    {
        $data = $request->validate([
            'source_type' => ['nullable', 'string', 'max:50'],
            'source_id' => ['nullable', 'uuid'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'codes' => ['nullable', 'array', 'max:500'],
            'codes.*' => ['string', 'max:100'],
        ]);

        $batchId = (string) Str::uuid();
        DB::table('scan_batches')->insert([
            'scan_batch_id' => $batchId,
            'source_type' => $data['source_type'] ?? 'MANUAL',
            'source_id' => $data['source_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'created_by' => auth('api')->id(),
            'created_at' => now(),
        ]);

        foreach ($data['codes'] ?? [] as $code) {
            DB::table('scan_record_events')->insert([
                'scan_event_id' => (string) Str::uuid(),
                'scan_batch_id' => $batchId,
                'raw_code' => $code,
                'result_status' => 'RECEIVED',
                'created_at' => now(),
            ]);
        }

        return $this->respondCreated(
            DB::table('scan_batches')->where('scan_batch_id', $batchId)->first(),
            'Tạo batch scan thành công'
        );
    }

    private function productMaster(Request $request, string $type)
    {
        $query = DB::table('product_masters')
            ->where('master_type', $type)
            ->orderBy('name');
        $this->keyword($query, $request, ['code', 'name', 'status']);

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    private function tablePage(Request $request, string $table, string $orderColumn)
    {
        $query = DB::table($table)->orderByDesc($orderColumn);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    private function staticPage(Request $request, array $rows)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = $this->perPage($request);
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'last_page' => (int) ceil(count($rows) / $perPage),
                'total' => count($rows),
                'request_id' => $this->currentRequestId(),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    private function keyword($query, Request $request, array $columns): void
    {
        if (! $request->filled('keyword')) {
            return;
        }

        $keyword = '%'.$request->input('keyword').'%';
        $query->where(function ($q) use ($columns, $keyword) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', $keyword);
            }
        });
    }

    protected function perPage(Request $request, int $default = 10): int
    {
        return min(100, max(1, (int) $request->input('per_page', $request->input('limit', $default))));
    }
}
