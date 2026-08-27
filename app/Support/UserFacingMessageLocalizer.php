<?php

namespace App\Support;

/**
 * Việt hóa message trả cho người dùng mà KHÔNG thay đổi error_code, API field,
 * permission key hoặc raw enum trong dữ liệu/DB.
 *
 * Đây là safety net ở boundary API. Nghiệp vụ mới vẫn nên viết message tiếng Việt
 * ngay tại nơi phát sinh; lớp này đảm bảo legacy/framework message tiếng Anh không
 * lọt lên UI trong thời gian chuyển đổi.
 */
final class UserFacingMessageLocalizer
{
    /** @var array<string, string> */
    private const EXACT = [
        'Not Found' => 'Không tìm thấy dữ liệu.',
        'Resource not found' => 'Không tìm thấy dữ liệu.',
        'Unauthenticated.' => 'Chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
        'Unauthorized' => 'Bạn không được phép thực hiện thao tác này.',
        'Forbidden' => 'Bạn không có quyền truy cập.',
        'This action is unauthorized.' => 'Bạn không có quyền thực hiện thao tác này.',
        'The given data was invalid.' => 'Dữ liệu gửi lên không hợp lệ.',
        'Validation failed.' => 'Dữ liệu gửi lên không hợp lệ.',
        'Method Not Allowed' => 'Phương thức truy cập không được hỗ trợ.',
        'Too Many Requests' => 'Bạn thao tác quá nhiều lần, vui lòng thử lại sau.',
        'Page Expired' => 'Phiên làm việc đã hết hạn, vui lòng thử lại.',
        'CSRF token mismatch.' => 'Phiên làm việc không hợp lệ, vui lòng thử lại.',
        'Server Error' => 'Hệ thống gặp lỗi nội bộ.',
        'Internal Server Error' => 'Hệ thống gặp lỗi nội bộ.',
        'Document must have at least one line.' => 'Chứng từ phải có ít nhất một dòng.',
        'Scan quantity must be greater than zero.' => 'Số lượng quét phải lớn hơn 0.',
        'Reject approval is outside the latest MVP baseline.' => 'Thao tác từ chối phê duyệt không thuộc quy trình MVP hiện hành.',
        'close_remainder is outside the latest MVP baseline.' => 'Thao tác đóng phần còn lại không thuộc quy trình MVP hiện hành.',
    ];

    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'DRAFT' => 'Nháp',
        'SCANNING' => 'Đang quét',
        'POSTED' => 'Đã ghi nhận',
        'CANCELLED' => 'Đã hủy',
        'PRINTED' => 'Đã in',
        'PREVIEWED' => 'Đã xem trước',
        'READY_TO_PRINT' => 'Sẵn sàng in',
        'PENDING_PRINT' => 'Chờ in',
        'PRINT_FAILED' => 'In thất bại',
        'VALID' => 'Hợp lệ',
        'DUPLICATE' => 'Đã tồn tại/đã xử lý',
        'UNKNOWN' => 'Không nhận diện được',
        'INVALID' => 'Không hợp lệ',
        'MATCH' => 'Khớp',
        'MISMATCH' => 'Có chênh lệch',
        'NOT_APPLICABLE' => 'Không áp dụng',
        'AVAILABLE' => 'Khả dụng',
        'NON_SELLABLE' => 'Không thể bán/xuất',
        'DEFECTIVE' => 'Hàng lỗi',
        'QUARANTINE' => 'Chờ kiểm tra',
        'ACTIVE' => 'Đang hoạt động',
        'INACTIVE' => 'Ngừng hoạt động',
        'LOCKED' => 'Đã khóa',
        'PRODUCT' => 'Sản phẩm',
        'COMPONENT' => 'Linh kiện',
        'RECEIVED_PENDING_PUTAWAY' => 'Chờ xếp khay',
        'STORED' => 'Đã xếp khay',
        'RECEIVED' => 'Đã tiếp nhận',
        'CHECKING' => 'Đang kiểm tra',
        'REPAIRING' => 'Đang sửa chữa',
        'COMPLETED' => 'Đã hoàn tất',
        'RETURNED' => 'Đã trả khách',
        'IN_WARRANTY' => 'Đang bảo hành',
        'RETURNED_TO_CUSTOMER' => 'Đã trả khách',
    ];

    public static function translate(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return $message;
        }

        $trimmed = trim($message);
        if (isset(self::EXACT[$trimmed])) {
            return self::preserveWhitespace($message, self::EXACT[$trimmed]);
        }

        $localized = $message;

        $replacements = [
            '/^The (.+) field is required\.$/i' => 'Trường $1 là bắt buộc.',
            '/^The (.+) field is required when (.+) is not present\.$/i' => 'Trường $1 là bắt buộc khi không có $2.',
            '/^The (.+) field prohibits (.+) from being present\.$/i' => 'Khi có trường $1 thì không được gửi đồng thời trường $2.',
            '/^The (.+) field must be true or false\.$/i' => 'Trường $1 phải là đúng hoặc sai.',
            '/^The (.+) must be true or false\.$/i' => 'Trường $1 phải là đúng hoặc sai.',
            '/^The (.+) must be a string\.$/i' => 'Trường $1 phải là chuỗi ký tự.',
            '/^The (.+) must be an integer\.$/i' => 'Trường $1 phải là số nguyên.',
            '/^The (.+) must be a number\.$/i' => 'Trường $1 phải là số.',
            '/^The (.+) must be numeric\.$/i' => 'Trường $1 phải là số.',
            '/^The (.+) must be an array\.$/i' => 'Trường $1 phải là danh sách.',
            '/^The (.+) must be a valid email address\.$/i' => 'Trường $1 phải là địa chỉ email hợp lệ.',
            '/^The (.+) must be a valid UUID\.$/i' => 'Trường $1 phải là UUID hợp lệ.',
            '/^The (.+) must be a valid date\.$/i' => 'Trường $1 phải là ngày hợp lệ.',
            '/^The (.+) must be at least ([0-9]+) characters\.$/i' => 'Trường $1 phải có ít nhất $2 ký tự.',
            '/^The (.+) may not be greater than ([0-9]+) characters\.$/i' => 'Trường $1 không được vượt quá $2 ký tự.',
            '/^The (.+) must be greater than ([^\.]+)\.$/i' => 'Trường $1 phải lớn hơn $2.',
            '/^The selected (.+) is invalid\.$/i' => 'Giá trị đã chọn cho trường $1 không hợp lệ.',
            '/^The (.+) has already been taken\.$/i' => '$1 này đã tồn tại trong hệ thống. Vui lòng nhập giá trị khác.',
            '/^The (.+) format is invalid\.$/i' => 'Định dạng trường $1 không hợp lệ.',
            '/^The (.+) field has a duplicate value\.$/i' => 'Trường $1 có giá trị bị trùng.',
            '/Scan quantity exceeds remaining quantity on line\s+([^\.]+)\./i' => 'Số lượng quét vượt quá số lượng còn lại ở dòng $1.',
            '/Full Post Receipt/i' => 'Hoàn tất nhập kho',
            '/Post Receipt/i' => 'Hoàn tất nhập kho',
            '/Post Issue/i' => 'Hoàn tất xuất kho',
            '/Packing Label/i' => 'Nhãn đóng gói',
            '/reverse_document/i' => 'Điều chỉnh trực tiếp chứng từ',
            '/close_remainder/i' => 'Đóng phần còn lại',
            '/outside the latest MVP baseline/i' => 'không thuộc quy trình MVP hiện hành',
            '/\bnot found\b/i' => 'không tìm thấy',
            '/\balready exists\b/i' => 'đã tồn tại',
            '/\bdoes not exist\b/i' => 'không tồn tại',
            '/\bis required\b/i' => 'là bắt buộc',
            '/\bmust be\b/i' => 'phải là',
            '/\bmust have\b/i' => 'phải có',
            '/\bcannot\b/i' => 'không thể',
            '/\bnot allowed\b/i' => 'không được phép',
            '/\bpermission denied\b/i' => 'không có quyền',
            '/\baccess denied\b/i' => 'không có quyền truy cập',
            '/\binvalid\b/i' => 'không hợp lệ',
            '/\bduplicate\b/i' => 'trùng lặp',
            '/\bReversal\b/i' => 'điều chỉnh tồn kho',
            '/\bupdate existing\b/i' => 'cho phép cập nhật dữ liệu hiện có',
            '/\bdry run\b/i' => 'kiểm tra trước',
            '/\bsku code\b/i' => 'mã SKU',
            '/\bsku name\b/i' => 'tên SKU',
            '/\bdst warehouse id\b/i' => 'kho đích',
            '/\bsrc warehouse id\b/i' => 'kho nguồn',
            '/\bwarehouse id\b/i' => 'kho',
            '/\bproduct id\b/i' => 'sản phẩm',
            '/\bitem instance id\b/i' => 'sản phẩm cụ thể',
            '/\bqty planned\b/i' => 'số lượng dự kiến',
            '/\bdoc no\b/i' => 'mã chứng từ',
            '/\bdoc date\b/i' => 'ngày chứng từ',
            '/\bquantity\b/i' => 'số lượng',
            '/\breason\b/i' => 'lý do',
            '/\bstatus\b/i' => 'trạng thái',
            '/\bphone\b/i' => 'số điện thoại',
            '/\bpassword\b/i' => 'mật khẩu',
            '/\busername\b/i' => 'tài khoản',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $localized = preg_replace($pattern, $replacement, $localized) ?? $localized;
        }

        foreach (self::STATUS_LABELS as $raw => $label) {
            $localized = preg_replace('/\b'.preg_quote($raw, '/').'\b/u', $label, $localized) ?? $localized;
        }

        // Package/legacy có thể trả một câu tiếng Anh chưa nằm trong bảng trên. Không
        // để câu đó lọt lên UI: giữ error_code/field key cho máy, còn người dùng nhận
        // message Việt fail-closed. Các acronym/mã kỹ thuật (SKU/RFID/error_code...) tự
        // chúng không kích hoạt vì biểu thức chỉ tìm từ/cấu trúc câu tiếng Anh phổ biến.
        if (self::stillLooksEnglish($localized)) {
            return self::preserveWhitespace(
                $message,
                'Không thể thực hiện yêu cầu do dữ liệu hoặc trạng thái hiện tại không hợp lệ.'
            );
        }

        return $localized;
    }

    /**
     * Việt hóa đệ quy value trong validation details nhưng giữ nguyên key field để
     * FE vẫn map lỗi đúng input và contract API không đổi.
     */
    public static function translateDetails(mixed $details): mixed
    {
        if (is_string($details)) {
            return self::translate($details);
        }

        if (! is_array($details)) {
            return $details;
        }

        $localized = [];
        foreach ($details as $key => $value) {
            $localized[$key] = self::translateDetails($value);
        }

        return $localized;
    }

    /**
     * "permission"/"product" bị loại khỏi danh sách kích hoạt: cả hai đều là từ mượn
     * tiếng Anh dùng phổ biến TRONG câu tiếng Việt hợp lệ của chính codebase này (vd
     * "Gán permission cho role thành công", "Tạo product master thành công") — giữ
     * chúng trong regex khiến message THÀNH CÔNG hợp lệ bị thay nhầm bằng câu lỗi
     * chung, làm UI báo sai kết quả dù request đã xử lý đúng (phát hiện 26/08 tại
     * RoleController::assignPermissions). Một câu tiếng Anh thật sự bỏ sót vẫn gần
     * như chắc chắn dính 1 trong các từ chức năng còn lại (the/must/field/error...).
     */
    private static function stillLooksEnglish(string $message): bool
    {
        return preg_match(
            '/\b(the|field|must|should|cannot|failed|failure|required|invalid|only|when|without|already|exists|document|quantity|warehouse|unauthorized|forbidden|server|error)\b/i',
            $message
        ) === 1;
    }

    private static function preserveWhitespace(string $source, string $replacement): string
    {
        preg_match('/^\s*/u', $source, $leading);
        preg_match('/\s*$/u', $source, $trailing);

        return ($leading[0] ?? '').$replacement.($trailing[0] ?? '');
    }
}
