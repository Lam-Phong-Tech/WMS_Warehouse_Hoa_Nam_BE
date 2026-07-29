<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'WMS_HoaNam API',
    description: 'Base API cho hệ thống quản lý kho Hoa Nam Tools. Envelope chuẩn: '
        .'{success, message, data, meta, links, error}. Xem docs/BRD_ERD_Upgrade_Guide.md '
        .'để biết phạm vi base vs phần cần nâng cấp theo BRD/ERD.'
)]
#[OA\Server(url: '/', description: 'Default server')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT'
)]
class OpenApi {}
