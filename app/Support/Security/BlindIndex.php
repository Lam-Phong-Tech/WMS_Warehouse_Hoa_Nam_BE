<?php

namespace App\Support\Security;

/**
 * WWHN-SEC-06 — blind index để TRA CỨU CHÍNH XÁC trên cột đã mã hoá.
 *
 * BÀI TOÁN: `customer_phone` vừa phải mã hoá vừa phải tìm được. Ciphertext AEAD
 * là ngẫu nhiên (IV mới mỗi lần) nên cùng một số điện thoại mã hoá hai lần ra
 * hai chuỗi khác nhau — không WHERE được, không index được.
 *
 * LỜI GIẢI: lưu thêm một cột "vân tay" tất định bên cạnh ciphertext.
 *
 * VÌ SAO KHÔNG DÙNG SHA-256 TRẦN:
 * Số điện thoại Việt Nam có khoảng 10^8 giá trị hợp lệ. Một GPU duyệt hết không
 * gian đó trong vài giây, nên `sha256(phone)` KHÔNG giấu được gì: ai lấy được
 * DB là dựng bảng tra ngược xong ngay. Bắt buộc phải là HMAC có khoá — không có
 * khoá thì không dựng được bảng tra, dù không gian đầu vào nhỏ đến đâu.
 *
 * VÌ SAO PHẢI CHUẨN HOÁ TRƯỚC KHI HMAC:
 * "0912 345 678", "+84912345678", "84912345678" và "0912345678" là CÙNG một số
 * với người dùng. HMAC thì không — bốn chuỗi ra bốn vân tay khác nhau và người
 * dùng sẽ thấy "không tìm thấy" cho chính hồ sơ họ vừa nhập. Chuẩn hoá đưa cả
 * bốn về một dạng chính tắc TRƯỚC khi băm.
 *
 * ĐIỀU BLIND INDEX KHÔNG LÀM ĐƯỢC — VÀ PHẢI NÓI RÕ:
 * Nó chỉ trả lời "bằng đúng", không trả lời "chứa". Sau khi cột chuyển sang mã
 * hoá, tìm kiếm SĐT theo mảnh ("345") sẽ không còn khớp. Đây là thay đổi ngữ
 * nghĩa cần BA duyệt, không phải chi tiết kỹ thuật — xem
 * docs/ops/SECURITY_PII_ENCRYPTION.md §"Ảnh hưởng tới tìm kiếm".
 *
 * RÒ RỈ CÒN LẠI (chấp nhận có ý thức): hai hồ sơ cùng số điện thoại có cùng vân
 * tay, nên người đọc được DB biết được "hai hồ sơ này cùng một khách" dù không
 * biết số nào. Đổi lại là khả năng tra cứu. Muốn bịt nốt thì phải bỏ tra cứu.
 */
final class BlindIndex
{
    private const SEPARATOR = ':';

    /**
     * Vân tay của một số điện thoại, tính bằng khoá ĐANG hoạt động.
     *
     * Trả về "<key_id>:<hex>" để lúc xoay khoá vẫn phân biệt được hàng nào đã
     * chuyển sang khoá mới.
     */
    public static function phone(?string $raw): ?string
    {
        $normalized = self::normalizePhone($raw);

        return $normalized === null ? null : self::hmac($normalized, 'phone');
    }

