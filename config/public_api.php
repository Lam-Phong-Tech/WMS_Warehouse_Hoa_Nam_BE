<?php

/**
 * App PV (storefront công khai) — hợp đồng `/api/v1/public/*`.
 *
 * Mặt API này TÁCH HẲN khỏi nhóm `/api/v1/pv/*` cũ (WWHN-252/249).
 * Nhóm cũ trả available_qty; nhóm public chỉ trả nhãn IN_STOCK/PREORDER,
 * không lộ số tồn. Sản phẩm ACTIVE đã publish vẫn hiển thị khi hết tồn.
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
    | (nhóm PT, HAND_TOOLS, SPARE_PART). Domain là một lớp gộp ở tầng
    | trình bày, ánh xạ sang `category_code`, nên BA đổi cách gộp mà không cần
    | migration hay sửa dữ liệu.
    |
    | Category không nằm trong bảng này vẫn tìm/lọc được bằng `category=<code>`;
    | nó chỉ không thuộc domain nào và không xuất hiện ở /home.
    */
    'domains' => [
        'POWER_TOOLS' => [
            'name' => 'Máy và dụng cụ điện',
            'description' => 'Máy và thiết bị động lực DongCheng.',
            'categories' => [
                'PT_DRILL_DRIVER', 'PT_IMPACT', 'PT_GRINDING', 'PT_SAW',
                'PT_CONCRETE', 'PT_WOODWORK', 'PT_AIR', 'PT_WELDING',
                'PT_GARDEN', 'PT_CLEANING', 'PT_GENERATOR', 'PT_PUMP',
                'PT_MEASURE_LIGHT', 'PT_COMBO_KIT', 'PT_SPECIAL',
            ],
        ],
        'HAND_TOOLS' => [
            'name' => 'Dụng cụ cầm tay',
            'description' => 'Dụng cụ thao tác trực tiếp, không dùng nguồn động lực.',
            'categories' => [
                'CLAMPING_TOOLS', 'WRENCHES', 'SOCKETS', 'FASTENING_TOOLS',
                'CUTTING_TOOLS', 'MEASURING_TOOLS', 'ELECTRICAL_TOOLS',
                'PAINTING_WALL_TOOLS', 'MECHANICAL_TOOLS', 'HYDRAULIC_TOOLS',
                'STORAGE_TOOLS', 'GARDEN_HAND_TOOLS',
            ],
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

    // Trần số id cho chế độ tra-theo-id của `GET /public/products` (màn "đã lưu"
    // của App PV). Tách khỏi max_limit vì hai con số phục vụ hai việc khác nhau:
    // max_limit là kích thước MỘT TRANG cuộn, còn đây là số sản phẩm người dùng
    // đã lưu mà app hỏi lại trong MỘT lượt.
    'max_ids' => (int) env('PUBLIC_API_MAX_IDS', 50),

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
    | Media (ảnh sản phẩm import qua pv-admin/catalog-import)
    |--------------------------------------------------------------------------
    | Chỉ nhận ảnh từ CDN đã xác minh — importer không tin bất kỳ https:// nào
    | client gửi lên. Thêm host mới đi qua review có chủ đích, không phải qua
    | request của client.
    */
    'media_host_allowlist' => array_filter(array_map('trim', explode(',', env('PUBLIC_API_MEDIA_HOST_ALLOWLIST', 'images.bigk.click')))),

    'media_max_bytes' => (int) env('PUBLIC_API_MEDIA_MAX_BYTES', 10 * 1024 * 1024),

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

        /*
        | Số sản phẩm tối đa trong MỘT yêu cầu tư vấn (`items[]`).
        |
        | Đây là ranh giới NGHIỆP VỤ, không phải giới hạn kỹ thuật: một yêu cầu
        | 100 dòng thì người trực máy không tư vấn nổi trong một cuộc gọi, và nó
        | đã là đơn hàng chứ không còn là yêu cầu tư vấn. Cũng là trần chi phí
        | của endpoint ghi ẩn danh duy nhất: mỗi dòng là một sản phẩm phải đối
        | chiếu với danh mục công khai.
        */
        'max_items' => max(1, min(20, (int) env('PUBLIC_API_QUOTE_MAX_ITEMS', 20))),

        /*
        | Trần số lượng khách được nêu cho một dòng. Không liên quan tồn kho —
        | đây chỉ là chặn số vô nghĩa (gõ nhầm 999999) trước khi nó vào DB.
        */
        'max_quantity' => (int) env('PUBLIC_API_QUOTE_MAX_QUANTITY', 9999),
    ],

    /*
    |--------------------------------------------------------------------------
    | GET /api/v1/public/health/version
    |--------------------------------------------------------------------------
    | Chỉ công bố version của HỢP ĐỒNG API, không phải version build/commit —
    | lộ commit SHA ra endpoint ẩn danh là thông tin thừa cho người tấn công.
    |
    | 1.2.0: thêm page_info.total theo bộ lọc, công bố giới hạn Quote trong
    | /config; replay giữ kết quả đã nhận khi catalogue/privacy thay đổi.
    | Giữ nguyên cursor, envelope và body Quote cũ. Privacy version độc lập,
    | KHÔNG tăng theo contract version khi nội dung chính sách không thay đổi.
    */
    'contract_version' => env('PUBLIC_API_CONTRACT_VERSION', '1.2.0'),
];
