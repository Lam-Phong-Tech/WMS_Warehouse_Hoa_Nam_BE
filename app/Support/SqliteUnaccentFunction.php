<?php

namespace App\Support;

use Illuminate\Database\Connection;

/**
 * Đăng ký hàm `hn_unaccent(text)` cho kết nối SQLite.
 *
 * PostgreSQL bỏ dấu bằng `translate()` dựng sẵn; SQLite không có hàm tương
 * đương. Nếu để SQLite dùng `lower()` suông thì tìm kiếm không dấu chỉ đúng
 * trên PostgreSQL, còn bộ test (chạy sqlite in-memory) sẽ XANH mà không thật sự
 * chứng minh được gì — đúng loại lỗ hổng khiến bug lọt lên production.
 *
 * Vì vậy SQLite được cấp một UDF gọi thẳng vào VietnameseText::fold(), tức là
 * cùng một bảng ánh xạ ký tự mà PostgreSQL dùng qua translate(). Hai driver cho
 * kết quả giống hệt nhau, và test có giá trị chứng minh.
 */
final class SqliteUnaccentFunction
{
    public const NAME = 'hn_unaccent';

    /** @var array<string, true> tên connection đã đăng ký, tránh gọi lại mỗi query */
    private static array $registered = [];

    private function __construct() {}

    public static function ensureRegistered(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        $name = $connection->getName() ?? 'sqlite';
        if (isset(self::$registered[$name])) {
            return;
        }

        $pdo = $connection->getPdo();
        if (! method_exists($pdo, 'sqliteCreateFunction')) {
            return;
        }

        $pdo->sqliteCreateFunction(
            self::NAME,
            static fn (?string $value): string => VietnameseText::fold($value),
            1,
        );

        self::$registered[$name] = true;
    }

    /**
     * RefreshDatabase dựng lại CSDL in-memory giữa các test, kèm PDO mới, nên
     * cache đăng ký phải xoá được — nếu không, test thứ hai trở đi sẽ gọi một
     * hàm chưa tồn tại trên PDO mới.
     */
    public static function forget(): void
    {
        self::$registered = [];
    }
}
