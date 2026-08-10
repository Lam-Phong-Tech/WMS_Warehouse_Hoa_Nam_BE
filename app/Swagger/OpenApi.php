<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '2.0.0',
    title: 'WMS_HoaNam API',
    description: 'OpenAPI route surface is generated from the real Laravel /api/v1 route list. '
        .'Business semantics follow HN-BA-MASTER-DEVTEST-001 v1.0 and HN-WMS-ERD-DD-001 v1.0 (04/08/2026). '
        .'MVP is full-post only, has no Stocktake scope, and uses inventory movements as the authoritative ledger. '
        .'Envelope: {success, message, data, meta, links, error}.'
)]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'Configured API server')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT'
)]
#[OA\OpenApi(
    tags: [
        new OA\Tag(name: '00. Platform', description: 'Health and platform-level endpoints.'),
        new OA\Tag(name: '01. Authentication', description: 'JWT authentication and current-user context.'),
        new OA\Tag(name: '02. Users', description: 'IAM user management.'),
        new OA\Tag(name: '03. Roles', description: 'IAM role and role-permission management.'),
        new OA\Tag(name: '04. Permissions', description: 'IAM permission catalog.'),
        new OA\Tag(name: '05. Product', description: 'SKU and canonical catalog masters. Catalog is independent from stock availability.'),
        new OA\Tag(name: '06. Warehouse', description: 'Hoa Nam single-warehouse master.'),
        new OA\Tag(name: '07. Inventory', description: 'Inventory balance, append-only movement trace, scan classification and reconciliation.'),
        new OA\Tag(name: '09. Stock In (Inbound)', description: 'Inbound full-post flow: DRAFT -> SCANNING -> POSTED or CANCELLED.'),
        new OA\Tag(name: '10. Stock Out (Outbound)', description: 'Outbound import/match/full-post flow: DRAFT -> SCANNING -> POSTED or CANCELLED.'),
        new OA\Tag(name: '12. Dashboard / Report', description: 'Inventory XNT, reconciliation and dashboard reporting.'),
        new OA\Tag(name: '15. Audit Log', description: 'Append-only audit evidence.'),
        new OA\Tag(name: '16. Agency', description: 'Recipient master only; Agency never owns inventory balance.'),
        new OA\Tag(name: '17. Defect Catalog', description: 'Warranty defect master.'),
        new OA\Tag(name: '18. Physical Inventory', description: 'Item instances, physical codes, import batches and containers.'),
        new OA\Tag(name: '19. Label / Printer', description: 'Immutable label snapshots, printing and reprint evidence.'),
        new OA\Tag(name: '20. Warranty', description: 'Warranty cases and warranty-only component issue flow.'),
        new OA\Tag(name: '21. Public Catalog', description: 'Read-only PV catalog/recommendation endpoints; no cart/order/payment/reservation.'),
    ]
)]
class OpenApi
{
}
