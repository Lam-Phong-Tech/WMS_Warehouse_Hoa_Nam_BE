<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/** Runtime owner is P12MixedDocumentsController; detailed versioned schema stays in docs/contracts. */
final class P12MixedDocumentsOpenApi
{
    #[OA\Get(
        path: '/api/v1/mini-app/documents',
        operationId: 'p12MixedDocumentList',
        summary: 'Danh sach chung chung tu theo kho, bo loc va cursor',
        description: 'Read-only p12-mixed-documents-v3, cursor v3, document-timestamps-v1. web_queue=WAITING_WEB_POST uses persisted RECORD handoff AND current canonical status, not readiness or historical receipt. Complete schema: docs/contracts/p12-mixed-documents-openapi.json; extension: docs/contracts/p13-web-post-queue-v1.json. No partial source data/totals or FE page merging.',
        x: ['contract' => 'p12-mixed-documents-v3', 'revision' => 'p12-v3-web-post-queue-r1-20261023'],
        security: [['bearerAuth' => []]],
        tags: ['22. Mini App'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', description: 'Optional assertion matching confirmed session warehouse, not a scope selector.', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'source', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ALL', 'INBOUND', 'OUTBOUND', 'WARRANTY'], default: 'ALL')),
            new OA\Parameter(name: 'web_queue', in: 'query', description: 'Filter explicit RECORD handoffs before totals/cursor; WARRANTY is not a stock POST queue. No legacy backfill.', schema: new OA\Schema(type: 'string', enum: ['ALL', 'WAITING_WEB_POST'], default: 'ALL')),
            new OA\Parameter(name: 'q', in: 'query', description: 'Literal normalized keyword, max200 after normalization; raw max600.', schema: new OA\Schema(type: 'string', maxLength: 600)),
            new OA\Parameter(name: 'keyword', in: 'query', description: 'Earlier alias for q; conflicting normalized values are rejected.', schema: new OA\Schema(type: 'string', maxLength: 600)),
            new OA\Parameter(name: 'status', in: 'query', description: 'ALL or source-qualified canonical status, as returned in meta.status_filter_keys.', schema: new OA\Schema(type: 'string', default: 'ALL')),
            new OA\Parameter(name: 'date_from', in: 'query', description: 'Required with date_to, Vietnam date within the 90-day selection window.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', description: 'Inclusive Vietnam date, implemented with next midnight exclusive.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'timezone', in: 'query', schema: new OA\Schema(type: 'string', enum: ['Asia/Ho_Chi_Minh'])),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at_asc', 'created_at_desc'], default: 'created_at_desc')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
            new OA\Parameter(name: 'cursor', in: 'query', description: 'start or original opaque cursor bound to current actor/family/warehouse/rights/filter; fixed TTL900s.', schema: new OA\Schema(type: 'string', maxLength: 4096)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Complete authorized filtered result, current-response snapshot; not frozen between pages.', headers: [
                new OA\Header(header: 'Cache-Control', schema: new OA\Schema(type: 'string', example: 'no-store, private')),
                new OA\Header(header: 'Vary', schema: new OA\Schema(type: 'string', example: 'Authorization, Origin')),
                new OA\Header(header: 'X-Content-Type-Options', schema: new OA\Schema(type: 'string', example: 'nosniff')),
                new OA\Header(header: 'X-Request-ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
            ]),
            new OA\Response(response: 401, description: 'Unauthenticated or revoked session'),
            new OA\Response(response: 403, description: 'Owner permission or warehouse scope denied'),
            new OA\Response(response: 409, description: 'Context/cursor binding changed; restart cursor=start preserving filters'),
            new OA\Response(response: 410, description: 'Cursor expired'),
            new OA\Response(response: 422, description: 'Invalid query/date/cursor or GET body'),
            new OA\Response(response: 503, description: 'Disabled/unavailable owner or handoff source; no partial data/total'),
        ]
    )]
    public function documents(): void {}
}
