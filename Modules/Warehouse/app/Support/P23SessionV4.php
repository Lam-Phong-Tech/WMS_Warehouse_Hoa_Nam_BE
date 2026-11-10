<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use App\Support\ExplicitReadFields;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\User;

/** Explicit read representation. No writes, current-person/code lookups, or receipts. */
final class P23SessionV4
{
    public const HEADER = 'X-Scan-Session-Contract';

    public const CONTRACT = 'mini-app-p23-read-v4';

    public const REVISION = 'p23-session-evidence-r1';

    private const MAX_EVENTS = 20000;

    private array $projectedEvents = [];

    public static function requested(Request $request): bool
    {
        $value = $request->header(self::HEADER);
        if ($value !== null && ! in_array($value, [self::CONTRACT, P23ScanSessionPolicy::CONTRACT], true)) {
            ApiError::abort(422, 'P23_CONTRACT_UNSUPPORTED', 'Contract phiên quét chưa được hỗ trợ.');
        }
        if ($value === self::CONTRACT && $request->hasHeader(ExplicitReadFields::HEADER)) {
            ApiError::abort(422, 'P23_REPRESENTATION_CONFLICT', 'V4 giữ null và fieldSources; không ghép biểu diễn bỏ trường null.');
        }

        return $value === self::CONTRACT;
    }

    public static function scope(array $scope, Request $request): array
    {
        $actor = User::whereKey($scope['actor_id'])->first();
        $scope['p23_contract'] = self::CONTRACT;
        $scope['p23_rights'] = [
            'codes' => $actor !== null && $actor->hasPermission('inventory.trace'),
            'names' => $actor !== null && $actor->hasPermission('iam.user.view'),
            'components' => $actor !== null && $actor->hasPermission('warranty.component_issue') && $scope['rights']['WARRANTY'] && $scope['documentsRead'],
        ];

        return $scope;
    }

    public static function field(mixed $value, ?string $source, ?string $status = null): array
    {
        return ['status' => $status ?? ($value === null ? 'NOT_RECORDED' : 'RECORDED'), 'source' => $source];
    }

