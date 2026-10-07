<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Validation\InputLimits;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\MiniAppDraftService;

/** Typed inbound staging over P03 persistence and the existing canonical record service. */
final class P04InboundController extends Controller
{
    public const CONTRACT = 'mini-app-p04-v1';

    private const ITEM_FIELDS = 'raw_code,quantity,scan_source,item_type,box_number,new_sku_type';

    public function __construct(
        private readonly MiniAppDraftService $drafts,
        private readonly MiniAppInboundStagingController $inbound,
    ) {}

    public function context(Request $request): JsonResponse
    {
        $this->input($request, [], false);
        $data = $this->drafts->context($request)->getData(true)['data'];
        $warehouse = isset($data['active_warehouse_id']) ? Warehouse::find($data['active_warehouse_id']) : null;
        $canonical = $warehouse !== null && $warehouse->code === 'KHO-TONG-HN' && $warehouse->status === 'ACTIVE';
        $permissions = $data['permissions']['INBOUND'];
        foreach ($permissions as &$allowed) {
            $allowed = $allowed && $canonical;
        }
        unset($allowed);
        $data['actor_id'] = (string) $request->user()->user_id;
        $data['permissions'] = $permissions + ['record' => $permissions['save']];
        $data['warehouse_status'] = $warehouse?->status;
        $data['inbound_supported'] = $canonical;
        $data['scan_event_mode'] = 'LOCAL_UNTIL_RECORD';
        $data['scan_session_creation'] = 'WITH_DRAFT_SAVE';
        $data['scan_session_expiry_policy'] = 'AUTH_SESSION';
        $data['document_number_assignment'] = 'ON_RECORD';
        $data['supplier_directory'] = ['scope' => 'WAREHOUSE', 'source' => 'inbound_suppliers', 'required' => false];

        return $this->response($data);
    }

    public function types(Request $request): JsonResponse
    {
        $input = $this->input($request, [], false);

        return DB::transaction(function () use ($request, $input): JsonResponse {
            $this->scope($request, $input['warehouse_id'], 'read');
            // Capability catalogue, not business fixtures. No purchase/transfer/
            // return subtypes are invented over the sole canonical receipt flow.
            return $this->response([[
                'id' => StockDocument::TYPE_RECEIPT, 'code' => StockDocument::TYPE_RECEIPT,
                'label' => 'Nhập kho', 'operation' => 'INBOUND',
                'permission' => ['all' => ['inbound.create', 'inbound.scan']],
                'allowed' => $this->drafts->permissions($request->user())['INBOUND']['save'],
            ]], 200, ['source' => 'CANONICAL_RECORD_CAPABILITY']);
        });
    }

