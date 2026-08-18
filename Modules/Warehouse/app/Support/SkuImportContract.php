<?php

namespace Modules\Warehouse\Support;

use Modules\Auth\Models\User;

/**
 * WWHN-331 FE331-P1-02R3 / P1-03R3 / P1-06R3 — hợp đồng import SKU đọc từ
 * `config/warehouse.php` (`warehouse.sku_import`).
 *
 * Trước đây bốn giá trị 1000 dòng / 10MB / 24h idempotency / 24h hạn file lỗi nằm
 * rải rác thành hằng số riêng ở ImportService, ImportController và
 * ImportErrorFileService, mỗi nơi kèm một ghi chú "giá trị tạm" — rồi FE viết lại
 * đúng những con số đó vào một Alert cho người dùng đọc. Ba hệ quả: đổi hạn mức phải
 * sửa nhiều chỗ và dễ lệch; API không nói ra hạn mức nên FE buộc phải hard-code; và
 * người dùng cuối nhìn thấy chú thích "chưa chốt chính thức" của nội bộ dev.
 *
 * Lớp này là nơi duy nhất đọc config đó và cũng là nơi duy nhất dựng khối
 * `constraints` mà API trả ra, nên hạn mức máy chủ đang áp dụng và hạn mức UI hiển
 * thị không thể lệch nhau.
 */
final class SkuImportContract
{
    /** Số dòng dữ liệu tối đa mỗi lần import (không tính header). */
    public static function maxRows(): int
    {
        return max(1, (int) config('warehouse.sku_import.max_rows', 1000));
    }

    /** Kích thước upload tối đa tính bằng KB — dùng thẳng cho rule `max:` của validator. */
    public static function maxFileSizeKb(): int
    {
        return max(1, (int) config('warehouse.sku_import.max_file_size_kb', 10240));
    }

    /** @return array<int, string> */
    public static function allowedExtensions(): array
    {
        $extensions = config('warehouse.sku_import.allowed_extensions', ['xlsx', 'xls']);

        return is_array($extensions) && $extensions !== []
            ? array_values(array_map('strval', $extensions))
            : ['xlsx', 'xls'];
    }

    /** Số giờ file lỗi CSV còn tải được kể từ lúc sinh. */
    public static function errorFileExpiryHours(): int
    {
        return max(1, (int) config('warehouse.sku_import.error_file_expiry_hours', 24));
    }

    /** Cửa sổ coi 2 lần COMMIT trùng định danh là replay. */
    public static function idempotencyWindowHours(): int
    {
        return max(1, (int) config('warehouse.sku_import.idempotency_window_hours', 24));
    }

    /**
     * FE331-P1-02R3: vân tay của các tùy chọn tham gia ĐỊNH DANH một lần import.
     *
     * Chuỗi chuẩn hoá trước khi băm là `update_existing=0` hoặc `update_existing=1`
     * (ghi rõ trong DEC-IMP-001 để QA tự dựng lại được giá trị mà không cần đọc code).
     * Băm thay vì lưu thẳng để cột không phải đổi kiểu khi sau này định danh có thêm
     * tùy chọn mới — lúc đó chỉ nối thêm cặp key=value theo thứ tự alphabet.
     */
    public static function optionsFingerprint(bool $updateExisting): string
    {
        return hash('sha256', self::canonicalOptions($updateExisting));
    }

    /** Chuỗi chuẩn hoá đứng trước phép băm — phơi ra để test/evidence đối chiếu được. */
    public static function canonicalOptions(bool $updateExisting): string
    {
        return 'update_existing='.($updateExisting ? '1' : '0');
    }

    /**
     * Khối `constraints` trả ra cho client. UI hiển thị đúng những số này thay vì tự
     * viết lại hằng số của riêng nó.
     *
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'decision_ref' => (string) config('warehouse.sku_import.decision_ref', 'DEC-IMP-001'),
            'max_rows' => self::maxRows(),
            'max_file_size_kb' => self::maxFileSizeKb(),
            'max_file_size_mb' => round(self::maxFileSizeKb() / 1024, 2),
            'allowed_extensions' => self::allowedExtensions(),
            'error_file_expiry_hours' => self::errorFileExpiryHours(),
            'idempotency_window_hours' => self::idempotencyWindowHours(),
            'idempotency_identity' => self::idempotencyIdentity(),
        ];
    }

    /** @return array<int, string> */
    public static function idempotencyIdentity(): array
    {
        $identity = config('warehouse.sku_import.idempotency_identity', ['file_sha256', 'actor_id', 'update_existing']);

        return is_array($identity) && $identity !== []
            ? array_values(array_map('strval', $identity))
            : ['file_sha256', 'actor_id', 'update_existing'];
    }

    /**
     * FE331-P1-06R3: phạm vi dữ liệu hiệu lực khi đọc Import báo cáo SKU.
     *
     * Trả null nghĩa là GLOBAL (không giới hạn theo người tạo); trả một chuỗi id
     * nghĩa là chỉ được đọc báo cáo do chính người đó tạo.
     *
     * Quy ước lấy đúng nguồn `scope_ref` mà EnforceInventoryWarehouseScope và
     * /reports/dashboard-quick đang dùng (`User::warehouseScopeIds()`), nên không
     * phát sinh permission mới và không có nguồn scope thứ hai để lệch nhau: người
     * dùng không bị giới hạn kho = vai trò quản trị master toàn cục, cần đối soát
     * chéo mọi lần import; người dùng bị giới hạn kho chỉ được thấy chính file mình
     * đã nộp, vì file lỗi CSV chứa nguyên nội dung file của người nộp.
     */
    public static function reportActorScope(?User $user): ?string
    {
        if (! $user instanceof User) {
            // Không xác định được người gọi thì fail-closed: không cho đọc gì.
            return '';
        }

        return $user->warehouseScopeIds() === null ? null : (string) $user->getKey();
    }
}