    /**
     * Mọi vân tay có thể có của cùng một số, theo TẤT CẢ khoá còn trong keyring.
     *
     * Truy vấn tìm kiếm phải dùng hàm này (whereIn) chứ không phải ::phone(),
     * nếu không thì trong cửa sổ xoay khoá các hàng chưa kịp re-index sẽ biến
     * mất khỏi kết quả — một lỗi im lặng, chỉ lộ ra khi khách hàng phàn nàn.
     *
     * @return array<int, string>
     */
    public static function phoneCandidates(?string $raw): array
    {
        $normalized = self::normalizePhone($raw);

        if ($normalized === null) {
            return [];
        }

        $candidates = [];

        foreach (PiiKeyring::keyIds(PiiKeyring::PURPOSE_BLIND_INDEX) as $keyId) {
            $candidates[] = self::hmac($normalized, 'phone', $keyId);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Vân tay của HỌ TÊN — một vân tay cho MỖI TỪ, nối bằng dấu cách.
     *
     * VÌ SAO KHÔNG BĂM CẢ CHUỖI: nhân viên tiếp nhận bảo hành tìm khách bằng
     * "Nguyễn", "Lan", "chị Lan" — hầu như không ai gõ đủ họ tên đúng thứ tự.
     * Băm cả chuỗi thì chỉ khớp khi gõ CHÍNH XÁC toàn bộ tên, tức là bỏ luôn
     * chức năng tìm kiếm. Băm từng từ giữ được cách người ta thật sự tra cứu.
     *
     * Cột lưu là text dạng "h1 h2 h3", truy vấn bằng LIKE '%<vân tay của từ>%'.
     * Vân tay là hex 64 ký tự nên xác suất một vân tay là chuỗi con của vân tay
     * khác coi như bằng 0.
     *
     * RÒ RỈ CÓ Ý THỨC, ghi rõ để không ai tưởng nó kín tuyệt đối:
     *  - lộ SỐ TỪ trong tên (đếm được bằng mắt trên bản dump)
     *  - hai người trùng một từ trong tên thì trùng vân tay từ đó
     * Không lộ nội dung từ nào, và không dựng được bảng tra ngược nếu không có
     * khoá. Đổi lại là giữ được tìm kiếm theo tên. Muốn bịt nốt thì phải bỏ
     * tìm kiếm theo tên.
     *
     * Giới hạn thật cần biết: đây là khớp TRỌN TỪ, không phải khớp giữa từ.
     * Gõ "guyen" sẽ không ra "Nguyen". Đây là thay đổi so với `LIKE '%kw%'` cũ.
     */
    public static function nameTokens(?string $raw): ?string
    {
        $tokens = self::normalizeNameTokens($raw);

        if ($tokens === []) {
            return null;
        }

        return implode(' ', array_map(
            static fn (string $token): string => self::hmac($token, 'name-token'),
            $tokens,
        ));
    }

    /**
     * Mọi vân tay có thể có của MỘT TỪ khoá tìm kiếm, theo tất cả khoá còn trong
     * keyring. Bên gọi dựng `orWhere(col, 'like', "%{$candidate}%")` cho từng cái.
     *
     * @return array<int, string>
     */
    public static function nameTokenCandidates(?string $word): array
    {
        return array_values(array_unique(array_merge(...array_values(self::nameTokenCandidateGroups($word)) ?: [[]])));
    }

    /**
     * Vân tay NHÓM THEO TỪ: mỗi phần tử là danh sách vân tay của MỘT từ theo mọi
     * khoá còn trong keyring.
     *
     * VÌ SAO CẦN NHÓM: người dùng gõ đủ "Ngô Thị Hường" mong kết quả HẸP LẠI.
     * Nếu chỉ OR tất cả vân tay với nhau thì gõ càng nhiều từ càng ra nhiều kết
     * quả (bất kỳ ai tên "Ngô", "Thị" hoặc "Hường") — ngược hoàn toàn với hành vi
     * `LIKE '%Ngô Thị Hường%'` cũ. Nhóm theo từ cho phép bên gọi dựng
     * "AND giữa các từ, OR giữa các phiên bản khoá của cùng một từ".
     *
     * @return array<int, array<int, string>>
     */
    public static function nameTokenCandidateGroups(?string $word): array
    {
        $tokens = self::normalizeNameTokens($word);

        if ($tokens === []) {
            return [];
        }

        $groups = [];

        foreach ($tokens as $token) {
            $group = [];
            foreach (PiiKeyring::keyIds(PiiKeyring::PURPOSE_BLIND_INDEX) as $keyId) {
                $group[] = self::hmac($token, 'name-token', $keyId);
            }
            $groups[] = array_values(array_unique($group));
        }

        return $groups;
    }

    /**
     * Chuẩn hoá tên trước khi băm: bỏ dấu tiếng Việt, hạ chữ thường, bỏ ký tự
     * không phải chữ/số, tách theo khoảng trắng.
     *
     * BỎ DẤU LÀ CÓ CHỦ ĐÍCH: người dùng gõ "Nguyen" phải tìm ra "Nguyễn". Giữ
     * dấu thì hai chuỗi ra hai vân tay khác nhau và người dùng thấy "không tìm
     * thấy" cho đúng hồ sơ họ vừa nhập.
     *
     * @return array<int, string>
     */
    public static function normalizeNameTokens(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $value = self::stripVietnameseDiacritics(mb_strtolower(trim($raw), 'UTF-8'));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';
        $tokens = array_values(array_filter(explode(' ', $value), static fn (string $t): bool => $t !== ''));

        return array_values(array_unique($tokens));
    }

    private static function stripVietnameseDiacritics(string $value): string
    {
        static $map = null;

        if ($map === null) {
            $accented = [
                'a' => 'àáạảãâầấậẩẫăằắặẳẵ',
                'e' => 'èéẹẻẽêềếệểễ',
                'i' => 'ìíịỉĩ',
                'o' => 'òóọỏõôồốộổỗơờớợởỡ',
                'u' => 'ùúụủũưừứựửữ',
                'y' => 'ỳýỵỷỹ',
                'd' => 'đ',
            ];

            $map = [];
            foreach ($accented as $plain => $variants) {
                foreach (preg_split('//u', $variants, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                    $map[$char] = $plain;
                }
            }
        }

        return strtr($value, $map);
    }

    /**
     * Dạng chính tắc của số điện thoại Việt Nam.
     *
     * - bỏ mọi ký tự không phải chữ số, trừ dấu "+" ở đầu
     * - "+84..." / "0084..." / "84..." (10-11 chữ số sau mã nước) -> "0..."
     * - chuỗi không còn chữ số nào -> null (không tạo vân tay cho rác)
     *
     * CỐ Ý KHÔNG cố sửa số sai: nếu người dùng nhập một chuỗi không phải số
     * điện thoại thì nó được chuẩn hoá về chính nó (chỉ còn chữ số) và vẫn tra
     * cứu được — chuẩn hoá là để KHỚP, không phải để xác thực dữ liệu. Việc xác
     * thực định dạng đã có ở lớp validate của controller.
     */
    public static function normalizePhone(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        // 0084xxxxxxxxx -> 84xxxxxxxxx
        if (str_starts_with($digits, '0084')) {
            $digits = substr($digits, 2);
        }

        // 84xxxxxxxxx -> 0xxxxxxxxx (chỉ khi phần còn lại dài đúng như số nội địa)
        if (str_starts_with($digits, '84') && strlen($digits) >= 11 && strlen($digits) <= 12) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    private static function hmac(string $normalized, string $domain, ?string $keyId = null): string
    {
        $keyId ??= PiiKeyring::activeKeyId(PiiKeyring::PURPOSE_BLIND_INDEX);
        $key = PiiKeyring::keyById(PiiKeyring::PURPOSE_BLIND_INDEX, $keyId);

        if ($key === null) {
            throw new \RuntimeException('Blind index key id "'.$keyId.'" không có trong keyring.');
        }

        // `domain` tách không gian vân tay giữa các loại dữ liệu: một ngày nào đó
        // thêm blind index cho email thì email và phone trùng chuỗi vẫn không
        // trùng vân tay.
        return $keyId.self::SEPARATOR.hash_hmac('sha256', $domain."\0".$normalized, $key);
    }
}
