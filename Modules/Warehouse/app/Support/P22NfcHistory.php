<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Throwable;

/** P08 opt-in NFC audit projection. No current-tag join, write, or receipt inference. */
final class P22NfcHistory
{
    public const CONTRACT = 'p22-nfc-history-v1';

    public const HEADER = 'X-NFC-History-Contract';

    public function read(Request $request): JsonResponse
    {
        $input = $this->input($request);

        return P06ReadSnapshot::run(function (array $snapshot) use ($request, $input): JsonResponse {
            $scope = $this->scope($request, $input['warehouse_id']);
            $filters = $input;
            unset($filters['cursor']);
            $fingerprint = P08HistoryPolicy::fingerprint($scope, ['contract' => self::CONTRACT,
                'timestamp_contract' => NfcTimestamp::CONTRACT, 'path' => $request->path(), ...$filters]);
            $cursor = null;
            $asOf = $this->databaseTime(now());
            if (isset($input['cursor'])) {
                try {
                    $cursor = json_decode(Crypt::decryptString($input['cursor']), true, 32, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    ApiError::abort(422, 'HISTORY_CURSOR_INVALID', 'Cursor không hợp lệ.');
                }
                if (! is_array($cursor) || ! P08HistoryPolicy::cursorMatches($cursor, $fingerprint, time())) {
                    ApiError::abort(422, 'HISTORY_CURSOR_SCOPE_MISMATCH', 'Cursor hết hạn hoặc khác phiên, kho, quyền, bộ lọc.');
                }
                $asOf = $cursor['asOf'];
            }
            $query = $this->query($scope);
            $query->where(fn (Builder $q): Builder => $q->whereNull('event_time')->orWhere('event_time', '<=', $asOf));
            if ($input['type'] !== 'ALL') {
                $query->where('event_type', $input['type']);
            }
            if (isset($input['status']) && $input['status'] !== 'ALL') {
                $input['status'] === 'UNKNOWN' ? $query->whereNull('status') : $query->where('status', $input['status']);
            }
            if (isset($input['keyword']) && trim($input['keyword']) !== '') {
                $pattern = P08HistoryPolicy::pattern($input['keyword']);
                $query->where(function (Builder $q) use ($pattern): void {
                    foreach (['hardware_uid', 'previous_hardware_uid', 'serial_number'] as $column) {
                        $q->orWhereRaw("LOWER({$column}) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
                    }
                });
            }
            if ($input['date_mode'] !== 'ALL') {
                $from = $input['date_mode'] === 'DATE' ? $input['date'] : $input['from'];
                $to = $input['date_mode'] === 'DATE' ? $input['date'] : $input['to'];
                $timezone = config('app.timezone', 'Asia/Ho_Chi_Minh');
                $query->where('event_time', '>=', $this->calendarBoundary(Carbon::createFromFormat('!Y-m-d', $from, $timezone)))
                    ->where('event_time', '<', $this->calendarBoundary(Carbon::createFromFormat('!Y-m-d', $to, $timezone)->addDay()));
            }
            $meta = ['contract' => self::CONTRACT, 'owner' => 'P08', 'source' => 'NFC', 'countUnit' => 'EVENT',
                'timestamp_contract' => NfcTimestamp::CONTRACT,
                'warehouseId' => $scope['warehouse_id'], 'timezone' => config('app.timezone'), 'asOf' => HistoryTimestamp::utc($asOf),
                'snapshot' => $snapshot, 'sources' => ['NFC' => ['status' => 'INCLUDED']],
                'coveragePolicy' => 'ONLY_PERSISTED_EVENT_SNAPSHOTS', 'legacyPolicy' => 'EXCLUDE_UNPROVEN_EVENT_WAREHOUSE',
                'resultPolicy' => 'OWNER_RESULT_SNAPSHOT_NO_STORAGE_DEFAULTS',
                'paginationConsistency' => 'KEYSET_UPPER_BOUND_NOT_FROZEN_SNAPSHOT', 'exportAvailable' => false,
                'currentTagLookupPerformed' => false, 'isConfirmationReceipt' => false];
            if ($request->route('recordId') !== null) {
                $parent = $this->identity((string) $request->route('recordId'));
                $event = $this->identity($input['activityId']);
                $row = $query->where('owner_id', $parent)->where('event_id', $event)->first();
                if ($row === null) {
                    ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Sự kiện không thuộc đúng chip và phạm vi lịch sử.');
                }
                $selected = $this->event($row, $scope);

                return $this->reply(['recordId' => 'NFC:'.$parent, 'selectedActivity' => $selected, 'events' => [$selected]], $meta + ['total' => 1]);
            }
            if (isset($input['event_id'])) {
                $query->where('event_id', strtolower($input['event_id']));
                if (! (clone $query)->exists()) {
                    ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy sự kiện trong phạm vi lịch sử.');
                }
            }
            $total = (clone $query)->count();
            if (str_ends_with($request->path(), '/summary')) {
                $types = (clone $query)->selectRaw('event_type, COUNT(*) AS n')->groupBy('event_type')->pluck('n', 'event_type');
                $statuses = (clone $query)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->get()
                    ->map(fn (object $r): array => ['status' => $r->status, 'total' => (int) $r->n])->all();

                return $this->reply(['grandTotal' => $total, 'availableTotal' => $total, 'countUnit' => 'EVENT',
                    'typeTotals' => array_map(fn (string $type): array => ['type' => $type, 'total' => (int) ($types[$type] ?? 0)], ['ASSIGN', 'REPLACE', 'REVOKE', 'UNKNOWN']),
                    'statusTotals' => $statuses, 'coverage' => 'COMPLETE_FOR_AUTHORIZED_PERSISTED_EVENT_SNAPSHOTS'], $meta + ['total' => $total]);
            }
            if ($cursor !== null) {
                $query->where(fn (Builder $q): Builder => $q->where('sort_time', '<', $cursor['time'])
                    ->orWhere(fn (Builder $tie): Builder => $tie->where('sort_time', $cursor['time'])->where('event_id', '<', $this->identity($cursor['activityId']))));
            }
            $rows = $query->orderByDesc('sort_time')->orderByDesc('event_id')->limit($input['limit'] + 1)->get();
            $hasMore = $rows->count() > $input['limit'];
            $rows = $rows->take($input['limit']);
            $next = null;
            if ($hasMore) {
                $last = $rows->last();
                $next = Crypt::encryptString(json_encode(['v' => 1, 'scope' => $fingerprint, 'asOf' => $asOf,
                    'time' => $last->sort_time, 'activityId' => 'NFC:'.$last->event_id, 'expires' => $cursor['expires'] ?? time() + 900], JSON_THROW_ON_ERROR));
            }

            return $this->reply($rows->map(fn (object $r): array => $this->event($r, $scope))->values()->all(),
                $meta + ['total' => $total, 'limit' => $input['limit'], 'hasMore' => $hasMore, 'nextCursor' => $next]);
        });
    }

    private function input(Request $request): array
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true) || $request->getContent() !== '') {
            ApiError::abort(422, 'NFC_HISTORY_READ_ONLY', 'P22 chỉ đọc, không nhận body.');
        }
        $rules = ['warehouse_id' => ['required', 'uuid'], 'source' => ['sometimes', Rule::in(['NFC'])],
            'type' => ['sometimes', Rule::in(['ALL', 'ASSIGN', 'REPLACE', 'REVOKE', 'UNKNOWN'])],
            'keyword' => ['sometimes', 'string', 'max:200'], 'status' => ['sometimes', 'string', 'max:100'],
            'date_mode' => ['sometimes', Rule::in(['ALL', 'DATE', 'RANGE'])], 'date' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d'],
            'event_id' => ['sometimes', 'uuid'], 'activityId' => ['sometimes', 'string', 'max:160'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'string', 'max:4096']];
        if (array_diff(array_keys($request->query()), array_keys($rules)) !== []) {
            ApiError::abort(422, 'HISTORY_UNKNOWN_FILTER', 'Tham số ngoài contract P22.');
        }
        if ($request->route('sessionId') !== null) {
            ApiError::abort(422, 'NFC_HISTORY_ROUTE_UNSUPPORTED', 'P22 không phải lịch sử phiên quét.');
        }
        $detail = $request->route('recordId') !== null;
        if ($detail && (array_diff(array_keys($request->query()), ['warehouse_id', 'source', 'activityId']) !== [] || ! $request->query('activityId'))) {
            ApiError::abort(422, 'NFC_HISTORY_ACTIVITY_REQUIRED', 'Chi tiết cần đúng recordId và activityId; không chọn sự kiện đầu tiên.');
        }
        if (! $detail && $request->query->has('activityId')) {
            ApiError::abort(422, 'HISTORY_ACTIVITY_REQUIRES_RECORD', 'activityId chỉ dùng với recordId.');
        }
        if ($request->query->has('event_id') && (str_ends_with($request->path(), '/summary')
            || array_diff(array_keys($request->query()), ['warehouse_id', 'source', 'event_id']) !== [])) {
            ApiError::abort(422, 'NFC_HISTORY_EVENT_FILTER_CONFLICT', 'Tra event_id trực tiếp chỉ nhận kho và nguồn NFC.');
        }
        $i = Validator::make($request->query(), $rules)->validate();
        $i += ['source' => 'NFC', 'type' => 'ALL', 'date_mode' => 'ALL', 'limit' => 20];
        $i['warehouse_id'] = strtolower($i['warehouse_id']);
        $i['limit'] = (int) $i['limit'];
        $dates = match ($i['date_mode']) {
            'DATE' => isset($i['date']) && ! isset($i['from']) && ! isset($i['to']),
            'RANGE' => isset($i['from'], $i['to']) && $i['from'] <= $i['to'] && ! isset($i['date']),
            default => ! isset($i['date']) && ! isset($i['from']) && ! isset($i['to']),
        };
        if (! $dates) {
            ApiError::abort(422, 'HISTORY_INVALID_DATE_RANGE', 'DATE cần date; RANGE cần from/to; ALL không nhận ngày.');
        }
        if (str_ends_with($request->path(), '/summary') && array_intersect(array_keys($request->query()), ['limit', 'cursor', 'activityId']) !== []) {
            ApiError::abort(422, 'HISTORY_SUMMARY_NOT_PAGED', 'Summary không phân trang.');
        }

