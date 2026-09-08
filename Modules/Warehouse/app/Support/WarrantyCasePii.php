<?php

namespace Modules\Warehouse\Support;

use App\Support\Security\BlindIndex;
use App\Support\Security\PiiCrypto;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WWHN-SEC-06 — mã hoá PII của hồ sơ bảo hành.
 *
 * VÌ SAO LÀ MỘT HELPER CHỨ KHÔNG PHẢI CAST CỦA ELOQUENT
 *
 * `warranty_case` KHÔNG phải bảng: nó là VIEW trên `warranty_cases` với trigger
 * `INSTEAD OF INSERT/UPDATE/DELETE`, và gần như toàn bộ luồng bảo hành đọc/ghi
 * bằng `DB::table('warranty_case')` chứ không qua model. Cast/mutator của
 * Eloquent do đó KHÔNG chạy: thêm cast vào model `WarrantyCase` sẽ không mã hoá
 * lúc ghi và trả ciphertext thô cho lớp mask lúc đọc — hỏng im lặng, đúng kiểu
 * lỗi tệ nhất cho một lớp bảo mật.
 *
 * Vì vậy quy ước là: **mọi** truy vấn chạm tới ba cột PII phải đi qua đúng ba
 * hàm dưới đây.
 *
 *   ghi   -> WarrantyCasePii::encode($payload)
 *   đọc   -> WarrantyCasePii::decodeRow($row) / ::decodeRows($rows)
 *   select-> WarrantyCasePii::selectColumns($alias)  (thay cho 3 cột plaintext)
 *   tìm   -> WarrantyCasePii::applyKeyword($query, $keyword)
 *
 * Test `WwhnSec06WarrantyPiiTest::test_khong_con_truy_van_nao_doc_cot_plaintext`
 * quét mã nguồn để hàng rào này không bị bỏ quên ở lần thêm màn hình sau.
 *
 * ------------------------------------------------------------------------
 * TÌM KIẾM ĐỔI NGỮ NGHĨA — ĐỌC KỸ TRƯỚC KHI SỬA MÀN WAR-01
 * ------------------------------------------------------------------------
 *
 * Trước: `LIKE '%kw%'` trên cả customer_name / customer_phone / customer_address.
 * Sau:
 *   - SĐT   : khớp CHÍNH XÁC sau chuẩn hoá (mọi cách gõ +84/84/0/space đều ra).
 *             Gõ mảnh số ("345") KHÔNG còn khớp.
 *   - Tên   : khớp TRỌN TỪ, không dấu ("nguyen" ra "Nguyễn"). Gõ giữa từ
 *             ("guyen") KHÔNG còn khớp.
 *   - Địa chỉ: KHÔNG còn tìm được. Đây là cột có giá trị tra cứu thấp nhất và
 *             là cột duy nhất không thể vừa mã hoá vừa tìm gần đúng mà không
 *             dựng thêm một lớp index rò rỉ nhiều hơn phần bảo vệ nó mang lại.
 */
final class WarrantyCasePii
{
    /** Tên thuộc tính ứng dụng dùng => cột ciphertext trong CSDL. */
    public const CIPHER_COLUMNS = [
        'customer_name' => 'customer_name_ciphertext',
        'customer_phone' => 'customer_phone_ciphertext',
        'customer_address' => 'customer_address_ciphertext',
    ];

    public const LOOKUP_COLUMNS = [
        'customer_phone' => 'customer_phone_lookup',
        'customer_name' => 'customer_name_lookup',
    ];

    /**
     * AAD gắn ciphertext vào đúng ô của nó. Dùng tên BẢNG GỐC (`warranty_cases`)
     * chứ không phải tên view, vì đó mới là nơi dữ liệu thật nằm và là thứ không
     * đổi nếu lớp view legacy bị gỡ sau này.
     */
    private const CONTEXT = [
        'customer_name' => 'warranty_cases.customer_name',
        'customer_phone' => 'warranty_cases.customer_phone',
        'customer_address' => 'warranty_cases.customer_address',
    ];

