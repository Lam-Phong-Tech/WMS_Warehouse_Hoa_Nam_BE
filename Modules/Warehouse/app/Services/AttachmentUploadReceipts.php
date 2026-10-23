<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Warehouse\Support\P09WarrantyScope;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Durable, opt-in receipts around existing owners; not an upload/cancel/retry API. */
final class AttachmentUploadReceipts
{
    public const CONTRACT = 'owner-attachment-upload-v1';

    public const TABLE = 'attachment_upload_requests';

    public const UPLOAD_ROUTES = [
        'api/v1/mini-app/inbound-documents/{id}/attachments' => 'INBOUND',
        'api/v1/mini-app/outbound-documents/{id}/attachments' => 'OUTBOUND',
        'api/v1/mini-app/warranty-cases/{id}/attachments' => 'WARRANTY',
    ];

    /** Commit the intent before an outer shift transaction can run storage work. */
    public function prepareForShift(Request $request, Closure $fenceGuard): void
    {
        $request->attributes->set('attachment_upload.prepared', $this->prepare($request, $fenceGuard));
    }

    public function afterShiftRollback(Request $request): void
    {
        $prepared = $request->attributes->get('attachment_upload.prepared');
        if (is_array($prepared) && $prepared['created']) {
            $this->uncertain($request, $prepared['attempt']);
        }
    }

