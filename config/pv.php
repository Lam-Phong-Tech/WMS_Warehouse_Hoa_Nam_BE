<?php

/**
 * WWHN-252 / HN-PV-001 — App PV (Web App công khai) contract knobs.
 *
 * SRS v1.12 §3 + §15 (OP-09): "Người xem App PV" là actor ẨN DANH, chỉ đọc
 * SKU đã active + published cùng available_qty kho tổng; response KHÔNG được
 * chứa serial/item/container/movement/cost hay bất kỳ dữ liệu vận hành nào.
 *
 * NFR-SRS-016 + §13.7 (PV cache): read model tồn kho có max staleness <= 60s và
 * phải phát ra data_as_of/last_inventory_sync_at để UI hiển thị độ tươi.
 * NFR-SRS-009: read/list API chạy với page_size <= 50.
 */
return [
    // Ngưỡng "dữ liệu còn tươi" công bố cho client (giây). Vượt ngưỡng thì UI
    // phải hiện trạng thái degraded thay vì im lặng hiển thị số cũ.
    'max_staleness_seconds' => (int) env('PV_MAX_STALENESS_SECONDS', 60),

    // NFR-SRS-009 đo P95 với page_size <= 50 nên đó là trần cứng của PV.
    'default_page_size' => (int) env('PV_DEFAULT_PAGE_SIZE', 20),
    'max_page_size' => (int) env('PV_MAX_PAGE_SIZE', 50),

    // Trần số SKU mà một lần gọi /pv/recommendations có thể trải ra từ rule
    // theo category/tag trước khi phân trang. Chặn rule cấu hình sai kéo cả
    // catalog vào một response.
    'max_recommendation_products' => (int) env('PV_MAX_RECOMMENDATION_PRODUCTS', 500),
];
