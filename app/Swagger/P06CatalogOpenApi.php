<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Components(parameters: [
    new OA\Parameter(parameter: 'P06CardRevision', name: 'X-P06-Card-Metadata-Revision', in: 'header', description: 'Opt in to PV descriptions with r2; absent/r1 preserves the legacy null description. Unknown revision returns 422.', schema: new OA\Schema(type: 'string', enum: ['p06-card-master-r1', 'p06-card-master-r2'], default: 'p06-card-master-r1')),
    new OA\Parameter(parameter: 'P06Warehouse', name: 'warehouse_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(parameter: 'P06Identity', name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(parameter: 'P06Page', name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
    new OA\Parameter(parameter: 'P06PerPage', name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
    new OA\Parameter(parameter: 'P06Keyword', name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 200)),
    new OA\Parameter(parameter: 'P06Serial', name: 'serial', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 200)),
    new OA\Parameter(parameter: 'P06ItemCode', name: 'item_code', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 512)),
    new OA\Parameter(parameter: 'P06SkuId', name: 'sku_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(parameter: 'P06Status', name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE', 'ALL'], default: 'ACTIVE')),
    new OA\Parameter(parameter: 'P06SkuType', name: 'sku_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['PRODUCT', 'COMPONENT', 'ALL'], default: 'ALL')),
])]
#[OA\Schema(schema: 'P06CardMetadataR1', type: 'object', required: ['category_name', 'brand_name', 'description', 'metadata_status'],
    description: 'Additive p06-card-master-r1 fields. Current FK master labels, never historical snapshots or mutation eligibility. Existing identity/version fields are unchanged.',
    properties: [
        new OA\Property(property: 'category_name', type: 'string', nullable: true, description: 'sku.category_id -> product_categories.id -> category_name; inactive linked masters retain their existing label'),
        new OA\Property(property: 'brand_name', type: 'string', nullable: true, description: 'sku.brand_id -> brands.id -> brand_name; no SKU-name inference'),
        new OA\Property(property: 'description', type: 'string', nullable: true, enum: [null], description: 'Legacy r1 stays null. Request p06-card-master-r2 to read the exact SKU PV profile description.'),
        new OA\Property(property: 'metadata_status', type: 'object', required: ['category_name', 'brand_name', 'description'], properties: [
            new OA\Property(property: 'category_name', type: 'string', enum: ['NOT_ASSIGNED', 'REFERENCE_NOT_FOUND', 'LABEL_UNAVAILABLE', 'CURRENT_ACTIVE_MASTER', 'CURRENT_INACTIVE_MASTER', 'CURRENT_MASTER_STATUS_UNKNOWN']),
            new OA\Property(property: 'brand_name', type: 'string', enum: ['NOT_ASSIGNED', 'REFERENCE_NOT_FOUND', 'LABEL_UNAVAILABLE', 'CURRENT_ACTIVE_MASTER', 'CURRENT_INACTIVE_MASTER', 'CURRENT_MASTER_STATUS_UNKNOWN']),
            new OA\Property(property: 'description', type: 'string', enum: ['SOURCE_NOT_DEFINED']),
        ]),
    ])]
#[OA\Schema(schema: 'P06CardMetadataR2', type: 'object', required: ['category_name', 'brand_name', 'description', 'metadata_status'], properties: [
    new OA\Property(property: 'category_name', type: 'string', nullable: true),
    new OA\Property(property: 'brand_name', type: 'string', nullable: true),
    new OA\Property(property: 'description', type: 'string', nullable: true, description: 'Exact-SKU current PV profile description, literal text, not HTML. No name/specification/short-description fallback.'),
    new OA\Property(property: 'metadata_status', type: 'object', required: ['category_name', 'brand_name', 'description'], properties: [
        new OA\Property(property: 'category_name', type: 'string', enum: ['NOT_ASSIGNED', 'REFERENCE_NOT_FOUND', 'LABEL_UNAVAILABLE', 'CURRENT_ACTIVE_MASTER', 'CURRENT_INACTIVE_MASTER', 'CURRENT_MASTER_STATUS_UNKNOWN']),
        new OA\Property(property: 'brand_name', type: 'string', enum: ['NOT_ASSIGNED', 'REFERENCE_NOT_FOUND', 'LABEL_UNAVAILABLE', 'CURRENT_ACTIVE_MASTER', 'CURRENT_INACTIVE_MASTER', 'CURRENT_MASTER_STATUS_UNKNOWN']),
        new OA\Property(property: 'description', type: 'string', enum: ['CURRENT_PV_PROFILE', 'PROFILE_NOT_FOUND', 'PROFILE_AMBIGUOUS', 'DESCRIPTION_UNAVAILABLE']),
    ]),
])]
#[OA\Schema(schema: 'P06CardMetadata', oneOf: [new OA\Schema(ref: '#/components/schemas/P06CardMetadataR1'), new OA\Schema(ref: '#/components/schemas/P06CardMetadataR2')])]
#[OA\Schema(schema: 'P06MetadataRevision', type: 'object', required: ['contract', 'card_metadata_revision', 'snapshot'], properties: [
    new OA\Property(property: 'contract', type: 'string', enum: ['mini-app-p06-v1']),
    new OA\Property(property: 'card_metadata_revision', type: 'string', enum: ['p06-card-master-r1', 'p06-card-master-r2']),
    new OA\Property(property: 'snapshot', type: 'object', description: 'Existing single-response P06 database snapshot, never a cross-request cursor'),
])]
#[OA\Get(path: '/api/v1/mini-app/catalog/context', operationId: 'p06CatalogContext', summary: 'P06 actor/session/warehouse and read permissions', security: [['bearerAuth' => []]], tags: ['05. Product'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/P06CardRevision')],
    responses: [new OA\Response(response: 200, description: 'mini-app-p06-v1 context; no warehouse query or GET body'), new OA\Response(response: 401, description: 'Authentication required')])]