    public function suppliers(Request $request): JsonResponse
    {
        $input = $this->input($request, [
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ], false);

        return DB::transaction(function () use ($request, $input): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id'], 'read');
            $query = DB::table('inbound_suppliers')->where('warehouse_id', $scope['warehouse_id'])
                ->where('status', $input['status'] ?? 'ACTIVE');
            $term = trim((string) ($input['q'] ?? ''));
            if ($term !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
                $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
            }
            $total = (clone $query)->count();
            $page = (int) ($input['page'] ?? 1);
            $perPage = (int) ($input['per_page'] ?? 20);
            $rows = $query->orderBy('name')->orderBy('id')->forPage($page, $perPage)
                ->get(['id', 'name', 'status', 'version', 'updated_at']);

            return $this->response($rows, 200, [
                'page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'warehouse_id' => $scope['warehouse_id'], 'source' => 'inbound_suppliers',
            ]);
        });
    }

    public function save(Request $request, string $id): JsonResponse
    {
        $input = $this->input($request, [
            'scan_session_id' => ['required', 'uuid'],
            'inbound_type' => ['required', Rule::in([StockDocument::TYPE_RECEIPT])],
            'supplier_id' => ['sometimes', 'nullable', 'uuid'],
            'name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'doc_date' => array_merge(['sometimes', 'nullable'], InputLimits::pastDate()),
            'items' => ['present', 'array', 'list', 'max:1000'],
            'items.*' => ['array:'.self::ITEM_FIELDS],
            ...$this->itemRules('items.*.'),
        ]);

        return DB::transaction(function () use ($request, $id, $input): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id'], 'save');
            $this->supplier($input['supplier_id'] ?? null, $scope['warehouse_id']);
            $codes = [];
            $options = [];
            foreach ($input['items'] as $item) {
                $raw = trim($item['raw_code']);
                $codes[] = ['raw_code' => $raw] + array_intersect_key($item, array_flip(['quantity', 'scan_source']));
                $options[hash('sha256', $raw)] = array_intersect_key($item, array_flip(['item_type', 'box_number', 'new_sku_type']));
            }
            $form = [
                'p04_contract' => self::CONTRACT,
                'metadata' => array_intersect_key($input, array_flip(['inbound_type', 'supplier_id', 'name', 'note', 'doc_date'])),
                'item_options' => $options,
            ];
            $response = $this->drafts->save($this->subrequest($request, 'PUT', '/api/v1/mini-app/drafts/'.$id, [
                'warehouse_id' => $scope['warehouse_id'], 'operation' => 'INBOUND',
                'scan_session_id' => strtolower($input['scan_session_id']),
                'title' => $input['name'] ?? null, 'codes' => $codes, 'form' => $form,
            ]), $id);

            return $this->decorate($request, $response);
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $input = $this->input($request, ['scan_session_id' => ['sometimes', 'uuid']], false);

        return DB::transaction(function () use ($request, $id, $input): JsonResponse {
            $this->scope($request, $input['warehouse_id'], 'read');
            $draft = $this->draft($request, $id, $input, $request->hasHeader('If-Match'));

            return $this->response(['document' => $this->decorateDraft($request, $draft)])
                ->header('ETag', '"'.$draft['version'].'"');
        });
    }

    public function scanSession(Request $request, string $id): JsonResponse
    {
        // Creation is the same atomic PUT that creates the P03 draft. Resume and
        // status are this read; no orphan scan-session row or artificial TTL.
        $response = $this->show($request, $id);
        $document = $response->getData(true)['data']['document'];

        return $this->response($document['scan_session']);
    }

    public function validateCode(Request $request, string $id): JsonResponse
    {
        $input = $this->input($request, [
            'scan_session_id' => ['required', 'uuid'], 'client_event_id' => ['required', 'uuid'],
            'item' => ['required', 'array:'.self::ITEM_FIELDS], ...$this->itemRules('item.'),
        ]);
        $this->requireVersion($request);

        return DB::transaction(function () use ($request, $id, $input): JsonResponse {
            $this->scope($request, $input['warehouse_id'], 'save');
            $draft = $this->draft($request, $id, $input, true);
            $this->editable($draft);
            $items = $this->items($draft);
            $items[] = $input['item'];
            $rows = $this->inspectRows($request, $items, $draft['warehouse_id']);

            return $this->response([
                'document_id' => $id, 'scan_session_id' => $draft['scan_session_id'], 'version' => $draft['version'],
                'client_event_id' => strtolower($input['client_event_id']), 'event_persisted' => false,
                'result' => $rows[array_key_last($rows)], 'scan_evidence_created' => false, 'stock_effect' => 'NONE',
            ]);
        });
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $input = $this->input($request, ['scan_session_id' => ['required', 'uuid']]);
        $this->requireVersion($request);

        return DB::transaction(function () use ($request, $id, $input): JsonResponse {
            $this->scope($request, $input['warehouse_id'], 'save');
            $draft = $this->draft($request, $id, $input, true);
            $this->editable($draft);
            $metadata = $this->metadata($draft);
            $supplier = $this->supplier($metadata['supplier_id'] ?? null, $draft['warehouse_id']);
            $rows = $this->inspectRows($request, $this->items($draft), $draft['warehouse_id']);
            $valid = $rows !== [] && count(array_filter($rows, fn (array $row): bool => $row['outcome'] !== 'ACCEPTED')) === 0;
            $groups = [];
            foreach ($rows as $row) {
                if ($row['outcome'] !== 'ACCEPTED') {
                    continue;
                }
                $sku = $row['resolved'];
                $key = $sku['sku_id'] ?? $sku['sku_code'];
                $groups[$key] ??= ['sku_id' => $sku['sku_id'], 'sku_code' => $sku['sku_code'], 'sku_name' => $sku['sku_name'], 'quantity' => 0];
                $groups[$key]['quantity'] += $row['quantity'];
            }

            return $this->response([
                'document' => $this->decorateDraft($request, $draft), 'metadata' => $metadata,
                'supplier' => $supplier, 'items' => $rows, 'sku_summary' => array_values($groups),
                'can_record' => $valid, 'revalidated_at' => now()->toIso8601String(),
                'revalidation_policy' => 'AGAIN_AT_RECORD', 'stock_effect' => 'NONE', 'scan_evidence_created' => false,
            ]);
        });
    }

    public function record(Request $request, string $id): JsonResponse
    {
        $input = $this->input($request, [
            'scan_session_id' => ['required', 'uuid'], 'client_request_id' => ['sometimes', 'uuid'],
        ]);
        $version = $this->requireVersion($request);
        if (isset($input['client_request_id']) && strtolower($input['client_request_id']) !== strtolower((string) $request->header('Idempotency-Key'))) {
            ApiError::abort(409, 'REQUEST_IDENTITY_MISMATCH', 'client_request_id phải trùng Idempotency-Key.');
        }

        return DB::transaction(function () use ($request, $id, $input, $version): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id'], 'save');
            // Read current ownership without rejecting a historical version before
            // handoff has a chance to replay the exact already-committed receipt.
            $draft = $this->draft($request, $id, $input, false);
            $request->headers->set('X-Draft-ID', $id);
            $request->headers->set('X-Draft-Scan-Session-ID', $draft['scan_session_id']);
            $request->headers->set('X-Draft-Version', (string) $version);
            $response = $this->drafts->handoff($request, function (Request $handoff) use ($draft, $scope, $id): JsonResponse {
                $metadata = $this->metadata($draft);
                $supplier = $this->supplier($metadata['supplier_id'] ?? null, $scope['warehouse_id']);
                $items = $this->items($draft);
                $rows = $this->inspectRows($handoff, $items, $scope['warehouse_id']);
                $errors = array_values(array_filter($rows, fn (array $row): bool => $row['outcome'] !== 'ACCEPTED'));
                if ($items === [] || $errors !== []) {
                    ApiError::abort(422, 'P04_REVALIDATION_FAILED', 'Kiểm tra lại các mã và số lượng trước khi ghi nhận.', ['items' => $errors]);
                }
                $payload = array_intersect_key($metadata, array_flip(['name', 'note', 'doc_date']));
                $payload['warehouse_id'] = $scope['warehouse_id'];
                $payload['items'] = $items;
                $command = $this->subrequest($handoff, 'POST', '/api/v1/mini-app/inbound/record', $payload);
                $command->attributes->set('p03.draft_handoff', true);
                // Existing service is the only implementation of recording. It
                // re-resolves/locks the domain, creates scan evidence, NEVER Posts.
                $response = $this->inbound->record($command);
                if (! $response->isSuccessful()) {
                    throw new HttpResponseException($response);
                }
                $body = $response->getData(true);
                $documentId = $body['data']['document_id'];
                DB::table('mini_app_inbound_record_metadata')->insert([
                    'document_id' => $documentId, 'draft_id' => $id, 'inbound_type' => $metadata['inbound_type'],
                    'supplier_id' => $supplier?->id, 'supplier_name' => $supplier?->name,
                    'supplier_version' => $supplier?->version, 'recorded_at' => now(),
                ]);
                $body['data']['draft_id'] = $id;
                $body['data']['inbound_type'] = $metadata['inbound_type'];
                $body['data']['supplier'] = $supplier;
                $body['data']['record_status'] = 'RECORDED';
                $body['data']['posted'] = false;

                return $response->setData($body);
            }, 'INBOUND');

            return $response;
        });
    }

    public function reconcile(Request $request, string $id, string $requestId): JsonResponse
    {
        $input = $this->input($request, [
            'scan_session_id' => ['required', 'uuid'], 'command' => ['required', Rule::in(['SAVE', 'CANCEL', 'RECORD'])],
        ], false);

        return DB::transaction(function () use ($request, $id, $requestId, $input): JsonResponse {
            $this->scope($request, $input['warehouse_id'], 'read');
            $response = $this->drafts->reconcile($this->subrequest($request, 'GET', $request->path(), $input + ['operation' => 'INBOUND']), $id, $requestId);
            $body = $response->getData();
            $document = $body->data->document;
            $body->data->state = $body->data->outcome === 'UNKNOWN' ? 'UNKNOWN'
                : (($document?->business_status === 'POSTED') ? 'POSTED' : $document?->status);
            $body->meta->contract = self::CONTRACT;

            return $response->setData($body);
        });
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $input = $this->input($request, [
            'scan_session_id' => ['required', 'uuid'], 'confirmed_request_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request, $id, $input): JsonResponse {
            $this->scope($request, $input['warehouse_id'], 'cancel');
            $response = $this->drafts->cancel($this->subrequest($request, 'POST', $request->path(), $input + ['operation' => 'INBOUND']), $id);

            return $this->decorate($request, $response);
        });
    }

    public function document(Request $request, string $id): JsonResponse
    {
        $input = $this->input($request, [], false);
        if (! Str::isUuid($id)) {
            ApiError::abort(404, 'INBOUND_DOCUMENT_NOT_FOUND', 'Không tìm thấy chứng từ nhập kho.');
        }

        return DB::transaction(function () use ($request, $input, $id): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id'], 'read');
            // Canonical records are shared with authorized readers of this
            // warehouse (P12/P22); personal staging drafts remain actor+session scoped.
            $document = StockDocument::query()->with(['lines', 'statusHistory'])
                ->where('doc_type', StockDocument::TYPE_RECEIPT)->where('dst_warehouse_id', $scope['warehouse_id'])
                ->whereKey($id)->first();
            if ($document === null) {
                ApiError::abort(404, 'INBOUND_DOCUMENT_NOT_FOUND', 'Không tìm thấy chứng từ nhập kho trong phạm vi hiện tại.');
            }
            $metadata = DB::table('mini_app_inbound_record_metadata')->where('document_id', $id)->first();

            return $this->response([
                'document' => $document, 'history' => $document->statusHistory,
                'inbound_metadata' => $metadata, 'status' => $document->status,
                'posted' => $document->status === StockDocument::STATUS_POSTED,
                'observed_at' => now()->toIso8601String(),
            ])->header('ETag', '"'.$document->version.'"');
        });
    }

    private function input(Request $request, array $extra, bool $body = true): array
    {
        $rules = ['warehouse_id' => ['required', 'uuid']] + $extra;
        if ($request->is('api/v1/mini-app/inbound/context')) {
            $rules = [];
        }
        if ($body && (! $request->isJson() || $request->query() !== [])) {
            ApiError::abort(422, 'INVALID_P04_REQUEST', 'Gửi JSON body, không gửi query cho thao tác này.');
        }
        if (strlen($request->getContent()) > 768 * 1024) {
            ApiError::abort(413, 'DRAFT_PAYLOAD_TOO_LARGE', 'Nội dung bản nháp vượt giới hạn.');
        }
        $input = $body ? $request->json()->all() : $request->query();
        $keys = array_values(array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '.')));
        if (array_diff(array_keys($input), $keys) !== []) {
            ApiError::abort(422, 'UNEXPECTED_FIELD', 'Có trường ngoài contract P04; không tự khai actor, phiên, trạng thái hoặc phiên bản.');
        }

        return Validator::make($input, $rules)->validate();
    }

    private function itemRules(string $prefix): array
    {
        return [
            $prefix.'raw_code' => ['required', 'string', 'min:1', 'max:512'],
            $prefix.'quantity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            $prefix.'scan_source' => ['sometimes', Rule::in(['CAMERA', 'SCANNER', 'HARDWARE', 'MANUAL'])],
            $prefix.'item_type' => ['sometimes', Rule::in(['ITEM', 'BOX'])],
            $prefix.'new_sku_type' => ['sometimes', Rule::in(['PRODUCT', 'COMPONENT'])],
            $prefix.'box_number' => ['sometimes', 'regex:/^[0-9]{1,20}$/'],
        ];
    }

    private function scope(Request $request, string $warehouseId, string $action): array
    {
        $actor = User::whereKey($request->user()->user_id)->lockForUpdate()->first();
        if ($actor === null || $actor->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn hoạt động.');
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        $id = strtolower($warehouseId);
        if (! $actor->canAccessWarehouse($id)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Không có quyền truy cập kho.');
        }
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        if ($context['warehouse_context']['status'] !== 'READY') {
            ApiError::abort(409, $context['warehouse_context']['error_code'] ?? 'WAREHOUSE_CONTEXT_REQUIRED', 'Cần xác nhận lại kho đang làm việc.');
        }
        if ($context['active_warehouse_id'] !== $id) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho yêu cầu không khớp kho của phiên.');
        }
        $warehouse = Warehouse::whereKey($id)->lockForUpdate()->first();
        if ($warehouse === null || $warehouse->status !== 'ACTIVE') {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho không còn hoạt động.');
        }
        if ($warehouse->code !== 'KHO-TONG-HN') {
            ApiError::abort(422, 'INBOUND_WAREHOUSE_NOT_SUPPORTED', 'Luồng nhập hiện tại chỉ hỗ trợ kho canonical đã cấu hình.');
        }
        if (! $this->drafts->permissions($actor)['INBOUND'][$action]) {
            ApiError::abort(403, 'P04_OPERATION_DENIED', 'Không có quyền thực hiện thao tác nhập kho này.');
        }

        return ['actor' => $actor, 'warehouse_id' => $id, 'auth_session_id' => $session];
    }

    private function draft(Request $request, string $id, array $input, bool $checkVersion): array
    {
        $query = array_intersect_key($input, array_flip(['warehouse_id', 'scan_session_id'])) + ['operation' => 'INBOUND'];
        $sub = $this->subrequest($request, 'GET', '/api/v1/mini-app/drafts/'.$id, $query);
        if (! $checkVersion) {
            $sub->headers->remove('If-Match');
        }

        return $this->drafts->show($sub, $id)->getData(true)['data'];
    }

    private function editable(array $draft): void
    {
        if ($draft['status'] !== 'DRAFT' || $draft['business_document_id'] !== null) {
            ApiError::abort(409, 'DRAFT_STATE_CONFLICT', 'Bản nháp không còn ở trạng thái cho phép thao tác.');
        }
    }

    private function metadata(array $draft): array
    {
        if (($draft['form']['p04_contract'] ?? null) !== self::CONTRACT) {
            ApiError::abort(409, 'P04_METADATA_REQUIRED', 'Lưu metadata P04 trước khi kiểm tra hoặc ghi nhận nháp này.');
        }
        $metadata = $draft['form']['metadata'] ?? [];

        return Validator::make($metadata, [
            'inbound_type' => ['required', Rule::in([StockDocument::TYPE_RECEIPT])],
            'name' => ['required', 'string', 'max:200'],
            'supplier_id' => ['sometimes', 'nullable', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'doc_date' => array_merge(['sometimes', 'nullable'], InputLimits::pastDate()),
        ])->validate();
    }

    private function supplier(?string $id, string $warehouseId): ?object
    {
        if ($id === null) {
            return null;
        }
        $supplier = DB::table('inbound_suppliers')->where('id', strtolower($id))->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
        if ($supplier === null) {
            ApiError::abort(422, 'SUPPLIER_NOT_AVAILABLE', 'Nhà cung cấp không tồn tại trong phạm vi kho này.');
        }
        if ($supplier->status !== 'ACTIVE') {
            ApiError::abort(409, 'SUPPLIER_INACTIVE', 'Nhà cung cấp đã ngừng sử dụng; chọn lại trước khi ghi nhận.');
        }

        return $supplier;
    }

    private function items(array $draft): array
    {
        $items = [];
        foreach ($draft['codes'] as $code) {
            $options = $draft['form']['item_options'][hash('sha256', $code['raw_code'])] ?? [];
            if (! is_array($options)) {
                ApiError::abort(422, 'INVALID_DRAFT_FORM', 'Tùy chọn mã trong nháp không hợp lệ.');
            }
            $items[] = $code + array_intersect_key($options, array_flip(['item_type', 'box_number', 'new_sku_type']));
        }

        return $items;
    }

    private function inspectRows(Request $request, array $items, string $warehouseId): array
    {
        $rows = [];
        $seen = [];
        $skuTypes = [];
        foreach ($items as $index => $item) {
            $row = ['row_no' => $index + 1, 'raw_code' => $item['raw_code'] ?? '', 'outcome' => 'REJECTED', 'reason_code' => null];
            $validation = Validator::make($item, $this->itemRules(''));
            if ($validation->fails()) {
                $rows[] = $row + ['errors' => $validation->errors()->toArray()];
                $rows[array_key_last($rows)]['reason_code'] = 'INVALID_ITEM_FIELDS';
                continue;
            }
            try {
                $resolved = $this->inbound->resolveCode($this->subrequest($request, 'POST', '/api/v1/mini-app/inbound/resolve-code', [
                    'warehouse_id' => $warehouseId, 'raw_code' => $item['raw_code'],
                ]))->getData(true)['data'];
                $box = $resolved['item_type'] === 'BOX';
                $identity = $box ? 'BOX:'.$resolved['box_code']
                    : (isset($resolved['item_id']) ? 'ITEM:'.strtolower($resolved['item_id']) : 'CANDIDATE:'.strtoupper((string) $resolved['item_unique']));
                $row['resolved'] = $resolved;
                $quantity = (int) ($item['quantity'] ?? ($box ? 0 : 1));
                $reason = null;
                if (isset($seen[$identity])) {
                    $row['outcome'] = 'DUPLICATE';
                    $reason = 'DUPLICATE_ITEM_IN_BATCH';
                } elseif (isset($item['item_type']) && $item['item_type'] !== $resolved['item_type']) {
                    $reason = 'ITEM_TYPE_MISMATCH';
                } elseif ($box && $quantity < 1) {
                    $reason = 'BOX_QUANTITY_REQUIRED';
                } elseif (! $box && $quantity !== 1) {
                    $reason = 'ITEM_QUANTITY_MUST_BE_ONE';
                } elseif ($box && isset($item['box_number']) && ltrim((string) $item['box_number'], '0') !== ltrim((string) $resolved['box_number'], '0')) {
                    $reason = 'BOX_NUMBER_MISMATCH';
                } elseif (! $box && ($resolved['requires_new_sku_type'] ?? false) && ! isset($item['new_sku_type'])) {
                    $reason = 'NEW_SKU_TYPE_REQUIRED';
                } elseif (! $box && $resolved['resolution_status'] !== 'NEW_SKU_CANDIDATE'
                    && $resolved['sku_type'] === 'COMPONENT' && ! ($resolved['is_serial_tracked'] ?? false)) {
                    $reason = 'MINI_APP_COMPONENT_FLOW_REQUIRES_BOX';
                }
                $seen[$identity] = true;
                if ($resolved['resolution_status'] === 'NEW_SKU_CANDIDATE') {
                    $type = $box ? 'COMPONENT' : ($item['new_sku_type'] ?? null);
                    $skuKey = strtoupper($resolved['sku_code']);
                    if (isset($skuTypes[$skuKey]) && $type !== $skuTypes[$skuKey]) {
                        $reason = 'NEW_SKU_TYPE_CONFLICT';
                    }
                    if ($type !== null) {
                        $skuTypes[$skuKey] = $type;
                    }
                }
                $row['reason_code'] = $reason;
                $row['quantity'] = $quantity;
                if ($reason === null) {
                    $row['outcome'] = 'ACCEPTED';
                }
            } catch (HttpResponseException $exception) {
                if (! in_array($exception->getResponse()->getStatusCode(), [409, 422], true)) {
                    throw $exception;
                }
                $error = json_decode($exception->getResponse()->getContent(), true);
                $row['reason_code'] = $error['error']['code'] ?? $error['error_code'] ?? 'CODE_REJECTED';
                $row['message'] = $error['message'] ?? 'Mã không hợp lệ cho thao tác nhập kho.';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function requireVersion(Request $request): int
    {
        $value = $request->header('If-Match');
        if ($value === null) {
            ApiError::abort(428, 'VERSION_REQUIRED', 'Bắt buộc gửi If-Match phiên bản nháp.');
        }
        if (! preg_match('/^"([1-9][0-9]{0,8})"$/D', $value, $match)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là phiên bản nháp dương có dấu nháy kép.');
        }

        return (int) $match[1];
    }

    private function subrequest(Request $request, string $method, string $path, array $data): Request
    {
        $sub = Request::create('/'.ltrim($path, '/'), $method, $method === 'GET' ? $data : [], [], [],
            ['CONTENT_TYPE' => 'application/json'], $method === 'GET' ? null : json_encode($data, JSON_THROW_ON_ERROR));
        $sub->headers->replace($request->headers->all());
        $sub->headers->set('Content-Type', 'application/json');
        $sub->headers->remove('Content-Length');
        $sub->attributes->add($request->attributes->all());
        $sub->setUserResolver($request->getUserResolver());

        return $sub;
    }

    private function decorateDraft(Request $request, array $draft): array
    {
        $draft['actor_id'] = (string) $request->user()->user_id;
        $draft['document_no'] = $draft['business_document_id'] === null ? null
            : DB::table('stock_documents')->where('id', $draft['business_document_id'])->value('doc_no');
        $draft['numbering_status'] = $draft['document_no'] === null ? 'ASSIGNED_ON_RECORD' : 'ASSIGNED';
        $draft['scan_session'] = [
            'document_id' => $draft['document_id'], 'scan_session_id' => $draft['scan_session_id'],
            'auth_session_id' => $draft['auth_session_id'], 'warehouse_id' => $draft['warehouse_id'],
            'status' => $draft['status'] === 'DRAFT' ? 'OPEN' : 'CLOSED', 'document_version' => $draft['version'],
            'expires_at' => null, 'expiry_policy' => 'AUTH_SESSION',
            'created_at' => $draft['created_at'], 'updated_at' => $draft['updated_at'],
        ];
        $draft['form'] = (object) $draft['form'];

        return $draft;
    }

    private function decorate(Request $request, JsonResponse $response): JsonResponse
    {
        $body = $response->getData(true);
        $body['data']['document'] = $this->decorateDraft($request, $body['data']['document']);
        $body['meta']['contract'] = self::CONTRACT;

        return $response->setData($body);
    }

    private function response(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => ['contract' => self::CONTRACT] + $meta], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
