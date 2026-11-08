<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/mini-app/inbound/suppliers',
    operationId: 'p04_supplier_create',
    summary: 'Create a supplier with an automatically allocated NCC code',
    description: 'Active stable session and supplier.create required. Uses the canonical active KHO-TONG-HN warehouse server-side. NCC numbering is global, includes inactive rows and expands beyond NCC-999. Codes and UUIDs cannot be supplied by clients. Not idempotent: a repeated POST creates another supplier.',
    security: [['bearerAuth' => []]],
    tags: ['22. Mini App'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        type: 'object', required: ['name'], additionalProperties: false,
        properties: [
            new OA\Property(property: 'name', type: 'string', minLength: 1, maxLength: 200, example: 'Honda'),
            new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE'], default: 'ACTIVE'),
        ]
    )),
    responses: [
        new OA\Response(response: 201, description: 'Supplier created. ETag contains version 1.',
            headers: [new OA\Header(header: 'ETag', schema: new OA\Schema(type: 'string', example: '"1"'))],
            content: new OA\JsonContent(ref: '#/components/schemas/SupplierWriteResponse')),
        new OA\Response(response: 401, description: 'Unauthenticated or expired stable session.'),
        new OA\Response(response: 403, description: 'Inactive account or missing supplier.create.'),
        new OA\Response(response: 409, description: 'Stable session required, canonical warehouse unavailable, unsupported database driver or exhausted NCC namespace.'),
        new OA\Response(response: 413, description: 'JSON body exceeds 16 KiB.'),
        new OA\Response(response: 422, description: 'Invalid name/status, unknown or immutable fields, or query parameters supplied.'),
    ]
)]
#[OA\Patch(
    path: '/api/v1/mini-app/inbound/suppliers/{id}',
    operationId: 'p04_supplier_update',
    summary: 'Edit supplier name/status while preserving UUID, code and receipt history',
    description: 'Active stable session and supplier.update required. Send at least one of name/status. If-Match must contain the current positive integer version, quoted or unquoted; weak tags, wildcards and lists are rejected. Successful edits increment version. Query parameters and fields other than name/status are rejected.',
    security: [['bearerAuth' => []]],
    tags: ['22. Mini App'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: '"1"')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        type: 'object', minProperties: 1, additionalProperties: false,
        properties: [
            new OA\Property(property: 'name', type: 'string', minLength: 1, maxLength: 200, example: 'Honda Viet Nam'),
            new OA\Property(property: 'status', type: 'string', enum: ['ACTIVE', 'INACTIVE']),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Updated supplier. ETag contains the new version.',
            headers: [new OA\Header(header: 'ETag', schema: new OA\Schema(type: 'string', example: '"2"'))],
            content: new OA\JsonContent(ref: '#/components/schemas/SupplierWriteResponse')),
        new OA\Response(response: 401, description: 'Unauthenticated or expired stable session.'),
        new OA\Response(response: 403, description: 'Inactive account or missing supplier.update.'),
        new OA\Response(response: 404, description: 'SUPPLIER_NOT_FOUND, including malformed UUID.'),
        new OA\Response(response: 409, description: 'Stable session required or supplier version exhausted.'),
        new OA\Response(response: 412, description: 'STALE_VERSION; reload the supplier before retrying.'),
        new OA\Response(response: 413, description: 'JSON body exceeds 16 KiB.'),
        new OA\Response(response: 422, description: 'Invalid version, invalid/empty payload or unknown/immutable fields.'),
        new OA\Response(response: 428, description: 'PRECONDITION_REQUIRED; If-Match missing.'),
    ]
)]
#[OA\Schema(
    schema: 'SupplierWriteResponse', type: 'object', required: ['success', 'data', 'meta'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'data', ref: '#/components/schemas/P04SupplierDirectoryRow'),
        new OA\Property(property: 'meta', type: 'object', properties: [
            new OA\Property(property: 'contract', type: 'string', example: 'mini-app-p04-v1'),
            new OA\Property(property: 'supplier_code_contract', type: 'string', example: 'supplier-business-code-v1'),
            new OA\Property(property: 'scope', type: 'string', example: 'GLOBAL'),
            new OA\Property(property: 'source', type: 'string', example: 'inbound_suppliers'),
            new OA\Property(property: 'request_id', type: 'string'),
            new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
        ]),
    ]
)]
final class SupplierWriteOpenApi {}
