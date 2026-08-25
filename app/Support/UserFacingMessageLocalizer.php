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
        ];

        foreach ($replacements as $pattern => $replacement) {
            $localized = preg_replace($pattern, $replacement, $localized) ?? $localized;
        }

        foreach (self::STATUS_LABELS as $raw => $label) {
            $localized = preg_replace('/\b'.preg_quote($raw, '/').'\b/u', $label, $localized) ?? $localized;
        }

        return $localized;
    }

    private static function preserveWhitespace(string $source, string $replacement): string
    {
        preg_match('/^\s*/u', $source, $leading);
        preg_match('/\s*$/u', $source, $trailing);

        return ($leading[0] ?? '').$replacement.($trailing[0] ?? '');
    }
}