    public function batches(array $scope, array $rows, string $asOf, array $sources): array
    {
        $ids = array_map(fn ($r) => $r->session_id, $rows);
        if ($ids === []) {
            return [];
        }
        $batches = collect($rows)->keyBy('session_id')->all();
        $events = $this->events($scope, $ids, $asOf);
        $relations = app(P23SessionRelations::class)->resolve($scope, $batches, $events);
        $this->auditActors($scope, $events);
        $complete = ! in_array('UNAVAILABLE', array_column($sources, 'eventStatus'), true);
        $warehouse = DB::table('warehouses')->where('id', $scope['warehouse_id'])->first(['id', 'code', 'name']);
        $result = [];
        foreach ($rows as $row) {
            $batchEvents = array_values(array_filter($events, fn ($e) => $e->owner_id === $row->session_id));
            $statuses = [];
            $safe = [];
            $valid = [];
            $unknownIdentity = false;
            $acceptanceUnavailable = null;
            $links = [];
            $scanners = [];
            $issued = [];
            $issueStatus = 'NOT_APPLICABLE';
            foreach ($batchEvents as $event) {
                $statuses[$event->status ?? 'UNKNOWN'] = ($statuses[$event->status ?? 'UNKNOWN'] ?? 0) + 1;
                $relation = $relations[$event->event_id] ?? [];
                $verified = $event->status === 'ACCEPTED' || ($event->status === 'VALID' && isset($relation['link']));
                if ($event->status === 'VALID' && ! isset($relation['link'])) {
                    $acceptanceUnavailable = $relation['relationStatus'] ?? 'NOT_ESTABLISHED';
                }
                if ($verified) {
                    $raw = $event->evidence->raw_code ?? null;
                    $identity = is_string($raw) && trim($raw) !== '' ? 'raw:'.hash('sha256', trim($raw))
                        : (! empty($event->code_id) ? 'physical:'.$event->code_id : null);
                    if ($identity === null) {
                        $unknownIdentity = true;
                    } else {
                        $valid[$identity] = true;
                    }
                }
                $projected = $this->event($scope, $event, $relation, $verified);
                $safe[] = $projected;
                $this->projectedEvents[$event->event_id] = $projected;
                $actor = $projected['actor'];
                if ($actor['userId'] !== null) {
                    $key = $actor['userId'];
                    if (isset($scanners[$key]) && $scanners[$key]['displayName'] !== $actor['displayName']) {
                        $actor['displayName'] = null;
                        $actor['displayNameStatus'] = 'MULTIPLE_PERSISTED_NAMES';
                    }
                    $scanners[$key] = $actor;
                }
                if (isset($relation['link'])) {
                    foreach ([$relation['link'], ...($relation['additionalLinks'] ?? [])] as $link) {
                        $links[$link['kind'].':'.$link['id']] = $link;
                    }
                }
                if ($row->source_type === 'COMPONENT_ISSUE_DOCUMENT') {
                    $issueStatus = $relation['issuedStatus'] ?? 'NOT_ESTABLISHED';
                    if ($issueStatus === 'VERIFIED_POSTED_LEDGER') {
                        $issued[$relation['evidenceId']] = $relation['issuedQuantity'];
                    }
                }
            }
            $summary = [
                'verifiedValidCodes' => $this->metric($complete && ! $unknownIdentity && $acceptanceUnavailable === null ? count($valid) : null,
                    ! $complete ? 'PARTIAL_SOURCE' : ($unknownIdentity ? 'IDENTITY_NOT_RECORDED' : ($acceptanceUnavailable ?? 'VERIFIED_PERSISTED_RESULTS')), 'UNIQUE_CODE', 'ACCEPTED_OR_VALID_WITH_VERIFIED_OWNER_RELATION'),
                'explicitRejectedAttempts' => $this->metric($complete ? ($statuses['REJECTED'] ?? 0) : null, $complete ? 'RECORDED_RESULTS' : 'PARTIAL_SOURCE', 'ATTEMPT', 'EXACT_REJECTED_RESULT_ONLY'),
                'duplicateAttempts' => $this->metric($complete ? ($statuses['DUPLICATE'] ?? 0) : null, $complete ? 'RECORDED_RESULTS' : 'PARTIAL_SOURCE', 'ATTEMPT', 'EXACT_DUPLICATE_RESULT_ONLY'),
                'issuedComponentQuantity' => $this->metric(null, $scope['p23_rights']['components'] ? $issueStatus : 'REDACTED_PERMISSION', 'COMPONENT_UNIT', 'POSTED_ITEMS_MATCH_NEGATIVE_OWNER_LEDGER'),
                'scope' => 'WHOLE_SESSION_AT_ASOF_NOT_PAGE_OR_EVENT_FILTER', 'asOf' => $asOf,
                'resolvedIsAccepted' => false, 'otherIsRejected' => false,
            ];
            if (! $complete) {
                $summary['issuedComponentQuantity']['status'] = 'PARTIAL_SOURCE';
            }
            if ($scope['p23_rights']['components'] && $row->source_type === 'COMPONENT_ISSUE_DOCUMENT' && $complete && $batchEvents !== []) {
                $states = array_map(fn ($e) => $relations[$e->event_id]['issuedStatus'] ?? 'NOT_ESTABLISHED', $batchEvents);
                $validStates = array_filter($states, fn ($s) => $s !== 'NOT_APPLICABLE');
                if ($issued !== [] && count(array_filter($validStates, fn ($s) => $s !== 'VERIFIED_POSTED_LEDGER')) === 0) {
                    $summary['issuedComponentQuantity'] = $this->metric(array_sum($issued), 'VERIFIED_POSTED_LEDGER', 'COMPONENT_UNIT', 'POSTED_ITEMS_MATCH_NEGATIVE_OWNER_LEDGER');
                } else {
                    $failures = array_values(array_filter($validStates, fn ($s) => $s !== 'VERIFIED_POSTED_LEDGER'));
                    $summary['issuedComponentQuantity']['status'] = $failures[0] ?? 'NOT_ESTABLISHED';
                }
            }
            $data = app(P23ScanSessionReadModel::class)->serialize($row);
            $data['contract'] = self::CONTRACT;
            $data['revision'] = self::REVISION;
            $data['warehouse'] = ['warehouseId' => $row->warehouse_id, 'code' => $warehouse?->code, 'name' => $warehouse?->name,
                'nameStatus' => $warehouse === null ? 'SOURCE_UNAVAILABLE' : 'CURRENT_DIRECTORY_NOT_HISTORICAL_SNAPSHOT', 'source' => 'warehouses'];
            $data['counts'] = P23ScanSessionPolicy::counts($complete ? $statuses : null);
            $data['summary'] = $summary;
            $data['businessLinks'] = array_values($links);
            $data['relatedDocuments'] = array_values($links);
            $relationStatuses = array_map(fn ($e) => $relations[$e->event_id]['relationStatus'] ?? 'NOT_ESTABLISHED', $batchEvents);
            $unavailable = in_array('SOURCE_UNAVAILABLE', $relationStatuses, true);
            $redacted = in_array('REDACTED_PERMISSION', $relationStatuses, true);
            $data['relatedDocumentsStatus'] = $unavailable ? 'SOURCE_UNAVAILABLE' : ($links === [] ? ($redacted ? 'REDACTED_PERMISSION' : 'NOT_ESTABLISHED') : 'VERIFIED_OWNER_RELATION');
            $data['businessLinksStatus'] = $data['relatedDocumentsStatus'];
            $documents = array_values(array_filter($links, fn ($link) => $link['kind'] !== 'WARRANTY'));
            $data['primaryDocument'] = count($documents) === 1 ? $documents[0] : (count($links) === 1 ? array_values($links)[0] : null);
            $data['primaryDocumentStatus'] = $data['primaryDocument'] !== null ? 'VERIFIED_OWNER_RELATION'
                : ($links === [] ? $data['relatedDocumentsStatus'] : 'MULTIPLE_VERIFIED_RELATED_RECORDS');
            ksort($scanners);
            $data['scanners'] = $scanners === [] ? null : array_values($scanners);
            $data['scannersStatus'] = ! $complete ? 'PARTIAL_SOURCE' : ($scanners === [] ? 'NOT_RECORDED' : 'PERSISTED_EVENT_ACTORS');
            $data['actor']['displayName'] = null;
            $data['actor']['displayNameStatus'] = 'NOT_PERSISTED_AS_HISTORY_SNAPSHOT';
            $data['latestEvent'] = $complete ? ($safe[0] ?? null) : null;
            $data['latestEventStatus'] = $complete ? ($safe === [] ? 'NO_RETAINED_EVENTS' : 'AVAILABLE_RETAINED_EVENTS') : 'PARTIAL_SOURCE';
            $data['fieldSources'] = [];
            foreach (['batchNo' => 'batch_no', 'createdAt' => 'created_at', 'startedAt' => 'started_at', 'endedAt' => 'ended_at', 'status' => 'status'] as $field => $column) {
                $data['fieldSources'][$field] = self::field($data[$field], $row->source_table.'.'.$column);
            }
            $data['fieldSources']['scanners'] = self::field($data['scanners'], 'PERSISTED_EVENT_ACTOR_OR_VERIFIED_SCAN_AUDIT', $data['scannersStatus']);
            $data['fieldSources']['primaryDocument'] = self::field($data['primaryDocument'], 'EVENT_OWNER_FK_LINE_DOCUMENT_WAREHOUSE', $data['primaryDocumentStatus']);
            $result[$row->session_id] = $data;
        }

        return $result;
    }

