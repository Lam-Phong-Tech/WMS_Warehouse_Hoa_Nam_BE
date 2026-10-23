<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\AttachmentUploadReceipts;
use Modules\Warehouse\Services\MiniAppDraftService;
use Modules\Warehouse\Services\P19ComponentCommand;
use Modules\Warehouse\Support\P09WarrantyPolicy;
use Modules\Warehouse\Support\P09WarrantyRequests;
use Modules\Warehouse\Support\P09WarrantyScope;
use Modules\Warehouse\Support\P19CommandScope;

/** Verify existing native receipts; never save, replay or synthesize an owner result. */
final class P14ShiftOwnerEvidence
{
    public static function p03(Request $request, array $body, object $shift): ?array
    {
        $path = $request->path();
        $typed = preg_match('~^api/v1/mini-app/inbound/drafts/([a-fA-F0-9-]+)(?:/(record|cancel))?$~D', $path, $m) === 1;
        $generic = ! $typed && preg_match('~^api/v1/mini-app/drafts/([a-fA-F0-9-]+)(?:/(cancel))?$~D', $path, $m) === 1;
        $record = isset(MiniAppDraftService::RECORD_ROUTES[$path]) && $request->isMethod('POST') && $request->hasHeader('X-Draft-ID');
        if (! $typed && ! $generic && ! $record) {
            return null;
        }
        $command = $record ? 'RECORD' : match ($request->method().' '.($m[2] ?? '')) {
            'PUT ' => 'SAVE', 'POST record' => 'RECORD', 'POST cancel' => 'CANCEL', default => null,
        };
        if ($command === null) {
            return null;
        }
        $id = strtolower((string) ($record ? $request->header('X-Draft-ID') : $m[1]));
        $operation = $typed ? 'INBOUND' : ($record ? MiniAppDraftService::RECORD_ROUTES[$path] : $request->input('operation'));
        $scan = strtolower((string) ($request->input('scan_session_id') ?? $request->header('X-Draft-Scan-Session-ID')));
        $key = strtolower(trim((string) $request->header('Idempotency-Key')));
        $receipt = data_get($body, $command === 'RECORD' ? 'meta.p03_draft.receipt' : 'data.receipt');
        if (! is_array($receipt) || ! Str::isUuid($id) || ! Str::isUuid($scan) || ! Str::isUuid($key)
            || ($receipt['outcome'] ?? null) !== 'SUCCEEDED') {
            self::invalid();
        }
        $native = DB::table(MiniAppDraftService::RECEIPTS)->where('actor_id', $shift->actor_id)
            ->where('auth_session_id', $shift->auth_session_id)->where('warehouse_id', $shift->warehouse_id)
            ->where('document_id', $id)->where('scan_session_id', $scan)->where('request_id', $key)
            ->where('operation', $operation)->where('command', $command)
            ->where('response_status', '>=', 200)->where('response_status', '<', 300)->where('response_status', '!=', 202)->first();
        if ($native === null) {
            self::invalid();
        }
        foreach (['request_id', 'document_id', 'scan_session_id', 'warehouse_id', 'operation', 'command'] as $field) {
            if (($receipt[$field] ?? null) !== $native->{$field}) {
                self::invalid();
            }
        }
        if (($receipt['version'] ?? null) !== (int) $native->version) {
            self::invalid();
        }
        $draft = DB::table(MiniAppDraftService::TABLE)->where('document_id', $id)->where('actor_id', $shift->actor_id)
            ->where('auth_session_id', $shift->auth_session_id)->where('warehouse_id', $shift->warehouse_id)
            ->where('scan_session_id', $scan)->where('operation', $operation)->first();
        if ($draft === null) {
            self::invalid();
        }

        // SAVE proof is historical; current snapshot/version is independently
        // checked by close-check. A later owner state is not this receipt.
        return [
            'protocol' => MiniAppDraftService::CONTRACT, 'command' => $command,
            'native_request_id' => $key, 'document_id' => $id, 'scan_session_id' => $scan,
            'operation' => $operation, 'version' => (int) $native->version,
            'receipt_sha256' => hash('sha256', (string) $native->response_body),
            'business_id' => $command === 'RECORD'
                ? data_get(json_decode($native->response_body, true, 512, JSON_THROW_ON_ERROR), 'meta.p03_draft.document.business_document_id') : null,
            'journal_kind' => $command === 'RECORD' ? $operation : 'OWNER_REQUEST',
        ];
    }

