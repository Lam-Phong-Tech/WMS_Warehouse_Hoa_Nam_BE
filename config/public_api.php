<?php

/**
 * App PV (storefront công khai) — hợp đồng `/api/v1/public/*`.
 *
 * Mặt API này TÁCH HẲN khỏi nhóm `/api/v1/pv/*` cũ (WWHN-252/249). Nhóm cũ bị
 * khoá bởi Wwhn252PvContractTest: nó trả `available_qty` và cố ý vẫn hiển thị
 * SKU tồn 0 (DEC-CATALOG-001). Hợp đồng mới của FE App PV nói ngược lại — không
 * được lộ số tồn, và chỉ trả hàng đang còn — nên hai mặt phải sống song song
 * thay vì sửa cái cũ.
 *
 * Mọi knob nghiệp vụ (hotline, giờ hỗ trợ, domain, feature flag) để ở đây để BA
 * chỉnh qua .env mà không cần deploy code hay migration.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | GET /api/v1/public/config
    |--------------------------------------------------------------------------
    | Payload FE gọi lúc khởi động app. Không chứa bí mật: mọi giá trị ở đây đều
    | hiển thị công khai trên UI.
    */
    'contact' => [
        'hotline' => env('PUBLIC_API_HOTLINE', '1900 1234'),
        'zalo_oa_id' => env('PUBLIC_API_ZALO_OA_ID'),
        'zalo_oa_url' => env('PUBLIC_API_ZALO_OA_URL'),
        'email' => env('PUBLIC_API_CONTACT_EMAIL'),
    ],

    /*
    | Giờ hỗ trợ. `days` theo ISO-8601 (1 = thứ Hai ... 7 = Chủ nhật) để FE khỏi
    | phải parse tiếng Việt.
    */
    'support_hours' => [
        'timezone' => env('PUBLIC_API_SUPPORT_TIMEZONE', 'Asia/Ho_Chi_Minh'),
        'weekday_open' => env('PUBLIC_API_SUPPORT_WEEKDAY_OPEN', '08:00'),
        'weekday_close' => env('PUBLIC_API_SUPPORT_WEEKDAY_CLOSE', '17:30'),
        'saturday_open' => env('PUBLIC_API_SUPPORT_SATURDAY_OPEN', '08:00'),
        'saturday_close' => env('PUBLIC_API_SUPPORT_SATURDAY_CLOSE', '12:00'),
        'note' => env('PUBLIC_API_SUPPORT_NOTE', 'Chủ nhật và ngày lễ nghỉ.'),
    ],

    'privacy' => [
        'url' => env('PUBLIC_API_PRIVACY_URL', 'https://hoanam.vn/chinh-sach-bao-mat'),
        // FE gửi lại đúng chuỗi này trong POST /quote-requests. Đổi version tức
        // là bắt người dùng đồng ý lại — đừng đổi nếu nội dung không đổi.
        'version' => env('PUBLIC_API_PRIVACY_VERSION', '1.0.0'),
    ],

    /*
    | Bật maintenance là FE hiện màn hình bảo trì. Các endpoint đọc vẫn phục vụ
    | bình thường; chỉ POST /quote-requests bị chặn 503 (xem controller) vì gửi
    | yêu cầu tư vấn lúc bảo trì thì không ai xử lý.
    */
    'maintenance' => [
        'enabled' => (bool) env('PUBLIC_API_MAINTENANCE', false),
        'message' => env('PUBLIC_API_MAINTENANCE_MESSAGE', 'Hệ thống đang được bảo trì. Vui lòng quay lại sau.'),
    ],

    /*
    | Feature flags FE đọc để bật/tắt module UI. Chỉ nhận boolean — FE không được
    | nhận cấu hình phức tạp qua đây.
    */
    'feature_flags' => [
        'quote_request' => (bool) env('PUBLIC_API_FEATURE_QUOTE_REQUEST', true),
        'related_products' => (bool) env('PUBLIC_API_FEATURE_RELATED_PRODUCTS', true),
        'facet_filters' => (bool) env('PUBLIC_API_FEATURE_FACET_FILTERS', true),
        'product_search' => (bool) env('PUBLIC_API_FEATURE_PRODUCT_SEARCH', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain (POWER_TOOLS / HAND_TOOLS / ACCESSORIES)
    |--------------------------------------------------------------------------
    | CSDL KHÔNG có cột `domain` — taxonomy thật là `product_categories`
    | (TRACTOR/HARVESTER/PUMP/ENGINE/SPARE_PART). Domain là một lớp gộp ở tầng
    | trình bày, ánh xạ sang `category_code`, nên BA đổi cách gộp mà không cần
    | migration hay sửa dữ liệu.
    |
    | Category không nằm trong bảng này vẫn tìm/lọc được bằng `category=<code>`;
    | nó chỉ không thuộc domain nào và không xuất hiện ở /home.
    */
    'domains' => [
        'POWER_TOOLS' => [
            'name' => 'Máy và thiết bị động lực',
            'description' => 'Máy nông nghiệp, động cơ và thiết bị chạy điện/xăng dầu.',
            'categories' => ['TRACTOR', 'HARVESTER', 'PUMP', 'ENGINE'],
        ],
        'HAND_TOOLS' => [
            'name' => 'Dụng cụ cầm tay',
            'description' => 'Dụng cụ thao tác trực tiếp, không dùng nguồn động lực.',
            'categories' => [],
        ],
        'ACCESSORIES' => [
            'name' => 'Phụ tùng và phụ kiện',
            'description' => 'Phụ tùng thay thế và phụ kiện đi kèm máy.',
            'categories' => ['SPARE_PART'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Listing / phân trang
    |--------------------------------------------------------------------------
    | Cursor là token đục do BE phát hành (ký HMAC bằng APP_KEY) — client không
    | được tự dựng. `limit` có trần cứng vì P95 chỉ đo tới ngưỡng này.
    */
    'default_limit' => (int) env('PUBLIC_API_DEFAULT_LIMIT', 20),
    'max_limit' => (int) env('PUBLIC_API_MAX_LIMIT', 50),

    // Số sản phẩm mỗi section trên /home.
    'home_section_size' => (int) env('PUBLIC_API_HOME_SECTION_SIZE', 12),

    // Trần số giá trị trả về cho MỘT facet, chặn facet spec bung ra vô hạn.
    'max_facet_values' => (int) env('PUBLIC_API_MAX_FACET_VALUES', 50),

    /*
    | Các khoá spec (trong sku.specification) được phép dùng làm facet/filter
    | `spec.<code>`. Allowlist chứ không mở tự do: specification là JSON tự do
    | của vận hành, mở hết là lộ khoá nội bộ ra bộ lọc công khai.
    */
    'spec_facets' => [
        'power' => 'Công suất',
        'voltage' => 'Điện áp',
        'weight' => 'Trọng lượng',
        'capacity' => 'Dung tích',
        'engine_type' => 'Loại động cơ',
    ],

    /*
    |--------------------------------------------------------------------------
    | POST /api/v1/public/quote-requests
    |--------------------------------------------------------------------------
    | Hợp đồng FE: Idempotency-Key dài 16..128 ký tự. Khác với
    | App\Support\IdempotencyKeyGuard (8..100) đang dùng cho nhóm nội bộ, nên
    | nhóm public có ngưỡng riêng ở đây thay vì nới lỏng guard dùng chung.
    */
    'quote_request' => [
        'idempotency_key_min' => 16,
        'idempotency_key_max' => 128,
        'note_max_length' => 1000,
        'full_name_max_length' => 120,
        // Giữ bản ghi trùng Idempotency-Key trong bao lâu thì trả lại kết quả cũ.
        'idempotency_ttl_hours' => (int) env('PUBLIC_API_QUOTE_IDEMPOTENCY_TTL_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | GET /api/v1/public/health/version
    |--------------------------------------------------------------------------
    | Chỉ công bố version của HỢP ĐỒNG API, không phải version build/commit —
    | lộ commit SHA ra endpoint ẩn danh là thông tin thừa cho người tấn công.
    */
    'contract_version' => env('PUBLIC_API_CONTRACT_VERSION', '1.0.0'),
];
