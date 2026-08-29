<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/**
 * Schema dùng chung cho `GET /api/v1/reports/dashboard-overview`.
 *
 * Ba khối dưới đây lặp lại nhiều lần trong payload (5 KPI cùng dạng tách
 * product/component, 2 loại SKU cùng dạng tách theo stock_status). Khai một lần ở
 * đây và để controller `$ref` tới, cùng lý do đã ghi ở ReversalNetEffectSchemas:
 * contract không thể lệch khỏi runtime mà không sửa cùng một chỗ.
 */
#[OA\Schema(
    schema: 'DashboardSkuSplit',
    title: 'Số liệu tách theo loại SKU',
    description: 'total luôn bằng product + component. Chiều tách lấy từ products.sku_type, riêng cột xuất thì tách theo movement_type (ISSUE = sản phẩm, COMPONENT_ISSUE = linh kiện bảo hành) vì ERD đã ràng buộc chứng từ xuất chỉ nhận SKU loại PRODUCT.',
    required: ['product', 'component', 'total'],
    properties: [
        new OA\Property(property: 'product', type: 'number'),
        new OA\Property(property: 'component', type: 'number'),
        new OA\Property(property: 'total', type: 'number'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardStockStatusSplit',
    title: 'Tồn hiện tại theo trạng thái',
    description: 'SUM(inventory_balances.on_hand_qty) theo từng stock_status của ERD bảng 29. Luôn phát hành đủ 4 khoá, thiếu dữ liệu thì bằng 0 — client không phải phòng thủ khoá vắng mặt.',
    required: ['AVAILABLE', 'NON_SELLABLE', 'DEFECTIVE', 'QUARANTINE'],
    properties: [
        new OA\Property(property: 'AVAILABLE', type: 'number'),
        new OA\Property(property: 'NON_SELLABLE', type: 'number'),
        new OA\Property(property: 'DEFECTIVE', type: 'number'),
        new OA\Property(property: 'QUARANTINE', type: 'number'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardTopSku',
    title: 'SKU xuất nhiều / ít nhất trong kỳ',
    required: ['sku_code', 'sku_name', 'issue_qty'],
    properties: [
        new OA\Property(property: 'sku_code', type: 'string'),
        new OA\Property(property: 'sku_name', type: 'string'),
        new OA\Property(property: 'issue_qty', type: 'number', description: 'Tổng lượng đã xuất (movement_type=ISSUE) trong period, quy về số dương.'),
    ],
    type: 'object',
)]
final class DashboardOverviewSchemas
{
}