    public function detail(array $scope, string $id, array $pageEvents, string $asOf): array
    {
        [$q, $sources] = app(P23ScanSessionReadModel::class)->build($scope);
        $row = $q->where('session_id', $id)->first();
        if ($row === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiên quét trong kho.');
        }
        $batch = $this->batches($scope, [$row], $asOf, $sources)[$id];
        $events = [];
        foreach ($pageEvents as $event) {
            if (! isset($this->projectedEvents[$event['eventId']])) {
                ApiError::abort(503, 'P23_EVENT_PROJECTION_MISMATCH', 'Sự kiện không khớp snapshot phân trang.');
            }
            $events[] = $this->projectedEvents[$event['eventId']];
        }

        return ['contract' => self::CONTRACT, 'revision' => self::REVISION, 'batch' => $batch,
            'counts' => $batch['counts'], 'summary' => $batch['summary'], 'events' => $events,
            'relatedDocuments' => $batch['relatedDocuments'], 'batchSources' => $sources,
            'eventProjectionStatus' => 'PERSISTED_EVIDENCE_WITH_PRIVACY_V4', 'retentionStatus' => 'NOT_PUBLISHED',
            'versionStatus' => 'NO_PERSISTED_BATCH_VERSION', 'isConfirmationReceipt' => false];
    }

