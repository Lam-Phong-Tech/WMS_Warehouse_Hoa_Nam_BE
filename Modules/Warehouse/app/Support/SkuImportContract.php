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

    /** Kích thước upload tối đa tính bằng byte — dùng cho kiểm tra ở tầng service. */
    public static function maxFileSizeBytes(): int
    {
        return self::maxFileSizeKb() * 1024;
    }

    /** @return array<int, string> */
    public static function allowedExtensions(): array
    {
        $extensions = config('warehouse.sku_import.allowed_extensions', ['xlsx', 'xls']);

        return is_array($extensions) && $extensions !== []
            ? array_values(array_map('strval', $extensions))
            : ['xlsx', 'xls'];
    }

    /**
     * DEV330-P1-02R3 — chính sách quét dòng vật lý (physical-scan policy) đã duyệt.
     *
     * Bội số này nhân với số dòng vật lý cần đọc để phục vụ đúng hạn mức dữ liệu, ra
     * trần quét. Trước đây nó là số `20` viết thẳng trong ImportService — một chính sách
     * chống DoS chưa ai duyệt, nằm lẫn trong code như hằng số kỹ thuật.
     */
    public static function maxPhysicalScanMultiplier(): int
    {
        return max(1, (int) config('warehouse.sku_import.max_physical_scan_multiplier', 20));
    }

    /** Header + maxRows + 1 dòng dư để phát hiện vượt hạn mức mà vẫn bounded-read. */
    public static function maxPhysicalRowsToRead(): int
    {
        return self::maxRows() + 2;
    }

    /** Trần số dòng VẬT LÝ được quét trước khi dừng và báo lỗi tường minh. */
    public static function maxPhysicalRowsToScan(): int
    {
        return self::maxPhysicalScanMultiplier() * self::maxPhysicalRowsToRead();
    }

    /**
     * DEV330-P1-02R3 — ngân sách hiệu năng đã duyệt (ms) cho từng kịch bản ở hạn mức.
     * Benchmark test fail khi vượt → hồi quy bậc độ phức tạp bị chặn tại CI.
     *
     * @return array<string, int>
     */
    public static function performanceObjectiveMs(): array
    {
        $objective = config('warehouse.sku_import.performance_objective_ms', []);
        $defaults = [
            'dry_run_at_max_rows' => 15000,
            'commit_at_max_rows' => 60000,
            'reject_oversize' => 15000,
        ];

        if (! is_array($objective)) {
            return $defaults;
        }

        foreach ($defaults as $key => $fallback) {
            $defaults[$key] = max(1, (int) ($objective[$key] ?? $fallback));
        }

        return $defaults;
    }

    /** DEV330-P1-07R3 — TTL của lock chống 2 request COMMIT trùng định danh chạy song song. */
    public static function lockTtlSeconds(): int
    {
        return max(1, (int) config('warehouse.sku_import.lock.ttl_seconds', 120));
    }

    /** DEV330-P1-07R3 — thời gian chờ tối đa để giành lock. */
    public static function lockWaitSeconds(): int
    {
        return max(1, (int) config('warehouse.sku_import.lock.wait_seconds', 15));
    }

    /**
     * DEV330-P1-07R3 — hết thời gian chờ lock thì TỪ CHỐI (true) hay chạy tiếp (false).
     *
     * Mặc định fail-closed. Chỉ đọc `FAIL_OPEN` khi hợp đồng ghi đúng chuỗi đó, để
     * không ai vô tình hạ tiêu chuẩn toàn vẹn dữ liệu bằng một giá trị cấu hình gõ sai.
     */
    public static function lockFailsClosed(): bool
    {
        return strtoupper((string) config('warehouse.sku_import.lock.on_timeout', 'FAIL_CLOSED')) !== 'FAIL_OPEN';
    }

    /**
     * FE331-P1-08R4 — hạn của CHỖ GIỮ CHÍNH DANH trong cơ sở dữ liệu (durable claim).
     *
     * Đây KHÔNG phải "thời gian import được phép chạy" mà là van cứu hộ cho tiến trình
     * chết giữa chừng: quá hạn thì request sau được tiếp quản chỗ giữ đó. Vì vậy giá trị
     * phải lớn hơn hẳn ngân sách hiệu năng đã duyệt cho một lần commit ở đúng hạn mức
     * (`performance_objective_ms.commit_at_max_rows`, mặc định 60s) — mặc định 900s là
     * 15× ngân sách đó và cũng lớn hơn mọi trần thực thi ở tầng dưới (PHP
     * `max_execution_time`, `fastcgi_read_timeout` của nginx), nên một chỗ giữ chỉ có
     * thể hết hạn khi tiến trình giữ nó đã thực sự chết.
     *
     * Sàn cứng: không bao giờ nhỏ hơn 2× ngân sách commit (và không nhỏ hơn TTL khoá
     * cache), kể cả khi cấu hình sai — một giá trị quá thấp sẽ mở lại đúng ca đua mà chỗ
     * giữ sinh ra để đóng, và đó không phải thứ được phép hạ bằng một biến môi trường.
     */
    public static function claimTtlSeconds(): int
    {
        $configured = (int) config('warehouse.sku_import.lock.claim_ttl_seconds', 900);
        $commitBudgetSeconds = (int) ceil(self::performanceObjectiveMs()['commit_at_max_rows'] / 1000);

        return max($configured, 2 * $commitBudgetSeconds, self::lockTtlSeconds());
    }

    /**
     * FE331-P1-08R4 — định danh một lần import, băm lại làm khoá chính của chỗ giữ.
     *
     * Đúng bằng bộ định danh replay của DEC-IMP-001 §2 (`file_sha256 + actor_id +
     * options_fingerprint`), không phải một định nghĩa thứ hai: chỗ giữ và phép kiểm tra
     * trùng phải nói về CÙNG một khái niệm "một lần import" — nếu lệch nhau thì thứ được
     * tuần tự hoá không còn là thứ được kiểm tra.
     */
    public static function identityHash(string $actorId, string $fileSha256, string $optionsFingerprint): string
    {
        return hash('sha256', $actorId.'|'.$fileSha256.'|'.$optionsFingerprint);
    }

    /** Số giờ file lỗi CSV còn tải được kể từ lúc sinh (hạn dùng LOGIC). */
    public static function errorFileExpiryHours(): int
    {
        return max(1, (int) config('warehouse.sku_import.error_file_expiry_hours', 24));
    }

    /** DEV330-P1-01R3 — khoảng đệm sau hạn dùng logic trước khi xoá VẬT LÝ. */
    public static function errorFilePurgeGraceHours(): int
    {
        return max(0, (int) config('warehouse.sku_import.error_file_purge_grace_hours', 0));
    }

    /** Giờ chạy job purge hằng ngày — cùng nguồn với `routes/console.php` và tài liệu. */
    public static function errorFilePurgeDailyAt(): string
    {
        $at = (string) config('warehouse.sku_import.error_file_purge_daily_at', '02:15');

        return preg_match('/^\d{2}:\d{2}$/', $at) === 1 ? $at : '02:15';
    }

    /** DEV330-P1-01R3 — có quét cả file mồ côi (không còn báo cáo nào trỏ tới) hay không. */
    public static function errorFileOrphanSweepEnabled(): bool
    {
        return (bool) config('warehouse.sku_import.error_file_orphan_sweep', true);
    }

    /**
     * DEV330-P1-01R3 — thời gian tối đa một file lỗi CÓ THỂ nằm trên đĩa, tính cả nhịp
     * chạy job (mỗi ngày một lần). Đây là con số cần công bố cho bảo mật/tuân thủ, vì
     * "hết hạn 24h" ở tầng API không nói gì về đĩa.
     */
    public static function errorFileMaxOnDiskHours(): int
    {
        return self::errorFileExpiryHours() + self::errorFilePurgeGraceHours() + 24;
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
            // DEV330-P1-02R3: trần quét dòng vật lý là một hạn mức người dùng CÓ THỂ
            // chạm phải (file thưa dòng trống) nên phải công bố như mọi hạn mức khác,
            // không để họ đoán vì sao một file 300 dòng lại bị từ chối.
            'max_physical_rows_to_scan' => self::maxPhysicalRowsToScan(),
            'error_file_expiry_hours' => self::errorFileExpiryHours(),
            // DEV330-P1-01R3: hạn dùng logic (API 404) và thời gian tồn tại tối đa trên
            // đĩa là hai con số khác nhau — công bố cả hai để không ai suy nhầm rằng
            // "hết hạn" đồng nghĩa "đã bị xoá".
            'error_file_max_on_disk_hours' => self::errorFileMaxOnDiskHours(),
            'idempotency_window_hours' => self::idempotencyWindowHours(),
            'idempotency_identity' => self::idempotencyIdentity(),
            // DEV330-P1-07R3: hai request COMMIT trùng ĐỦ định danh gửi đồng thời thì
            // request thứ hai bị từ chối 409 CONCURRENCY_CONFLICT (không âm thầm chạy
            // song song). Công bố để client hiển thị đúng thông điệp "thử lại sau".
            'concurrent_import_policy' => self::lockFailsClosed() ? 'FAIL_CLOSED_409' : 'FAIL_OPEN',
            // FE331-P1-08R4: `concurrent_import_policy` ở trên chỉ mô tả hành vi khi HẾT
            // THỜI GIAN CHỜ khoá cache — nó không nói gì về chuyện khoá ấy có thật sự
            // khoá hay không. Trường dưới đây công bố chốt chặn thật: chỗ giữ chính danh
            // nằm trong cơ sở dữ liệu, độc lập hoàn toàn với cache driver, và KHÔNG bị
            // `FAIL_OPEN` vô hiệu hoá. Client (và QA) nhờ đó phân biệt được "đang chạy
            // trên một hệ có bảo đảm" với "đang chạy trên một khoá có thể im lặng không
            // khoá gì" mà không phải đọc mã nguồn.
            'concurrency_guard' => 'DB_DURABLE_CLAIM',
            'concurrency_guard_table' => 'sku_import_claims',
            'claim_ttl_seconds' => self::claimTtlSeconds(),
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
