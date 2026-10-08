<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Services\MiniAppDraftService;

/** Candidate closure protocol. Never saves a draft, records stock or logs a user out. */
final class P14ShiftService
{
    public const CONTRACT = 'p14-shift-v1';

    public function check(Request $request, string $id): array
    {
        $this->query($request, ['warehouse_id']);

        return DB::transaction(function () use ($request, $id): array {
            [$actor, $shift] = $this->owned($request, $id, true);
            [$drafts, $blockers] = $this->inspect($actor, $shift);
            $fingerprint = $this->fingerprint($shift, $drafts);
            $token = Crypt::encryptString(json_encode([
                'contract' => self::CONTRACT, 'shift_id' => $id, 'actor_id' => $actor->user_id,
                'session_id' => $shift->auth_session_id, 'warehouse_id' => $shift->warehouse_id,
                'actor_version' => (int) $actor->version, 'version' => (int) $shift->version,
                'fingerprint' => $fingerprint, 'expires' => now()->timestamp + (int) config('p14.close_check_seconds'),
            ], JSON_THROW_ON_ERROR));

            return [
                'shift_id' => $id, 'warehouse_id' => $shift->warehouse_id,
                'can_end' => $blockers === [], 'can_end_scope' => 'SERVER_CHECK_ONLY', 'blockers' => $blockers,
                'expected_version' => (int) $shift->version, 'check_token' => $blockers === [] ? $token : null,
                'client_confirmation_required' => ['local_state' => 'CLEAN', 'pending_request_ids' => []],
                'draft_scope' => 'AUTH_SESSION_AT_CLOSE_NOT_SHIFT_OWNERSHIP',
                'draft_count' => count($drafts), 'draft_owners_unchanged' => true,
            ];
        });
    }

