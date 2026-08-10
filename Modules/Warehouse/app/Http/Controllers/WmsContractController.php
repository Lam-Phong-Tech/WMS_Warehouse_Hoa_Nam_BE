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
        $query = DB::table('physical_code')->orderByDesc('created_at');
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
        $skuSelect = Schema::hasColumn('products', 'sku_code') ? 'p.sku_code' : 'p.code';
        $query = DB::table('inventory_serials as s')
            ->leftJoin('products as p', 'p.id', '=', 's.product_id')
            ->select('s.*', 'p.code as product_code', DB::raw("{$skuSelect} as sku_code"), 'p.name as product_name')
            ->orderByDesc('s.created_at');
        $this->keyword($query, $request, ['s.serial_no', 's.status', 'p.code', 'p.name']);

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
        $query = DB::table('putaway_task as t')
            ->leftJoin('warehouses as w', 'w.id', '=', 't.warehouse_id')
            ->select('t.*', 'w.code as warehouse_code', 'w.name as warehouse_name')
            ->orderByDesc('t.created_at');

        if (Schema::hasColumn('putaway_task', 'stock_document_id')) {
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
        $query = DB::table('putaway_task as t')
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

    #[OA\Get(
        path: '/api/v1/stocktake/sessions',
        operationId: 'wms_stocktake_sessions',
        summary: 'List stocktake sessions',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated stocktake sessions'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing inventory.view permission'),
        ]
    )]
    public function stocktakeSessions(Request $request)
    {
        return $this->tablePage($request, 'stocktake_session', 'created_at');
    }

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
        return $this->tablePage($request, 'reversal_document', 'created_at');
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
        return $this->tablePage($request, 'scan_batch', 'created_at');
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
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated scan events'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing inventory.view permission'),
        ]
    )]
    public function scanEvents(Request $request)
    {
        return $this->tablePage($request, 'scan_event', 'created_at');
    }

    #[OA\Post(
        path: '/api/v1/scan/batches',
        operationId: 'wms_scan_batch_create',
        summary: 'Create a scan batch and optional scan events',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'source_type', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'source_id', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'codes', type: 'array', nullable: true, items: new OA\Items(type: 'string', maxLength: 255)),
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
            'source_id' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'codes' => ['nullable', 'array'],
            'codes.*' => ['string', 'max:255'],
        ]);

        $batchId = (string) Str::uuid();
        DB::table('scan_batch')->insert([
            'scan_batch_id' => $batchId,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'created_by' => auth('api')->id(),
            'created_at' => now(),
        ]);

        foreach ($data['codes'] ?? [] as $code) {
            DB::table('scan_event')->insert([
                'scan_event_id' => (string) Str::uuid(),
                'scan_batch_id' => $batchId,
                'raw_code' => $code,
                'result_status' => 'RECEIVED',
                'created_at' => now(),
            ]);
        }

        return $this->respondCreated(
            DB::table('scan_batch')->where('scan_batch_id', $batchId)->first(),
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

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->input('per_page', $request->input('limit', 15))));
    }
}
