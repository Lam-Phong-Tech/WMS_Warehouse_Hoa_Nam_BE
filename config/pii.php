<?php

/*
|--------------------------------------------------------------------------
| WWHN-SEC-06 — khoá cho lớp mã hoá PII ở tầng ứng dụng
|--------------------------------------------------------------------------
|
| VÌ SAO KHÔNG DÙNG THẲNG APP_KEY:
|
| APP_KEY được dùng cho session, cookie ký, signed URL và mọi `Crypt::` khác.
| Nó là khoá mà hướng dẫn vận hành của Laravel bảo "xoay khi nghi ngờ lộ" — xoay
| APP_KEY chỉ làm người dùng phải đăng nhập lại. Nếu PII cũng nằm dưới APP_KEY
| thì xoay khoá đồng nghĩa với LÀM HỎNG VĨNH VIỄN dữ liệu khách hàng: không ai
| dám xoay nữa, và khoá quan trọng nhất hệ thống bị đóng băng. Vì vậy PII có
| keyring riêng, vòng đời riêng, và quy trình xoay riêng (re-encrypt theo lô).
|
| VÌ SAO KHOÁ MÃ HOÁ VÀ KHOÁ BLIND INDEX PHẢI KHÁC NHAU:
|
| Blind index là HMAC TẤT ĐỊNH — cùng số điện thoại luôn ra cùng giá trị, đó
| chính là thứ làm cho nó tra cứu được. Dùng chung khoá với lớp mã hoá nghĩa là
| một khoá bị lộ sẽ vừa giải mã được dữ liệu vừa cho phép dựng bảng tra ngược.
| Tách khoá giữ cho hai rủi ro độc lập nhau.
|
| ĐỊNH DẠNG KEYRING:
|
| Cả hai keyring là JSON map "key_id" -> khoá base64 32 byte, ví dụ
| (GIÁ TRỊ MINH HOẠ, KHÔNG PHẢI KHOÁ THẬT — sinh bằng `php artisan pii:make-key`):
|
|   PII_ENCRYPTION_KEYS={"v1":"base64:....","v2":"base64:...."}
|   PII_ACTIVE_ENCRYPTION_KEY=v2
|
| Ciphertext mang key_id ở đầu ("v2$..."), nên trong lúc xoay khoá hệ thống vẫn
| GIẢI MÃ được dữ liệu ký bằng khoá cũ trong khi GHI bằng khoá mới. Chỉ khi
| `pii:reencrypt` đã chạy hết mới được gỡ khoá cũ khỏi keyring.
|
| DEV KHÔNG PHẢI CẤU HÌNH GÌ:
|
| Thiếu keyring ở local/testing thì khoá được DẪN XUẤT tất định từ APP_KEY bằng
| HKDF với salt riêng cho từng mục đích. `docker compose up` chạy được ngay, và
| khoá dev vẫn khác nhau giữa hai mục đích. Ngoài local/testing thì thiếu keyring
| là FAIL ở `wms:golive-check` — dữ liệu thật không bao giờ được nằm dưới một
| khoá dẫn xuất từ APP_KEY.
|
*/

