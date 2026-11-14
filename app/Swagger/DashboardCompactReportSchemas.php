<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardXntProjection',
    additionalProperties: false,
    description: 'view=dashboard: all canonical XNT rows and exact numeric values, with unused fields removed. Existing page/per_page behavior is retained.',
    required: ['lines'],
    properties: [
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
            required: ['product_code', 'product_name', 'opening', 'receipt_net', 'issue_net', 'component_issue_net', 'reversal_unlinked_net', 'closing'],
            properties: [
                new OA\Property(property: 'product_code', type: 'string'),
                new OA\Property(property: 'product_name', type: 'string'),
                new OA\Property(property: 'opening', type: 'number'),
                new OA\Property(property: 'receipt_net', type: 'number'),
                new OA\Property(property: 'issue_net', type: 'number'),
                new OA\Property(property: 'component_issue_net', type: 'number'),
                new OA\Property(property: 'reversal_unlinked_net', type: 'number'),
                new OA\Property(property: 'closing', type: 'number'),
            ],
            type: 'object',
        )),
        new OA\Property(property: 'pagination', type: 'object', description: 'Present only when per_page is supplied; unchanged canonical pagination metadata.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardThresholdProjection',
    additionalProperties: false,
    description: 'view=dashboard-thresholds: all canonical reconciliation rows, preserving ordering and availability; no reconciliation evidence or duplicate mismatch array.',
    required: ['rows'],
    properties: [
        new OA\Property(property: 'rows', type: 'array', items: new OA\Items(
            required: ['product_code', 'product_name', 'sku_type', 'available_qty'],
            properties: [
                new OA\Property(property: 'product_code', type: 'string'),
                new OA\Property(property: 'product_name', type: 'string'),
                new OA\Property(property: 'sku_type', type: 'string'),
                new OA\Property(property: 'available_qty', type: 'number'),
            ],
            type: 'object',
        )),
    ],
    type: 'object',
)]
final class DashboardCompactReportSchemas {}
