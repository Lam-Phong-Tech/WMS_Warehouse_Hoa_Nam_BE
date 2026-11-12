<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\ProductIdentityResolver;
use Modules\Warehouse\Services\ProductQrPayloadParser;
use Modules\Warehouse\Support\P06ReadSnapshot;
use Modules\Warehouse\Support\P09WarrantyPage;
use Modules\Warehouse\Support\P09WarrantyPolicy as Policy;
use Modules\Warehouse\Support\P09WarrantyRequests;
use Modules\Warehouse\Support\P09WarrantyScope as Scope;
use Modules\Warehouse\Support\P23WarrantyDateFilter;
use Modules\Warehouse\Support\P23WarrantyTimeline;
use Modules\Warehouse\Support\WarrantyCasePii;
use Modules\Warehouse\Support\WarrantyWarehouseScope;

/** P09 mobile transport; WMS remains the only warranty state-machine owner. */
class MiniAppWarrantyController extends Controller
{
    use CachesReadPayload;

    public function __construct(
        private readonly ProductQrPayloadParser $parser,
        private readonly ProductIdentityResolver $identityResolver,
        private readonly CanonicalMvpController $canonical,
        private readonly CurrentBaselineWarrantyController $warranty,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->queryFields($request, ['warehouse_id', 'search', 'status', 'sort', 'cursor', 'page', 'per_page', ...P23WarrantyDateFilter::INPUT_FIELDS]);
        $input = Validator::make($request->query(), ['search' => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', Rule::in(['ALL', ...Policy::STATUSES])],
            'sort' => ['sometimes', Rule::in(['created_at_desc', 'created_at_asc'])]])->validate();

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $input): JsonResponse {
            $scope = Scope::resolve($request);
            $q = Scope::cases($scope);
            $dates = P23WarrantyDateFilter::apply($request, $q);
            if (isset($input['search']) && trim($input['search']) !== '') {
                $this->applyWarrantySearch($q, $input['search'], $scope);
            }
            // Facet counts share every filter except the selected status.
            $counts = (clone $q)->selectRaw('c.status, COUNT(*) AS count')->groupBy('c.status')->pluck('count', 'status');
            $statusCounts = ['ALL' => (int) $counts->sum()];
            foreach (Policy::STATUSES as $status) {
                $statusCounts[$status] = (int) ($counts[$status] ?? 0);
            }
            if (isset($input['status']) && $input['status'] !== 'ALL') {
                $q->where('c.status', $input['status']);
            }
            $pageScope = $scope + ($dates === [] ? [] : ['p23_date_filter' => $dates]);
            [$ids, $page] = P09WarrantyPage::read($request, $q, $pageScope, 'c.warranty_case_id', 'c.created_at', ($input['sort'] ?? 'created_at_desc') === 'created_at_desc');
            // Bounded to per_page, reuse canonical PII masking rather than a second serializer.
            $rows = array_map(fn (string $id): array => $this->caseData($request, $scope, $id), $ids);

            return $this->reply($rows, $scope, $page + $dates + [
                'snapshot' => $snapshot, 'search_fields' => ['warranty_case_code', 'temporary_item_code', 'serial_number'],
                'status_counts' => $statusCounts,
                'status_counts_basis' => 'SAME_WAREHOUSE_SEARCH_DATE_FILTERS_EXCLUDING_STATUS',
            ]);
        });
    }

    private function applyWarrantySearch(Builder $query, string $search, array $scope): void
    {
        $pattern = Policy::pattern($search);
        $query->where(function (Builder $filter) use ($pattern, $scope): void {
            $filter->whereRaw("LOWER(c.warranty_case_code) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(c.temporary_item_code) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereExists(function (Builder $item) use ($pattern, $scope): void {
                    $item->selectRaw('1')->from('item_instances as i')->whereColumn('i.item_id', 'c.item_id')
                        ->whereRaw("LOWER(i.serial_number) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
                    Scope::isolate($item, 'item_instances', 'i', $scope);
                });
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->queryFields($request, ['warehouse_id']);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $id): JsonResponse {
            $scope = Scope::resolve($request);
            $data = $this->caseData($request, $scope, $id);

            return $this->reply($data, $scope, ['snapshot' => $snapshot])->header('ETag', '"'.$data['version'].'"');
        });
    }

    private function caseData(Request $request, array $scope, string $id): array
    {
        $case = Scope::requireCase($scope, $id);
        $response = $this->warranty->showWarrantyCase($request, $case->warranty_case_id);
        $data = Policy::safeCase($response->getData(true)['data'], $scope['pii']);
        $data['capabilities'] = ['attachments_read' => $scope['documents'],
            'attachments_download' => $scope['documents'] && $scope['pii'],
            'posted_component_issues_read' => $scope['documents'] && $scope['component']];

        return $data;
    }

    public function events(Request $request, string $id): JsonResponse
    {
        $this->queryFields($request, ['warehouse_id', 'sort', 'cursor', 'page', 'per_page']);
        Validator::make($request->query(), ['sort' => ['sometimes', Rule::in(['event_at_asc', 'event_at_desc'])]])->validate();

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $id): JsonResponse {
            $scope = Scope::resolve($request);
            $case = Scope::requireCase($scope, $id);
            $q = DB::table('warranty_event as e')->where('e.warranty_case_id', $case->warranty_case_id);
            Scope::isolate($q, 'warranty_event', 'e', $scope);
            $timeline = P23WarrantyTimeline::metadata(clone $q);
            [$ids, $page] = P09WarrantyPage::read($request, clone $q, $scope, 'e.warranty_event_id', 'e.event_at', $request->query('sort', 'event_at_asc') === 'event_at_desc');
            $events = $q->whereIn('e.warranty_event_id', $ids)->get(['e.warranty_event_id', 'e.warranty_case_id', 'e.event_type', 'e.from_status', 'e.to_status', 'e.note', 'e.created_by', 'e.event_at'])->keyBy('warranty_event_id');
            $actorIds = $events->pluck('created_by')->filter()->unique()->values()->all();
            $actors = collect();
            if ($actorIds !== []) {
                try {
                    $actors = DB::table('app_user')->whereIn('user_id', $actorIds)
                        ->get(['user_id', 'display_name'])->keyBy('user_id');
                } catch (\Throwable) {
                    $actors = collect();
                }
            }
            $rows = array_map(function (string $eventId) use ($events, $actors, $scope): array {
                $e = $events[$eventId];
                $actorId = $e->created_by;
                $actor = $actorId === null ? null : $actors->get($actorId);
                $displayName = $actor?->display_name;
                $displayName = is_string($displayName) && trim($displayName) !== '' ? $displayName : null;

                return ['event_id' => $e->warranty_event_id, 'warranty_case_id' => $e->warranty_case_id,
                    'event_type' => $e->event_type, 'from_status' => $e->from_status, 'status' => $e->to_status,
                    'to_status' => $e->to_status, 'event_at' => $e->event_at,
                    'actor' => ['user_id' => $actorId, 'user_id_source' => 'warranty_event.created_by',
                        'display_name' => $displayName,
                        'display_name_status' => $actorId === null ? 'NOT_RECORDED_IN_EVENT'
                            : ($displayName === null ? 'ACTOR_SOURCE_UNAVAILABLE' : 'CURRENT_DIRECTORY_LABEL_NOT_HISTORICAL_SNAPSHOT'),
                        'display_name_source' => $displayName === null ? null : 'app_user.display_name'],
                    'related_document_event_id' => null, 'related_document_event_status' => 'NOT_RECORDED_IN_EVENT',
                    'message' => $scope['pii'] ? $e->note : null,
                    'message_redacted' => ! $scope['pii'], 'time_status' => $e->event_at === null ? 'NOT_RECORDED' : 'SOURCE_TIMESTAMP'];
            }, $ids);

            return $this->reply($rows, $scope, $page + $timeline + ['snapshot' => $snapshot, 'order' => $request->query('sort', 'event_at_asc')]);
        });
    }

    public function resolveCode(Request $request): JsonResponse
    {
        $this->queryFields($request, ['warehouse_id']);
        $body = $this->body($request, ['warehouse_id', 'raw_code', 'item_id']);
        $data = Validator::make($body, ['raw_code' => ['nullable', 'string', 'max:512'], 'item_id' => ['nullable', 'uuid']])->validate();
        if ((empty($data['raw_code']) ? 0 : 1) + (empty($data['item_id']) ? 0 : 1) !== 1) {
            ApiError::abort(422, 'WARRANTY_IDENTITY_REQUIRED', 'Gửi đúng một raw_code hoặc item_id.');
        }

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $data): JsonResponse {
            $s = Scope::resolve($request);
            $item = ! empty($data['item_id']) ? Scope::requireItem($s, strtolower($data['item_id'])) : $this->resolveItem(trim($data['raw_code']), $s);
            $active = DB::table('warranty_case')->where('item_id', $item->item_id)->whereNotIn('status', ['RETURNED', 'CANCELLED'])->exists();
            $eligible = in_array($item->item_status, ['ISSUED', 'RETURNED_TO_CUSTOMER'], true) && ! $active;

            return $this->reply(['resolved' => true, 'item_id' => $item->item_id, 'serial_number' => $item->serial_number,
                'sku_id' => $item->sku_id, 'item_status' => $item->item_status, 'active_warranty_exists' => $active,
                'eligible_for_warranty' => $eligible, 'eligibility_code' => $active ? 'ACTIVE_WARRANTY_EXISTS' : ($eligible ? 'ELIGIBLE' : 'ITEM_NOT_ISSUED'),
                'stock_effect' => 'NONE', 'movement_created' => false, 'wms_case_created' => false], $s, ['snapshot' => $snapshot]);
        });
    }

    /** Exact identities only. Conflicting serial/code/assignment candidates are rejected. */
    private function resolveItem(string $code, array $scope, bool $lock = false): object
    {
        if ($this->parser->looksComposite($code)) {
            $p = $this->parser->parse($code);
            if (! $p->isValid() || $p->skuCode === null || $p->itemUnique === null) {
                ApiError::abort(422, 'WARRANTY_ITEM_NOT_FOUND', 'Không xác định được hiện vật từ mã.');
            }
            $sku = $this->identityResolver->requireActiveSkuByCode($p->skuCode);
            $item = $this->identityResolver->findItemByUnique($p->itemUnique);
            if ($item === null) {
                ApiError::abort(404, 'WARRANTY_ITEM_NOT_FOUND', 'Không tìm thấy hiện vật.');
            }
            $this->identityResolver->assertItemMatchesSku($item, $sku, $p);

            return Scope::requireItem($scope, (string) $item->item_id, $lock);
        }
        $ids = DB::table('item_instances')->where('serial_number', $code)->limit(2)->pluck('item_id')->all();
        $codes = DB::table('physical_codes')->where('code_value', $code)->where('status', 'ACTIVE')->limit(2)->get();
        foreach ($codes as $physical) {
            if (! empty($physical->item_id)) {
                $ids[] = (string) $physical->item_id;
            }
            if (($physical->assigned_object_type ?? null) === 'ITEM' && ! empty($physical->assigned_object_id)) {
                $ids[] = (string) $physical->assigned_object_id;
            }
            if (Schema::hasColumn('item_instances', 'physical_code_id')) {
                array_push($ids, ...DB::table('item_instances')->where('physical_code_id', $physical->physical_code_id)->limit(2)->pluck('item_id')->all());
            }
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) !== 1) {
            ApiError::abort(count($ids) > 1 ? 409 : 404, count($ids) > 1 ? 'WARRANTY_IDENTITY_AMBIGUOUS' : 'WARRANTY_ITEM_NOT_FOUND', 'Không xác định được duy nhất hiện vật trong phạm vi kho.');
        }

        return Scope::requireItem($scope, (string) $ids[0], $lock);
    }

    public function store(Request $request): JsonResponse
    {
        $s = Scope::resolve($request, true);

        return P09WarrantyRequests::execute($request, $s, 'CREATE', null, function (string $requestId) use ($request, $s): JsonResponse {
            $this->queryFields($request, ['warehouse_id']);
            $body = $this->body($request, Policy::CREATE_FIELDS);
            $inner = $this->commandRequest($request, $requestId);
            WarrantyWarehouseScope::prepareCreate($inner);
            WarrantyWarehouseScope::lockCreate($inner);
            $data = Validator::make($body, ['item_id' => ['nullable', 'uuid'], 'item_code' => ['nullable', 'string', 'max:512'], 'case_no' => ['nullable', 'string', 'max:80'], 'warranty_case_code' => ['nullable', 'string', 'max:80']])->validate();
            $item = ! empty($data['item_id']) ? Scope::requireItem($s, strtolower($data['item_id']), true) : null;
            if (! empty($data['item_code'])) {
                $resolved = $this->resolveItem(trim($data['item_code']), $s, true);
                if ($item !== null && (string) $item->item_id !== (string) $resolved->item_id) {
                    ApiError::abort(422, 'WARRANTY_ITEM_IDENTITY_MISMATCH', 'item_id không khớp serial/mã đã cung cấp.');
                }
                $item = $resolved;
            }
            if (isset($body['case_no'], $body['warranty_case_code']) && trim($body['case_no']) !== trim($body['warranty_case_code'])) {
                ApiError::abort(422, 'WARRANTY_CASE_CODE_MISMATCH', 'Hai mã phiếu không khớp nhau.');
            }
            if ($item !== null) {
                if (DB::table('warranty_case')->where('item_id', $item->item_id)->whereNotIn('status', ['RETURNED', 'CANCELLED'])->exists()) {
                    ApiError::abort(409, 'ACTIVE_WARRANTY_EXISTS', 'Hiện vật đã có hồ sơ bảo hành đang hoạt động.');
                }
                $inner->merge(['item_id' => (string) $item->item_id]);
            }
            $response = $this->warranty->storeWarrantyCase($inner);
            $this->invalidateAfterWrite();

            return $response;
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $s = Scope::resolve($request, true);
        $id = (string) Scope::requireCase($s, $id)->warranty_case_id;

        return P09WarrantyRequests::execute($request, $s, 'UPDATE', strtolower($id), function (string $requestId) use ($request, $s, $id): JsonResponse {
            $this->queryFields($request, ['warehouse_id']);
            $body = $this->body($request, Policy::UPDATE_FIELDS);
            if (! $request->hasHeader('If-Match')) {
                ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc.');
            }
            try {
                $version = Policy::version((string) $request->header('If-Match'));
            } catch (\InvalidArgumentException) {
                ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là phiên bản số nguyên dương, ví dụ "1".');
            }
            $inner = $this->commandRequest($request, $requestId);
            WarrantyWarehouseScope::assertCase($inner, $id, true);
            $case = Scope::requireCase($s, $id, true);
            // Canonical same-status handler previously indexed confirmed_defect when
            // only note was sent. Keep notes append-only without changing intake text.
            Validator::make($body, ['note' => ['nullable', 'string', 'max:2000']])->validate();
            $sameStatus = ! isset($body['status']) || $body['status'] === $case->status;
            if ($sameStatus && isset($body['note']) && trim((string) $body['note']) !== '') {
                Validator::make($body, ['note' => ['required', 'string', 'max:2000']])->validate();
                if ((int) $case->version !== $version) {
                    ApiError::abort(412, 'STALE_VERSION', 'Hồ sơ vừa được cập nhật; tải lại trước khi sửa.');
                }
                if (in_array($case->status, ['RETURNED', 'CANCELLED'], true)) {
                    ApiError::abort(409, 'STATE_CONFLICT', 'Hồ sơ đã đóng, không được cập nhật.');
                }
                $fields = $body;
                unset($fields['note'], $fields['warehouse_id'], $fields['status']);
                if ($fields === []) {
                    DB::table('warranty_case')->where('warranty_case_id', $id)->update(WarrantyCasePii::encode(['version' => $version + 1, 'updated_by' => $s['actor_id'], 'updated_at' => now()]));
                } else {
                    $inner->replace($fields);
                    $this->warranty->updateWarrantyCase($inner, $id);
                }
                DB::table('warranty_event')->insert(['warranty_event_id' => (string) Str::uuid(), 'warranty_case_id' => $id,
                    'event_type' => 'NOTE', 'from_status' => $case->status, 'to_status' => $case->status,
                    'note' => $body['note'], 'created_by' => $s['actor_id'], 'event_at' => now()]);
                AuditLogger::record(action: 'WARRANTY_NOTE_ADDED', objectType: 'warranty_case', objectId: $id,
                    actorId: $s['actor_id'], requestId: $requestId, afterData: ['version' => $version + 1, 'stock_effect' => 'NONE']);
                $response = $this->warranty->showWarrantyCase($inner, $id);
            } else {
                $response = $this->warranty->updateWarrantyCase($inner, $id);
            }
            $this->invalidateAfterWrite();

            return $response;
        });
    }

    public function reconcile(Request $request, string $requestId): JsonResponse
    {
        $this->queryFields($request, ['warehouse_id']);

        return P09WarrantyRequests::reconcile(Scope::resolve($request), $requestId);
    }

    public function componentIssues(Request $request, string $id): JsonResponse
    {
        $this->queryFields($request, ['warehouse_id', 'cursor', 'page', 'per_page']);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $id): JsonResponse {
            $s = Scope::resolve($request);
            $case = Scope::requireCase($s, $id);
            if (! $s['documents'] || ! $s['component']) {
                ApiError::abort(403, 'COMPONENT_ISSUE_READ_FORBIDDEN', 'Cần documents.read và warranty.component_issue để đọc liên kết phiếu linh kiện.');
            }
            $required = ['id', 'warranty_case_id', 'warehouse_id', 'status', 'created_at'];
            if (array_diff($required, Schema::getColumnListing('component_issue_documents')) !== []) {
                ApiError::abort(503, 'COMPONENT_ISSUE_LINK_UNAVAILABLE', 'Nguồn liên kết linh kiện chưa khả dụng.');
            }
            $q = DB::table('component_issue_documents as d')->where('d.warranty_case_id', $case->warranty_case_id)
                ->where('d.warehouse_id', $s['warehouse_id'])->where('d.status', 'POSTED');
            Scope::isolate($q, 'component_issue_documents', 'd', $s);
            [$ids, $page] = P09WarrantyPage::read($request, $q, $s, 'd.id', 'd.created_at');

            return $this->reply(array_map(fn (string $document): array => ['component_issue_document_id' => $document,
                'warranty_case_id' => $case->warranty_case_id, 'status' => 'POSTED', 'can_open' => true,
                'detail_url' => '/api/v1/mini-app/warranty-component-issues/'.$document], $ids), $s, $page + ['snapshot' => $snapshot]);
        });
    }

    private function commandRequest(Request $request, string $requestId): Request
    {
        $inner = Request::createFrom($request);
        // P09 owns the receipt. The canonical callback must not persist a second PII snapshot.
        $inner->headers->remove('Idempotency-Key');
        $inner->attributes->set('request_id', $requestId);

        return $inner;
    }

    private function body(Request $request, array $allowed): array
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        if (array_diff(array_keys($body), $allowed) !== [] || $request->allFiles() !== []) {
            ApiError::abort(422, 'WARRANTY_UNKNOWN_FIELD', 'Payload có trường ngoài contract P09.');
        }

        return $body;
    }

    private function queryFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->query()), $allowed) !== []) {
            ApiError::abort(422, 'WARRANTY_UNKNOWN_FILTER', 'Bộ lọc ngoài contract P09.');
        }
    }

    private function invalidateAfterWrite(): void
    {
        DB::afterCommit(function (): void {
            $this->bumpReadCache('stock:version');
            $this->bumpReadCache('warranty:version');
        });
    }

    private function reply(mixed $data, array $scope, array $meta = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data,
            'meta' => Scope::metadata($scope) + $meta + ['request_id' => request()->attributes->get('request_id'), 'timestamp' => now()->toIso8601String()]])
            ->header('Cache-Control', 'private, no-store');
    }
}