return [

    /*
    | Cipher cho PII. AES-256-GCM là AEAD: ciphertext tự mang tag xác thực nên
    | sửa một byte trong DB sẽ ném lỗi giải mã thay vì trả về rác âm thầm.
    */
    'cipher' => env('PII_CIPHER', 'aes-256-gcm'),

    'encryption' => [
        // JSON: {"v1":"base64:...","v2":"base64:..."} — để trống ở dev.
        'keys' => env('PII_ENCRYPTION_KEYS', ''),
        'active' => env('PII_ACTIVE_ENCRYPTION_KEY', 'v1'),
    ],

    'blind_index' => [
        'keys' => env('PII_BLIND_INDEX_KEYS', ''),
        'active' => env('PII_ACTIVE_BLIND_INDEX_KEY', 'v1'),
    ],

    /*
    | Chỉ có ý nghĩa ở local/testing: dùng APP_KEY làm nguyên liệu HKDF khi
    | keyring trống. Đặt false để ép cả dev cũng phải khai keyring tường minh.
    */
    'allow_derived_dev_keys' => (bool) env('PII_ALLOW_DERIVED_DEV_KEYS', true),

    /*
    |--------------------------------------------------------------------------
    | Phân loại dữ liệu — cột nào mã hoá, cột nào KHÔNG, và vì sao
    |--------------------------------------------------------------------------
    |
    | Bảng này là hợp đồng review, không phải cấu hình runtime. Mục đích: ngăn
    | đúng hai kiểu sai lầm ngược nhau — bỏ sót một cột nhạy cảm, và mã hoá bừa
    | một cột chỉ để checklist đẹp (mã hoá cột không cần thiết làm mất index,
    | mất tìm kiếm, và thêm chi phí giải mã cho mỗi hàng đọc ra).
    |
    | ĐÃ MÃ HOÁ (dữ liệu định danh một cá nhân, không phải dữ liệu vận hành):
    |   public_quote_requests.full_name / .phone / .note — khách vãng lai gửi form
    |   warranty_cases.customer_name    — họ tên khách hàng (ĐÃ mã hoá)
    |   warranty_cases.customer_phone   — SĐT; blind index tra cứu chính xác (ĐÃ mã hoá)
    |   warranty_cases.customer_address — địa chỉ (ĐÃ mã hoá; KHÔNG còn tìm kiếm được)
    |
    | CỐ Ý KHÔNG MÃ HOÁ, kèm lý do:
    |
    |   app_users.email / .phone
    |       Là ĐỊNH DANH ĐĂNG NHẬP (từ 07/09/2026 chỉ đăng nhập bằng email đầy
    |       đủ). Mã hoá thì mọi lần đăng nhập phải đi qua blind index, và mọi
    |       màn IAM mất tìm kiếm gần đúng. Đây là dữ liệu nhân viên nội bộ trong
    |       phạm vi công ty, độ nhạy thấp hơn PII khách hàng. Xử lý đúng là
    |       kiểm soát truy cập + mã hoá ở tầng lưu trữ (TDE), không phải cột.
    |
    |   stock_documents.recipient_name / .recipient_address
    |       Người nhận ở đây là ĐẠI LÝ/ĐƠN VỊ (pháp nhân), không phải cá nhân —
    |       xem Modules/Warehouse .../agencies. Đây là dữ liệu chứng từ phải in
    |       lên nhãn và đối soát; mã hoá làm hỏng cả đối soát lẫn in nhãn mà
    |       không bảo vệ một cá nhân nào.
    |
    |   agencies.contact_phone / .address
    |       Thông tin liên hệ PHÁP NHÂN, thường có trên tài liệu công khai.
    |
    |   audit_logs.ip_address / refresh_tokens.ip_address / user_agent
    |       Là BẰNG CHỨNG ĐIỀU TRA. Mã hoá chúng làm mất chính khả năng truy vết
    |       khi có sự cố — đúng lúc cần đọc nhất thì không đọc được hàng loạt.
    |       Rủi ro được xử lý bằng thời hạn lưu trữ, không bằng mã hoá.
    |
    |   password_hash / otp_hash / reset_token_hash / refresh_tokens.token_hash
    |       Đã là MỘT CHIỀU (bcrypt / SHA-256 cho token entropy cao). Mã hoá một
    |       giá trị băm là vô nghĩa; "encrypt password" là lỗi thiết kế, không
    |       phải nâng cấp.
    */
    'classification' => [
        'encrypted' => [
            'public_quote_requests.full_name',
            'public_quote_requests.phone',
            'public_quote_requests.note',
            'warranty_cases.customer_name',
            'warranty_cases.customer_phone',
            'warranty_cases.customer_address',
        ],
        'blind_indexed' => [
            // Khớp CHÍNH XÁC sau chuẩn hoá (+84/84/0/khoảng trắng đều ra một dạng).
            'public_quote_requests.phone',
            'warranty_cases.customer_phone',
            // Khớp TRỌN TỪ, không dấu, AND giữa các từ — xem BlindIndex::nameTokens().
            'warranty_cases.customer_name',
        ],
    ],
];
