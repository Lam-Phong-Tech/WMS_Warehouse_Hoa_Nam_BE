<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Support\HistoryTimestamp;
use Modules\Warehouse\Support\P06ReadSnapshot;
use Modules\Warehouse\Support\P08HistoryPolicy as Policy;
use Modules\Warehouse\Support\P08HistoryReadModel;
use Modules\Warehouse\Support\P23ScanSessionReadModel;
use Modules\Warehouse\Support\P23SessionV4;
use Throwable;

/** P08 has GET/HEAD only. History is not a write receipt or proof of NFC confirm. */
final class P08HistoryController extends Controller
{
    public function __construct(private readonly P08HistoryReadModel $model) {}

    public function records(Request $request): JsonResponse
    {
        return $this->read($request, function (Builder $q, array $s, array $i, array $m): JsonResponse {
            [$rows, $paging] = $this->page($q, $s, $i, $m);

            return $this->reply($rows, $m + $paging);
        });
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->read($request, function (Builder $q, array $s, array $i, array $m): JsonResponse {
            $total = (clone $q)->count();
            $groups = (clone $q)->selectRaw('business, COUNT(*) AS n')->groupBy('business')->pluck('n', 'business')->all();
            $statuses = (clone $q)->selectRaw('source, status, COUNT(*) AS n')->groupBy('source', 'status')->get();
            $complete = ! collect($m['sources'])->contains(fn (array $source): bool => in_array($source['status'], ['UNAVAILABLE', 'PARTIAL'], true));

            return $this->reply(['grandTotal' => $complete ? $total : null, 'availableTotal' => $total,
                'countUnit' => 'ACTIVITY', 'groups' => array_map(fn (string $group): array => ['business' => $group, 'availableTotal' => (int) ($groups[$group] ?? 0)], Policy::GROUPS),
                'statusTotals' => $statuses->map(fn (object $row): array => ['source' => $row->source, 'status' => $row->status, 'total' => (int) $row->n])->all(),
                'coverage' => $complete ? 'COMPLETE_FOR_AUTHORIZED_SUPPORTED_SCOPE' : 'PARTIAL',
                'quantity' => null, 'quantityStatus' => 'NOT_AN_ACTIVITY_COUNT'], $m + ['total' => $total]);
        });
    }

