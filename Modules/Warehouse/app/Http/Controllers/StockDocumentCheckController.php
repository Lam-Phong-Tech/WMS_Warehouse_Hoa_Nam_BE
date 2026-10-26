<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\StableAuthSession;
use Modules\Auth\Support\WarehouseContext;
use Modules\Notification\Support\EventSourceSnapshot;
use Modules\Warehouse\Support\StockDocumentEventEvidence as Evidence;

/** Explicit back-office human confirmation. No scan, validation alias or stock effect. */
final class StockDocumentCheckController extends Controller
{
    public function confirm(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['warehouse_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']]);
        if (! $request->isJson() || $request->query() !== [] || $request->json('confirmed') !== true
            || array_diff(array_keys($request->json()->all()), ['warehouse_id', 'confirmed']) !== []) {
            ApiError::abort(422, 'DOCUMENT_CHECK_PAYLOAD_INVALID', 'Only warehouse_id and confirmed are accepted.');
        }
        $key = (string) $request->header('Idempotency-Key');
        if (! Str::isUuid($key) || ! preg_match('/^"([1-9][0-9]{0,8})"$/D', (string) $request->header('If-Match'), $match)) {
            ApiError::abort(428, 'DOCUMENT_CHECK_IDENTITY_REQUIRED', 'UUID Idempotency-Key and strong If-Match required.');
        }

        return DB::transaction(function () use ($request, $id, $key, $match, $input): JsonResponse {
            [$actor, $session, $warehouse, $source, $parent] = $this->scope($request, $id, $input['warehouse_id'], true);
            $hash = hash('sha256', json_encode([$id, (int) $match[1], $warehouse, $actor->user_id, $session, true]));
            $existing = DB::table(Evidence::TABLE)->where('request_key', strtolower($key))->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    ApiError::abort(409, 'IDEMPOTENCY_CONFLICT', 'Keep the original key, body, version and owner scope.');
                }

                return $this->receipt($existing)->header('Idempotent-Replay', 'true');
            }
            if ((int) $parent->version !== (int) $match[1]) {
                ApiError::abort(409, 'DOCUMENT_CHECK_VERSION_CONFLICT', 'Reload the current document version.');
            }
            if (! in_array($parent->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'DOCUMENT_CHECK_STATE_CONFLICT', 'Closed/posted documents cannot be checked.');
            }
            $row = Evidence::append($id, $source, $warehouse, (int) $parent->version, 'CHECKED',
                EventSourceSnapshot::capture($actor->user_id, $warehouse), 'User explicitly confirmed document check',
                strtolower($key), $hash, $session);

            return $this->receipt((object) $row);
        });
    }

    public function reconcile(Request $request, string $id, string $key): JsonResponse
    {
        $request->validate(['warehouse_id' => ['required', 'uuid']]);
        [$actor, $session, $warehouse] = $this->scope($request, $id, $request->query('warehouse_id'));
        $row = DB::table(Evidence::TABLE)->where('request_key', strtolower($key))->where('document_id', $id)
            ->where('actor_id', $actor->user_id)->where('auth_session_id', $session)->where('warehouse_id', $warehouse)->first();

        return $row === null ? response()->json(['success' => true, 'data' => ['outcome' => 'UNKNOWN', 'retry_policy' => 'CHECK_ONLY']], 200)
            ->header('Cache-Control', 'private, no-store') : $this->receipt($row);
    }

    private function scope(Request $request, string $id, string $warehouse, bool $lock = false): array
    {
        $actor = $request->user() instanceof User ? User::find($request->user()->user_id) : null;
        $source = (string) $request->route()->defaults['document_source'];
        $prefix = $source === 'INBOUND' ? 'inbound' : 'outbound';
        if ($actor === null || $actor->status !== 'ACTIVE' || ! $actor->hasPermission($prefix.'.view') || ! $actor->hasPermission($prefix.'.scan')) {
            ApiError::abort(403, 'ACCESS_DENIED', 'Document view and scan rights required.');
        }
        $session = app(StableAuthSession::class)->requireId($actor);
        $context = app(WarehouseContext::class)->payload($actor, $session, 'AUTH_SESSION_REQUIRED');
        if ($context['warehouse_context']['status'] !== 'READY' || $context['active_warehouse_id'] !== $warehouse || ! $actor->canAccessWarehouse($warehouse)) {
            ApiError::abort(403, 'DATA_SCOPE_DENIED', 'Confirmed warehouse scope required.');
        }
        $ownerWarehouse = DB::table('warehouses')->where('id', $warehouse)->where('status', 'ACTIVE')->first();
        if ($ownerWarehouse === null) {
            ApiError::abort(403, 'DATA_SCOPE_DENIED', 'Active owner warehouse required.');
        }
        foreach (['tenant_id', 'org_id', 'organization_id'] as $field) {
            $userValue = $actor->getAttribute($field);
            $ownerValue = $ownerWarehouse->{$field} ?? null;
            if (($userValue !== null || $ownerValue !== null) && (! is_string($userValue) || $userValue === '' || $userValue !== $ownerValue)) {
                ApiError::abort(403, 'DATA_SCOPE_DENIED', 'Owner organization mismatch.');
            }
        }
        $q = DB::table('stock_documents')->where('id', strtolower($id))->where('doc_type', $source === 'INBOUND' ? 'receipt' : 'issue')
            ->where($source === 'INBOUND' ? 'dst_warehouse_id' : 'src_warehouse_id', $warehouse)->whereNull('deleted_at');
        $parent = ($lock ? $q->lockForUpdate() : $q)->first();
        if ($parent === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Document not found in owner scope.');
        }

        return [$actor, $session, $warehouse, $source, $parent];
    }

    private function receipt(object $row): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['contract' => 'document-human-check-v1',
            'outcome' => 'SUCCEEDED', 'request_key' => $row->request_key, 'event_id' => $row->id,
            'document_id' => $row->document_id, 'warehouse_id' => $row->warehouse_id,
            'document_version' => (int) $row->document_version, 'stock_effect' => 'NONE', 'retry_policy' => 'CHECK_ONLY']])
            ->header('Cache-Control', 'private, no-store');
    }
}
