<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Services\MiniAppDraftService;
use Modules\Warehouse\Support\P13WebPostHandoff;

/** P05 consumes an existing planned issue; never creates a replacement source or Posts. */
final class P05OutboundController extends Controller
{
    public const CONTRACT = 'mini-app-p05-v1';

    public function context(Request $request): JsonResponse
    {
        $this->input($request, [], false, false);
        $actor = $request->user();
        $data = app(MiniAppDraftService::class)->context($request)->getData(true)['data'];
        $rights = $data['permissions']['OUTBOUND'];
        $data['actor_id'] = (string) $actor->user_id;
        $data['permissions'] = $rights + ['record' => $rights['save'] && $rights['read']];
        $data['selector'] = [
            'method' => 'GET', 'path' => '/api/v1/mini-app/outbound/sources',
            'source_kind' => 'WMS_OUTBOUND_DOCUMENT', 'status' => 'DRAFT',
            'unscanned_only' => true, 'sort' => ['created_at:desc', 'id:desc'],
        ];
        $data['record'] = [
            'method' => 'POST', 'path' => '/api/v1/mini-app/outbound/record',
            'linkage_fields' => ['source_document_id', 'source_version'],
            'reconcile_mode' => 'P03_LINKED', 'stock_effect' => 'NONE',
        ];
        $data['recipients'] = ['path' => '/api/v1/recipients', 'permission' => 'agency.view',
            'allowed' => $actor->hasPermission('agency.view'), 'search_field' => 'keyword', 'scope' => 'GLOBAL_MASTER'];
        $data['products'] = ['path' => '/api/v1/mini-app/products', 'permission' => 'sku.view',
            'allowed' => $actor->hasPermission('sku.view'), 'search_field' => 'keyword', 'sku_type' => 'PRODUCT'];
        // Neither source stores a verified province/district/ward dataset version.
        // Preserve historical address text; do not invent or silently migrate codes.
        $data['address_contract'] = ['format' => 'TEXT', 'dataset_version' => null,
            'structured_codes_supported' => false, 'history_policy' => 'PRESERVE_TEXT', 'dataset_decision' => 'PENDING_DEV_BA'];

        return $this->response($data);
    }

