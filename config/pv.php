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

    /*
     * ẢNH SẢN PHẨM APP PV (MST-PV-01) — cùng lối với tệp đính kèm bảo hành: client
     * POST multipart lên API, BE ghi vào object storage, DB chỉ giữ URL.
     *
     * Disk mặc định là `s3` — chính disk media của cả hệ thống: MinIO ở stack dev
     * (docker-compose đặt sẵn AWS_ENDPOINT=http://minio:9000) và Cloudflare R2 ở
     * bản deploy (đặt AWS_* trỏ vào R2). Đặt `PV_IMAGE_DISK=r2` nếu muốn tách ảnh
     * PV sang bucket R2 riêng đã khai ở config/filesystems.php.
     */
    'image_disk' => env('PV_IMAGE_DISK', 's3'),

    /** Thư mục (prefix key) chứa ảnh sản phẩm trong bucket. */
    'image_prefix' => env('PV_IMAGE_PREFIX', 'pv-product-images'),

    /** Trần dung lượng mỗi ảnh — khớp FILE_RULES.warrantyImage bên FE. */
    'image_max_bytes' => (int) env('PV_IMAGE_MAX_BYTES', 10 * 1024 * 1024),

    /*
     * Gốc URL công khai của ảnh.
     *
     * Để TRỐNG (mặc định) thì ảnh được phục vụ qua chính API — `GET /pv/product-images/{key}`
     * — nên bucket giữ nguyên chế độ PRIVATE. Đây là mặc định đúng cho R2: bucket
     * R2 mặc định không public, và bắt người vận hành mở public + dựng custom domain
     * chỉ để chạy được màn hình là một rào cản không cần thiết.
     *
     * Khi đã có domain công khai cho bucket (r2.dev hoặc custom domain sau CDN), đặt
     * `PV_IMAGE_PUBLIC_BASE=https://cdn.example.com` để ảnh MỚI trỏ thẳng vào CDN,
     * bỏ qua PHP. Ảnh cũ vẫn chạy vì URL đã lưu trong DB không đổi.
     */
    'image_public_base' => rtrim((string) env('PV_IMAGE_PUBLIC_BASE', ''), '/'),
];