    public function detail(Request $request, string $recordId): JsonResponse
    {
        [$source, $id] = $this->identity($recordId);
        $recordId = $source.':'.$id;

        return $this->read($request, function (Builder $q, array $s, array $i, array $m) use ($recordId): JsonResponse {
            $q->where('record_id', $recordId);
            if (! (clone $q)->exists()) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy bản ghi lịch sử trong phạm vi được cấp.');
            }
            $recordTarget = $this->recordTarget($q, $recordId, $s);
            $selected = null;
            if (isset($i['activityId'])) {
                [$type, $id] = $this->identity($i['activityId']);
                $row = (clone $q)->where('activity_id', $type.':'.$id)->first();
                if ($row === null) {
                    ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Sự kiện không thuộc đúng bản ghi lịch sử này.');
                }
                $selected = $this->event($row, $s);
            }
            [$events, $paging] = $this->page($q, $s, $i, $m);

            return $this->reply(['recordId' => $recordId, 'selectedActivity' => $selected, 'events' => $events,
                'record_target' => $recordTarget],
                $m + $paging + ['record_target_contract' => 'p08-record-target-v1']);
        }, $source);
    }

    public function scanSession(Request $request, string $sessionId): JsonResponse
    {
        $v4 = P23SessionV4::requested($request);
        [, $id] = $this->identity('SCAN:'.$sessionId);

        return $this->read($request, function (Builder $q, array $s, array $i, array $m) use ($id, $v4): JsonResponse {
            $tables = $this->model->requireScanSession($s, $id);
            $q->where('record_id', 'SCAN:'.$id);
            $counts = (clone $q)->selectRaw("COALESCE(status, 'UNKNOWN') AS state, COUNT(*) AS n")->groupByRaw("COALESCE(status, 'UNKNOWN')")->pluck('n', 'state')->all();
            [$events, $paging] = $this->page($q, $s, $i, $m);

            if ($v4) {
                $m['p23_contract'] = P23SessionV4::CONTRACT;
            }

            $response = $this->reply(['sessionId' => $id, 'sessionType' => 'SCAN_BATCH', 'sourceTables' => $tables,
                ...Policy::scanCounts($counts), 'events' => $events,
                'p23' => $v4 ? app(P23SessionV4::class)->detail($s, $id, $events, $m['asOf'])
                    : app(P23ScanSessionReadModel::class)->detail($s, $id, $counts, $m['sources']['SCAN'])], $m + $paging);
            if ($v4) {
                $response->setVary([P23SessionV4::HEADER], false);
            }

            return $response;
        }, 'SCAN');
    }

    private function input(Request $request): array
    {
        $rules = ['warehouse_id' => ['required', 'uuid'], 'source' => ['sometimes', Rule::in(['ALL', ...Policy::SOURCES])],
            'business' => ['sometimes', Rule::in(['ALL', ...Policy::GROUPS])], 'status' => ['sometimes', 'string', 'max:100'],
            'keyword' => ['sometimes', 'string', 'max:200'], 'date_mode' => ['sometimes', Rule::in(['DATE', 'RANGE', 'ALL'])],
            'date' => ['sometimes', 'date_format:Y-m-d'], 'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d'],
            'activityId' => ['sometimes', 'string', 'max:160'], 'cursor' => ['sometimes', 'string', 'max:4096'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100']];
        if (array_diff(array_keys($request->query()), array_keys($rules)) !== []) {
            ApiError::abort(422, 'HISTORY_UNKNOWN_FILTER', 'Tham số ngoài contract P08; không dùng tenant/org/user ID để đổi phạm vi.');
        }
        $i = Validator::make($request->query(), $rules)->validate();
        $i += ['source' => 'ALL', 'business' => 'ALL', 'date_mode' => 'ALL', 'limit' => 20];
        $i['limit'] = (int) $i['limit'];
        $i['warehouse_id'] = strtolower($i['warehouse_id']);
        $validDates = match ($i['date_mode']) {
            'DATE' => isset($i['date']) && ! isset($i['from']) && ! isset($i['to']),
            'RANGE' => isset($i['from'], $i['to']) && $i['from'] <= $i['to'] && ! isset($i['date']),
            default => ! isset($i['date']) && ! isset($i['from']) && ! isset($i['to']),
        };
        if (! $validDates) {
            ApiError::abort(422, 'HISTORY_INVALID_DATE_RANGE', 'DATE cần date; RANGE cần from/to hợp lệ; ALL không nhận ngày.');
        }
        if (str_ends_with($request->path(), '/summary') && (isset($i['cursor']) || isset($i['activityId']))) {
            ApiError::abort(422, 'HISTORY_SUMMARY_NOT_PAGED', 'Summary không nhận cursor hoặc activityId.');
        }
        if (isset($i['activityId']) && $request->route('recordId') === null) {
            ApiError::abort(422, 'HISTORY_ACTIVITY_REQUIRES_RECORD', 'activityId chỉ dùng cùng recordId ở màn chi tiết.');
        }

        return $i;
    }

    /** Shared authorization for the P23 batch read; callers validate warehouse_id first. */
    public function scope(Request $request, array $i): array
    {
        $presented = $request->user();
        if (! $presented instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }
        $actor = User::whereKey($presented->user_id)->first();
        if ($actor === null || $actor->status !== 'ACTIVE' || ! $actor->hasPermission('history.read')) {
            ApiError::abort(403, 'HISTORY_READ_FORBIDDEN', 'Không có quyền history.read hoặc tài khoản không còn hoạt động.');
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        $warehouse = $i['warehouse_id'];
        if (! $actor->canAccessWarehouse($warehouse)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho nằm ngoài phạm vi được cấp.');
        }
        if (($context['warehouse_context']['status'] ?? null) !== 'READY' || ($context['active_warehouse_id'] ?? null) !== $warehouse) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Xác nhận lại kho làm việc của phiên đăng nhập.');
        }
        $row = DB::table('warehouses')->where('id', $warehouse)->where('status', 'ACTIVE')->first();
        if ($row === null) {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho không còn hoạt động.');
        }
        $dimensions = [];
        foreach (['tenant_id', 'org_id', 'organization_id'] as $field) {
            $value = $actor->getAttribute($field);
            $owner = $row->{$field} ?? null;
            if ($value !== null || $owner !== null) {
                if (! is_string($value) || $value === '' || $value !== $owner) {
                    ApiError::abort(403, 'HISTORY_ORGANIZATION_MISMATCH', 'Không xác minh được cùng tenant/tổ chức với kho.');
                }
                $dimensions[$field] = $value;
            }
        }
        $docs = ['receipt' => $actor->hasPermission('inbound.view'), 'issue' => $actor->hasPermission('outbound.view')];
        $rights = ['DOCUMENT' => in_array(true, $docs, true), 'WARRANTY' => $actor->hasPermission('warranty.view') || $actor->hasPermission('warranty.manage'),
            'NFC' => $actor->hasPermission('physical_code.assign_rfid') || $actor->hasPermission('inventory.trace'), 'SCAN' => $actor->hasPermission('inventory.view')];

        return ['warehouse_id' => $warehouse, 'actor_id' => (string) $actor->user_id, 'auth_session_id' => $session,
            'dimensions' => $dimensions, 'rights' => $rights, 'documentRights' => $docs,
            'documentsRead' => $actor->hasPermission('documents.read')];
    }

    private function read(Request $request, callable $callback, ?string $source = null): JsonResponse
    {
        $input = $this->input($request);
        if ($source !== null) {
            if ($input['source'] !== 'ALL' && $input['source'] !== $source) {
                ApiError::abort(422, 'HISTORY_ID_SOURCE_MISMATCH', 'Nguồn lọc không khớp loại ID.');
            }
            $input['source'] = $source;
        }

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $input, $callback): JsonResponse {
            $scope = $this->scope($request, $input);
            if ($request->route('sessionId') !== null && P23SessionV4::requested($request)) {
                $scope = P23SessionV4::scope($scope, $request);
            }
            $filters = $input;
            unset($filters['cursor']);
            $fingerprint = Policy::fingerprint($scope, ['path' => $request->path(),
                'timestamp_contract' => HistoryTimestamp::CONTRACT, 'storage_timezone' => HistoryTimestamp::NAIVE_TIMEZONE, ...$filters]);
            $asOf = $this->databaseTime(now());
            $cursor = null;
            if (isset($input['cursor'])) {
                try {
                    $cursor = json_decode(Crypt::decryptString($input['cursor']), true, 32, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    ApiError::abort(422, 'HISTORY_CURSOR_INVALID', 'Cursor không hợp lệ; tải lại từ trang đầu.');
                }
                if (! is_array($cursor) || ! Policy::cursorMatches($cursor, $fingerprint, time())) {
                    ApiError::abort(422, 'HISTORY_CURSOR_SCOPE_MISMATCH', 'Cursor hết hạn hoặc khác bộ lọc/quyền/phiên/kho.');
                }
                $asOf = $cursor['asOf'];
            }
            [$query, $sources] = $this->model->build($scope, $input['source']);
            $query->where(fn (Builder $q): Builder => $q->whereNull('event_time')->orWhere('event_time', '<=', HistoryTimestamp::key($asOf)));
            foreach (['business', 'status'] as $key) {
                if (isset($input[$key]) && $input[$key] !== 'ALL') {
                    $query->where($key, $input[$key]);
                }
            }
            if (isset($input['keyword']) && trim($input['keyword']) !== '') {
                $pattern = Policy::pattern($input['keyword']);
                $query->where(fn (Builder $q): Builder => $q->whereRaw("LOWER(label) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(action) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
            }
            if ($input['date_mode'] !== 'ALL') {
                $from = $input['date_mode'] === 'DATE' ? $input['date'] : $input['from'];
                $to = $input['date_mode'] === 'DATE' ? $input['date'] : $input['to'];
                $timezone = config('app.timezone', 'Asia/Ho_Chi_Minh');
                $query->where('event_time', '>=', HistoryTimestamp::key(Carbon::createFromFormat('!Y-m-d', $from, $timezone)))
                    ->where('event_time', '<', HistoryTimestamp::key(Carbon::createFromFormat('!Y-m-d', $to, $timezone)->addDay()));
            }
            $meta = ['contract' => 'mini-app-p08-v1', 'warehouseId' => $scope['warehouse_id'],
                'timestamp_contract' => HistoryTimestamp::CONTRACT,
                'timestamp_storage_policy' => 'AWARE_PRESERVE_INSTANT_NAIVE_SOURCE_VIETNAM_WALL_CLOCK',
                'organizationScope' => $scope['dimensions'], 'tenantIsolation' => $scope['dimensions'] === [] ? 'SINGLE_TENANT_DATABASE_REQUIRED' : 'TRUSTED_ACTOR_AND_WAREHOUSE_COLUMNS',
                'sources' => $sources, 'countUnit' => 'ACTIVITY', 'dateMode' => $input['date_mode'],
                'timezone' => config('app.timezone'), 'asOf' => $asOf, 'snapshot' => $snapshot,
                'scopePolicy' => 'PERSISTED_OWNER_WAREHOUSE_BEFORE_DEDUP_AND_TOTAL',
                'coveragePolicy' => 'ONLY_PERSISTED_SCOPED_EVENTS_NOT_ALL_BUSINESS_RECORDS',
                'nfcLegacyPolicy' => 'EXCLUDED_WHEN_ASSIGNMENT_WAREHOUSE_NOT_RECORDED',
                'paginationConsistency' => 'KEYSET_UPPER_BOUND_NOT_FROZEN_SNAPSHOT',
                'historyExportAvailable' => false];
            // Internal cursor state is never included in a response envelope.
            $input['_cursor'] = $cursor;
            $input['_fingerprint'] = $fingerprint;

            return $callback($query, $scope, $input, $meta);
        });
    }

    private function page(Builder $query, array $scope, array $input, array $meta): array
    {
        $total = (clone $query)->count();
        $cursor = $input['_cursor'];
        if ($cursor !== null) {
            $query->where(fn (Builder $q): Builder => $q->where('sort_time', '<', $cursor['time'])
                ->orWhere(fn (Builder $tie): Builder => $tie->where('sort_time', $cursor['time'])->where('activity_id', '<', $cursor['activityId'])));
        }
        $rows = $query->orderByDesc('sort_time')->orderByDesc('activity_id')->limit($input['limit'] + 1)->get();
        $hasMore = $rows->count() > $input['limit'];
        $rows = $rows->take($input['limit']);
        $next = null;
        if ($hasMore) {
            $last = $rows->last();
            $next = Crypt::encryptString(json_encode(['v' => 1, 'scope' => $input['_fingerprint'], 'asOf' => $meta['asOf'],
                'time' => $last->sort_time, 'activityId' => $last->activity_id, 'expires' => $cursor['expires'] ?? time() + 900], JSON_THROW_ON_ERROR));
        }

        return [$rows->map(fn (object $row): array => $this->event($row, $scope))->values()->all(),
            ['total' => $total, 'limit' => $input['limit'], 'nextCursor' => $next, 'hasMore' => $hasMore]];
    }

    private function event(object $row, array $scope): array
    {
        $path = match ($row->source) {
            'DOCUMENT' => match ($row->business) {
                'INBOUND' => '/api/v1/mini-app/inbound-documents/'.$row->owner_id,
                'OUTBOUND' => '/api/v1/mini-app/outbound-documents/'.$row->owner_id,
                default => null,
            },
            'WARRANTY' => '/api/v1/mini-app/warranty-cases/'.$row->owner_id,
            'NFC' => '/api/v1/mini-app/nfc-tags/'.$row->owner_id,
            default => null,
        };
        $canOpen = $path !== null && ($row->source !== 'DOCUMENT' || $scope['documentsRead']);
        $attachments = $canOpen && $scope['documentsRead'] && in_array($row->source, ['DOCUMENT', 'WARRANTY'], true);

        return ['activityId' => $row->activity_id, 'recordId' => $row->record_id, 'eventId' => $row->event_id,
            'source' => $row->source, 'sourceTable' => $row->source_table, 'business' => $row->business,
            'status' => $row->status, 'action' => $row->action, 'time' => HistoryTimestamp::utc($row->event_time),
            'timeStatus' => $row->event_time === null ? 'NOT_RECORDED' : 'SOURCE_TIMESTAMP', 'label' => $row->label,
            'codeId' => $row->code_id, 'quantity' => null, 'rawCode' => null,
            'rawCodePolicy' => 'OMITTED_MAY_CONTAIN_PII_OR_SECRETS',
            'detail' => ['canOpen' => $canOpen, 'url' => $canOpen ? $path : null, 'ownerPermissionRechecked' => true],
            'attachments' => ['canOpen' => $attachments, 'url' => $attachments ? $path.'/attachments' : null,
                'policy' => 'OWNER_ENDPOINT_REAUTHORIZATION_NO_STORAGE_URL'],
            'isConfirmationReceipt' => false];
    }

    /** Persisted record identity, independent of selected activity and the cursor page. */
    private function recordTarget(Builder $query, string $recordId, array $scope): array
    {
        $identities = (clone $query)->select('record_id', 'owner_id', 'source', 'business')->distinct()->limit(2)->get();
        $row = $identities->first();
        [$source, $id] = Policy::identity($recordId);
        if ($identities->count() !== 1 || $row === null || $row->record_id !== $recordId
            || $row->source !== $source || ! Str::isUuid((string) $row->owner_id) || strtolower($row->owner_id) !== $id) {
            ApiError::abort(503, 'HISTORY_RECORD_IDENTITY_UNRESOLVED', 'Không xác minh được duy nhất chủ quản của bản ghi lịch sử.');
        }
        $ownerPath = match ($source) {
            'DOCUMENT' => match ($row->business) {
                'INBOUND' => '/api/v1/mini-app/inbound-documents/'.$id,
                'OUTBOUND' => '/api/v1/mini-app/outbound-documents/'.$id,
                default => null,
            },
            'WARRANTY' => '/api/v1/mini-app/warranty-cases/'.$id,
            default => null,
        };
        $canOpen = $ownerPath !== null && ($source !== 'DOCUMENT' || $scope['documentsRead']);
        $canFiles = $canOpen && $scope['documentsRead'];
        $target = static fn (?string $path, bool $allowed): array => [
            'available' => $allowed, 'read_target' => $allowed ? [
                'method' => 'GET', 'path' => $path, 'query' => ['warehouse_id' => $scope['warehouse_id']],
            ] : null,
        ];

        return [
            'contract' => 'p08-record-target-v1',
            'identity' => ['record_id' => $recordId, 'source' => $source, 'owner_id' => $id,
                'warehouse_id' => $scope['warehouse_id'], 'resource_type' => match ($source) {
                    'DOCUMENT' => 'STOCK_DOCUMENT', 'WARRANTY' => 'WARRANTY_CASE',
                    'NFC' => 'PHYSICAL_CODE', 'SCAN' => 'SCAN_BATCH',
                }],
            'detail' => $target($ownerPath, $canOpen),
            'attachments' => $target($ownerPath === null ? null : $ownerPath.'/attachments', $canFiles),
            'scan_session' => $target('/api/v1/mini-app/history/scan-sessions/'.$id, $source === 'SCAN'),
            'nfc_history' => $target('/api/v1/mini-app/nfc-tags/'.$id.'/events', $source === 'NFC'),
            'owner_permission_rechecked_on_open' => true,
            'selection_policy' => 'NO_ACTIVITY_SELECTED_WITHOUT_EXPLICIT_ACTIVITY_ID',
            'is_confirmation_receipt' => false, 'write_operations' => [],
        ];
    }

    private function identity(string $id): array
    {
        try {
            return Policy::identity($id);
        } catch (\InvalidArgumentException) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'ID lịch sử không đúng định dạng nguồn:UUID.');
        }
    }

    private function databaseTime(Carbon $time): string
    {
        return HistoryTimestamp::utc($time);
    }

    private function reply(mixed $data, array $meta): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => $meta + [
            'request_id' => request()->attributes->get('request_id'), 'timestamp' => now()->toIso8601String(),
        ]])->header('Cache-Control', 'private, no-store');
    }
}
