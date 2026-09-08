<?php

/*
|--------------------------------------------------------------------------
| WWHN-SEC-08 — xác thực hai bước cho tài khoản đặc quyền
|--------------------------------------------------------------------------
|
| NGUYÊN TẮC PHẠM VI: MFA đặt ở nơi nó ĐỔI ĐƯỢC KẾT CỤC của một vụ lộ mật khẩu,
| và KHÔNG đặt ở nơi nó chỉ làm chậm công việc.
|
| Nhân viên kho quét mã cả ca, mỗi phiếu vài chục lần chạm màn hình. Bắt họ nhập
| 6 chữ số ở mỗi thao tác là cách chắc chắn để họ dùng chung một điện thoại đặt
| cạnh máy quét — tức là MFA biến mất trên thực tế nhưng vẫn "có" trên giấy tờ.
| Vì vậy MFA ở đây là:
|
|   - MỘT LẦN mỗi phiên đăng nhập (không phải mỗi thao tác)
|   - chỉ BẮT BUỘC với vai trò đổi được cấu hình/quyền/dữ liệu toàn hệ thống
|   - mở cho MỌI vai trò tự bật nếu muốn
|
*/

return [

    /*
    | Vai trò BẮT BUỘC có MFA khi `enforce` bật. Đây là các vai trò có thể thay
    | đổi phân quyền, cấu hình bảo mật, hoặc dữ liệu của toàn hệ thống — nơi một
    | mật khẩu bị lộ không còn là sự cố của một người.
    |
    | KHÔNG đưa vai trò vận hành kho vào đây (xem phần "NGUYÊN TẮC PHẠM VI").
    */
    'required_roles' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MFA_REQUIRED_ROLES', 'SUPER_ADMIN,ADMIN,IAM_ADMIN'))
    ))),

    /*
    | Bật cưỡng chế: tài khoản thuộc `required_roles` mà CHƯA đăng ký MFA sẽ bị
    | chặn mọi endpoint (trừ nhóm MFA + logout + đổi mật khẩu) cho tới khi đăng ký.
    |
    | MẶC ĐỊNH false — CÓ CHỦ ĐÍCH. Bật cưỡng chế cùng lúc với việc deploy tính
    | năng sẽ khoá toàn bộ quản trị viên hiện hữu ra khỏi hệ thống trước khi ai
    | kịp đăng ký. Trình tự đúng: deploy -> quản trị viên đăng ký -> bật cờ.
    |
    | `wms:golive-check` (security.mfa) FAIL ngoài local/testing nếu cờ này còn
    | false, nên việc "quên bật" không thể lọt qua cutover.
    */
    'enforce' => (bool) env('MFA_ENFORCE_FOR_PRIVILEGED_ROLES', false),

    /*
    | Tên hiển thị trong app xác thực (Google Authenticator, 1Password...).
    */
    'issuer' => (string) env('MFA_ISSUER', 'WMS Hoa Nam'),

    'totp' => [
        'period' => (int) env('MFA_TOTP_PERIOD', 30),
        'digits' => (int) env('MFA_TOTP_DIGITS', 6),
        /*
        | Số bước thời gian chấp nhận lệch mỗi phía. 1 = ±30 giây.
        |
        | 0 tạo ra lớp sự cố "mã đúng vẫn báo sai" không debug được từ phía hỗ
        | trợ (đồng hồ điện thoại lệch vài giây là chuyện thường). Lớn hơn 2 thì
        | cửa sổ dùng lại một mã bị chụp màn hình rộng ra tương ứng.
        */
        'window' => (int) env('MFA_TOTP_WINDOW', 1),
    ],

    /*
    | Mã khôi phục: dùng khi mất điện thoại. MỖI MÃ CHỈ DÙNG ĐƯỢC MỘT LẦN và
    | được lưu ở dạng BĂM — hệ thống không bao giờ đọc lại được chúng, đúng như
    | mật khẩu. Người dùng thấy chúng đúng một lần lúc đăng ký.
    */
    'recovery_codes' => [
        'count' => (int) env('MFA_RECOVERY_CODE_COUNT', 8),
        'bytes' => 5, // 10 ký tự hex -> ~40 bit, đủ chống dò khi đã có khoá phiên
    ],

    /*
    | Phiên chờ MFA sau khi mật khẩu đúng. Ngắn CÓ CHỦ ĐÍCH: đây là một credential
    | tạm đã vượt qua nửa quá trình xác thực, không phải một phiên đăng nhập.
    */
    'challenge_ttl_seconds' => (int) env('MFA_CHALLENGE_TTL_SECONDS', 300),

    /*
    | Chống dò mã 6 chữ số. Không có trần thì 10^6 khả năng là dò được trong vài
    | phút bằng script — MFA khi đó chỉ còn là một bước bấm thêm.
    */
    'max_verify_attempts' => (int) env('MFA_MAX_VERIFY_ATTEMPTS', 5),
    'lockout_seconds' => (int) env('MFA_LOCKOUT_SECONDS', 900),
];
