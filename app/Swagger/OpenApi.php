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
#[OA\Server(url: 'http://localhost:8000', description: 'Local dev server')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT'
)]
#[OA\OpenApi(
    tags: [
        new OA\Tag(name: '01. Authentication', description: 'Đăng nhập/đăng xuất, refresh token JWT, thông tin tài khoản hiện tại.'),
        new OA\Tag(name: '05. Product', description: 'Danh mục sản phẩm/SKU của kho tổng.'),
        new OA\Tag(name: '06. Warehouse', description: 'Kho tổng Hoà Nam — chỉ 1 kho duy nhất, chỉ tra cứu và sửa thông tin.'),
        new OA\Tag(name: '07. Inventory', description: 'Tồn kho hiện tại (inventory balances) và lịch sử biến động tồn (stock movements, append-only). Không dùng nhóm này để tạo phiếu nhập/xuất.'),
        new OA\Tag(name: '08. Safe Stock', description: 'Định mức tồn kho an toàn (min/max) theo sản phẩm và lịch sử cảnh báo khi tồn vượt/dưới định mức.'),
        new OA\Tag(name: '09. Stock In (Inbound)', description: 'Phiếu nhập kho (doc_type=receipt): draft → pending → approved → received/partially_posted.'),
        new OA\Tag(name: '10. Stock Out (Outbound)', description: 'Phiếu xuất kho (doc_type=issue): draft → pending → approved → issued/partially_posted.'),
        new OA\Tag(name: '12. Dashboard / Report', description: 'Báo cáo Xuất-Nhập-Tồn (XNT) và dashboard nhanh cho kho tổng.'),
        new OA\Tag(name: '15. Audit Log', description: 'Nhật ký audit các thao tác quan trọng (tạo/duyệt/hạch toán/huỷ...), chỉ đọc qua API.'),
    ]
)]
class OpenApi {}