#[OA\Get(path: '/api/v1/mini-app/catalog/skus', operationId: 'p06CatalogSkus', summary: 'P06 SKU cards with current master labels', security: [['bearerAuth' => []]], tags: ['05. Product'],
    description: 'Query-only GET. Existing keyword/serial/item_code/sku_id/status/sku_type/page/per_page filters; unknown fields rejected. Metadata joins do not alter total/tab_totals/sort. sku.view; item identity filters additionally require item_instance.view.',
    parameters: [new OA\Parameter(ref: '#/components/parameters/P06CardRevision'), new OA\Parameter(ref: '#/components/parameters/P06Warehouse'), new OA\Parameter(ref: '#/components/parameters/P06Page'), new OA\Parameter(ref: '#/components/parameters/P06PerPage'),
        new OA\Parameter(ref: '#/components/parameters/P06Keyword'), new OA\Parameter(ref: '#/components/parameters/P06Serial'), new OA\Parameter(ref: '#/components/parameters/P06ItemCode'),
        new OA\Parameter(ref: '#/components/parameters/P06SkuId'), new OA\Parameter(ref: '#/components/parameters/P06Status'), new OA\Parameter(ref: '#/components/parameters/P06SkuType')],
    responses: [new OA\Response(response: 200, description: 'Existing card data[] plus additive metadata; private,no-store', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/P06CardMetadata')),
        new OA\Property(property: 'meta', ref: '#/components/schemas/P06MetadataRevision'),
    ])), new OA\Response(response: 403, description: 'Permission/scope denied'), new OA\Response(response: 422, description: 'Required warehouse query, invalid or unsupported query/body')])]
#[OA\Get(path: '/api/v1/mini-app/catalog/items', operationId: 'p06CatalogItems', summary: 'P06 historically scoped ITEM cards, not current location', security: [['bearerAuth' => []]], tags: ['05. Product'],
    description: 'Same list filters plus item_status. sku.view AND item_instance.view; scope uses selected-warehouse ledger history or permitted warranty ownership. Metadata from exact item.sku_id.',
    parameters: [new OA\Parameter(ref: '#/components/parameters/P06CardRevision'), new OA\Parameter(ref: '#/components/parameters/P06Warehouse'), new OA\Parameter(ref: '#/components/parameters/P06Page'), new OA\Parameter(ref: '#/components/parameters/P06PerPage'),
        new OA\Parameter(ref: '#/components/parameters/P06Keyword'), new OA\Parameter(ref: '#/components/parameters/P06Serial'), new OA\Parameter(ref: '#/components/parameters/P06ItemCode'),
        new OA\Parameter(ref: '#/components/parameters/P06SkuId'), new OA\Parameter(ref: '#/components/parameters/P06Status'), new OA\Parameter(ref: '#/components/parameters/P06SkuType'),
        new OA\Parameter(name: 'item_status', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 40, pattern: '^[A-Z_]+$'))],
    responses: [new OA\Response(response: 200, description: 'Existing ITEM data[] plus additive metadata', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/P06CardMetadata')),
        new OA\Property(property: 'meta', ref: '#/components/schemas/P06MetadataRevision'),
    ])), new OA\Response(response: 403, description: 'Permission/scope denied'), new OA\Response(response: 422, description: 'Invalid query or GET body')])]