    public function end(Request $request, string $id): array
    {
        $this->enabled();
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        if (array_diff(array_keys($body), ['warehouse_id', 'check_token', 'local_state', 'pending_request_ids']) !== []
            || $request->query() !== [] || $request->allFiles() !== []) {
            ApiError::abort(422, 'SHIFT_UNEXPECTED_FIELD', 'Nội dung kết thúc ca có trường không được hỗ trợ.');
        }
        $data = Validator::make($body, [
            'warehouse_id' => ['required', 'uuid'], 'check_token' => ['required', 'string', 'max:8192'],
            'local_state' => ['required', 'in:CLEAN,DIRTY,UNKNOWN'],
            'pending_request_ids' => ['present', 'array', 'max:1000'],
            'pending_request_ids.*' => ['string', 'max:128'],
        ])->validate();
        $key = strtolower(trim((string) $request->header('Idempotency-Key')));
        if (! Str::isUuid($key)) {
            ApiError::abort(422, 'SHIFT_REQUEST_ID_REQUIRED', 'Idempotency-Key phải là UUID hợp lệ.');
        }
        if (! $request->hasHeader('If-Match')) {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Cần If-Match từ close-check.');
        }
        if (! preg_match('/^"([1-9][0-9]{0,9})"$/D', (string) $request->header('If-Match'), $match)) {
            ApiError::abort(422, 'SHIFT_VERSION_INVALID', 'If-Match phải là phiên bản có dấu nháy kép.');
        }
        $expected = (int) $match[1];
        $hash = hash_hmac('sha256', json_encode([$id, $data, $expected], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($request, $id, $data, $key, $expected, $hash): array {
            [$actor, $shift] = $this->owned($request, $id, true);
            $previous = DB::table('p14_shift_closures')->where('shift_id', $id)->first();
            if ($previous !== null) {
                if ($previous->request_id !== $key || ! hash_equals($previous->request_hash, $hash)) {
                    ApiError::abort(409, 'SHIFT_END_CONFLICT', 'Ca đã kết thúc bằng yêu cầu khác; đọc lại receipt.');
                }

                return ['receipt' => json_decode($previous->receipt_json, true, 512, JSON_THROW_ON_ERROR), 'replayed' => true];
            }
            if ($data['local_state'] !== 'CLEAN' || $data['pending_request_ids'] !== []) {
                ApiError::abort(409, 'SHIFT_LOCAL_WORK_PENDING', 'Lưu và đối chiếu các yêu cầu đang chờ tại đúng nghiệp vụ trước khi kết thúc ca.');
            }
            if ((int) $shift->version !== $expected) {
                ApiError::abort(412, 'STALE_VERSION', 'Ca có thao tác mới; chạy lại close-check.');
            }
            try {
                $token = json_decode(Crypt::decryptString($data['check_token']), true, 16, JSON_THROW_ON_ERROR);
            } catch (DecryptException|JsonException) {
                ApiError::abort(422, 'SHIFT_CHECK_INVALID', 'Bằng chứng close-check không hợp lệ.');
            }
            if (! is_array($token) || ($token['contract'] ?? null) !== self::CONTRACT
                || ($token['shift_id'] ?? null) !== $id || ($token['actor_id'] ?? null) !== $actor->user_id
                || ($token['session_id'] ?? null) !== $shift->auth_session_id
                || ($token['warehouse_id'] ?? null) !== $shift->warehouse_id
                || ($token['actor_version'] ?? null) !== (int) $actor->version
                || ($token['version'] ?? null) !== $expected) {
                ApiError::abort(409, 'SHIFT_CHECK_SCOPE_MISMATCH', 'Close-check không thuộc đúng ca, phiên hoặc phiên bản quyền hiện tại.');
            }
            if (($token['expires'] ?? 0) <= now()->timestamp) {
                ApiError::abort(409, 'SHIFT_CHECK_EXPIRED', 'Close-check đã hết hạn; kiểm tra lại trước khi kết thúc.');
            }
            [$drafts, $blockers] = $this->inspect($actor, $shift);
            if ($blockers !== []) {
                ApiError::abort(409, 'SHIFT_END_BLOCKED', 'Chưa đủ điều kiện kết thúc ca.', ['blockers' => $blockers]);
            }
            if (! hash_equals($this->fingerprint($shift, $drafts), (string) ($token['fingerprint'] ?? ''))) {
                ApiError::abort(409, 'SHIFT_CHECK_STALE', 'Dữ liệu nháp đã thay đổi; chạy lại close-check.');
            }
            $ended = now()->utc();
            $receipt = [
                'shift_id' => $id, 'request_id' => $key, 'command' => 'END', 'outcome' => 'SUCCEEDED',
                'warehouse_id' => $shift->warehouse_id, 'auth_session_id' => $shift->auth_session_id,
                'status' => 'ENDED', 'version' => $expected + 1,
                'ended_at' => $ended->toISOString(), 'summary_id' => $id, 'login_preserved' => true,
            ];
            $operations = DB::table('p14_shift_operations')->where('shift_id', $id)->orderBy('request_id')->get();
            $recorded = [];
            foreach (['INBOUND', 'OUTBOUND', 'WARRANTY', 'COMPONENT_ISSUE'] as $operation) {
                $recorded[$operation] = $operations->where('operation', $operation)->pluck('business_id')->filter()->unique()->count();
            }
            $snapshot = [
                'contract' => self::CONTRACT, 'shift_id' => $id, 'warehouse_id' => $shift->warehouse_id,
                'started_at' => Carbon::parse($shift->started_at)->utc()->toISOString(), 'ended_at' => $receipt['ended_at'],
                'kpis' => [
                    'successful_request_count' => $operations->count(), 'count_unit' => 'TRACKED_OWNER_REQUEST',
                    'recorded_documents' => $recorded, 'recorded_documents_scope' => 'EXPLICIT_RECORD_ROUTES_ONLY',
                    'quantity_total' => null, 'quantity_status' => 'NOT_PROJECTED',
                    'posted_total' => null, 'posted_total_status' => 'NOT_PROJECTED',
                ],
                'draft_snapshot' => ['scope' => 'AUTH_SESSION_AT_CLOSE_NOT_SHIFT_OWNERSHIP',
                    'owner_unchanged' => true, 'items' => $drafts, 'total' => count($drafts)],
            ];
            DB::table('p14_shift_closures')->insert([
                'shift_id' => $id, 'request_id' => $key, 'request_hash' => $hash,
                'receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR),
                'snapshot_ciphertext' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)),
            ]);
            DB::table('mini_app_work_shifts')->where('shift_id', $id)->update(['ended_at' => $ended, 'version' => $expected + 1]);
            AuditLogger::record(action: 'WORK_SHIFT_ENDED', objectType: 'mini_app_work_shift', objectId: $id,
                actorId: (string) $actor->user_id, requestId: $key,
                afterData: ['status' => 'ENDED', 'version' => $expected + 1, 'stock_effect' => 'NONE'], result: 'SUCCESS');

            return ['receipt' => $receipt, 'replayed' => false];
        });
    }

    public function reconcile(Request $request, string $id, string $requestId): array
    {
        $this->query($request, ['warehouse_id']);
        $this->owned($request, $id, true, false);
        $row = DB::table('p14_shift_closures')->where('shift_id', $id)->where('request_id', strtolower($requestId))->first();
        if ($row === null) {
            ApiError::abort(404, 'SHIFT_REQUEST_UNKNOWN', 'Chưa có receipt kết luận cho yêu cầu này.', ['outcome' => 'UNKNOWN', 'retry_allowed' => false]);
        }

        return json_decode($row->receipt_json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function summary(Request $request, string $id): array
    {
        $this->query($request, ['warehouse_id', 'page', 'per_page']);
        [$actor] = $this->owned($request, $id, false, false);
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);
        Validator::make(['page' => $page, 'per_page' => $perPage], ['page' => ['integer', 'min:1'], 'per_page' => ['integer', 'between:1,100']])->validate();
        $row = DB::table('p14_shift_closures')->where('shift_id', $id)->first();
        if ($row === null) {
            ApiError::abort(409, 'SHIFT_SUMMARY_NOT_READY', 'Ca chưa có snapshot kết thúc.');
        }
        $snapshot = json_decode(Crypt::decryptString($row->snapshot_ciphertext), true, 512, JSON_THROW_ON_ERROR);
        $rights = app(MiniAppDraftService::class)->permissions($actor);
        foreach ($snapshot['draft_snapshot']['items'] as $draft) {
            if (! ($rights[$draft['operation']]['read'] ?? false)) {
                ApiError::abort(403, 'SHIFT_SNAPSHOT_FORBIDDEN', 'Quyền hiện tại không cho phép đọc toàn bộ snapshot này.');
            }
        }
        foreach ($snapshot['kpis']['recorded_documents'] as $operation => $count) {
            if ($count > 0 && ! ($rights[$operation]['read'] ?? false)) {
                ApiError::abort(403, 'SHIFT_SNAPSHOT_FORBIDDEN', 'Quyền nghiệp vụ đã thay đổi; không được đọc snapshot này.');
            }
        }
        $snapshot['draft_snapshot']['items'] = array_slice($snapshot['draft_snapshot']['items'], ($page - 1) * $perPage, $perPage);
        $snapshot['draft_snapshot']['page'] = $page;
        $snapshot['draft_snapshot']['per_page'] = $perPage;
        $snapshot['draft_snapshot']['last_page'] = max(1, (int) ceil($snapshot['draft_snapshot']['total'] / $perPage));
        $snapshot['immutable'] = true;

        return $snapshot;
    }

    /** End and owner mutations share the existing user-row lock, then warehouse, then shift. */
    public function owned(Request $request, string $id, bool $sameSession = true, bool $lock = true, bool $requireWarehouse = true): array
    {
        $this->enabled();
        $user = $request->user() ?? auth('api')->user();
        if (! $user instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }
        $q = User::query()->whereKey($user->user_id);
        $actor = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($actor === null || $actor->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn khả dụng.');
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        if (! Str::isUuid($id)) {
            ApiError::abort(404, 'SHIFT_NOT_FOUND', 'Không tìm thấy ca.');
        }
        $shift = DB::table('mini_app_work_shifts')->where('shift_id', strtolower($id))->where('actor_id', $actor->user_id)
            ->when($sameSession, fn ($q) => $q->where('auth_session_id', $session))->first();
        if ($shift === null) {
            ApiError::abort(404, 'SHIFT_NOT_FOUND', 'Không tìm thấy ca.');
        }
        if (! $actor->canAccessWarehouse($shift->warehouse_id)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho nằm ngoài quyền hiện tại.');
        }
        $provided = $request->input('warehouse_id');
        if (($requireWarehouse || $provided !== null) && (! is_string($provided) || strtolower($provided) !== $shift->warehouse_id)) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho của yêu cầu không khớp ca.');
        }
        if ($lock) {
            DB::table('warehouses')->where('id', $shift->warehouse_id)->lockForUpdate()->first();
            $shift = DB::table('mini_app_work_shifts')->where('shift_id', $id)->lockForUpdate()->first();
        }

        return [$actor, $shift];
    }

    private function inspect(User $actor, object $shift): array
    {
        $blockers = [];
        if ($shift->ended_at !== null) {
            $blockers[] = 'SHIFT_ALREADY_ENDED';
        }
        if ($shift->tracking_contract !== self::CONTRACT) {
            $blockers[] = 'SHIFT_TRACKING_INCOMPLETE';
        }
        $context = app(WarehouseContext::class)->payload($actor, $shift->auth_session_id, 'AUTH_SESSION_REQUIRED');
        if ($context['warehouse_context']['status'] !== 'READY' || $context['active_warehouse_id'] !== $shift->warehouse_id) {
            $blockers[] = $context['warehouse_context']['error_code'] ?? 'WAREHOUSE_CONTEXT_MISMATCH';
        }
        $drafts = DB::table(MiniAppDraftService::TABLE)->where('actor_id', $actor->user_id)
            ->where('auth_session_id', $shift->auth_session_id)->where('warehouse_id', $shift->warehouse_id)->where('status', 'DRAFT')
            ->orderBy('document_id')->limit((int) config('p14.snapshot_max_drafts') + 1)->get()->map(fn ($row): array => (array) $row)->all();
        if (count($drafts) > (int) config('p14.snapshot_max_drafts') || strlen(json_encode($drafts, JSON_THROW_ON_ERROR)) > (int) config('p14.snapshot_max_bytes')) {
            $blockers[] = 'DRAFT_SNAPSHOT_LIMIT';
        }
        $rights = app(MiniAppDraftService::class)->permissions($actor);
        foreach ($drafts as $draft) {
            $confirmed = DB::table(MiniAppDraftService::RECEIPTS)->where('document_id', $draft['document_id'])
                ->where('actor_id', $actor->user_id)->where('auth_session_id', $shift->auth_session_id)
                ->where('warehouse_id', $shift->warehouse_id)->where('request_id', $draft['last_request_id'])
                ->where('version', $draft['version'])->where('response_status', '>=', 200)->where('response_status', '<', 300)->exists();
            if (! $confirmed) {
                $blockers[] = 'DRAFT_RECEIPT_UNCONFIRMED';
            }
            if (! ($rights[$draft['operation']]['read'] ?? false)) {
                $blockers[] = 'DRAFT_SNAPSHOT_FORBIDDEN';
            }
        }
        // Native typed snapshots remain owned by their modules. P14 will not
        // pretend their save receipts are a complete P03 snapshot.
        if (DB::table('p14_shift_operations')->where('shift_id', $shift->shift_id)->whereIn('operation', ['UNSUPPORTED_OWNER_SAVE', 'PENDING_OWNER_RESULT'])->exists()) {
            $blockers[] = 'OWNER_SNAPSHOT_UNSUPPORTED';
        }

        return [$drafts, array_values(array_unique($blockers))];
    }

    private function fingerprint(object $shift, array $drafts): string
    {
        return hash('sha256', json_encode([$shift->shift_id, $shift->version, $drafts], JSON_THROW_ON_ERROR));
    }

    private function query(Request $request, array $keys): void
    {
        if (array_diff(array_keys($request->query()), $keys) !== []) {
            ApiError::abort(422, 'SHIFT_UNEXPECTED_FIELD', 'Truy vấn có trường không được hỗ trợ.');
        }
        Validator::make($request->query(), ['warehouse_id' => ['required', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']])->validate();
    }

    private function enabled(): void
    {
        if (! config('p14.shift_enabled')) {
            ApiError::abort(503, 'P14_SHIFT_NOT_ENABLED', 'Tích hợp kết thúc ca chưa được kích hoạt.');
        }
    }
}