    private function events(array $scope, array $ids, string $asOf): array
    {
        [$q] = app(P08HistoryReadModel::class)->build($scope, 'SCAN');
        $events = $q->whereIn('owner_id', $ids)->where(fn (Builder $q) => $q->whereNull('event_time')->orWhere('event_time', '<=', HistoryTimestamp::key($asOf)))
            ->orderByDesc('sort_time')->orderByDesc('activity_id')->limit(self::MAX_EVENTS + 1)->get();
        if ($events->count() > self::MAX_EVENTS) {
            ApiError::abort(503, 'P23_VERIFICATION_LIMIT', 'Giảm số phiên mỗi trang; không trả tổng hoặc nguồn chưa xác minh.');
        }
        foreach (['scan_events' => 'id', 'scan_record_events' => 'scan_event_id'] as $table => $key) {
            $selected = $events->where('source_table', $table);
            if ($selected->isEmpty()) {
                continue;
            }
            $query = DB::table($table.' as e')->whereIn('e.'.$key, $selected->pluck('event_id'));
            P09WarrantyScope::isolate($query, $table, 'e', $scope);
            $evidence = $query->get('e.*')->keyBy($key);
            foreach ($selected as $event) {
                if (! isset($evidence[$event->event_id])) {
                    ApiError::abort(503, 'P23_EVENT_SOURCE_UNAVAILABLE', 'Thiếu nguồn sự kiện trong snapshot.');
                }
                $event->evidence = $evidence[$event->event_id];
            }
        }

        return $events->all();
    }