#[OA\Get(path: '/api/v1/mini-app/catalog/skus/{id}', operationId: 'p06CatalogSkuDetail', summary: 'P06 exact UUID SKU detail', security: [['bearerAuth' => []]], tags: ['05. Product'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/P06CardRevision'), new OA\Parameter(ref: '#/components/parameters/P06Warehouse'), new OA\Parameter(ref: '#/components/parameters/P06Identity')],
    responses: [new OA\Response(response: 200, description: 'Existing detail/gallery/capabilities retained; data.card extended', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'card', ref: '#/components/schemas/P06CardMetadata')]),
        new OA\Property(property: 'meta', ref: '#/components/schemas/P06MetadataRevision'),
    ])), new OA\Response(response: 404, description: 'P06_RESOURCE_NOT_FOUND'), new OA\Response(response: 422, description: 'Required warehouse query or invalid GET body')])]
#[OA\Get(path: '/api/v1/mini-app/catalog/items/{id}', operationId: 'p06CatalogItemDetail', summary: 'P06 exact UUID scoped ITEM detail', security: [['bearerAuth' => []]], tags: ['05. Product'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/P06CardRevision'), new OA\Parameter(ref: '#/components/parameters/P06Warehouse'), new OA\Parameter(ref: '#/components/parameters/P06Identity')],
    responses: [new OA\Response(response: 200, description: 'Existing detail; metadata through exact item.sku_id, not serial search', content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'card', ref: '#/components/schemas/P06CardMetadata')]),
        new OA\Property(property: 'meta', ref: '#/components/schemas/P06MetadataRevision'),
    ])), new OA\Response(response: 403, description: 'SKU/ITEM permission denied'), new OA\Response(response: 404, description: 'No ITEM in readable warehouse history')])]
#[OA\Get(path: '/api/v1/mini-app/catalog/skus/{id}/stock', operationId: 'p06CatalogSkuStock', summary: 'P06 selected-warehouse balance projection and ledger comparison', security: [['bearerAuth' => []]], tags: ['05. Product'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/P06CardRevision'), new OA\Parameter(ref: '#/components/parameters/P06Warehouse'), new OA\Parameter(ref: '#/components/parameters/P06Identity')],
    responses: [new OA\Response(response: 200, description: 'Existing null/zero/quantity fingerprint semantics; no location allocation; master-label changes are not stock version changes'), new OA\Response(response: 403, description: 'inventory.view required')])]
#[OA\Get(path: '/api/v1/mini-app/catalog/skus/{id}/gallery', operationId: 'p06CatalogSkuGallery', summary: 'P06 gallery for exact SKU owner', security: [['bearerAuth' => []]], tags: ['05. Product'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/P06CardRevision'), new OA\Parameter(ref: '#/components/parameters/P06Warehouse'), new OA\Parameter(ref: '#/components/parameters/P06Identity')],
    responses: [new OA\Response(response: 200, description: 'Existing gallery IDs/access/URL/null/expiry semantics; no metadata-driven image fallback')])]
#[OA\Get(path: '/api/v1/mini-app/catalog/skus/{id}/timeline', operationId: 'p06CatalogSkuTimeline', summary: 'P06 SKU ledger/warranty timeline', security: [['bearerAuth' => []]], tags: ['05. Product'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/P06CardRevision'), new OA\Parameter(ref: '#/components/parameters/P06Warehouse'), new OA\Parameter(ref: '#/components/parameters/P06Identity'), new OA\Parameter(ref: '#/components/parameters/P06Page'), new OA\Parameter(ref: '#/components/parameters/P06PerPage'),
        new OA\Parameter(name: 'source', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ALL', 'LEDGER', 'WARRANTY']))],
    responses: [new OA\Response(response: 200, description: 'Existing explicit-source permissions and signed/null quantities; no date/type filter or cursor added'), new OA\Response(response: 403, description: 'Requested source not permitted')])]
#[OA\Get(path: '/api/v1/mini-app/catalog/items/{id}/timeline', operationId: 'p06CatalogItemTimeline', summary: 'P06 exact ITEM ledger/warranty timeline', security: [['bearerAuth' => []]], tags: ['05. Product'],
    parameters: [new OA\Parameter(ref: '#/components/parameters/P06CardRevision'), new OA\Parameter(ref: '#/components/parameters/P06Warehouse'), new OA\Parameter(ref: '#/components/parameters/P06Identity'), new OA\Parameter(ref: '#/components/parameters/P06Page'), new OA\Parameter(ref: '#/components/parameters/P06PerPage'),
        new OA\Parameter(name: 'source', in: 'query', schema: new OA\Schema(type: 'string', enum: ['ALL', 'LEDGER', 'WARRANTY']))],
    responses: [new OA\Response(response: 200, description: 'Existing ITEM history and source permissions; no current lookup used to enrich historical events'), new OA\Response(response: 404, description: 'No scoped ITEM')])]
final class P06CatalogOpenApi {}