        return $i;
    }

    private function scope(Request $request, string $warehouse): array
    {
        $presented = $request->user();
        if (! $presented instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }
        $actor = User::whereKey($presented->user_id)->first();
        if ($actor === null || $actor->status !== 'ACTIVE' || ! $actor->hasPermission('history.read')) {
            ApiError::abort(403, 'HISTORY_READ_FORBIDDEN', 'Thiếu quyền đọc lịch sử.');
        }
        if (! $actor->hasPermission('physical_code.assign_rfid') && ! $actor->hasPermission('inventory.trace')) {
            ApiError::abort(403, 'HISTORY_SOURCE_FORBIDDEN', 'Thiếu quyền đọc nguồn NFC.');
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        if (! $actor->canAccessWarehouse($warehouse)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho ngoài phạm vi được cấp.');
        }
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        if (($context['warehouse_context']['status'] ?? null) !== 'READY' || ($context['active_warehouse_id'] ?? null) !== $warehouse) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Xác nhận lại kho làm việc.');
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
                    ApiError::abort(403, 'HISTORY_ORGANIZATION_MISMATCH', 'Không xác minh được tổ chức/kho.');
                }
                $dimensions[$field] = $value;
            }
        }

        return ['actor_id' => (string) $actor->user_id, 'auth_session_id' => $session, 'warehouse_id' => $warehouse,
            'dimensions' => $dimensions, 'audit_details' => $actor->hasPermission('audit_log.view')];
    }

    private function query(array $scope): Builder
    {
        $table = (new AuditLog)->getTable();
        $columns = Schema::getColumnListing($table);
        if (array_diff(['id', 'object_id', 'object_type', 'action', 'result', 'created_at', 'actor_id', 'reason', 'after_data'], $columns) !== []) {
            ApiError::abort(503, 'HISTORY_SOURCE_UNAVAILABLE', 'Thiếu schema nguồn audit NFC.');
        }
        $q = DB::table($table.' as e')->where('e.object_type', 'physical_code')->whereIn('e.action', ['ASSIGN_ITEM_NFC', 'REVOKE_ITEM_NFC']);
        $fields = ['schema', 'physical_code_id', 'carrier_type', 'warehouse_id', 'warehouse_code', 'warehouse_name',
            'actor_id', 'actor_name', 'hardware_uid', 'item_id', 'serial_number', 'sku_id', 'sku_code', 'sku_name',
            'tag_status_at_event', 'previous_physical_code_id', 'previous_hardware_uid', 'previous_item_id', 'previous_warehouse_id'];
        $q->whereRaw($this->json('schema')." = 'nfc-audit-snapshot-v1'")
            ->whereRaw($this->json('carrier_type')." = 'NFC'")
            ->whereRaw($this->json('warehouse_id').' = ?', [$scope['warehouse_id']])
            ->whereRaw($this->json('physical_code_id').' = CAST(e.object_id AS VARCHAR(150))')
            ->whereRaw($this->json('actor_id').' = CAST(e.actor_id AS VARCHAR(150))');
        foreach (['tenant_id', 'org_id', 'organization_id'] as $field) {
            if (in_array($field, $columns, true) && empty($scope['dimensions'][$field])) {
                ApiError::abort(503, 'HISTORY_TENANCY_UNRESOLVED', 'Nguồn audit có tổ chức chưa được xác định.');
            }
            if (isset($scope['dimensions'][$field])) {
                $q->whereRaw($this->json($field).' = ?', [$scope['dimensions'][$field]]);
                if (in_array($field, $columns, true)) {
                    $q->where('e.'.$field, $scope['dimensions'][$field]);
                }
            } else {
                $q->whereRaw($this->json($field).' IS NULL');
            }
        }
        $type = "CASE WHEN e.action = 'REVOKE_ITEM_NFC' THEN 'REVOKE' WHEN ".$this->boolean('replacement_requested', false)." THEN 'ASSIGN' WHEN "
            .$this->boolean('replacement_verified', true).' AND '.$this->boolean('replacement_requested', true)
            .' AND '.$this->notBlank('previous_physical_code_id').' AND '.$this->notBlank('previous_hardware_uid')
            .' AND '.$this->notBlank('hardware_uid').' AND '.$this->notBlank('item_id')
            .' AND '.$this->json('previous_physical_code_id').' <> '.$this->json('physical_code_id')
            .' AND '.$this->json('previous_hardware_uid').' <> '.$this->json('hardware_uid')
            .' AND '.$this->json('previous_item_id').' = '.$this->json('item_id')
            .' AND '.$this->json('previous_warehouse_id').' = '.$this->json('warehouse_id')." THEN 'REPLACE' ELSE 'UNKNOWN' END";
        // The PostgreSQL compatibility writer defaults e.result NULL to SUCCESS.
        // Only the original, trusted owner snapshot can prove a P22 result.
        // Older snapshots without result remain unknown; never infer or backfill.
        $time = HistoryTimestamp::expression($table, 'e.created_at');
        $q->selectRaw('CAST(e.id AS VARCHAR(150)) AS event_id, CAST(e.object_id AS VARCHAR(150)) AS owner_id, '.$time.' AS event_time, '
            ."COALESCE(CAST($time AS VARCHAR(60)), '') AS sort_time, e.action, ".$this->json('result').' AS status, e.reason, '.$type.' AS event_type');
        foreach ($fields as $field) {
            $q->selectRaw($this->json($field).' AS '.$field);
        }

        return DB::query()->fromSub($q, 'p22_events');
    }

    private function json(string $field): string
    {
        if (DB::getDriverName() === 'pgsql') {
            $path = "'{nfc_history_v1,{$field}}'";

            return "CASE WHEN jsonb_typeof(CAST(e.after_data AS jsonb) #> {$path}) = 'string' THEN CAST(e.after_data AS jsonb) #>> {$path} END";
        }
        $path = "'$.nfc_history_v1.{$field}'";

        return "CASE WHEN json_valid(e.after_data) THEN CASE WHEN json_type(e.after_data, {$path}) = 'text' THEN json_extract(e.after_data, {$path}) END END";
    }

    private function boolean(string $field, bool $value): string
    {
        $literal = $value ? 'true' : 'false';
        if (DB::getDriverName() === 'pgsql') {
            return "(CAST(e.after_data AS jsonb) #> '{nfc_history_v1,{$field}}') = '{$literal}'::jsonb";
        }

        return "(CASE WHEN json_valid(e.after_data) THEN json_type(e.after_data, '$.nfc_history_v1.{$field}') END) = '{$literal}'";
    }

    private function notBlank(string $field): string
    {
        return 'NULLIF(TRIM('.$this->json($field)."), '') IS NOT NULL";
    }

    private function event(object $row, array $scope): array
    {
        $detail = $scope['audit_details'];
        $names = ['ASSIGN' => 'Gán', 'REPLACE' => 'Thay', 'REVOKE' => 'Thu hồi', 'UNKNOWN' => null];

        return ['recordId' => 'NFC:'.$row->owner_id, 'activityId' => 'NFC:'.$row->event_id, 'eventId' => $row->event_id,
            'physical_code_id' => $row->owner_id, 'source' => 'NFC', 'action' => $row->action,
            'type' => $row->event_type, 'type_label' => $names[$row->event_type], 'status' => $row->status,
            'status_source' => $row->status === null ? 'NOT_RECORDED' : 'PERSISTED_OWNER_RESULT',
            'time' => HistoryTimestamp::utc($row->event_time), 'timeStatus' => $row->event_time === null ? 'NOT_RECORDED' : 'AUDIT_CREATED_AT',
            'hardware_uid' => $row->hardware_uid, 'previous_hardware_uid' => $row->previous_hardware_uid,
            'previous_physical_code_id' => $row->previous_physical_code_id,
            'item_id' => $row->item_id, 'serial_number' => $row->serial_number,
            'sku' => ['sku_id' => $row->sku_id, 'sku_code' => $row->sku_code, 'sku_name' => $row->sku_name],
            'warehouse' => ['warehouse_id' => $row->warehouse_id, 'code' => $row->warehouse_code, 'name' => $row->warehouse_name],
            'actor' => $detail ? ['actor_id' => $row->actor_id, 'display_name' => $row->actor_name] : null,
            'reason' => $detail ? $row->reason : null, 'audit_details_status' => $detail ? 'PERMITTED' : 'REDACTED',
            'tag_status_at_event' => $row->tag_status_at_event, 'current_tag_status' => null,
            'identity_source' => 'PERSISTED_NFC_AUDIT_SNAPSHOT', 'missing_field_policy' => 'NULL_NOT_RECORDED',
            'raw_content' => null, 'raw_content_status' => 'NOT_EXPOSED_MAY_CONTAIN_SECRETS',
            'copy' => ['uid' => $row->hardware_uid, 'previous_uid' => $row->previous_hardware_uid, 'serial' => $row->serial_number],
            'isConfirmationReceipt' => false, 'mutation_available' => false];
    }

    private function identity(string $value): string
    {
        try {
            [$source, $id] = P08HistoryPolicy::identity($value);
            if ($source === 'NFC') {
                return $id;
            }
        } catch (\InvalidArgumentException) {
        }
        ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'ID phải là NFC:UUID của đúng đối tượng.');
    }

    private function calendarBoundary(Carbon $time): string
    {
        return $this->databaseTime($time);
    }

    private function databaseTime(Carbon $time): string
    {
        return HistoryTimestamp::key($time);
    }

    private function reply(mixed $data, array $meta): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => $meta + [
            'request_id' => request()->attributes->get('request_id'), 'timestamp' => now()->toIso8601String(),
        ]])->header('Cache-Control', 'private, no-store');
    }
}
