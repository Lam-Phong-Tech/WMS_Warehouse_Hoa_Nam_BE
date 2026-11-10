<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/master-data/suppliers',
    operationId: 'master_data_supplier_create',
    summary: 'Create a supplier from the web admin directory',
    description: 'Web-admin master data. Uses the same supplier.create grant, automatic NCC code, validation, ETag and response contract as the legacy Mini App write route, but is not a shift-scoped owner command. Active stable session required; codes and UUIDs cannot be supplied by clients. Not idempotent.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        type: 'object', required: ['name'], additionalProperties: false,
        properties: [
            new OA\Property(property: 'name', type: 'string', minLength: 1, maxLength: 200, example: 'Honda'),
            new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true, example: 'Nhà cung cấp linh kiện'),
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
        new OA\Response(response: 422, description: 'Invalid name/description/status, unknown or immutable fields, or query parameters supplied.'),
    ]
)]
#[OA\Patch(
    path: '/api/v1/master-data/suppliers/{id}',
    operationId: 'master_data_supplier_update',
    summary: 'Edit supplier name, description or status from the web admin directory',
    description: 'Web-admin master data. Requires supplier.update and If-Match with the current positive integer version. Preserves UUID, NCC code and receipt history. Not a shift-scoped owner command. Send at least one of name/description/status; other fields are rejected.',
    security: [['bearerAuth' => []]],
    tags: ['09. Stock In (Inbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: '"1"')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        type: 'object', minProperties: 1, additionalProperties: false,
        properties: [
            new OA\Property(property: 'name', type: 'string', minLength: 1, maxLength: 200, example: 'Honda Viet Nam'),
            new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
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
final class SupplierAdminWriteOpenApi {}
