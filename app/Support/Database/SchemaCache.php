<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\Schema;

/**
 * Bộ nhớ đệm cho câu hỏi "bảng/cột này có tồn tại không", trong PHẠM VI MỘT
 * TIẾN TRÌNH PHP.
 *
 * VÌ SAO CẦN: `Schema::hasColumn()` của Laravel KHÔNG nhớ gì cả — mỗi lần gọi là
 * một truy vấn vào catalog của PostgreSQL (`pg_attribute`/`pg_class`/`pg_type`,
 * kèm subselect sang `pg_collation`). Repo này gọi nó 115 chỗ trong đường chạy
 * request, dày nhất đúng ở hai service nóng nhất: ScanClassificationService (16)
 * và CurrentBaselineStockService (15) — luồng quét nhập/xuất, nơi người vận hành
 * đứng chờ.
 *
 * ĐO ĐƯỢC (docker local, cold cache, GET /api/v1/warranty-cases):
 *   5 truy vấn catalog = 8.7 ms / 18.8 ms TỔNG thời gian SQL của request — 46%
 *   thời gian SQL không mang một byte dữ liệu nghiệp vụ nào.
 *
 * CÁCH LÀM: nhớ NGUYÊN danh sách cột của bảng ở lần hỏi đầu tiên (một truy vấn),
 * các câu hỏi sau tra trong bộ nhớ. n cột → 1 truy vấn thay vì n.
 *
 * VÌ SAO AN TOÀN: schema không đổi GIỮA CHỪNG một request HTTP. Chỗ duy nhất nó
 * đổi trong cùng tiến trình là khi chạy migration (artisan, và test dựng lại DB)
 * — nên `AppServiceProvider` gắn listener xoá sạch bộ nhớ đệm này ở mọi sự kiện
 * migration/refresh. Bộ nhớ đệm nằm trong RAM tiến trình, KHÔNG xuống Valkey:
 * không có chuyện một tiến trình cũ phát tán schema cũ cho tiến trình mới sau khi
 * deploy.
 *
 * Khoá theo tên connection: bộ test đổi qua sqlite trong khi đường chạy thật là
 * pgsql, hai schema đó không được lẫn vào nhau.
 */
final class SchemaCache
{
    /** @var array<string, list<string>> "<connection>|<table>" => danh sách cột (rỗng nếu bảng không có) */
    private static array $columns = [];

    /** @var array<string, bool> "<connection>|<table>" => bảng có tồn tại không */
    private static array $tables = [];

    /** @var array<string, bool> "<connection>|<view>" => view có tồn tại không */
    private static array $views = [];

    /** @var array<string, list<string>> "<connection>|<quan hệ>" => danh sách cột, tính CẢ view */
    private static array $relationColumns = [];

    private function __construct() {}

    public static function hasTable(string $table, ?string $connection = null): bool
    {
        $key = self::key($table, $connection);
        if (! array_key_exists($key, self::$tables)) {
            self::$tables[$key] = Schema::connection($connection)->hasTable($table);
        }

        return self::$tables[$key];
    }

    /**
     * ERD của dự án dùng view cho một số hình chiếu legacy, nên nhiều chỗ hỏi
     * "bảng HOẶC view này có không". `Schema::hasView()` cũng là một truy vấn
     * catalog mỗi lần gọi — 13 chỗ, nằm đúng trong ScanClassificationService và
     * CurrentBaselineStockService.
     */
    public static function hasView(string $view, ?string $connection = null): bool
    {
        $key = self::key($view, $connection);
        if (! array_key_exists($key, self::$views)) {
            self::$views[$key] = Schema::connection($connection)->hasView($view);
        }

        return self::$views[$key];
    }

    public static function hasColumn(string $table, string $column, ?string $connection = null): bool
    {
        return in_array(
            strtolower($column),
            self::columns($table, $connection),
            true,
        );
    }

    /**
     * Danh sách cột (đã hạ chữ thường) của bảng. Bảng không tồn tại trả mảng rỗng
     * — cùng ngữ nghĩa với `Schema::hasColumn()` trên bảng không có.
     *
     * @return list<string>
     */
    public static function columns(string $table, ?string $connection = null): array
    {
        $key = self::key($table, $connection);
        if (! array_key_exists($key, self::$columns)) {
            $builder = Schema::connection($connection);
            self::$columns[$key] = $builder->hasTable($table)
                ? array_map('strtolower', $builder->getColumnListing($table))
                : [];
            // Đã biết bảng có hay không rồi thì đừng hỏi lại lần nữa.
            self::$tables[$key] = self::$columns[$key] !== [] || $builder->hasTable($table);
        }

        return self::$columns[$key];
    }

    /**
     * Như `columns()` nhưng ĐÚNG cả khi quan hệ là VIEW.
     *
     * BẪY: trên PostgreSQL, `Schema::hasTable()` của Laravel chỉ liệt kê BASE
     * TABLE — với view nó trả FALSE, nên `columns()` kết luận "quan hệ này
     * không có cột nào". Mọi đoạn dạng
     *
     *     if (SchemaCache::hasColumn($rel, $c)) { $query->where($c, ...); }
     *
     * chạy trên một view vì thế BỊ BỎ QUA IM LẶNG: không lỗi, không log, chỉ là
     * bộ lọc biến mất và người dùng nhận về dữ liệu chưa lọc. sqlite (bộ test)
     * lại trả true cho view nên lớp test không bao giờ chạm tới lỗi này — đúng
     * cách nó sống sót tới lúc lọc "Nhận diện"/"Bệnh/lỗi" và tìm theo mã hồ sơ
     * ở WAR-01 cùng chết trên môi trường thật.
     *
     * ERD dự án chiếu khá nhiều bảng qua view (`warranty_case`, `sku`,
     * `products`, `scan_event`, `container`...), nên chỗ nào hỏi theo TÊN QUAN
     * HỆ ĐANG TRUY VẤN chứ không theo bảng gốc thì phải dùng hàm này.
     *
     * `hasColumn()`/`columns()` giữ nguyên ngữ nghĩa "bảng thật" — có chỗ dựa
     * vào đó để phân biệt bảng với view, đổi chúng là đổi ngầm 61 điểm gọi.
     *
     * @return list<string>
     */
    public static function relationColumns(string $relation, ?string $connection = null): array
    {
        $key = self::key($relation, $connection);
        if (! array_key_exists($key, self::$relationColumns)) {
            // Bảng thật đi qua cache sẵn có -> view mới phải hỏi thêm catalog.
            $columns = self::columns($relation, $connection);
            if ($columns === [] && self::hasView($relation, $connection)) {
                $columns = array_map('strtolower', Schema::connection($connection)->getColumnListing($relation));
            }
            self::$relationColumns[$key] = $columns;
        }

        return self::$relationColumns[$key];
    }

    public static function hasRelationColumn(string $relation, string $column, ?string $connection = null): bool
    {
        return in_array(
            strtolower($column),
            self::relationColumns($relation, $connection),
            true,
        );
    }

    /** Gọi khi schema thay đổi thật (migration, refresh DB trong test). */
    public static function flush(): void
    {
        self::$columns = [];
        self::$tables = [];
        self::$relationColumns = [];
        self::$views = [];
    }

    private static function key(string $table, ?string $connection): string
    {
        return ($connection ?? Schema::getConnection()->getName()).'|'.strtolower($table);
    }
}
