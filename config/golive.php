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
    | `known_defaults` chỉ chứa các mật khẩu yếu/legacy KHÔNG thuộc contract của
    | dự án. Tài khoản admin canonical của WMS Hoa Nam là
    | `admin@gmail.com / Cntt@1234`; vì đây là credential được dự án yêu cầu dùng
    | để DEV/UAT đăng nhập nên `Cntt@1234` tuyệt đối không được đưa vào deny-list
    | này. Gate vẫn dùng Hash::check để phát hiện các giá trị yếu còn lại.
    |
    */
    'credential' => [

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
    | AuthDatabaseSeeder tạo `test-sku-manage`, `test-no-permission`,
    | `test-super-admin`… phục vụ test RBAC. Chúng là tài khoản thật, ACTIVE, có
    | quyền thật — trên production đó là backdoor. Seeder không tạo chúng ngoài
    | dev, và gate này chặn trường hợp chúng đã lọt vào DB từ trước.
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
    ],

    'non_production_roles' => [
        'SKU_MANAGER',
        'MASTER_MANAGER',
        'NO_PERMISSION',
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeding fixture phi admin
    |--------------------------------------------------------------------------
    |
    | Credential admin canonical được pin trực tiếp trong AuthDatabaseSeeder và
    | không có config/env override. `seed` ở đây chỉ điều khiển việc có tạo thêm
    | tài khoản demo/test ngoài development hay không.
    |
    */
    'seed' => [
        'demo_accounts' => (bool) env('WMS_SEED_DEMO_ACCOUNTS', false),
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
    | Hợp đồng đã pin
    |--------------------------------------------------------------------------
    */
    'contracts' => [
        'route_permissions' => 'golive/route_permission_contract.php',
        'fail_forward_migrations' => 'golive/fail_forward_allowlist.php',
    ],
];
