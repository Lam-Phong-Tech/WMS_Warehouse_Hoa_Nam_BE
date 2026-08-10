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
class OpenApi
{
}
