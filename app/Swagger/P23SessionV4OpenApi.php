<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'P23V4Metric', required: ['value', 'status', 'unit', 'source'], properties: [
    new OA\Property(property: 'value', type: 'integer', nullable: true, minimum: 0),
    new OA\Property(property: 'status', type: 'string', description: 'Unknown is null with a reason, never fabricated zero.'),
    new OA\Property(property: 'unit', type: 'string', enum: ['UNIQUE_CODE', 'ATTEMPT', 'COMPONENT_UNIT']),
    new OA\Property(property: 'source', type: 'string'),
])]
#[OA\Get(path: '/api/v1/mini-app/scan-sessions', operationId: 'p23ScanSessionList', tags: ['22. Mini App'],
    summary: 'Read persisted scan sessions; explicit v4 evidence projection',
    description: 'Header-free v3 remains unchanged. V4 is read-only and requires history.read,inventory.view,stable family,confirmed warehouse and tenant ACL. No new historical fields or business documents are fabricated.',
    security: [['bearerAuth' => []]], parameters: [
        new OA\Parameter(name: 'X-Scan-Session-Contract', in: 'header', schema: new OA\Schema(type: 'string', enum: ['mini-app-p23-read-v3', 'mini-app-p23-read-v4'])),
        new OA\Parameter(name: 'warehouse_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string')),
    ], responses: [new OA\Response(response: 200, description: 'data[] batch metadata,fieldSources,summary and verified owner read targets; mini-app-p23-read-v4 only on explicit opt-in'),
        new OA\Response(response: 401, description: 'Unauthenticated/replaced/expired family'), new OA\Response(response: 403, description: 'History/inventory/warehouse denied'),
        new OA\Response(response: 422, description: 'Invalid contract/filter/cursor or conflicting null-omission representation'), new OA\Response(response: 503, description: 'Source/tenant/verification bound unavailable; no fake empty data')])]
#[OA\Get(path: '/api/v1/mini-app/history/scan-sessions/{sessionId}', operationId: 'p23ScanSessionDetail', tags: ['22. Mini App'],
    summary: 'Read exact session event page plus whole-session verified evidence',
    description: 'V4 detail stays in data.p23 with meta.p23_contract. Legacy P08 events remain redacted. Counts are not issued quantity. Relations require persisted event-owner FKs, not a source_id guess. Nulls preserve missing historical facts.',
    security: [['bearerAuth' => []]], parameters: [
        new OA\Parameter(name: 'X-Scan-Session-Contract', in: 'header', schema: new OA\Schema(type: 'string', enum: ['mini-app-p23-read-v3', 'mini-app-p23-read-v4'])),
        new OA\Parameter(name: 'sessionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'warehouse_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string')),
    ], responses: [new OA\Response(response: 200, description: 'P08 envelope + data.p23.contract=mini-app-p23-read-v4 and evidence'),
        new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission denied'),
        new OA\Response(response: 404, description: 'No session in authorized scope'), new OA\Response(response: 422, description: 'Invalid representation or cursor'),
        new OA\Response(response: 503, description: 'Unverified source/tenant; never synthetic history')])]
final class P23SessionV4OpenApi {}