    private function prepare(Request $request, ?Closure $fenceGuard = null): array
    {
        if ($request->header('X-Upload-Contract') !== self::CONTRACT) {
            ApiError::abort(422, 'UPLOAD_CONTRACT_UNSUPPORTED', 'Phiên bản contract upload không được hỗ trợ.');
        }
        $this->schema();
        $owner = self::UPLOAD_ROUTES[$request->route()->uri()] ?? null;
        if ($owner === null || ! $request->isMethod('POST')) {
            ApiError::abort(422, 'UPLOAD_OWNER_INVALID', 'Không xác định được owner upload.');
        }
        $parent = (string) $request->route('id');
        $key = (string) $request->header('Idempotency-Key');
        $warehouse = (string) $request->header('X-Upload-Warehouse-ID');
        $digest = (string) $request->header('X-Upload-SHA256');
        if ($key === '' || $warehouse === '' || $digest === '') {
            ApiError::abort(428, 'UPLOAD_IDENTITY_REQUIRED', 'Bắt buộc key, UUID kho và SHA-256 của tệp.');
        }
        Validator::make(['key' => $key, 'warehouse' => $warehouse, 'sha256' => $digest], [
            'key' => ['required', 'uuid'], 'warehouse' => ['required', 'uuid'], 'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ])->validate();
        $key = strtolower($key);
        $warehouse = strtolower($warehouse);
        $allowed = $owner === 'WARRANTY' ? ['attachment_type'] : ['attachment_type', 'note'];
        if ($request->query() !== [] || array_diff(array_keys($request->request->all()), $allowed) !== []
            || array_diff(array_keys($request->allFiles()), ['file']) !== []) {
            ApiError::abort(422, 'UPLOAD_FIELD_NOT_ALLOWED', 'Giữ body multipart của đúng owner, không thêm trường ngoài contract.');
        }
        $scope = $this->scope($request, $owner, $parent, $warehouse, true);
        Validator::make(['file' => $request->file('file')], ['file' => ['required', 'file']])->validate();
        $file = $request->file('file');
        $actual = hash_file('sha256', $file->getRealPath());
        if (! is_string($actual) || ! hash_equals($digest, $actual)) {
            ApiError::abort(422, 'UPLOAD_FILE_HASH_MISMATCH', 'SHA-256 không khớp nội dung tệp nhận được.');
        }
        $fields = $request->request->all();
        ksort($fields);
        $hash = hash('sha256', json_encode([$scope['actor_id'], $scope['auth_session_id'], $owner, strtolower($parent),
            $warehouse, $actual, $file->getSize(), $file->getClientOriginalName(), $fields], JSON_THROW_ON_ERROR));
        [$attempt, $created] = DB::transaction(function () use ($scope, $key, $hash, $actual, $owner, $parent, $file, $fenceGuard): array {
            User::whereKey($scope['actor_id'])->lockForUpdate()->firstOrFail();
            // Same actor -> warehouse -> shift locks as END. An already ended
            // shift cannot reserve an intent; an intent committed first blocks END.
            if ($fenceGuard !== null) {
                $fenceGuard();
            }
            $query = DB::table(self::TABLE)->where('actor_id', $scope['actor_id'])->where('operation_key', $key);
            $existing = (clone $query)->first();
            if ($existing !== null) {
                if ($existing->auth_session_id !== $scope['auth_session_id']) {
                    ApiError::abort(409, 'UPLOAD_SESSION_MISMATCH', 'Không gắn yêu cầu upload cũ vào phiên đăng nhập mới.');
                }
                if (! hash_equals($existing->request_hash, $hash)) {
                    ApiError::abort(409, 'IDEMPOTENCY_CONFLICT', 'Key đã dùng với parent, tệp hoặc metadata khác.');
                }

                return [$existing, false];
            }
            if (DB::table(self::TABLE)->where('actor_id', $scope['actor_id'])
                ->where('auth_session_id', $scope['auth_session_id'])->where('warehouse_id', $scope['warehouse_id'])
                ->where('parent_type', $owner)->where('parent_id', strtolower($parent))->where('file_sha256', $actual)
                ->whereIn('state', ['PENDING', 'UNKNOWN'])->exists()) {
                ApiError::abort(409, 'UPLOAD_OUTCOME_UNKNOWN', 'Không đổi key để lặp lại tệp/ý định đang chưa rõ kết quả; đối chiếu yêu cầu gốc.');
            }
            $id = (string) Str::uuid();
            DB::table(self::TABLE)->insert([
                'id' => $id, 'actor_id' => $scope['actor_id'], 'auth_session_id' => $scope['auth_session_id'],
                'operation_key' => $key, 'warehouse_id' => $scope['warehouse_id'], 'parent_type' => $owner,
                'parent_id' => strtolower($parent), 'attachment_id' => (string) Str::uuid(), 'file_sha256' => $actual,
                'file_size_bytes' => $file->getSize(), 'request_hash' => $hash, 'state' => 'PENDING',
                'created_at' => now()->utc()->toIso8601String(), 'updated_at' => now()->utc()->toIso8601String(),
            ]);

            return [DB::table(self::TABLE)->where('id', $id)->first(), true];
        });

        return compact('attempt', 'created', 'scope', 'owner', 'parent', 'warehouse');
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Untracked legacy callers retain their owner contract, without receipts.
        if (! $request->hasHeader('X-Upload-Contract')) {
            return $next($request);
        }
        ['attempt' => $attempt, 'created' => $created, 'owner' => $owner, 'parent' => $parent,
            'warehouse' => $warehouse] = $request->attributes->get('attachment_upload.prepared') ?? $this->prepare($request);
        // Permissions/parent can change after preparation; reauthorize replay too.
        $scope = $this->scope($request, $owner, $parent, $warehouse, true);
        if (! $created) {
            if (! in_array($attempt->state, ['SUCCEEDED', 'REJECTED'], true)) {
                ApiError::abort(409, 'UPLOAD_OUTCOME_UNKNOWN', 'Chỉ đối chiếu key gốc; không upload lại khi chưa rõ kết quả.');
            }
            $response = response()->json(json_decode($attempt->response_body, false, 512, JSON_THROW_ON_ERROR), $attempt->http_status);

            return $this->decorate($response, $attempt, $scope, true);
        }
        $request->attributes->set('attachment_upload.id', $attempt->attachment_id);
        try {
            return DB::transaction(function () use ($request, $next, $owner, $parent, $warehouse, $attempt): Response {
                // Serializes compliant uploads per parent, including quota checks.
                $scope = $this->scope($request, $owner, $parent, $warehouse, true, true);
                try {
                    $response = $next($request);
                } catch (HttpResponseException $e) {
                    $response = $e->getResponse();
                } catch (ValidationException $e) {
                    $response = response()->json(['success' => false, 'error' => ['code' => 'BUSINESS_RULE_ERROR', 'details' => $e->errors()]], 422);
                }
                $status = $response->getStatusCode();
                $row = $this->attachment($attempt);
                if ($status >= 500 || ! $response instanceof JsonResponse) {
                    throw new HttpResponseException($response);
                }
                $body = $response->getData(true);
                $success = $status >= 200 && $status < 300;
                if ($success && ($row === null || $row->status !== 'ACTIVE' || data_get($body, 'data.id') !== $attempt->attachment_id)) {
                    ApiError::abort(502, 'UPLOAD_RECEIPT_INVALID', 'Owner chưa chứng minh tệp đã lưu; không báo thành công.');
                }
                if (! $success && $row !== null && $row->status !== 'QUARANTINED') {
                    ApiError::abort(502, 'UPLOAD_RECEIPT_INVALID', 'Kết quả owner không khớp trạng thái tệp.');
                }
                $state = $success ? 'SUCCEEDED' : 'REJECTED';
                $receipt = [
                    'contract' => self::CONTRACT, 'command' => 'UPLOAD', 'request_key' => $attempt->operation_key,
                    'actor_id' => $scope['actor_id'], 'auth_session_id' => $scope['auth_session_id'],
                    'warehouse_id' => $scope['warehouse_id'], 'parent_type' => $owner, 'parent_id' => $attempt->parent_id,
                    'file_sha256' => $attempt->file_sha256, 'attachment_id' => $row?->id,
                    'outcome' => $state, 'http_status' => $status,
                    'attachment_status_at_completion' => $row?->status,
                    'attachment_version_at_completion' => $row === null ? null : (int) $row->version,
                    'scan_result' => data_get($body, 'data.scan_result') ?? data_get($body, 'error.details.scan_result'),
                    'completed_at' => now()->utc()->toIso8601String(),
                ];
                DB::table(self::TABLE)->where('id', $attempt->id)->update([
                    'state' => $state, 'http_status' => $status, 'response_body' => json_encode($body, JSON_THROW_ON_ERROR),
                    'receipt' => json_encode($receipt, JSON_THROW_ON_ERROR), 'updated_at' => now()->utc()->toIso8601String(),
                ]);
                $saved = DB::table(self::TABLE)->where('id', $attempt->id)->first();

                return $this->decorate($response, $saved, $scope, false);
            });
        } catch (Throwable $e) {
            $this->uncertain($request, $attempt);
            throw $e;
        }
    }

    /** Owner GET: neither attaches a file nor infers rollback from an absent row. */
    public function show(Request $request, string $id, string $operationKey): JsonResponse
    {
        $this->schema();
        if (array_diff(array_keys($request->query()), ['warehouse_id', 'file_sha256']) !== []) {
            ApiError::abort(422, 'UPLOAD_FIELD_NOT_ALLOWED', 'Query đối chiếu không thuộc contract.');
        }
        $input = Validator::make($request->query() + ['key' => $operationKey], [
            'warehouse_id' => ['required', 'uuid'], 'file_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'key' => ['required', 'uuid'],
        ])->validate();
        $owner = (string) $request->route('attachment_owner');
        $scope = $this->scope($request, $owner, $id, strtolower($input['warehouse_id']), false);
        $attempt = DB::table(self::TABLE)->where('actor_id', $scope['actor_id'])->where('auth_session_id', $scope['auth_session_id'])
            ->where('operation_key', strtolower($operationKey))->first();
        if ($attempt !== null && ($attempt->parent_type !== $owner || $attempt->parent_id !== strtolower($id)
            || $attempt->warehouse_id !== $scope['warehouse_id'] || ! hash_equals($attempt->file_sha256, $input['file_sha256']))) {
            ApiError::abort(409, 'REQUEST_IDENTITY_MISMATCH', 'Key không khớp parent, kho hoặc tệp cần đối chiếu.');
        }
        $terminal = $attempt !== null && in_array($attempt->state, ['SUCCEEDED', 'REJECTED'], true);
        $row = $attempt === null ? null : $this->attachment($attempt);

        return response()->json(['success' => true, 'data' => [
            'outcome' => $terminal ? $attempt->state : 'UNKNOWN', 'request_state' => $attempt?->state,
            'request_key' => strtolower($operationKey), 'parent_type' => $owner, 'parent_id' => strtolower($id),
            'file_sha256' => $input['file_sha256'], 'auth_session_id' => $scope['auth_session_id'], 'warehouse_id' => $scope['warehouse_id'],
            'receipt' => $terminal ? json_decode($attempt->receipt, false, 512, JSON_THROW_ON_ERROR) : null,
            'current_attachment' => $row === null ? null : ['attachment_id' => $row->id, 'status' => $row->status, 'version' => (int) $row->version,
                'owner_download_required' => true],
            'retry_policy' => $terminal ? 'NONE' : 'CHECK_ONLY', 'cancel_supported' => false,
            'observed_at' => now()->utc()->toIso8601String(),
        ], 'meta' => ['contract' => self::CONTRACT]])->header('Cache-Control', 'private, no-store');
    }

    /** Trusted attributes are set only after a durable intent, never from client IDs. */
    public static function attachmentId(Request $request): string
    {
        return $request->attributes->get('attachment_upload.id') ?? (string) Str::uuid();
    }

    public static function trackStorage(Request $request, string $disk, string $key): void
    {
        if ($request->attributes->has('attachment_upload.id')) {
            $request->attributes->set('attachment_upload.storage', [$disk, $key]);
        }
    }

    private function scope(Request $request, string $owner, string $id, string $warehouse, bool $write, bool $lock = false): array
    {
        if (! in_array($owner, ['INBOUND', 'OUTBOUND', 'WARRANTY'], true) || ! Str::isUuid($id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy parent upload.');
        }
        if ($owner === 'WARRANTY') {
            $scope = P09WarrantyScope::resolve($request, $write);
            if (! $write && ! $scope['documents']) {
                ApiError::abort(403, 'WARRANTY_DOCUMENTS_DENIED', 'Thiếu quyền đọc tệp bảo hành.');
            }
            if ($scope['warehouse_id'] !== $warehouse) {
                ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho upload không khớp phiên.');
            }
            P09WarrantyScope::requireCase($scope, $id, $lock);

            return $scope;
        }
        $actor = $request->user();
        $actor = $actor instanceof User ? User::whereKey($actor->user_id)->first() : null;
        if ($actor === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }
        $permissions = $owner === 'INBOUND'
            ? ($write ? ['inbound.create', 'inbound.scan'] : ['inbound.view'])
            : ($write ? ['outbound.scan', 'outbound.post'] : ['outbound.view']);
        if ($actor->status !== 'ACTIVE' || ! collect($permissions)->contains(fn (string $p): bool => $actor->hasPermission($p))) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Không đủ quyền tệp của owner.');
        }
        $request->setUserResolver(fn () => $actor);
        $session = app(StableAuthSession::class)->requireId($actor);
        if (! $actor->canAccessWarehouse($warehouse)) {
            ApiError::abort(403, 'WAREHOUSE_ACCESS_DENIED', 'Kho nằm ngoài phạm vi được cấp.');
        }
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        if (($context['warehouse_context']['status'] ?? null) !== 'READY') {
            ApiError::abort(409, $context['warehouse_context']['error_code'] ?? 'WAREHOUSE_CONTEXT_REQUIRED', 'Xác nhận lại kho upload.');
        }
        if (($context['active_warehouse_id'] ?? null) !== $warehouse) {
            ApiError::abort(409, 'WAREHOUSE_CONTEXT_MISMATCH', 'Kho upload không khớp phiên.');
        }
        $parent = DB::table('stock_documents')->where('id', strtolower($id))->where('doc_type', $owner === 'INBOUND' ? 'receipt' : 'issue')
            ->where($owner === 'INBOUND' ? 'dst_warehouse_id' : 'src_warehouse_id', $warehouse)->whereNull('deleted_at');
        if ($lock) {
            $parent->lockForUpdate();
        }
        if ($parent->first() === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy parent trong kho.');
        }

        return ['actor_id' => (string) $actor->user_id, 'auth_session_id' => $session, 'warehouse_id' => $warehouse, 'pii' => true];
    }

    private function attachment(object $attempt): ?object
    {
        $query = DB::table($attempt->parent_type === 'WARRANTY' ? 'warranty_attachments' : 'document_attachments')->where('id', $attempt->attachment_id);
        if ($attempt->parent_type === 'WARRANTY') {
            $query->where('warranty_case_id', $attempt->parent_id);
        } else {
            $query->where('document_type', $attempt->parent_type)->where('document_id', $attempt->parent_id);
        }

        return $query->first();
    }

    private function decorate(JsonResponse $response, object $attempt, array $scope, bool $replay): JsonResponse
    {
        $body = $response->getData();
        $body->meta ??= (object) [];
        $body->meta->upload_receipt = json_decode($attempt->receipt, false, 512, JSON_THROW_ON_ERROR);
        $body->meta->request_id = request()->attributes->get('request_id');
        $body->meta->timestamp = now()->toIso8601String();
        if (! $response->isSuccessful()) {
            $body->message ??= 'Tệp không được owner chấp nhận.';
            $body->error_code = $body->error->code ?? $body->error_code ?? 'BUSINESS_RULE_ERROR';
            $body->errors ??= [];
        }
        if (! ($scope['pii'] ?? false) && isset($body->data->file_name)) {
            $body->data->file_name = null;
        }

        // The existing convention middleware consumes this internal marker,
        // preserving the canonical error metadata and its durable upload receipt.
        return $response->setData($body)->header('X-WMS-Envelope-Ready', '1')->header('Idempotent-Replay', $replay ? 'true' : 'false')->header('Cache-Control', 'private, no-store');
    }

    private function uncertain(Request $request, object $attempt): void
    {
        try {
            $current = DB::table(self::TABLE)->where('id', $attempt->id)->first();
            // An afterCommit callback may fail even though both DB rows committed.
            // Never delete a blob or replace a terminal receipt in that case.
            if ($current === null || in_array($current->state, ['SUCCEEDED', 'REJECTED'], true)) {
                return;
            }
            $cleanup = 'NOT_REQUIRED';
            $storage = $request->attributes->get('attachment_upload.storage');
            if ($this->attachment($attempt) === null && is_array($storage)) {
                $cleanup = Storage::disk($storage[0])->delete($storage[1]) ? 'CLEANED' : 'PENDING_OPERATOR';
            }
            DB::table(self::TABLE)->where('id', $attempt->id)->where('state', 'PENDING')->update([
                'state' => 'UNKNOWN', 'cleanup_status' => $cleanup, 'updated_at' => now()->utc()->toIso8601String(),
            ]);
        } catch (Throwable) {
            // A database outage cannot prove rollback. Durable intent remains and
            // operators must reconcile its reserved ID/storage prefix, not reupload.
        }
    }

    private function schema(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            ApiError::abort(503, 'UPLOAD_RECEIPT_MIGRATION_REQUIRED', 'Chưa triển khai schema receipt upload.');
        }
    }
}
