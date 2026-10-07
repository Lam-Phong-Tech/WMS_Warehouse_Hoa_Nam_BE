<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Services\MiniAppDraftService;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'P03DraftScope', type: 'object', required: ['warehouse_id', 'operation'], properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'operation', type: 'string', enum: MiniAppDraftService::OPERATIONS),
])]
#[OA\Schema(schema: 'P03DraftSave', type: 'object', required: ['warehouse_id', 'operation', 'scan_session_id', 'codes'], additionalProperties: false, properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'operation', type: 'string', enum: MiniAppDraftService::OPERATIONS),
    new OA\Property(property: 'scan_session_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string', maxLength: 200, nullable: true),
    new OA\Property(property: 'codes', type: 'array', maxItems: 1000, items: new OA\Items(type: 'object', required: ['raw_code'], additionalProperties: false, properties: [
        new OA\Property(property: 'raw_code', type: 'string', maxLength: 512),
        new OA\Property(property: 'quantity', type: 'integer', minimum: 1, maximum: 100000),
        new OA\Property(property: 'scan_source', type: 'string', maxLength: 32),
    ])),
    new OA\Property(property: 'form', type: 'object', description: 'UI snapshot only, max 64 KiB / 8 nested levels; never executed or trusted as a business request. No credentials.'),
])]
#[OA\Schema(schema: 'P03DraftCancel', type: 'object', required: ['warehouse_id', 'operation', 'scan_session_id', 'confirmed_request_id', 'reason'], additionalProperties: false, properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'operation', type: 'string', enum: MiniAppDraftService::OPERATIONS),
    new OA\Property(property: 'scan_session_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'confirmed_request_id', type: 'string', format: 'uuid', description: 'Last successful SAVE receipt for this exact version.'),
    new OA\Property(property: 'reason', type: 'string', minLength: 3, maxLength: 1000),
])]
final class P03DraftController extends Controller
{
    public function __construct(private readonly MiniAppDraftService $drafts) {}

    #[OA\Get(path: '/api/v1/mini-app/p03/context', operationId: 'p03Context', summary: 'P03: stable context, effective operation permissions and verified contact availability', security: [['bearerAuth' => []]], tags: ['22. Mini App'], responses: [new OA\Response(response: 200, description: 'mini-app-p03-v1; admin_contact NOT_CONFIGURED means channels=[]; no contact button'), new OA\Response(response: 401, description: 'UNAUTHENTICATED / AUTH_SESSION_EXPIRED'), new OA\Response(response: 409, description: 'AUTH_SESSION_REQUIRED')])]
    public function context(Request $request): JsonResponse
    {
        return $this->drafts->context($request);
    }

    #[OA\Get(path: '/api/v1/mini-app/drafts', operationId: 'p03DraftList', summary: 'Personal drafts, exact actor/login-family/confirmed-warehouse scope; SQL totals before paging', security: [['bearerAuth' => []]], tags: ['22. Mini App'], parameters: [
        new OA\Parameter(name: 'warehouse_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'operation', in: 'query', schema: new OA\Schema(type: 'string', enum: MiniAppDraftService::OPERATIONS)),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'CANCELLED', 'RECORDED'], default: 'DRAFT')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
    ], responses: [new OA\Response(response: 200, description: 'data[]; meta.total; deterministic updated_at DESC, document_id DESC'), new OA\Response(response: 403, description: 'WAREHOUSE_ACCESS_DENIED / P03_OPERATION_DENIED'), new OA\Response(response: 409, description: 'Warehouse context unavailable/mismatched'), new OA\Response(response: 422, description: 'Invalid filter')])]
    public function index(Request $request): JsonResponse
    {
        return $this->drafts->index($request);
    }

    #[OA\Get(path: '/api/v1/mini-app/drafts/{id}', operationId: 'p03DraftDetail', summary: 'Resume data only: authoritative persisted draft, never records or posts', security: [['bearerAuth' => []]], tags: ['22. Mini App'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'warehouse_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'operation', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: MiniAppDraftService::OPERATIONS)),
        new OA\Parameter(name: 'scan_session_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', schema: new OA\Schema(type: 'string')),
    ], responses: [new OA\Response(response: 200, description: 'data.document_id/scan_session_id/version/codes/form/summary/status; ETag'), new OA\Response(response: 404, description: 'DRAFT_NOT_FOUND including another actor/session/operation'), new OA\Response(response: 412, description: 'STALE_VERSION when optional If-Match differs')])]
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->drafts->show($request, $id);
    }

    #[OA\Put(path: '/api/v1/mini-app/drafts/{id}', operationId: 'p03DraftSave', summary: 'Save full snapshot at a client-generated stable draft UUID; no canonical WMS document or stock effect', security: [['bearerAuth' => []]], tags: ['22. Mini App'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, description: 'Strong quoted integer ETag; "0" for create, last version for replace.', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Stable UUID; also the receipt request_id.', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/P03DraftSave')), responses: [new OA\Response(response: 201, description: 'Created snapshot + committed receipt'), new OA\Response(response: 200, description: 'Replaced snapshot + receipt'), new OA\Response(response: 409, description: 'IDEMPOTENCY_CONFLICT / DRAFT_STATE_CONFLICT / SCAN_SESSION_CONFLICT'), new OA\Response(response: 412, description: 'STALE_VERSION'), new OA\Response(response: 428, description: 'Missing If-Match or Idempotency-Key'), new OA\Response(response: 422, description: 'Invalid payload')])]
    public function save(Request $request, string $id): JsonResponse
    {
        return $this->drafts->save($request, $id);
    }

    #[OA\Post(path: '/api/v1/mini-app/drafts/{id}/cancel', operationId: 'p03DraftCancel', summary: 'Soft cancel DRAFT only with confirmed latest SAVE receipt; never DELETE', security: [['bearerAuth' => []]], tags: ['22. Mini App'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/P03DraftCancel')), responses: [new OA\Response(response: 200, description: 'CANCELLED; snapshot/receipts retained'), new OA\Response(response: 409, description: 'DRAFT_STATE_CONFLICT / DRAFT_OUTCOME_UNKNOWN / IDEMPOTENCY_CONFLICT'), new OA\Response(response: 412, description: 'STALE_VERSION'), new OA\Response(response: 428, description: 'Missing precondition')])]
    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->drafts->cancel($request, $id);
    }

    #[OA\Get(path: '/api/v1/mini-app/drafts/{id}/requests/{requestId}', operationId: 'p03DraftReconcile', summary: 'Reconcile one exact command; receipt absent = UNKNOWN, never inferred failure or replacement document', security: [['bearerAuth' => []]], tags: ['22. Mini App'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'requestId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'warehouse_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'operation', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: MiniAppDraftService::OPERATIONS)),
        new OA\Parameter(name: 'scan_session_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'command', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['SAVE', 'CANCEL', 'RECORD'])),
    ], responses: [new OA\Response(response: 200, description: 'outcome SUCCEEDED with receipt/current document/original response, or UNKNOWN + SAME_REQUEST_ONLY; no writes'), new OA\Response(response: 409, description: 'REQUEST_IDENTITY_MISMATCH / warehouse context mismatch')])]
    public function reconcile(Request $request, string $id, string $requestId): JsonResponse
    {
        return $this->drafts->reconcile($request, $id, $requestId);
    }
}
