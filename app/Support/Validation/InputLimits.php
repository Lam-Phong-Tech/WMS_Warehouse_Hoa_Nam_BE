<?php

namespace App\Support\Validation;

/**
 * NGƯỠNG NHẬP LIỆU DÙNG CHUNG — trần/sàn cho mọi rule số và ngày của API.
 *
 * VÌ SAO TỒN TẠI: trước đây không một rule số nào của dự án có TRẦN TRÊN.
 * `'numeric', 'min:0'` chặn số âm nhưng nhận `10000000000000` (phản hồi người
 * dùng 05/09/2026). FE đã chặn ở `src/shared/validation/number-rules.ts`, nhưng
 * trình duyệt là lớp trải nghiệm chứ không phải lớp bảo vệ: gọi thẳng API bằng
 * Postman, bằng app quét, hay bằng một đoạn script thì giá trị vô lý vẫn ghi
 * được xuống DB. Trần THẬT phải nằm ở đây.
 *
 * ĐỒNG BỘ VỚI FE: các hằng dưới đây phải khớp `NUMBER_LIMITS` và `DATE_LIMITS`
 * của FE. Lệch nhau thì hoặc FE chặn oan giá trị BE chấp nhận, hoặc BE nhận thứ
 * FE không bao giờ gửi được — cả hai đều làm người dùng không hiểu chuyện gì.
 *
 * CƠ SỞ CHỌN SỐ:
 *  - Cột số lượng trong DB là `decimal(18,3)`. Trần 1.000.000 CHẶT HƠN DB rất
 *    nhiều — có chủ đích: đây là kho máy nông nghiệp đếm theo CÁI, không kho nào
 *    giữ tới một triệu hiện vật cùng một SKU. Trần chặt bắt lỗi gõ nhầm sớm.
 *  - Ngày nghiệp vụ không có dữ liệu trước 2000 và không có kế hoạch quá 10 năm.
 */
final class InputLimits
{
    private function __construct() {}

    /** Số lượng hiện vật / định mức tồn. Khớp NUMBER_LIMITS.quantityMax của FE. */
    public const QUANTITY_MAX = 1000000;

    /** Số chữ số thập phân của số lượng — khớp decimal(18,3) của DB. */
    public const QUANTITY_DECIMALS = 3;

    /**
     * Đơn giá. Nới hơn số lượng vì tiền Việt có mệnh giá lớn: một máy gặt vài
     * trăm triệu là bình thường. 999.999.999.999 = gần một nghìn tỷ đồng, đủ xa
     * cho mọi chứng từ thật mà vẫn chặn được số vô nghĩa.
     */
    public const MONEY_MAX = 999999999999;

    /** Số chữ số thập phân của tiền. */
    public const MONEY_DECIMALS = 2;

    /**
     * Bộ đếm phiên bản (optimistic locking). Trần = int 4 byte của Postgres —
     * đây là ràng buộc KỸ THUẬT chứ không phải nghiệp vụ, vượt là tràn cột.
     */
    public const VERSION_MAX = 2147483647;

    /** Thứ tự hiển thị / độ ưu tiên. Khớp NUMBER_LIMITS.sortOrderMax của FE. */
    public const SORT_ORDER_MAX = 9999;

    /** Số bản ghi mỗi trang. */
    public const PER_PAGE_MAX = 100;

    /** Số ngày (thời hạn bảo hành, hạn xử lý): 10 năm. */
    public const DAYS_MAX = 3650;

    /** Ngày nghiệp vụ sớm nhất — hệ thống không có dữ liệu trước mốc này. */
    public const DATE_MIN = '2000-01-01';

    /** Số năm tối đa được phép đặt lịch về tương lai. */
    public const FUTURE_MAX_YEARS = 10;

    /**
     * Rule cho một ô SỐ LƯỢNG (nguyên hoặc thập phân 3 chữ số).
     *
     * @return list<string>
     */
    public static function quantity(float|int $min = 0): array
    {
        return [
            'numeric',
            'min:'.$min,
            'max:'.self::QUANTITY_MAX,
            'decimal:0,'.self::QUANTITY_DECIMALS,
        ];
    }

    /**
     * Rule cho một ô TIỀN.
     *
     * @return list<string>
     */
    public static function money(float|int $min = 0): array
    {
        return [
            'numeric',
            'min:'.$min,
            'max:'.self::MONEY_MAX,
            'decimal:0,'.self::MONEY_DECIMALS,
        ];
    }

    /** Mốc muộn nhất cho ngày ĐÃ XẢY RA (ngày chứng từ, ngày mua). */
    public static function pastDateMax(): string
    {
        return now()->toDateString();
    }

    /** Mốc muộn nhất cho ngày KẾ HOẠCH (hạn dự kiến). */
    public static function futureDateMax(): string
    {
        return now()->addYears(self::FUTURE_MAX_YEARS)->toDateString();
    }

    /**
     * Rule cho một ô NGÀY trong cửa sổ nghiệp vụ chung (2000 → hôm nay+10 năm).
     *
     * @return list<string>
     */
    public static function date(): array
    {
        return [
            'date',
            'after_or_equal:'.self::DATE_MIN,
            'before_or_equal:'.self::futureDateMax(),
        ];
    }

    /**
     * Rule cho một ô NGÀY ĐÃ XẢY RA — không được ở tương lai.
     *
     * @return list<string>
     */
    public static function pastDate(): array
    {
        return [
            'date',
            'after_or_equal:'.self::DATE_MIN,
            'before_or_equal:'.self::pastDateMax(),
        ];
    }
}