    public function sources(Request $request): JsonResponse
    {
        $input = $this->input($request, [
            'q' => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', Rule::in(['DRAFT'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ], false);

        return DB::transaction(function () use ($request, $input): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id']);
            $query = $this->selectable($scope['warehouse_id']);
            if (trim($input['q'] ?? '') !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($input['q']))).'%';
                $query->where(function (Builder $q) use ($pattern): void {
                    $q->whereRaw("LOWER(d.doc_no) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(COALESCE(d.recipient_name, '')) LIKE ? ESCAPE '!'", [$pattern]);
                });
            }
            $total = (clone $query)->count();
            $page = (int) ($input['page'] ?? 1);
            $perPage = (int) ($input['per_page'] ?? 20);
            $rows = $query->select('d.*')->orderByDesc('d.created_at')->orderByDesc('d.id')->forPage($page, $perPage)->get();

            return $this->response($rows->map(fn (object $row): array => $this->sourcePayload($row))->all(), 200, [
                'page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage)),
                'warehouse_id' => $scope['warehouse_id'], 'source_kind' => 'WMS_OUTBOUND_DOCUMENT',
            ]);
        });
    }

    public function source(Request $request, string $id): JsonResponse
    {
        $input = $this->input($request, [], false);
        Validator::make(['id' => $id], ['id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($request, $input, $id): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id']);
            $row = DB::table('stock_documents')->where('id', $id)->where('doc_type', 'issue')
                ->where('src_warehouse_id', $scope['warehouse_id'])->lockForUpdate()->first();
            if ($row === null) {
                ApiError::abort(404, 'OUTBOUND_SOURCE_NOT_FOUND', 'Không tìm thấy phiếu nguồn trong kho hiện tại.');
            }
            $this->assertSelectable($row, $scope['warehouse_id']);

            return $this->response($this->sourcePayload($row))->header('ETag', '"'.$row->version.'"');
        });
    }

    /** Executed inside MiniAppDraftHandoff on the EXISTING outbound/record route. */
    public function recordExisting(Request $request): JsonResponse
    {
        if (! $request->attributes->get('p03.draft_handoff', false)) {
            ApiError::abort(409, 'P05_LINKED_DRAFT_REQUIRED', 'Ghi nhận phiếu nguồn phải liên kết bản nháp P03 để đối chiếu đúng yêu cầu.');
        }
        $input = $this->input($request, [
            'source_document_id' => ['required', 'uuid'], 'source_version' => ['required', 'integer', 'min:1'],
            'expected_total_qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:1000'],
            'items.*' => ['required', 'array:raw_code,scan_source'],
            'items.*.raw_code' => ['required', 'string', 'min:1', 'max:512'],
            'items.*.scan_source' => ['sometimes', 'nullable', Rule::in(['CAMERA', 'SCANNER', 'HARDWARE', 'MANUAL'])],
        ]);

        return DB::transaction(function () use ($request, $input): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id'], true);
            $id = strtolower($input['source_document_id']);
            $source = DB::table('stock_documents')->where('id', $id)->where('doc_type', 'issue')
                ->where('src_warehouse_id', $scope['warehouse_id'])->lockForUpdate()->first();
            if ($source === null) {
                ApiError::abort(404, 'OUTBOUND_SOURCE_NOT_FOUND', 'Không tìm thấy phiếu nguồn trong kho hiện tại.');
            }
            if ((int) $source->version !== (int) $input['source_version']) {
                ApiError::abort(412, 'SOURCE_STALE_VERSION', 'Phiếu nguồn đã thay đổi; đọc lại trước khi ghi nhận.', ['current_version' => (int) $source->version]);
            }
            $this->assertSelectable($source, $scope['warehouse_id']);
            $plan = DB::table('stock_document_lines')->where('stock_document_id', $id)->orderBy('id')->lockForUpdate()->get();
            $expected = [];
            foreach ($plan as $line) {
                $expected[$line->product_id] = ($expected[$line->product_id] ?? 0) + (int) $line->qty_planned;
            }
            if (array_sum($expected) !== (int) $input['expected_total_qty'] || count($input['items']) !== (int) $input['expected_total_qty']) {
                ApiError::abort(422, 'SOURCE_PLAN_QTY_MISMATCH', 'Số mã N/N phải bằng tổng kế hoạch của phiếu nguồn.');
            }
            // Keep the P03 saved version and the reviewed selector identity linked.
            $draft = DB::table('mini_app_drafts')->where('document_id', $request->header('X-Draft-ID'))->first();
            $form = json_decode($draft->form, true, 512, JSON_THROW_ON_ERROR);
            if (($form['p05_source']['document_id'] ?? null) !== $id || (int) ($form['p05_source']['version'] ?? -1) !== (int) $input['source_version']) {
                ApiError::abort(409, 'P05_DRAFT_SOURCE_MISMATCH', 'Lưu đúng phiếu nguồn và phiên bản vào bản nháp trước khi ghi nhận.');
            }
            $savedCodes = json_decode($draft->codes, true, 512, JSON_THROW_ON_ERROR);
            $normalize = fn (array $rows): array => array_map(fn (array $r): array => [
                trim((string) ($r['raw_code'] ?? '')), strtoupper((string) ($r['scan_source'] ?? '')), (int) ($r['quantity'] ?? 1),
            ], $rows);
            if ($normalize($savedCodes) !== $normalize($input['items'])) {
                ApiError::abort(409, 'P05_DRAFT_ITEMS_MISMATCH', 'Danh sách mã khác snapshot đã lưu; không ghi nhận bằng dữ liệu chưa đồng bộ.');
            }
            $actual = [];
            $seen = [];
            $resolvedRows = [];
            foreach ($input['items'] as $item) {
                $raw = trim($item['raw_code']);
                $resolved = app(MiniAppOutboundStagingController::class)->resolveCode($this->child($request, [
                    'warehouse_id' => $scope['warehouse_id'], 'raw_code' => $raw,
                ]))->getData(true)['data'];
                if (($resolved['eligible_for_outbound'] ?? null) !== true) {
                    ApiError::abort(409, $resolved['eligibility_code'] ?? 'OUTBOUND_ELIGIBILITY_UNKNOWN', 'Mã không còn đủ điều kiện xuất.');
                }
                if (isset($seen[$resolved['item_id']])) {
                    ApiError::abort(409, 'DUPLICATE_ITEM_IN_BATCH', 'Một hiện vật xuất hiện nhiều lần trong batch.');
                }
                $seen[$resolved['item_id']] = true;
                $actual[$resolved['sku_id']] = ($actual[$resolved['sku_id']] ?? 0) + 1;
                $resolvedRows[] = $resolved;
            }
            ksort($expected);
            ksort($actual);
            if ($actual !== $expected) {
                ApiError::abort(422, 'SOURCE_PLAN_SKU_MISMATCH', 'SKU và số lượng quét phải khớp từng dòng kế hoạch, không chỉ tổng N/N.');
            }
            foreach ($actual as $skuId => $quantity) {
                $available = (int) DB::table('inventory_balances')->where('warehouse_id', $scope['warehouse_id'])
                    ->where('product_id', $skuId)->where('stock_status', 'AVAILABLE')->sum('available_qty');
                if ($available < $quantity) {
                    ApiError::abort(422, 'INSUFFICIENT_STOCK', 'Tổng số lượng SKU vượt tồn khả dụng; chưa ghi nhận batch.');
                }
            }
            $version = (int) $source->version;
            $results = [];
            foreach ($input['items'] as $index => $item) {
                $child = $this->child($request, ['code_value' => trim($item['raw_code']), 'quantity' => 1] +
                    (isset($item['scan_source']) ? ['scan_source' => $item['scan_source']] : []));
                $child->headers->set('If-Match', '"'.$version.'"');
                $result = app(MiniAppOutboundController::class)->scan($child, $id);
                if (! $result->isSuccessful()) {
                    throw new HttpResponseException($result);
                }
                $data = $result->getData(true)['data'];
                $version = (int) $data['version'];
                $results[] = ['row_no' => $index + 1, ...$resolvedRows[$index],
                    'outbound_item_match_id' => $data['outbound_item_match_id'] ?? $data['match_id'] ?? null];
            }
            $rows = DB::table('stock_document_lines')->where('stock_document_id', $id)->get();
            if ($rows->isEmpty() || $rows->contains(fn (object $l): bool => (int) $l->scanned_qty !== (int) $l->qty_planned)) {
                ApiError::abort(409, 'ATOMIC_RECORD_INCOMPLETE', 'Chưa đủ N/N; toàn bộ giao dịch được hoàn tác.');
            }
            $current = DB::table('stock_documents')->where('id', $id)->first();
            if ($current->status !== 'SCANNING') {
                ApiError::abort(409, 'OUTBOUND_RECORD_STATE_INVALID', 'Trạng thái ghi nhận không hợp lệ; giao dịch được hoàn tác.');
            }

            P13WebPostHandoff::record($id, 'OUTBOUND', $scope['warehouse_id'], (int) $current->version);

            return $this->response([
                'document_id' => $id, 'source_document_id' => $id, 'source_version' => (int) $input['source_version'],
                'doc_no' => $current->doc_no, 'outbound_request_id' => $current->outbound_request_id,
                'src_warehouse_id' => $scope['warehouse_id'], 'version' => (int) $current->version,
                'status' => $current->status, 'mini_app_status' => 'READY_TO_ISSUE', 'record_status' => 'RECORDED',
                'posted' => false, 'full_scan' => true, 'expected_total_qty' => array_sum($expected),
                'scanned_total_qty' => array_sum($expected), 'remaining_qty' => 0,
                'source_created' => false, 'wms_document_created' => false, 'stock_effect' => 'NONE',
                'movement_created' => false, 'scan_evidence_created' => true, 'items' => $results,
            ])->header('ETag', '"'.$current->version.'"');
        });
    }

    public function document(Request $request, string $id): JsonResponse
    {
        $input = $this->input($request, [], false);
        Validator::make(['id' => $id], ['id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($request, $input, $id): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id']);
            $row = StockDocument::query()->whereKey($id)->where('doc_type', 'issue')
                ->where('src_warehouse_id', $scope['warehouse_id'])->lockForUpdate()->first();
            if ($row === null) {
                ApiError::abort(404, 'OUTBOUND_SOURCE_NOT_FOUND', 'Không tìm thấy phiếu xuất trong phạm vi hiện tại.');
            }
            $row->load(['lines', 'statusHistory']);

            return $this->response(['document' => $row, 'history' => $row->statusHistory,
                'status' => $row->status, 'posted' => $row->status === 'POSTED', 'observed_at' => now()->toIso8601String()])
                ->header('ETag', '"'.$row->version.'"');
        });
    }

    private function selectable(string $warehouse): Builder
    {
        return DB::table('stock_documents as d')->where('d.doc_type', 'issue')->where('d.src_warehouse_id', $warehouse)
            ->where('d.status', 'DRAFT')->where('d.created_at', '>', now()->subWeek())->whereNotNull('d.outbound_request_id')
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('stock_document_lines as l')->whereColumn('l.stock_document_id', 'd.id'))
            ->whereNotExists(function (Builder $q): void {
                $q->selectRaw('1')->from('stock_document_lines as l')->leftJoin('sku as s', 's.sku_id', '=', 'l.product_id')
                    ->whereColumn('l.stock_document_id', 'd.id')->where(function (Builder $invalid): void {
                        $invalid->where('l.qty_planned', '<=', 0)->orWhere('l.scanned_qty', '<>', 0)
                            ->orWhereNull('s.sku_id')->orWhere('s.sku_type', '<>', 'PRODUCT')->orWhere('s.status', '<>', 'ACTIVE');
                    });
            })
            ->whereNotExists(function (Builder $q): void {
                $q->selectRaw('1')->from('outbound_item_matches as m')->join('stock_document_lines as ml', 'ml.id', '=', 'm.outbound_line_id')
                    ->whereColumn('ml.stock_document_id', 'd.id')->where(function (Builder $active): void {
                        $active->whereNull('m.status')->orWhere('m.status', '<>', 'REMOVED');
                    });
            });
    }

    private function assertSelectable(object $row, string $warehouse): void
    {
        if (! $this->selectable($warehouse)->where('d.id', $row->id)->exists()) {
            ApiError::abort(409, 'OUTBOUND_SOURCE_NOT_SELECTABLE', 'Chỉ phiếu DRAFT chưa quét, chưa hết hạn và có kế hoạch PRODUCT hợp lệ được ghi nhận N/N.');
        }
    }

    private function sourcePayload(object $row): array
    {
        $lines = DB::table('stock_document_lines as l')->leftJoin('sku as s', 's.sku_id', '=', 'l.product_id')
            ->where('l.stock_document_id', $row->id)->orderBy('l.line_no')->orderBy('l.id')
            ->get(['l.id as line_id', 'l.product_id as sku_id', 's.sku_code', 's.sku_name', 'l.qty_planned', 'l.scanned_qty'])
            ->map(fn (object $l): array => ['line_id' => $l->line_id, 'sku_id' => $l->sku_id, 'sku_code' => $l->sku_code,
                'sku_name' => $l->sku_name, 'planned_qty' => (int) $l->qty_planned, 'scanned_qty' => (int) $l->scanned_qty])->all();

        return ['source_kind' => 'WMS_OUTBOUND_DOCUMENT', 'source_document_id' => $row->id, 'source_version' => (int) $row->version,
            'document_id' => $row->id, 'doc_no' => $row->doc_no, 'warehouse_id' => $row->src_warehouse_id,
            'outbound_request_id' => $row->outbound_request_id,
            'request_no' => DB::table('outbound_requests')->where('id', $row->outbound_request_id)->value('request_no'),
            'status' => $row->status, 'planned_total_qty' => array_sum(array_column($lines, 'planned_qty')), 'lines' => $lines,
            'recipient' => ['agency_id' => $row->agency_id ?? null, 'name' => $row->recipient_name,
                'address' => $row->recipient_address, 'phone' => $row->recipient_contact_phone],
            'note' => $row->note, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            'expires_at' => Carbon::parse($row->created_at)->addWeek()->toIso8601String(),
            'address_format' => 'TEXT', 'address_dataset_version' => null];
    }

    private function scope(Request $request, string $warehouseId, bool $write = false): array
    {
        $actor = User::whereKey($request->user()->user_id)->lockForUpdate()->first();
        if ($actor === null || $actor->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn hoạt động.');
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        $id = strtolower($warehouseId);
        if (! $actor->canAccessWarehouse($id)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho nằm ngoài quyền được cấp.');
        }
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        if ($context['warehouse_context']['status'] !== 'READY') {
            ApiError::abort(409, $context['warehouse_context']['error_code'] ?? 'WAREHOUSE_CONTEXT_REQUIRED', 'Xác nhận lại kho của phiên.');
        }
        if ($context['active_warehouse_id'] !== $id) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho gửi lên không khớp kho phiên.');
        }
        $warehouse = DB::table('warehouses')->where('id', $id)->lockForUpdate()->first();
        if ($warehouse === null || $warehouse->status !== 'ACTIVE') {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho không còn hoạt động.');
        }
        $rights = app(MiniAppDraftService::class)->permissions($actor)['OUTBOUND'];
        if (! $rights['read'] || ($write && ! $rights['save'])) {
            ApiError::abort(403, 'P05_OPERATION_DENIED', 'Không đủ quyền đọc hoặc ghi nhận phiếu xuất.');
        }

        return ['actor' => $actor, 'warehouse_id' => $id, 'auth_session_id' => $session];
    }

    private function input(Request $request, array $extra, bool $body = true, bool $warehouse = true): array
    {
        $rules = ($warehouse ? ['warehouse_id' => ['required', 'uuid']] : []) + $extra;
        if ($body && (! $request->isJson() || $request->query() !== [])) {
            ApiError::abort(422, 'INVALID_P05_REQUEST', 'Gửi JSON body, không kèm query.');
        }
        $data = $body ? $request->json()->all() : $request->query();
        $allowed = array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '.'));
        if (array_diff(array_keys($data), $allowed) !== []) {
            ApiError::abort(422, 'UNEXPECTED_FIELD', 'Không ghi đè metadata, người nhận hoặc kế hoạch phiếu nguồn.');
        }

        return Validator::make($data, $rules)->validate();
    }

    private function child(Request $request, array $body): Request
    {
        $child = Request::create($request->getUri(), 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        $child->attributes->add($request->attributes->all());
        $child->setUserResolver($request->getUserResolver());

        return $child;
    }

    private function response(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => ['contract' => self::CONTRACT] + $meta], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