    private function event(array $scope, object $event, array $relation, bool $verified): array
    {
        $row = $event->evidence;
        $raw = $row->raw_code ?? null;
        $codeType = in_array($row->code_type ?? null, ['QR', 'BARCODE', 'RFID', 'NFC'], true) ? $row->code_type : null;
        $safeFormat = is_string($raw) && strlen($raw) <= 512
            && ! preg_match('/token|password|secret|bearer|authorization|cookie|^eyJ/i', $raw)
            && preg_match('/\A(?:HN1\|SKU=[A-Z0-9._-]+\|ITEM=[A-Z0-9._-]+(?:\|SER=[A-Z0-9._-]+)?|[A-Z0-9][A-Z0-9._-]{1,149})\z/iD', $raw);
        $typed = $codeType !== null || (is_string($raw) && str_starts_with($raw, 'HN1|SKU='));
        $code = $scope['p23_rights']['codes'] && $safeFormat && $typed ? $raw : null;
        $codeStatus = ! $scope['p23_rights']['codes'] ? 'REDACTED_PERMISSION'
            : (! is_string($raw) || $raw === '' ? 'NOT_RECORDED' : ($code !== null ? 'SAFE_PERSISTED_IDENTIFIER' : 'REDACTED_UNVERIFIED_OR_SENSITIVE_FORMAT'));
        $actorId = $row->actor_id ?? $event->audit_actor_id ?? $relation['actorId'] ?? null;
        $actorSource = isset($row->actor_id) ? $event->source_table.'.actor_id'
            : (isset($event->audit_actor_id) ? $event->audit_actor_source : ($relation['actorSource'] ?? null));
        $name = $actorId !== null && $scope['p23_rights']['names'] ? ($row->actor_display_name ?? null) : null;
        $nameStatus = ! $scope['p23_rights']['names'] ? 'REDACTED_PERMISSION' : ($name !== null ? 'PERSISTED_EVENT_SNAPSHOT' : 'NOT_RECORDED');
        $actor = ['userId' => $actorId, 'displayName' => $name, 'source' => $actorSource,
            'displayNameStatus' => $nameStatus,
            'fieldSources' => ['userId' => self::field($actorId, $actorSource),
                'displayName' => self::field($name, $event->source_table.'.actor_display_name', $nameStatus)],
            'role' => 'EVENT_ACTOR_NOT_BATCH_CREATOR'];
        $qty = $row->quantity ?? $relation['eventQuantity'] ?? null;
        $qty = is_numeric($qty) && (float) $qty > 0 && (float) $qty === (float) (int) $qty ? (int) $qty : null;

        return ['eventId' => $event->event_id, 'activityId' => $event->activity_id, 'sessionId' => $event->owner_id,
            'sourceTable' => $event->source_table, 'status' => $event->status, 'eventType' => $row->event_type ?? null,
            'acceptanceStatus' => $event->status === 'RESOLVED' ? 'RESOLVED_NOT_ACCEPTED' : ($verified ? 'VERIFIED_VALID_CODE' : 'NOT_VERIFIED_ACCEPTANCE'),
            'occurredAt' => HistoryTimestamp::utc($event->event_time), 'time' => HistoryTimestamp::utc($event->event_time),
            'displayCode' => $code, 'rawCode' => null, 'rawCodePolicy' => 'NOT_EXPOSED_USE_AUTHORIZED_DISPLAY_CODE',
            'codeType' => $codeType, 'quantity' => $qty, 'actor' => $actor, 'actorId' => $actorId, 'displayName' => $name,
            'reason' => null, 'serial' => null, 'sku' => null, 'issuedQuantity' => $relation['issuedQuantity'] ?? null,
            'relatedDocument' => $relation['link'] ?? null,
            'fieldSources' => ['displayCode' => self::field($code, $event->source_table.'.raw_code', $codeStatus),
                'codeType' => self::field($codeType, $event->source_table.'.code_type'),
                'occurredAt' => self::field($event->event_time, $event->source_table.'.created_at'),
                'quantity' => self::field($qty, isset($row->quantity) ? $event->source_table.'.quantity' : ($relation['quantitySource'] ?? null)),
                'actor' => self::field($actorId, $actorSource), 'status' => self::field($event->status, $event->source_table.'.result_status'),
                'eventType' => self::field($row->event_type ?? null, $event->source_table.'.event_type'),
                'relatedDocument' => self::field($relation['link'] ?? null, 'VERIFIED_EVENT_OWNER_LINE_DOCUMENT_FK', $relation['relationStatus'] ?? 'NOT_ESTABLISHED'),
                'issuedQuantity' => self::field($relation['issuedQuantity'] ?? null, 'POSTED_COMPONENT_ITEMS_AND_OWNER_LEDGER', $relation['issuedStatus'] ?? 'NOT_APPLICABLE')],
            'isConfirmationReceipt' => false];
    }

    private function auditActors(array $scope, array $events): void
    {
        $table = (new AuditLog)->getTable();
        if ($events === [] || array_diff(['object_type', 'object_id', 'action', 'result', 'actor_id', 'after_data'], Schema::getColumnListing($table)) !== []) {
            return;
        }
        $byId = collect($events)->keyBy('event_id');
        $q = DB::table($table.' as a')->where('a.object_type', 'scan_event')->where('a.action', 'SCAN')->where('a.result', 'SUCCESS')
            ->whereIn('a.object_id', array_keys($byId->all()));
        P09WarrantyScope::isolate($q, $table, 'a', $scope);
        $actors = [];
        foreach ($q->get(['a.object_id', 'a.actor_id', 'a.after_data']) as $audit) {
            $data = is_string($audit->after_data) ? json_decode($audit->after_data, true) : (array) $audit->after_data;
            if (($data['scan_batch_id'] ?? null) === $byId[$audit->object_id]->owner_id && $audit->actor_id !== null) {
                $actors[$audit->object_id][$audit->actor_id] = true;
            }
        }
        foreach ($events as $event) {
            if (count($actors[$event->event_id] ?? []) === 1) {
                $event->audit_actor_id = array_key_first($actors[$event->event_id]);
                $event->audit_actor_source = $table.'.actor_id_VERIFIED_SCAN_EVENT_AND_BATCH';
            }
        }
    }

    private function metric(?int $value, string $status, string $unit, string $source): array
    {
        return compact('value', 'status', 'unit', 'source');
    }
}