    /**
     * Tên BẢNG THẬT chứa dữ liệu hồ sơ bảo hành trên driver đang chạy.
     *
     * Hai driver có bố cục NGƯỢC NHAU và đây là nguồn của một lớp lỗi rất khó
     * nhìn ra (ALTER/UPDATE chạy vào view rồi thất bại với thông báo lạ):
     *
     *   PostgreSQL : bảng `warranty_cases`, view `warranty_case`  (+ trigger INSTEAD OF)
     *   sqlite/test: bảng `warranty_case`,  view `warranty_cases` (alias legacy)
     *
     * `Schema::hasTable()` trả true cho CẢ view, nên không dùng nó để phân biệt
     * được — phải hỏi catalog của từng driver.
     */
    public static function baseTable(): string
    {
        foreach (['warranty_cases', 'warranty_case'] as $candidate) {
            if (Schema::hasTable($candidate) && ! self::isView($candidate)) {
                return $candidate;
            }
        }

        return 'warranty_case';
    }

    public static function isView(string $relation): bool
    {
        $connection = DB::connection();

        return match ($connection->getDriverName()) {
            'pgsql' => $connection->selectOne(
                'select 1 as found from information_schema.views where table_schema = current_schema() and table_name = ?',
                [$relation],
            ) !== null,
            'sqlite' => $connection->selectOne(
                "select 1 as found from sqlite_master where type = 'view' and name = ?",
                [$relation],
            ) !== null,
            default => false,
        };
    }

    /**
     * Biến payload ghi (có customer_name/phone/address dạng rõ) thành payload
     * chỉ chứa ciphertext + blind index.
     *
     * Bỏ hẳn ba khoá plaintext khỏi kết quả: nếu để lại, một cột plaintext còn
     * tồn tại ở giai đoạn chuyển tiếp sẽ tiếp tục được ghi và việc mã hoá thành
     * vô nghĩa.
     *
     * Khoá KHÔNG có mặt trong `$payload` thì cũng không có trong kết quả — điều
     * này quan trọng với UPDATE bộ phận: `update(['status' => ...])` không được
     * vô tình xoá trắng PII của hồ sơ.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function encode(array $payload): array
    {
        foreach (self::CIPHER_COLUMNS as $plain => $cipherColumn) {
            if (! array_key_exists($plain, $payload)) {
                continue;
            }

            $value = $payload[$plain];
            $value = $value === null ? null : (string) $value;

            $payload[$cipherColumn] = PiiCrypto::encrypt($value, self::CONTEXT[$plain]);

            if ($plain === 'customer_phone') {
                $payload[self::LOOKUP_COLUMNS['customer_phone']] = BlindIndex::phone($value);
            }

            if ($plain === 'customer_name') {
                $payload[self::LOOKUP_COLUMNS['customer_name']] = BlindIndex::nameTokens($value);
            }

            unset($payload[$plain]);
        }

        return $payload;
    }

    /**
     * Cột cần đưa vào mệnh đề SELECT tường minh, thay cho ba cột plaintext cũ.
     *
     * @return array<int, string>
     */
    public static function selectColumns(string $alias = ''): array
    {
        $prefix = $alias === '' ? '' : rtrim($alias, '.').'.';

        return [
            $prefix.'customer_name_ciphertext',
            $prefix.'customer_phone_ciphertext',
            $prefix.'customer_address_ciphertext',
        ];
    }

