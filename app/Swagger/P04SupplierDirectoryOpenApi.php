<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'P04SupplierDirectoryRow',
    required: ['id', 'code', 'name', 'description', 'display_name', 'code_status', 'status', 'version', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'Unchanged supplier UUID used as supplier_id in existing SAVE/RECORD commands.'),
        new OA\Property(property: 'code', type: 'string', nullable: true, example: 'NCC-001', description: 'Persisted unique business code. Null means not assigned; GET never allocates one.'),
        new OA\Property(property: 'name', type: 'string', description: 'Unmodified raw supplier name, not the display label.'),
        new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 500, description: 'Optional free-text supplier description, trimmed; null when blank or unset.'),
        new OA\Property(property: 'display_name', type: 'string', example: 'NCC-001 - Honda', description: 'Current directory label; not a historical document snapshot.'),
        new OA\Property(property: 'code_status', type: 'string', enum: ['ASSIGNED', 'NOT_ASSIGNED']),
        new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE']),
        new OA\Property(property: 'version', type: 'integer', minimum: 1, description: 'Existing supplier version is preserved during code initialization.'),
        new OA\Property(property: 'updated_at', type: 'string', description: 'Existing directory timestamp, not code-allocation time.'),
    ]
)]
#[OA\Get(
    path: '/api/v1/mini-app/inbound/suppliers',
    operationId: 'p04_supplier_directory',
    summary: 'Read the global inbound supplier directory with stable business codes',
    description: 'Requires an authenticated stable session and supplier.view OR inbound.view (legacy inbound lookup). Additive supplier-business-code-v1 fields preserve UUID, raw name and historical snapshots. This GET never writes or allocates a code.',
    security: [['bearerAuth' => []]],
    tags: ['22. Mini App'],
    parameters: [
        new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 200), description: 'Literal case-insensitive code, name or description search.'),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'], default: 'ACTIVE')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Global directory, SQL paged by name then UUID.', content: new OA\JsonContent(type: 'object', properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/P04SupplierDirectoryRow')),
            new OA\Property(property: 'meta', type: 'object', properties: [
                new OA\Property(property: 'contract', type: 'string', example: 'mini-app-p04-v1'),
                new OA\Property(property: 'supplier_code_contract', type: 'string', example: 'supplier-business-code-v1'),
                new OA\Property(property: 'scope', type: 'string', example: 'GLOBAL'),
                new OA\Property(property: 'source', type: 'string', example: 'inbound_suppliers'),
                new OA\Property(property: 'total', type: 'integer', minimum: 0),
                new OA\Property(property: 'page', type: 'integer', minimum: 1),
                new OA\Property(property: 'per_page', type: 'integer', minimum: 1, maximum: 100),
                new OA\Property(property: 'last_page', type: 'integer', minimum: 1),
            ]),
        ])),
        new OA\Response(response: 401, description: 'Unauthenticated, expired or replaced session.'),
        new OA\Response(response: 403, description: 'P04_OPERATION_DENIED or inactive account; no supplier data.'),
        new OA\Response(response: 409, description: 'Authenticated owner lacks the required stable session identity.'),
        new OA\Response(response: 422, description: 'Query/status/pagination validation failed.'),
    ]
)]
final class P04SupplierDirectoryOpenApi {}