    public static function nfc(Request $request, array $body, object $shift, ?string $originalHash): ?array
    {
        if (! $request->isMethod('PATCH') || preg_match('~^api/v1/mini-app/items/([a-fA-F0-9-]+)/nfc$~D', $request->path(), $m) !== 1) {
            return null;
        }
        $key = trim((string) $request->header('Idempotency-Key'));
        $row = DB::table(IdempotencyStore::TABLE)->where('operation', 'mini_app_nfc_confirm')
            ->where('user_id', $shift->actor_id)->where('idem_key', $key)->where('status', 'done')->first();
        $confirmation = data_get($body, 'data.confirmation');
        if ($row === null) {
            self::invalid('NFC_NATIVE_RECEIPT_ABSENT');
        }
        if (! is_array($confirmation)) {
            self::invalid('NFC_CONFIRMATION_ABSENT');
        }
        if ($originalHash === null || ! str_starts_with((string) $row->request_hash, 'v2:')
            || ! hash_equals(explode('|', (string) $row->request_hash)[0], explode('|', $originalHash)[0])) {
            self::invalid('NFC_REQUEST_DIGEST_MISMATCH');
        }
        if ((int) $row->response_code < 200 || (int) $row->response_code >= 300 || (int) $row->response_code === 202) {
            self::invalid('NFC_NATIVE_RESULT_UNCONFIRMED');
        }
        $saved = is_string($row->response_body) ? json_decode($row->response_body, true, 512, JSON_THROW_ON_ERROR) : (array) $row->response_body;
        $native = data_get($saved, 'data.confirmation');
        foreach (['state' => 'SUCCEEDED', 'item_id' => strtolower($m[1]), 'actor_id' => $shift->actor_id,
            'auth_session_id' => $shift->auth_session_id, 'warehouse_id' => $shift->warehouse_id] as $field => $value) {
            if (($confirmation[$field] ?? null) !== $value || ($native[$field] ?? null) !== $value) {
                self::invalid('NFC_SCOPE_MISMATCH');
            }
        }
        $tag = $native['physical_code_id'] ?? null;
        if (! is_string($tag) || ! Str::isUuid($tag) || ($confirmation['physical_code_id'] ?? null) !== $tag) {
            self::invalid('NFC_TAG_IDENTITY_MISMATCH');
        }

        return [
            'protocol' => 'mini_app_nfc_confirm', 'command' => 'CONFIRM', 'native_request_id' => $key,
            'item_id' => strtolower($m[1]), 'physical_code_id' => $tag,
            'receipt_sha256' => hash('sha256', json_encode($saved, JSON_THROW_ON_ERROR)),
            'business_id' => null, 'journal_kind' => 'OWNER_REQUEST',
        ];
    }

