<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Post(path: '/api/v1/inbound-documents/{id}/checks', operationId: 'confirmInboundHumanCheck',
    summary: 'Explicit human check of a scoped editable inbound document; stock-neutral', security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['warehouse_id', 'confirmed'], properties: [
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'), new OA\Property(property: 'confirmed', type: 'boolean', enum: [true]),
    ])), responses: [new OA\Response(response: 200, description: 'document-human-check-v1 SUCCEEDED receipt; no stock/document-state change'),
        new OA\Response(response: 409, description: 'Version/state/idempotency conflict'), new OA\Response(response: 428, description: 'UUID key and strong If-Match required')])]
#[OA\Post(path: '/api/v1/outbound-documents/{id}/checks', operationId: 'confirmOutboundHumanCheck',
    summary: 'Explicit human check of a scoped editable outbound document; stock-neutral', security: [['bearerAuth' => []]],
    tags: ['10. Stock Out (Outbound)'], parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['warehouse_id', 'confirmed'], properties: [
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'), new OA\Property(property: 'confirmed', type: 'boolean', enum: [true]),
    ])), responses: [new OA\Response(response: 200, description: 'document-human-check-v1 SUCCEEDED receipt; not RECORD validation'),
        new OA\Response(response: 409, description: 'Version/state/idempotency conflict'), new OA\Response(response: 428, description: 'UUID key and strong If-Match required')])]
#[OA\Get(path: '/api/v1/inbound-documents/{id}/checks/requests/{key}', operationId: 'reconcileInboundHumanCheck',
    summary: 'Read exact current-family check receipt; UNKNOWN is CHECK_ONLY', security: [['bearerAuth' => []]], tags: ['09. Stock In (Inbound)'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'warehouse_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [new OA\Response(response: 200, description: 'SUCCEEDED or UNKNOWN/CHECK_ONLY; original key only')])]
#[OA\Get(path: '/api/v1/outbound-documents/{id}/checks/requests/{key}', operationId: 'reconcileOutboundHumanCheck',
    summary: 'Read exact current-family check receipt; UNKNOWN is CHECK_ONLY', security: [['bearerAuth' => []]], tags: ['10. Stock Out (Outbound)'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'warehouse_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [new OA\Response(response: 200, description: 'SUCCEEDED or UNKNOWN/CHECK_ONLY; never grants retry or takeover')])]
final class DocumentHumanCheckOpenApi {}
