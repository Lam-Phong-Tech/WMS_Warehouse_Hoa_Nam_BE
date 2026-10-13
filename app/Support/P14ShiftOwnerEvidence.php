<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\MiniAppDraftService;

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
        if ($row === null || ! is_array($confirmation) || $originalHash === null
            || ! str_starts_with((string) $row->request_hash, 'v2:')
            || ! hash_equals(explode('|', (string) $row->request_hash)[0], explode('|', $originalHash)[0])
            || (int) $row->response_code < 200 || (int) $row->response_code >= 300 || (int) $row->response_code === 202) {
            self::invalid();
        }
        $saved = is_string($row->response_body) ? json_decode($row->response_body, true, 512, JSON_THROW_ON_ERROR) : (array) $row->response_body;
        $native = data_get($saved, 'data.confirmation');
        foreach (['state' => 'SUCCEEDED', 'item_id' => strtolower($m[1]), 'actor_id' => $shift->actor_id,
            'auth_session_id' => $shift->auth_session_id, 'warehouse_id' => $shift->warehouse_id] as $field => $value) {
            if (($confirmation[$field] ?? null) !== $value || ($native[$field] ?? null) !== $value) {
                self::invalid();
            }
        }
        $tag = $native['physical_code_id'] ?? null;
        if (! is_string($tag) || ! Str::isUuid($tag) || ($confirmation['physical_code_id'] ?? null) !== $tag) {
            self::invalid();
        }

        return [
            'protocol' => 'mini_app_nfc_confirm', 'command' => 'CONFIRM', 'native_request_id' => $key,
            'item_id' => strtolower($m[1]), 'physical_code_id' => $tag,
            'receipt_sha256' => hash('sha256', json_encode($saved, JSON_THROW_ON_ERROR)),
            'business_id' => null, 'journal_kind' => 'OWNER_REQUEST',
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

    private static function invalid(): never
    {
        ApiError::abort(502, 'SHIFT_OWNER_RECEIPT_INVALID', 'Chưa xác minh được receipt đúng thao tác và phạm vi; không chốt kết quả ca.');
    }
}