    /**
     * Giải mã một hàng đọc ra, gán lại vào đúng tên thuộc tính cũ để mọi lớp
     * phía sau (mask PII, export, DTO) không phải biết gì về việc mã hoá.
     *
     * Fail-soft CÓ CHỦ ĐÍCH ở đây (khác với PiiCrypto::decrypt vốn fail-closed):
     * một hồ sơ có ciphertext hỏng không được làm sập cả trang danh sách 50 hồ
     * sơ. Giá trị trả về là null và sự cố toàn vẹn được phát hiện bằng
     * `pii:warranty-cases --verify`, không phải bằng một trang 500.
     */
    public static function decodeRow(mixed $row): mixed
    {
        if ($row === null) {
            return null;
        }

        $isArray = is_array($row);

        foreach (self::CIPHER_COLUMNS as $plain => $cipherColumn) {
            $cipher = $isArray ? ($row[$cipherColumn] ?? null) : ($row->{$cipherColumn} ?? null);

            if ($cipher === null && ! ($isArray ? array_key_exists($cipherColumn, $row) : property_exists($row, $cipherColumn))) {
                // Truy vấn không lấy cột này — đừng bịa ra thuộc tính rỗng.
                continue;
            }

            try {
                $value = PiiCrypto::decrypt($cipher === null ? null : (string) $cipher, self::CONTEXT[$plain]);
            } catch (\Throwable) {
                $value = null;
            }

            if ($isArray) {
                $row[$plain] = $value;
            } else {
                $row->{$plain} = $value;
            }
        }

        // Gỡ cột kỹ thuật khỏi hàng sau khi đã giải mã.
        //
        // KHÔNG CHỈ LÀ DỌN DẸP: `tablePage()` trả nguyên hàng ra response, nên để
        // lại `customer_phone_lookup` là gửi thẳng vân tay HMAC cho trình duyệt.
        // Vân tay là TẤT ĐỊNH — client giữ nó có thể đối chiếu hai hồ sơ bất kỳ
        // để biết "cùng một khách", đúng thứ mà lớp mask §13.8 đang cố che.
        foreach (array_merge(array_values(self::CIPHER_COLUMNS), array_values(self::LOOKUP_COLUMNS)) as $technical) {
            if ($isArray) {
                unset($row[$technical]);
            } elseif (property_exists($row, $technical)) {
                unset($row->{$technical});
            }
        }

        return $row;
    }

    /**
     * @param  iterable<int, mixed>  $rows
     */
    public static function decodeRows(iterable $rows): void
    {
        foreach ($rows as $row) {
            // Object đi theo tham chiếu nên gán thuộc tính ở decodeRow là đủ.
            self::decodeRow($row);
        }
    }

    /**
     * Nhánh OR tìm kiếm theo PII, thay cho `LIKE '%kw%'` trên cột plaintext.
     *
     * Gọi bên trong một `where(function ($nested) {...})` đã có sẵn để OR không
     * tràn ra ngoài và vô hiệu các filter status/identification_status.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public static function applyKeyword($query, string $keyword, string $alias = ''): void
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return;
        }

        $prefix = $alias === '' ? '' : rtrim($alias, '.').'.';

        foreach (BlindIndex::phoneCandidates($keyword) as $candidate) {
            $query->orWhere($prefix.self::LOOKUP_COLUMNS['customer_phone'], '=', $candidate);
        }

        $nameGroups = BlindIndex::nameTokenCandidateGroups($keyword);

        if ($nameGroups !== []) {
            // AND giữa các TỪ, OR giữa các phiên bản KHOÁ của cùng một từ.
            //
            // Gõ đủ "Ngô Thị Hường" phải HẸP kết quả lại. OR phẳng toàn bộ vân tay
            // sẽ làm điều ngược lại: càng gõ nhiều từ càng ra nhiều người (ai tên
            // "Ngô", hoặc "Thị", hoặc "Hường"), trái hẳn hành vi LIKE cũ.
            $lookupColumn = $prefix.self::LOOKUP_COLUMNS['customer_name'];

            $query->orWhere(function ($group) use ($nameGroups, $lookupColumn): void {
                foreach ($nameGroups as $candidates) {
                    $group->where(function ($anyKeyVersion) use ($candidates, $lookupColumn): void {
                        foreach ($candidates as $candidate) {
                            // LIKE trên chuỗi vân tay nối bằng dấu cách. Vân tay là
                            // hex 64 ký tự nên không có nguy cơ vân tay này là chuỗi
                            // con của vân tay khác.
                            $anyKeyVersion->orWhere($lookupColumn, 'like', '%'.$candidate.'%');
                        }
                    });
                }
            });
        }
    }
}