    public static function p09(Request $request, array $body, object $shift): ?array
    {
        if (! in_array($request->route()?->uri(), [
            'api/v1/mini-app/warranty-cases', 'api/v1/mini-app/warranty-cases/{id}',
        ], true) || ! in_array($request->method(), ['PATCH', 'POST'], true)) {
            return null;
        }
        $receipt = data_get($body, 'data.receipt');
        $requestId = strtolower((string) $request->header('X-Request-ID'));
        if (! is_array($receipt) || ! Str::isUuid($requestId) || ($receipt['state'] ?? null) !== 'SUCCEEDED') {
            self::invalid('P09_RECEIPT_UNCONFIRMED');
        }
        $scope = P09WarrantyScope::resolve($request, true);
        if ($scope['actor_id'] !== $shift->actor_id || $scope['auth_session_id'] !== $shift->auth_session_id
            || $scope['warehouse_id'] !== $shift->warehouse_id) {
            self::invalid('P09_SCOPE_MISMATCH');
        }
        $scopeHash = P09WarrantyPolicy::digest(P09WarrantyScope::key($scope), (string) config('app.key'));
        $native = DB::table(P09WarrantyRequests::TABLE)->where('scope_hash', $scopeHash)
            ->where('request_id', $requestId)->where('state', 'SUCCEEDED')->first();
        if ($native === null || ($receipt['request_id'] ?? null) !== $native->request_id
            || ($receipt['warranty_case_id'] ?? null) !== $native->warranty_case_id
            || ($receipt['version'] ?? null) !== (int) $native->committed_version
            || ($receipt['status'] ?? null) !== $native->committed_status) {
            self::invalid('P09_NATIVE_RECEIPT_MISMATCH');
        }
        if (! DB::table('warranty_case_warehouse_owners')->where('warranty_case_id', $native->warranty_case_id)
            ->where('warehouse_id', $shift->warehouse_id)->exists()) {
            self::invalid('P09_OWNER_MISMATCH');
        }

        return [
            'protocol' => 'mini-app-p09-v1', 'command' => $native->operation,
            'native_request_id' => $native->request_id, 'version' => (int) $native->committed_version,
            'status' => $native->committed_status,
            'receipt_sha256' => hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR)),
            'business_id' => $native->warranty_case_id, 'journal_kind' => 'WARRANTY',
        ];
    }

    public static function p19(Request $request, array $body, object $shift): ?array
    {
        $operation = P19ComponentCommand::ROUTES[$request->route()?->uri() ?? ''] ?? null;
        if ($operation === null || $request->header(P19ComponentCommand::HEADER) !== P19ComponentCommand::CONTRACT) {
            return null;
        }
        $key = strtolower(trim((string) $request->header('Idempotency-Key')));
        $receipt = data_get($body, 'data.receipt');
        if (! Str::isUuid($key) || ! is_array($receipt) || ($receipt['outcome'] ?? null) !== 'SUCCEEDED') {
            self::invalid('P19_RECEIPT_UNCONFIRMED');
        }
        $scope = P19CommandScope::resolve($request);
        if ($scope['actor_id'] !== $shift->actor_id || $scope['auth_session_id'] !== $shift->auth_session_id
            || $scope['warehouse_id'] !== $shift->warehouse_id) {
            self::invalid('P19_SCOPE_MISMATCH');
        }
        $native = DB::table(P19ComponentCommand::TABLE)->where('request_id', $key)
            ->where('actor_id', $shift->actor_id)->where('auth_session_id', $shift->auth_session_id)
            ->where('warehouse_id', $shift->warehouse_id)->where('scope_hash', P19CommandScope::digest($scope))
            ->where('operation', $operation)->where('outcome', 'SUCCEEDED')->first();
        $result = $native?->result_json === null ? null : json_decode($native->result_json, true, 512, JSON_THROW_ON_ERROR);
        $business = $result['business_document_id'] ?? null;
        if ($native === null || ($receipt['request_id'] ?? null) !== $key
            || ($receipt['operation'] ?? null) !== $operation || ($receipt['result'] ?? null) !== $result
            || ! is_string($business) || ! Str::isUuid($business)
            || ! DB::table('component_issue_documents')->where('id', $business)
                ->where('warehouse_id', $shift->warehouse_id)->exists()) {
            self::invalid('P19_NATIVE_RECEIPT_MISMATCH');
        }

        return [
            'protocol' => P19ComponentCommand::CONTRACT, 'command' => $operation,
            'native_request_id' => $key, 'document_version' => $result['document_version'] ?? null,
            'receipt_sha256' => hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR)),
            'business_id' => $business, 'journal_kind' => 'COMPONENT_ISSUE',
        ];
    }

    public static function p18(Request $request, array $body, object $shift, int $httpStatus): ?array
    {
        $owner = AttachmentUploadReceipts::UPLOAD_ROUTES[$request->route()?->uri() ?? ''] ?? null;
        if (! $request->isMethod('POST') || $owner === null
            || $request->header('X-Upload-Contract') !== AttachmentUploadReceipts::CONTRACT) {
            return null;
        }
        $receipt = data_get($body, 'meta.upload_receipt');
        if (! is_array($receipt)) {
            if ($httpStatus < 200 || $httpStatus >= 300) {
                return null; // Pre-intent validation/authorization is not a durable rejection.
            }
            self::invalid('P18_RECEIPT_ABSENT');
        }
        $key = strtolower(trim((string) $request->header('Idempotency-Key')));
        $parent = strtolower((string) $request->route('id'));
        $outcome = $receipt['outcome'] ?? null;
        if (! Str::isUuid($key) || ! Str::isUuid($parent)
            || ! in_array($outcome, ['SUCCEEDED', 'REJECTED'], true)
            || ($outcome === 'SUCCEEDED' && $httpStatus !== 201)
            || ($outcome === 'REJECTED' && ($httpStatus < 400 || $httpStatus >= 500))) {
            self::invalid('P18_TERMINAL_RESULT_INVALID');
        }
        $native = DB::table(AttachmentUploadReceipts::TABLE)->where('actor_id', $shift->actor_id)
            ->where('auth_session_id', $shift->auth_session_id)->where('warehouse_id', $shift->warehouse_id)
            ->where('operation_key', $key)->where('parent_type', $owner)->where('parent_id', $parent)
            ->where('file_sha256', $request->header('X-Upload-SHA256'))->where('state', $outcome)->first();
        if ($native === null || (int) $native->http_status !== $httpStatus
            || strtolower((string) $request->header('X-Upload-Warehouse-ID')) !== $shift->warehouse_id
            || json_decode((string) $native->receipt, true, 512, JSON_THROW_ON_ERROR) !== $receipt) {
            self::invalid('P18_NATIVE_RECEIPT_MISMATCH');
        }
        foreach (['contract' => AttachmentUploadReceipts::CONTRACT, 'command' => 'UPLOAD',
            'request_key' => $key, 'actor_id' => $shift->actor_id, 'auth_session_id' => $shift->auth_session_id,
            'warehouse_id' => $shift->warehouse_id, 'parent_type' => $owner, 'parent_id' => $parent] as $field => $value) {
            if (($receipt[$field] ?? null) !== $value) {
                self::invalid('P18_SCOPE_MISMATCH');
            }
        }

        return [
            'protocol' => AttachmentUploadReceipts::CONTRACT, 'command' => 'UPLOAD',
            'native_request_id' => $key, 'parent_type' => $owner, 'parent_id' => $parent,
            'attachment_id' => $receipt['attachment_id'] ?? null, 'native_outcome' => $outcome,
            'receipt_sha256' => hash('sha256', (string) $native->receipt),
            'business_id' => null, 'journal_kind' => $outcome === 'REJECTED' ? 'OWNER_REJECTED' : 'OWNER_REQUEST',
        ];
    }

    public static function audit(array $proof, object $shift, string $requestId): void
    {
        unset($proof['journal_kind']);
        AuditLogger::record(action: 'WORK_SHIFT_OWNER_VERIFIED', objectType: 'mini_app_work_shift',
            objectId: $shift->shift_id, actorId: $shift->actor_id, requestId: $requestId,
            afterData: ['contract' => 'p14-owner-evidence-v1', 'auth_session_id' => $shift->auth_session_id,
                'warehouse_id' => $shift->warehouse_id, 'evidence' => $proof], result: 'SUCCESS');
    }

    private static function invalid(string $check = 'OWNER_EVIDENCE_INVALID'): never
    {
        ApiError::abort(502, 'SHIFT_OWNER_RECEIPT_INVALID', 'Chưa xác minh được receipt đúng thao tác và phạm vi; không chốt kết quả ca.', ['evidence_check' => $check]);
    }
}
