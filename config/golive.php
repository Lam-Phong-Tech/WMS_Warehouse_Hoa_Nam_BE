<?php

/**
 * WWHN-300 — tham số của gate `php artisan wms:golive-check`.
 *
 * Tách ra config vì đây là các quyết định *vận hành* (môi trường nào được coi là
 * dev, mật khẩu yếu nào phải bị từ chối, hạ tầng nào được miễn có thẩm quyền)
 * chứ không phải hằng số kỹ thuật. Reviewer/DevOps phải sửa được chúng mà không
 * đụng vào logic của check, và mọi thay đổi ở đây đều nằm trong diff review.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Môi trường phát triển
    |--------------------------------------------------------------------------
    |
    | Chỉ những môi trường liệt kê ở đây mới được hạ FAIL xuống WARN cho các phát
    | hiện phụ thuộc môi trường (CORS trống, thiếu APP_BUILD_SHA, tài khoản demo).
    | MỌI môi trường khác — kể cả tên lạ do gõ sai APP_ENV — được coi là
    | production và bị siết ở mức chặt nhất. Đây là mặc định fail-closed: một
    | APP_ENV gõ nhầm không được biến production thành "dev".
    |
    */
    'development_environments' => ['local', 'testing'],

    /*
    |--------------------------------------------------------------------------
    | Môi trường demo/UAT được phép nhận tài khoản fixture (P0 bảo mật 10/09/2026)
    |--------------------------------------------------------------------------
    |
    | WMS_SEED_DEMO_ACCOUNTS=true CHỈ có hiệu lực khi APP_ENV nằm trong danh sách
    | này. Trước đây cờ đó mở toàn bộ fixture — kể cả `test-super-admin` — trên BẤT
    | KỲ môi trường nào, production cũng vậy. Ngay cả ở demo, không tài khoản
    | SUPER_ADMIN fixture nào được tạo (Modules\Auth\Support\SeedEnvironment).
    |
    */
    'demo_environments' => ['uat', 'demo'],

    /*
    |--------------------------------------------------------------------------
    | Allowlist cho --allow-debug (DEV300-P1-02)
    |--------------------------------------------------------------------------
    |
    | APP_DEBUG=true rò stack trace + toàn bộ config ra response. Trước đây cờ
    | --allow-debug tắt được phát hiện này ở BẤT KỲ môi trường nào, nghĩa là một
    | operator chạy lệnh trên production với cờ đó vẫn nhận PASS. Nay cờ chỉ có
    | hiệu lực khi APP_ENV nằm trong allowlist tường minh dưới đây; ngoài danh
    | sách này APP_DEBUG=true là FAIL không thể override.
    |
    */
    'debug_override_environments' => ['local', 'testing', 'development', 'dev'],

    /*
    |--------------------------------------------------------------------------
    | Vệ sinh tài khoản trước cutover (DEV300-P1-01)
    |--------------------------------------------------------------------------
    |
    | `compromised` là mật khẩu ĐÃ LỘ của chính dự án: từng nằm trong seeder,
    | OpenAPI, Postman và tài liệu bàn giao, và sẽ nằm mãi trong lịch sử git. Trước
    | 10/09/2026 file này ghi rằng giá trị đó "tuyệt đối không được đưa vào
    | deny-list" vì là credential DEV/UAT canonical — tức gate go-live được cấu hình
    | để KHÔNG BAO GIỜ nhìn thấy đúng lỗ hổng nghiêm trọng nhất. Nay nó là mục đầu
    | tiên của deny-list: tài khoản ACTIVE nào còn dùng nó ngoài local/testing là
    | FAIL, và `wms:bootstrap-super-admin` / fixture demo từ chối nhận nó.
    |
    | Đây là nơi DUY NHẤT trong repo được phép chứa chuỗi này — Hash::check cần bản
    | rõ để dò. `scripts/check-known-credentials.mjs` chặn mọi chỗ khác.
    |
    | `known_defaults` là mật khẩu yếu phổ biến. Gate dùng Hash::check trên cả hai
    | danh sách (CredentialPolicy::knownDefaults()).
    |
    */
    'credential' => [

        'compromised' => [
            'Cntt@1234',
        ],

        'known_defaults' => [
            'Cntt@123',
            'password',
            'Password@123',
            'admin',
            'admin123',
            'Admin@123',
            '123456',
            '12345678',
        ],

        // Chặn trên số tài khoản ACTIVE được dò. Bcrypt cố ý chậm; vượt ngưỡng
        // này thì coverage không đầy đủ nên gate báo FAIL thay vì im lặng bỏ qua.
        'probe_limit' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tài khoản / role chỉ dành cho môi trường phi production
    |--------------------------------------------------------------------------
    |
    | AuthDatabaseSeeder, IamCoverageSeeder, RoleAccountMatrixSeeder và
    | UatScenarioSeeder tạo tài khoản fixture phục vụ test/UAT. Chúng là tài khoản
    | thật, ACTIVE, có quyền thật — trên production đó là backdoor. Seeder không tạo
    | chúng ngoài development/demo, và gate này chặn trường hợp chúng đã lọt vào DB
    | từ trước.
    |
    */
    'non_production_usernames' => [
        '/^test[-_]/i',
        '/^demo[-_]?/i',
        '/^qa[-_]/i',
        '/^sample[-_]/i',
        '/^dummy[-_]/i',
        // Tài khoản mẫu theo vai trò của AuthDatabaseSeeder::USERS: staff01,
        // approver01, keeper01, viewer01, manager01. Chúng không mang tiền tố
        // "test-" nên phải khớp tường minh, nếu không WMS_SEED_DEMO_ACCOUNTS=true
        // trên production sẽ tạo ra chúng mà gate vẫn xanh.
        '/^(staff|approver|keeper|viewer|manager)\d{2}$/i',
        '/^inbound-tester\d{2}$/i',
        // RoleAccountMatrixSeeder: <mã vai trò viết thường, gạch nối>-01..03.
        '/^(super-admin|admin|warehouse-manager|warehouse-staff|warranty-staff|leadership|viewer|sku-manager|master-manager|no-permission|inbound-only-test)-\d{2}$/i',
        // IamCoverageSeeder: tên như người thật nên chỉ khớp đúng từng username đã seed.
        '/^(giamdoc\.hoa|phogiamdoc\.tuan|truongvung\.nam|admin\.hethong|admin\.phanquyen|admin\.cu|kythuat\.baohanh|kythuat\.hientruong|ketoan\.kho)$/i',
        '/^(baohanh|thukho|nhanvienkho|duyetkho|quanlykho|kinhdoanh|danhmuc|sku)\d{2}$/i',
        // UatScenarioSeeder (wms:seed-uat).
        '/^uat\./i',
    ],

    'non_production_roles' => [
        'SKU_MANAGER',
        'MASTER_MANAGER',
        'NO_PERMISSION',
        'INBOUND_ONLY_TEST',
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeding tài khoản fixture (P0 bảo mật 10/09/2026)
    |--------------------------------------------------------------------------
    |
    | `db:seed` ngoài development/demo KHÔNG tạo tài khoản nào. `fixture_password`
    | là mật khẩu chung của tài khoản fixture ở local/testing/demo và KHÔNG có giá
    | trị mặc định trong source — thiếu thì seeder dừng. CI_DEMO_PASSWORD được nhận
    | để các workflow CI hiện có không phải đổi tên biến.
    |
    */
    'seed' => [
        'demo_accounts' => (bool) env('WMS_SEED_DEMO_ACCOUNTS', false),
        'fixture_password' => env('WMS_FIXTURE_PASSWORD') ?: env('CI_DEMO_PASSWORD'),
        'min_demo_password_length' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bootstrap quản trị viên đầu tiên (`wms:bootstrap-super-admin`)
    |--------------------------------------------------------------------------
    |
    | Mật khẩu ban đầu không bao giờ nằm ở đây: lệnh đọc nó từ biến môi trường
    | của chính tiến trình hoặc tự sinh ngẫu nhiên. Sàn cứng 12 ký tự trong lệnh.
    |
    */
    'bootstrap' => [
        'min_password_length' => (int) env('WMS_BOOTSTRAP_MIN_PASSWORD_LENGTH', 16),
    ],

    /*
    |--------------------------------------------------------------------------
    | Miễn trừ có thẩm quyền (governed N/A) — DEV300-P1-03
    |--------------------------------------------------------------------------
    |
    | Hạ tầng audit outbox và reconciliation incident là điều kiện của
    | SRS §7.5 + BR-EXP/BR-RPT. Thiếu bảng = KHÔNG kết luận được "không có
    | backlog", nên mặc định là FAIL. Nếu một môi trường thực sự không triển khai
    | thành phần đó, người có thẩm quyền phải ghi disposition ở đây (ai duyệt,
    | ngày, lý do) — khi đó check hạ xuống WARN kèm nguyên văn disposition trong
    | evidence, thay vì biến mất khỏi báo cáo.
    |
    | Định dạng: 'core.audit_backlog' => 'WWHN-xxx — <ai duyệt> <ngày> — <lý do>'
    |
    */
    'governed_na' => [
        // 'core.audit_backlog' => '',
        // 'core.reconciliation_backlog' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Chính sách vòng đời token (WWHN-SEC-04)
    |--------------------------------------------------------------------------
    |
    | Access token của dự án là JWT stateless HS256: đã phát ra thì có hiệu lực
    | tới lúc hết hạn tự nhiên. `EnforceCredentialFreshness` chỉ cắt được nó khi
    | nạn nhân CHỦ ĐỘNG đổi mật khẩu; blacklist chỉ bắt được token đã đi qua
    | logout. Nên TTL chính là trần thời gian một token bị đánh cắp còn dùng
    | được — thiết bị quét thất lạc, XSS, hay log bị lộ đều nằm trong trần đó.
    |
    | 31/08/2026 `.env` được đặt JWT_TTL=43200 (30 NGÀY) cho đợt quét ngoài kho,
    | kèm ghi chú "KHÔNG deploy giá trị này". Nhưng không có gate nào kiểm, nên
    | giá trị đó có thể lên production hoàn toàn im lặng. Cách xử lý đúng cho
    | máy quét là refresh token xoay vòng (đã có sẵn: HttpOnly cookie +
    | refresh_ttl 14 ngày) — người dùng KHÔNG phải đăng nhập lại, chỉ access
    | token là ngắn. Kéo dài access token không đổi lấy được gì về trải nghiệm
    | mà chỉ nới cửa sổ tấn công.
    |
    | Đặt 0 để tắt kiểm (không khuyến nghị; chỉ dùng khi đang điều tra sự cố).
    |
    */
    'session' => [
        'max_jwt_ttl_minutes' => (int) env('GOLIVE_MAX_JWT_TTL_MINUTES', 120),
        'max_jwt_refresh_ttl_minutes' => (int) env('GOLIVE_MAX_JWT_REFRESH_TTL_MINUTES', 20160),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hợp đồng đã pin
    |--------------------------------------------------------------------------
    */
    'contracts' => [
        'route_permissions' => 'golive/route_permission_contract.php',
        'fail_forward_migrations' => 'golive/fail_forward_allowlist.php',
    ],
];
