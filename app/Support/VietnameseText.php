<?php

namespace App\Support;

/**
 * Chuẩn hoá chuỗi tiếng Việt cho tìm kiếm không dấu, không phân biệt hoa/thường.
 *
 * Hợp đồng App PV yêu cầu `GET /api/v1/public/products?q=` phải trim, bỏ qua
 * hoa/thường VÀ bỏ dấu. Cách duy nhất cho kết quả GIỐNG NHAU giữa PHP và SQL là
 * hai bên dùng CHUNG một bảng ánh xạ ký tự — nên bảng đó chỉ tồn tại ở đây:
 * PHP gọi fold(), SQL gọi translate(col, ACCENTED, PLAIN) với đúng hai chuỗi
 * lấy từ accentedChars()/plainChars(). Sửa một bên mà quên bên kia là nguồn bug
 * kinh điển "gõ có dấu ra kết quả, gõ không dấu thì không".
 *
 * Cố ý KHÔNG dùng extension `unaccent` của PostgreSQL: nó chưa được cài trên
 * CSDL này và CREATE EXTENSION đòi quyền superuser, trong khi translate() là
 * hàm dựng sẵn và xử lý UTF-8 đúng.
 */
final class VietnameseText
{
    /**
     * Cặp (có dấu -> không dấu). Thứ tự và độ dài hai chuỗi phải khớp từng ký tự.
     * Chỉ liệt kê chữ THƯỜNG vì mọi lời gọi đều lower() trước.
     */
    private const ACCENTED = 'áàảãạăắằẳẵặâấầẩẫậđéèẻẽẹêếềểễệíìỉĩịóòỏõọôốồổỗộơớờởỡợúùủũụưứừửữựýỳỷỹỵ';

    private const PLAIN = 'aaaaaaaaaaaaaaaaadeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyy';

    private function __construct() {}

    /**
     * Chuỗi ký tự có dấu, dùng làm tham số thứ 2 của SQL translate().
     */
    public static function accentedChars(): string
    {
        return self::ACCENTED;
    }

    /**
     * Chuỗi ký tự không dấu, dùng làm tham số thứ 3 của SQL translate().
     */
    public static function plainChars(): string
    {
        return self::PLAIN;
    }

    /**
     * Hạ hoa/thường, bỏ dấu, gom khoảng trắng thừa. Đây là dạng chuẩn dùng để so
     * khớp; KHÔNG dùng để hiển thị.
     */
    public static function fold(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = mb_strtolower($value, 'UTF-8');

        $accented = self::splitChars(self::ACCENTED);
        $plain = self::splitChars(self::PLAIN);
        $value = str_replace($accented, $plain, $value);

        // Nhiều khoảng trắng liên tiếp (kể cả tab/newline người dùng dán vào) chỉ
        // nên là một dấu cách, nếu không "máy  bơm" sẽ không khớp "máy bơm".
        return (string) preg_replace('/\s+/u', ' ', $value);
    }

    /**
     * Escape các ký tự đặc biệt của LIKE để người dùng gõ `%` hay `_` không biến
     * truy vấn thành ký tự đại diện quét toàn bảng.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return array<int, string>
     */
    private static function splitChars(string $value): array
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return $chars === false ? [] : $chars;
    }

    /**
     * Bảo vệ hằng số khỏi lệch độ dài khi ai đó thêm ký tự vào một trong hai
     * chuỗi — translate() của PostgreSQL sẽ im lặng cắt bớt thay vì báo lỗi.
     */
    public static function assertMapIsConsistent(): void
    {
        $accented = mb_strlen(self::ACCENTED, 'UTF-8');
        $plain = mb_strlen(self::PLAIN, 'UTF-8');

        if ($accented !== $plain) {
            throw new \LogicException(
                "VietnameseText: bảng dấu lệch độ dài (có dấu {$accented} ký tự, không dấu {$plain} ký tự)."
            );
        }
    }
}
