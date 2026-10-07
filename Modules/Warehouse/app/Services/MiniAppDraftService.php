<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Models\Warehouse;

/** Personal pre-record snapshots. Never creates a stock document, scan evidence or movement. */
final class MiniAppDraftService
{
    public const CONTRACT = 'mini-app-p03-v1';

    public const TABLE = 'mini_app_drafts';

    public const RECEIPTS = 'mini_app_draft_receipts';

    public const OPERATIONS = ['INBOUND', 'OUTBOUND', 'WARRANTY', 'COMPONENT_ISSUE'];

    public const RECORD_ROUTES = [
        'api/v1/mini-app/inbound/record' => 'INBOUND',
        'api/v1/mini-app/outbound/record' => 'OUTBOUND',
        'api/v1/mini-app/warranty-cases' => 'WARRANTY',
        'api/v1/mini-app/warranty-component-issues/record' => 'COMPONENT_ISSUE',
        'api/v1/mini-app/component-issue-documents/record' => 'COMPONENT_ISSUE',
    ];

    public function context(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $session = app(StableAuthSession::class)->requireId($actor);
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        $ready = $context['warehouse_context']['status'] === 'READY';

        return $this->response([
            'auth_session_id' => $session,
            ...$context,
            'permissions' => $this->permissions($actor, $ready),
            'warehouse_profile' => ['method' => 'GET', 'path' => '/api/v1/warehouses/{id}', 'permission' => 'warehouse.view', 'allowed' => $actor->hasPermission('warehouse.view')],
            // No verified administrator contact has been configured. Do not invent a recipient.
            'admin_contact' => ['status' => 'NOT_CONFIGURED', 'channels' => []],
            'document_kind' => 'STAGING_DRAFT',
            'record_handoff_required' => true,
            'stock_effect' => 'NONE',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $this->readInput($request, [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(['DRAFT', 'CANCELLED', 'RECORDED'])],
        ], false);

        return DB::transaction(function () use ($request, $input): JsonResponse {
            $scope = $this->scope($request, $input);
            $allowed = array_keys(array_filter($this->permissions($scope['actor']), fn (array $p): bool => $p['read']));
            if (isset($input['operation'])) {
                $this->authorize($scope, $input['operation'], 'read');
                $allowed = [$input['operation']];
            }
            if ($allowed === []) {
                ApiError::abort(403, 'P03_OPERATION_DENIED', 'Không có quyền đọc bản nháp.');
            }
            $query = $this->ownedQuery($scope)->whereIn('operation', $allowed)->where('status', $input['status'] ?? 'DRAFT');
            $total = (clone $query)->count();
            $perPage = (int) ($input['per_page'] ?? 20);
            $page = (int) ($input['page'] ?? 1);
            $rows = $query->orderByDesc('updated_at')->orderByDesc('document_id')->forPage($page, $perPage)->get();

            return $this->response($rows->map(fn (object $row): array => $this->document($row))->all(), 200, [
                'page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'warehouse_id' => $scope['warehouse_id'], 'auth_session_id' => $scope['session_id'],
            ]);
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->uuid($id);
        $input = $this->readInput($request, ['scan_session_id' => ['sometimes', 'uuid']]);

        return DB::transaction(function () use ($request, $input, $id): JsonResponse {
            $scope = $this->scope($request, $input);
            $this->authorize($scope, $input['operation'], 'read');
            $row = $this->findOwned($scope, $id, $input['operation'], $input['scan_session_id'] ?? null);
            if ($request->hasHeader('If-Match')) {
                $this->version($row, $this->expectedVersion($request));
            }

            return $this->response($this->document($row))->header('ETag', '"'.$row->version.'"');
        });
    }

    public function save(Request $request, string $id): JsonResponse
    {
        $this->uuid($id);
        $input = $this->bodyInput($request, [
            'scan_session_id' => ['required', 'uuid'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'codes' => ['present', 'array', 'list', 'max:1000'],
            'codes.*' => ['array:raw_code,quantity,scan_source'],
            'codes.*.raw_code' => ['required', 'string', 'max:512', 'distinct:strict'],
            'codes.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'codes.*.scan_source' => ['sometimes', 'string', 'max:32'],
            'form' => ['sometimes', 'array', 'max:100'],
        ]);
        if (($input['form'] ?? []) !== [] && array_is_list($input['form'])) {
            ApiError::abort(422, 'INVALID_DRAFT_FORM', 'Form phải là object, không phải danh sách.');
        }
        $this->rejectSecrets($input['form'] ?? []);
        // Validation uses arrays; preserve original nested JSON object/array shapes
        // in the UI snapshot and in its idempotency hash.
        $raw = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $input['form'] = (object) ($raw->form ?? []);
        $expected = $this->expectedVersion($request);
        $key = $this->requestKey($request);

        return DB::transaction(function () use ($request, $input, $id, $expected, $key): JsonResponse {
            $scope = $this->scope($request, $input);
            $this->authorize($scope, $input['operation'], 'save');
            $hash = $this->hash(['SAVE', $id, $expected, $input, $scope['warehouse_id']]);
            if ($replay = $this->replay($scope, $key, $hash)) {
                return $replay;
            }
            $row = DB::table(self::TABLE)->where('document_id', $id)->lockForUpdate()->first();
            if ($row !== null) {
                $row = $this->findOwned($scope, $id, $input['operation'], $input['scan_session_id']);
                $this->editable($row);
                $this->version($row, $expected);
            } elseif ($expected !== 0) {
                ApiError::abort(404, 'DRAFT_NOT_FOUND', 'Không tìm thấy bản nháp trong phiên và kho hiện tại.');
            }
            $collision = DB::table(self::TABLE)->where('actor_id', $scope['actor']->user_id)
                ->where('auth_session_id', $scope['session_id'])->where('scan_session_id', strtolower($input['scan_session_id']))
                ->where('document_id', '<>', $id)->exists();
            if ($collision) {
                ApiError::abort(409, 'SCAN_SESSION_CONFLICT', 'Phiên quét đã gắn với bản nháp khác; không tạo bản thay thế.');
            }
            $now = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
            $data = [
                'title' => $input['title'] ?? null, 'codes' => $this->json($input['codes']),
                'form' => $this->json((object) ($input['form'] ?? [])), 'version' => $expected + 1,
                'last_request_id' => $key, 'updated_at' => $now,
            ];
            if ($row === null) {
                DB::table(self::TABLE)->insert($data + [
                    'document_id' => $id, 'scan_session_id' => strtolower($input['scan_session_id']),
                    'actor_id' => $scope['actor']->user_id, 'auth_session_id' => $scope['session_id'],
                    'warehouse_id' => $scope['warehouse_id'], 'operation' => $input['operation'],
                    'status' => 'DRAFT', 'created_at' => $now,
                ]);
            } else {
                DB::table(self::TABLE)->where('document_id', $id)->update($data);
            }
            $saved = $this->findOwned($scope, $id, $input['operation'], $input['scan_session_id']);
            $code = $row === null ? 201 : 200;

            return $this->finish($scope, $saved, $key, $hash, 'SAVE', $code);
        }, 3);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->uuid($id);
        $input = $this->bodyInput($request, [
            'scan_session_id' => ['required', 'uuid'],
            'confirmed_request_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $expected = $this->expectedVersion($request);
        $key = $this->requestKey($request);

        return DB::transaction(function () use ($request, $input, $id, $expected, $key): JsonResponse {
            $scope = $this->scope($request, $input);
            $this->authorize($scope, $input['operation'], 'cancel');
            $hash = $this->hash(['CANCEL', $id, $expected, $input, $scope['warehouse_id']]);
            if ($replay = $this->replay($scope, $key, $hash)) {
                return $replay;
            }
            $row = $this->findOwned($scope, $id, $input['operation'], $input['scan_session_id']);
            $this->editable($row);
            $this->version($row, $expected);
            $confirmed = $this->receiptQuery($scope, strtolower($input['confirmed_request_id']))->first();
            if ($confirmed === null || $confirmed->document_id !== $id || $confirmed->command !== 'SAVE'
                || (int) $confirmed->version !== (int) $row->version || $row->last_request_id !== $confirmed->request_id) {
                ApiError::abort(409, 'DRAFT_OUTCOME_UNKNOWN', 'Đối chiếu receipt lưu gần nhất trước khi hủy; không hủy yêu cầu chưa rõ kết quả.');
            }
            DB::table(self::TABLE)->where('document_id', $id)->update([
                'status' => 'CANCELLED', 'version' => $expected + 1, 'last_request_id' => $key,
                'cancel_reason' => $input['reason'], 'updated_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ]);
            $cancelled = $this->findOwned($scope, $id, $input['operation'], $input['scan_session_id']);

            return $this->finish($scope, $cancelled, $key, $hash, 'CANCEL', 200);
        }, 3);
    }

    public function reconcile(Request $request, string $id, string $requestId): JsonResponse
    {
        $this->uuid($id);
        $this->uuid($requestId);
        $input = $this->readInput($request, [
            'scan_session_id' => ['required', 'uuid'], 'command' => ['required', Rule::in(['SAVE', 'CANCEL', 'RECORD'])],
        ]);

        return DB::transaction(function () use ($request, $input, $id, $requestId): JsonResponse {
            $scope = $this->scope($request, $input);
            $this->authorize($scope, $input['operation'], 'read');
            $receipt = $this->receiptQuery($scope, $requestId)->first();
            if ($receipt !== null && ($receipt->document_id !== $id || $receipt->scan_session_id !== strtolower($input['scan_session_id'])
                || $receipt->warehouse_id !== $scope['warehouse_id'] || $receipt->operation !== $input['operation'] || $receipt->command !== $input['command'])) {
                ApiError::abort(409, 'REQUEST_IDENTITY_MISMATCH', 'request_id không khớp bản nháp, phiên quét hoặc thao tác yêu cầu.');
            }
            $row = $this->ownedQuery($scope)->where('document_id', $id)->where('operation', $input['operation'])
                ->where('scan_session_id', strtolower($input['scan_session_id']))->first();

            return $this->response([
                'request_id' => $requestId, 'document_id' => $id, 'scan_session_id' => strtolower($input['scan_session_id']),
                'warehouse_id' => $scope['warehouse_id'], 'operation' => $input['operation'], 'command' => $input['command'],
                'outcome' => $receipt === null ? 'UNKNOWN' : 'SUCCEEDED',
                'receipt' => $receipt === null ? null : $this->receiptPayload($receipt),
                'document' => $row === null ? null : $this->document($row),
                'response' => $receipt === null ? null : json_decode($receipt->response_body, false, 512, JSON_THROW_ON_ERROR),
                // Absence is not proof of failure. A delayed original may still arrive.
                'retry' => $receipt === null ? 'SAME_REQUEST_ONLY' : 'NONE',
                'new_document_allowed' => false, 'cancel_allowed' => $receipt !== null && $row !== null && $row->status === 'DRAFT'
                    && $receipt->command === 'SAVE' && (int) $receipt->version === (int) $row->version
                    && $row->last_request_id === $receipt->request_id && $this->permissions($scope['actor'])[$input['operation']]['cancel'],
            ]);
        });
    }

    /** Optional correlation on EXISTING owner routes. Does not implement a record/Post API. */
    public function handoff(Request $request, Closure $next, string $operation): JsonResponse
    {
        $identity = Validator::make([
            'document_id' => $request->header('X-Draft-ID'), 'scan_session_id' => $request->header('X-Draft-Scan-Session-ID'),
            'version' => $request->header('X-Draft-Version'),
        ], ['document_id' => ['required', 'uuid'], 'scan_session_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1', 'max:999999999']])->validate();
        $id = strtolower($identity['document_id']);
        $key = $this->requestKey($request);

        return DB::transaction(function () use ($request, $next, $operation, $identity, $id, $key): JsonResponse {
            $actor = $this->actor($request);
            $sessionId = app(StableAuthSession::class)->requireId($actor);
            $pre = DB::table(self::TABLE)->where('document_id', $id)->where('actor_id', $actor->user_id)
                ->where('auth_session_id', $sessionId)->first();
            if ($pre === null) {
                ApiError::abort(404, 'DRAFT_NOT_FOUND', 'Không tìm thấy bản nháp trong phiên hiện tại.');
            }
            $scope = $this->scope($request, ['warehouse_id' => $pre->warehouse_id]);
            $this->authorize($scope, $operation, 'save');
            $row = $this->findOwned($scope, $id, $operation, $identity['scan_session_id']);
            // Includes all owner request fields and its optimistic header; never hashes transport tokens.
            $hash = $this->hash(['RECORD', $id, $identity, $request->method(), $request->path(), $request->query(), $request->json()->all(), $request->header('If-Match')]);
            if ($replay = $this->replay($scope, $key, $hash)) {
                return $replay;
            }
            $this->editable($row);
            $this->version($row, (int) $identity['version']);
            foreach (['warehouse_id', 'src_warehouse_id', 'dst_warehouse_id'] as $field) {
                if ($request->filled($field) && strtolower((string) $request->input($field)) !== $scope['warehouse_id']) {
                    ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho của thao tác ghi nhận không khớp kho bản nháp.');
                }
            }
            // Namespace the existing owner's idempotency key so an older unrelated
            // login/document cannot be replayed into this personal draft.
            $request->headers->set('Idempotency-Key', hash('sha256', 'p03|'.$scope['session_id'].'|'.$id.'|'.$key));
            $request->attributes->set('p03.draft_handoff', true);
            try {
                $response = $next($request);
            } finally {
                $request->headers->set('Idempotency-Key', $key);
            }
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new HttpResponseException($response); // Roll back, including any nested owner writes.
            }
            if (! $response instanceof JsonResponse) {
                ApiError::abort(502, 'DRAFT_RECORD_RESPONSE_INVALID', 'Không có receipt JSON hợp lệ từ nghiệp vụ ghi nhận.');
            }
            $body = $response->getData(true);
            $businessId = $this->businessId($scope, $operation, $body);
            DB::table(self::TABLE)->where('document_id', $id)->update([
                'status' => 'RECORDED', 'business_document_id' => $businessId, 'last_request_id' => $key,
                'version' => ((int) $row->version) + 1, 'updated_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ]);
            $recorded = $this->findOwned($scope, $id, $operation, $identity['scan_session_id']);
            $receipt = $this->receiptData($scope, $recorded, $key, $hash, 'RECORD', $response->getStatusCode());
            $body['meta']['p03_draft'] = ['document' => $this->document($recorded), 'receipt' => $this->receiptPayload((object) $receipt)];
            $this->persistReceipt($receipt, $body);

            return $response->setData($body)->header('Cache-Control', 'private, no-store')->header('Idempotent-Replay', 'false');
        });
    }

    public function permissions(User $actor, bool $ready = true): array
    {
        $has = fn (string $key): bool => $actor->hasPermission($key);
        $matrix = [
            'INBOUND' => ['read' => $has('inbound.view'), 'save' => $has('inbound.create') && $has('inbound.scan'), 'cancel' => $has('inbound.cancel') || $has('inbound.create')],
            'OUTBOUND' => ['read' => $has('outbound.view'), 'save' => ($has('outbound.request.manual_create') || $has('outbound.import')) && $has('outbound.scan'), 'cancel' => $has('outbound.cancel')],
            'WARRANTY' => ['read' => $has('warranty.view') || $has('warranty.manage'), 'save' => $has('warranty.manage'), 'cancel' => $has('warranty.manage')],
            'COMPONENT_ISSUE' => ['read' => $has('warranty.component_issue'), 'save' => $has('warranty.component_issue'), 'cancel' => $has('warranty.component_issue')],
        ];
        foreach ($matrix as &$entry) {
            foreach ($entry as &$value) {
                $value = $ready && $value;
            }
            unset($value);
        }
        unset($entry);

        return $matrix;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user() ?? auth('api')->user();
        if (! $actor instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }
        if ($actor->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn hoạt động.');
        }

        return $actor;
    }

    private function scope(Request $request, array $input): array
    {
        // Parent lock serializes save/cancel/record/reconcile with context switches.
        $actor = User::query()->whereKey($this->actor($request)->user_id)->lockForUpdate()->first();
        if ($actor === null || $actor->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCESS_DENIED', 'Tài khoản không còn hoạt động.');
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        $id = strtolower($input['warehouse_id']);
        if (! $actor->canAccessWarehouse($id)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho nằm ngoài phạm vi được cấp.');
        }
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        if ($context['warehouse_context']['status'] !== 'READY') {
            $code = $context['warehouse_context']['error_code'] ?? 'WAREHOUSE_CONTEXT_REQUIRED';
            ApiError::abort($code === 'WAREHOUSE_ACCESS_DENIED' ? 403 : 409, $code, 'Xác nhận lại ngữ cảnh kho trước khi thao tác bản nháp.');
        }
        if ($context['active_warehouse_id'] !== $id) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho gửi lên không khớp kho đã xác nhận trong phiên.');
        }
        $warehouse = Warehouse::query()->whereKey($id)->lockForUpdate()->first();
        if ($warehouse === null || $warehouse->status !== 'ACTIVE') {
            ApiError::abort(409, 'WAREHOUSE_INACTIVE', 'Kho không còn hoạt động.');
        }

        return ['actor' => $actor, 'session_id' => $session, 'warehouse_id' => $id];
    }

    private function authorize(array $scope, string $operation, string $action): void
    {
        if (! ($this->permissions($scope['actor'])[$operation][$action] ?? false)) {
            ApiError::abort(403, 'P03_OPERATION_DENIED', 'Không có quyền thực hiện thao tác bản nháp này.');
        }
    }

    private function ownedQuery(array $scope): Builder
    {
        return DB::table(self::TABLE)->where('actor_id', $scope['actor']->user_id)
            ->where('auth_session_id', $scope['session_id'])->where('warehouse_id', $scope['warehouse_id']);
    }

    private function findOwned(array $scope, string $id, string $operation, ?string $scanId = null): object
    {
        $row = $this->ownedQuery($scope)->where('document_id', $id)->where('operation', $operation)
            ->when($scanId !== null, fn (Builder $q): Builder => $q->where('scan_session_id', strtolower($scanId)))->lockForUpdate()->first();
        if ($row === null) {
            ApiError::abort(404, 'DRAFT_NOT_FOUND', 'Không tìm thấy bản nháp trong phiên, kho và nghiệp vụ hiện tại.');
        }

        return $row;
    }

    private function editable(object $row): void
    {
        if ($row->status !== 'DRAFT' || $row->business_document_id !== null) {
            ApiError::abort(409, 'DRAFT_STATE_CONFLICT', 'Chỉ được sửa hoặc hủy bản nháp DRAFT chưa ghi nhận; không xóa hồ sơ đã ghi nhận hoặc chưa rõ kết quả.');
        }
    }

    private function version(object $row, int $expected): void
    {
        if ((int) $row->version !== $expected) {
            ApiError::abort(412, 'STALE_VERSION', 'Bản nháp đã thay đổi; tải lại trước khi thao tác.', ['current_version' => (int) $row->version, 'expected_version' => $expected]);
        }
    }

    private function expectedVersion(Request $request): int
    {
        $header = $request->header('If-Match');
        if ($header === null) {
            ApiError::abort(428, 'VERSION_REQUIRED', 'Bắt buộc If-Match; dùng "0" cho lần lưu đầu tiên.');
        }
        if (! preg_match('/^"(0|[1-9][0-9]{0,8})"$/D', $header, $match)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là ETag phiên bản số nguyên có dấu nháy kép.');
        }

        return (int) $match[1];
    }

    private function requestKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            ApiError::abort(428, 'IDEMPOTENCY_KEY_REQUIRED', 'Bắt buộc Idempotency-Key UUID ổn định cho thao tác.');
        }
        if (! Str::isUuid($key)) {
            ApiError::abort(422, 'INVALID_IDEMPOTENCY_KEY', 'Idempotency-Key của P03 phải là UUID.');
        }

        return strtolower($key);
    }

    private function receiptQuery(array $scope, string $key): Builder
    {
        return DB::table(self::RECEIPTS)->where('actor_id', $scope['actor']->user_id)->where('auth_session_id', $scope['session_id'])->where('request_id', $key);
    }

    private function replay(array $scope, string $key, string $hash): ?JsonResponse
    {
        $row = $this->receiptQuery($scope, $key)->first();
        if ($row === null) {
            return null;
        }
        if (! hash_equals($row->request_hash, $hash) || $row->warehouse_id !== $scope['warehouse_id']) {
            ApiError::abort(409, 'IDEMPOTENCY_CONFLICT', 'Khóa đã được dùng với nội dung, phiên bản hoặc định danh khác.');
        }
        $body = (array) json_decode($row->response_body, false, 512, JSON_THROW_ON_ERROR);
        $body['meta'] = (array) ($body['meta'] ?? []);
        $body['meta']['request_id'] = request()->attributes->get('request_id');
        $response = response()->json($body, (int) $row->response_status)->header('Idempotent-Replay', 'true')->header('Cache-Control', 'private, no-store');
        if ($row->command !== 'RECORD') {
            $response->header('ETag', '"'.$row->version.'"');
        } elseif (isset($body['data']->version)) {
            $response->header('ETag', '"'.((int) $body['data']->version).'"');
        }

        return $response;
    }

    private function finish(array $scope, object $row, string $key, string $hash, string $command, int $code): JsonResponse
    {
        $receipt = $this->receiptData($scope, $row, $key, $hash, $command, $code);
        $body = ['success' => true, 'data' => ['document' => $this->document($row), 'receipt' => $this->receiptPayload((object) $receipt)], 'meta' => ['contract' => self::CONTRACT, 'request_id' => request()->attributes->get('request_id')]];
        $this->persistReceipt($receipt, $body);

        return response()->json($body, $code)->header('ETag', '"'.$row->version.'"')->header('Idempotent-Replay', 'false')->header('Cache-Control', 'private, no-store');
    }

    private function receiptData(array $scope, object $row, string $key, string $hash, string $command, int $code): array
    {
        return ['actor_id' => $scope['actor']->user_id, 'auth_session_id' => $scope['session_id'], 'request_id' => $key,
            'document_id' => $row->document_id, 'scan_session_id' => $row->scan_session_id, 'warehouse_id' => $row->warehouse_id,
            'operation' => $row->operation, 'command' => $command, 'request_hash' => $hash, 'response_status' => $code,
            'version' => (int) $row->version, 'completed_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }

    private function receiptPayload(object $row): array
    {
        return ['request_id' => $row->request_id, 'document_id' => $row->document_id, 'scan_session_id' => $row->scan_session_id,
            'warehouse_id' => $row->warehouse_id, 'operation' => $row->operation, 'command' => $row->command,
            'outcome' => 'SUCCEEDED', 'version' => (int) $row->version, 'committed_at' => $this->instant($row->completed_at)];
    }

    private function persistReceipt(array $receipt, array $body): void
    {
        DB::table(self::RECEIPTS)->insert($receipt + ['response_body' => $this->json($body)]);
        AuditLogger::record(action: 'P03_DRAFT_'.$receipt['command'], objectType: 'mini_app_draft', objectId: $receipt['document_id'],
            actorId: (string) $receipt['actor_id'], reason: data_get($body, 'data.document.cancel_reason'), requestId: request()->attributes->get('request_id'), idempotencyKey: $receipt['request_id'],
            afterData: ['version' => $receipt['version'], 'operation' => $receipt['operation'], 'warehouse_id' => $receipt['warehouse_id']], result: 'SUCCESS');
    }

    private function document(object $row): array
    {
        $codes = json_decode($row->codes, true, 512, JSON_THROW_ON_ERROR);

        return ['document_id' => $row->document_id, 'document_kind' => 'STAGING_DRAFT', 'scan_session_id' => $row->scan_session_id,
            'auth_session_id' => $row->auth_session_id, 'warehouse_id' => $row->warehouse_id, 'operation' => $row->operation,
            'version' => (int) $row->version, 'status' => $row->status, 'title' => $row->title, 'codes' => $codes,
            'form' => json_decode($row->form, false, 512, JSON_THROW_ON_ERROR), 'codes_validated' => false,
            'summary' => ['code_count' => count($codes), 'quantity_total' => array_sum(array_map(fn (array $c): int => (int) ($c['quantity'] ?? 1), $codes))],
            'business_document_id' => $row->business_document_id, 'business_status' => $this->businessStatus($row), 'last_request_id' => $row->last_request_id,
            'created_at' => $this->instant($row->created_at), 'updated_at' => $this->instant($row->updated_at), 'cancel_reason' => $row->cancel_reason,
            'stock_effect' => 'NONE'];
    }

    private function businessStatus(object $row): ?string
    {
        if ($row->business_document_id === null) {
            return null;
        }
        [$table, $key] = match ($row->operation) {
            'INBOUND', 'OUTBOUND' => ['stock_documents', 'id'],
            'WARRANTY' => ['warranty_case', 'warranty_case_id'],
            'COMPONENT_ISSUE' => ['component_issue_documents', 'id'],
        };

        return DB::table($table)->where($key, $row->business_document_id)->value('status');
    }

    private function businessId(array $scope, string $operation, array $body): string
    {
        $candidates = [data_get($body, 'data.document_id'), data_get($body, 'data.warranty_case_id'), data_get($body, 'data.component_issue_document_id'), data_get($body, 'data.id'), data_get($body, 'data.document.id')];
        foreach ($candidates as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                continue;
            }
            $query = match ($operation) {
                'INBOUND' => DB::table('stock_documents')->where('id', $id)->where('doc_type', 'receipt')->where('dst_warehouse_id', $scope['warehouse_id']),
                'OUTBOUND' => DB::table('stock_documents')->where('id', $id)->where('doc_type', 'issue')->where('src_warehouse_id', $scope['warehouse_id']),
                'WARRANTY' => DB::table('warranty_case_warehouse_owners')->where('warranty_case_id', $id)->where('warehouse_id', $scope['warehouse_id']),
                'COMPONENT_ISSUE' => DB::table('component_issue_documents')->where('id', $id)->where('warehouse_id', $scope['warehouse_id']),
            };
            if ($query->exists()) {
                return $id;
            }
        }
        ApiError::abort(502, 'DRAFT_RECORD_RESPONSE_INVALID', 'Receipt nghiệp vụ không xác nhận được chứng từ và kho; giao dịch được hoàn tác.');
    }

    private function readInput(Request $request, array $extra = [], bool $operationRequired = true): array
    {
        $rules = ['warehouse_id' => ['required', 'uuid'], 'operation' => [$operationRequired ? 'required' : 'sometimes', Rule::in(self::OPERATIONS)]] + $extra;
        $input = $request->query();
        $this->allowedKeys($input, array_keys($rules));

        return Validator::make($input, $rules)->validate();
    }

    private function bodyInput(Request $request, array $extra): array
    {
        if (! $request->isJson()) {
            ApiError::abort(415, 'JSON_REQUIRED', 'P03 chỉ nhận application/json.');
        }
        if (strlen($request->getContent()) > 768 * 1024) {
            ApiError::abort(413, 'DRAFT_PAYLOAD_TOO_LARGE', 'Nội dung bản nháp vượt giới hạn 768 KiB.');
        }
        $input = $request->json()->all();
        $rules = ['warehouse_id' => ['required', 'uuid'], 'operation' => ['required', Rule::in(self::OPERATIONS)]] + $extra;
        $this->allowedKeys($input, array_keys(array_filter($rules, fn ($v, $k): bool => ! str_contains($k, '.'), ARRAY_FILTER_USE_BOTH)));
        if ($request->query() !== []) {
            ApiError::abort(422, 'UNEXPECTED_FIELD', 'Không gửi query trên thao tác lưu/hủy bản nháp.');
        }

        return Validator::make($input, $rules)->validate();
    }

    private function allowedKeys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            ApiError::abort(422, 'UNEXPECTED_FIELD', 'Có trường ngoài contract P03; actor/session/status/version do máy chủ xác định.');
        }
    }

    private function rejectSecrets(array $input, int $depth = 0): void
    {
        if ($depth > 8 || strlen($this->json($input)) > 65536) {
            ApiError::abort(422, 'INVALID_DRAFT_FORM', 'Form vượt giới hạn kích thước hoặc độ sâu.');
        }
        foreach ($input as $key => $value) {
            if (preg_match('/password|token|authorization|cookie|secret/i', (string) $key)) {
                ApiError::abort(422, 'INVALID_DRAFT_FORM', 'Không lưu thông tin xác thực vào bản nháp.');
            }
            if (is_array($value)) {
                $this->rejectSecrets($value, $depth + 1);
            }
        }
    }

    private function uuid(string $id): void
    {
        if (! Str::isUuid($id) || strtolower($id) !== $id) {
            ApiError::abort(404, 'DRAFT_NOT_FOUND', 'Định danh phải là UUID chữ thường.');
        }
    }

    private function hash(array $input): string
    {
        return hash('sha256', $this->json($this->canonical($input)));
    }

    private function canonical(mixed $value): mixed
    {
        if (is_object($value)) {
            $fields = get_object_vars($value);
            ksort($fields);

            return (object) array_map(fn ($v) => $this->canonical($v), $fields);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => $this->canonical($v), $value);
    }

    private function instant(string $value): string
    {
        return Carbon::parse($value, 'UTC')->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function response(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'meta' => ['contract' => self::CONTRACT, 'request_id' => request()->attributes->get('request_id')] + $meta], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
