<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/** Shared read-only support endpoints used by Warehouse Mini App flows. */
final class MiniAppSharedOpenApi
{
    private const TAG = '22. Mini App';

    #[OA\Get(
        path: '/api/v1/mini-app/printer-configs',
        operationId: 'mini_app_printer_configs_list',
        x: ['permission' => 'label.print'],
        summary: 'Mini App - danh sách cấu hình máy in Packing Label',
        description: 'Read-only alias của canonical printer-configs, dùng để lấy printer_config_id trước thao tác in nhãn.',
        security: [['bearerAuth' => []]],
        tags: [self::TAG],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách printer_configs.'),
            new OA\Response(response: 401, description: 'Chưa xác thực.'),
            new OA\Response(response: 403, description: 'Thiếu label.print.'),
        ]
    )]
    public function listPrinterConfigs(): void {}
}
