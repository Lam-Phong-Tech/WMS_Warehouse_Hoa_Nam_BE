<?php

namespace Tests\Feature;

use App\Support\Security\BlindIndex;
use App\Support\Security\PiiCrypto;
use App\Support\Security\PiiKeyring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\WarrantyCasePii;
use Tests\TestCase;

/**
 * WWHN-SEC-06 — PII hồ sơ bảo hành.
 *
 * `warranty_case` là bề mặt khó nhất của lớp mã hoá: view legacy + trigger
 * INSTEAD OF, và ~15 điểm gọi dùng query builder chứ không dùng Eloquent. Bộ
 * test này chứng minh HÀNH VI ở cả bốn mặt mà một lần refactor sau này có thể
 * phá mà không ai nhận ra:
 *
 *   1. dump CSDL không còn đọc được tên/SĐT/địa chỉ
 *   2. ứng dụng vẫn đọc lại đúng giá trị
 *   3. tìm kiếm vẫn hoạt động — và HẸP LẠI khi gõ thêm từ
 *   4. không còn truy vấn nào chạm cột plaintext (hàng rào tĩnh)
 */
class WwhnSec06WarrantyPiiTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'Nguyễn Thị Hường';

    private const PHONE = '0912345678';

    private const ADDRESS = '12 Lê Lợi, Quận 1, TP.HCM';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pii.encryption.keys', (string) json_encode(['v1' => $this->key('enc')]));
        config()->set('pii.blind_index.keys', (string) json_encode(['v1' => $this->key('idx')]));
        config()->set('pii.encryption.active', 'v1');
        config()->set('pii.blind_index.active', 'v1');
        PiiKeyring::flush();
        PiiCrypto::flush();
    }

    public function test_cot_plaintext_khong_con_ton_tai_trong_schema(): void
    {
        $relation = $this->relation();

        foreach (['customer_name', 'customer_phone', 'customer_address'] as $column) {
            $this->assertFalse(
                Schema::hasColumn($relation, $column),
                "Cột plaintext {$relation}.{$column} vẫn còn — Phase 6 chưa hoàn tất.",
            );
        }

        foreach (['customer_name_ciphertext', 'customer_phone_ciphertext', 'customer_address_ciphertext', 'customer_phone_lookup', 'customer_name_lookup'] as $column) {
            $this->assertTrue(Schema::hasColumn($relation, $column), "Thiếu cột {$column}.");
        }
    }

    public function test_pii_khong_xuat_hien_o_dang_ro_trong_csdl(): void
    {
        $id = $this->createCase();

        // Góc nhìn của kẻ cầm bản dump: đọc thẳng bảng, không qua helper nào.
        $raw = DB::table('warranty_case')->where('warranty_case_id', $id)->first();
        $serialized = (string) json_encode((array) $raw, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::NAME, $serialized);
        $this->assertStringNotContainsString(self::PHONE, $serialized);
        $this->assertStringNotContainsString(self::ADDRESS, $serialized);
        // Cả dạng chuẩn hoá cũng không được lộ (đây chính là lỗi của cột
        // `phone_normalized` cũ: "đã mã hoá" nhưng số vẫn nằm ngay bên cạnh).
        $this->assertStringNotContainsString(BlindIndex::normalizePhone(self::PHONE) ?? 'x', $serialized);
    }

    public function test_ung_dung_doc_lai_dung_gia_tri(): void
    {
        $id = $this->createCase();

        $row = DB::table('warranty_case')->where('warranty_case_id', $id)->first();
        WarrantyCasePii::decodeRow($row);

        $this->assertSame(self::NAME, $row->customer_name);
        $this->assertSame(self::PHONE, $row->customer_phone);
        $this->assertSame(self::ADDRESS, $row->customer_address);
    }

    public function test_giai_ma_xong_thi_go_bo_cot_ky_thuat(): void
    {
        $id = $this->createCase();

        $row = DB::table('warranty_case')->where('warranty_case_id', $id)->first();
        WarrantyCasePii::decodeRow($row);

        // tablePage() trả nguyên hàng ra response. Để lại `customer_phone_lookup`
        // là gửi vân tay TẤT ĐỊNH cho trình duyệt — client giữ nó có thể đối chiếu
        // hai hồ sơ bất kỳ để biết "cùng một khách", đúng thứ mask §13.8 đang che.
        foreach (['customer_name_ciphertext', 'customer_phone_ciphertext', 'customer_address_ciphertext', 'customer_phone_lookup', 'customer_name_lookup'] as $technical) {
            $this->assertFalse(property_exists($row, $technical), "Cột kỹ thuật {$technical} vẫn còn trên hàng trả ra.");
        }
    }

    public function test_tim_theo_so_dien_thoai_moi_cach_go(): void
    {
        $id = $this->createCase();

        foreach (['0912345678', '0912 345 678', '091.234.5678', '+84912345678', '84912345678'] as $typed) {
            $found = $this->searchIds($typed);

            $this->assertContains($id, $found, "Không tìm thấy khi gõ: {$typed}");
        }

        $this->assertSame([], $this->searchIds('0999888777'));
    }

    public function test_tim_theo_ten_khong_dau_va_hep_lai_khi_go_them_tu(): void
    {
        $huong = $this->createCase(name: 'Nguyễn Thị Hường', phone: '0900000001');
        $lan = $this->createCase(name: 'Nguyễn Thị Lan', phone: '0900000002');

        // Không dấu vẫn ra: người dùng gõ "nguyen" phải tìm thấy "Nguyễn".
        $this->assertEqualsCanonicalizing([$huong, $lan], $this->searchIds('nguyen'));
        $this->assertEqualsCanonicalizing([$huong, $lan], $this->searchIds('thi'));

        // Gõ THÊM từ phải HẸP kết quả lại — đây là điểm dễ làm sai nhất: nếu OR
        // phẳng toàn bộ vân tay thì càng gõ nhiều càng ra nhiều, ngược hoàn toàn
        // với hành vi LIKE cũ mà người dùng đang quen.
        $this->assertSame([$huong], $this->searchIds('nguyen huong'));
        $this->assertSame([$lan], $this->searchIds('nguyen lan'));
        $this->assertSame([], $this->searchIds('nguyen khong-ton-tai'));
    }

    public function test_mat_khoa_thi_khong_doc_duoc_du_lieu(): void
    {
        $id = $this->createCase();

        // Mô phỏng: lấy được cả CSDL lẫn mã nguồn, nhưng không lấy được keyring.
        config()->set('pii.encryption.keys', (string) json_encode(['v1' => $this->key('khoa-khac')]));
        PiiKeyring::flush();
        PiiCrypto::flush();

        $row = DB::table('warranty_case')->where('warranty_case_id', $id)->first();
        WarrantyCasePii::decodeRow($row);

        // decodeRow fail-soft (một hồ sơ hỏng không được làm sập cả trang danh
        // sách), nên bằng chứng là giá trị KHÔNG đọc được, chứ không phải exception.
        $this->assertNull($row->customer_name);
        $this->assertNull($row->customer_phone);
    }

    public function test_encode_khong_dung_cham_vao_truong_khong_duoc_gui(): void
    {
        // UPDATE bộ phận: `update(['status' => ...])` KHÔNG được vô tình xoá trắng
        // PII của hồ sơ. Đây là lớp lỗi im lặng và không thể phục hồi.
        $encoded = WarrantyCasePii::encode(['status' => 'CHECKING']);

        $this->assertSame(['status' => 'CHECKING'], $encoded);
        $this->assertArrayNotHasKey('customer_name_ciphertext', $encoded);
        $this->assertArrayNotHasKey('customer_phone_lookup', $encoded);
    }

    /**
     * Hàng rào tĩnh: ngăn một PR sau này thêm lại `select('wc.customer_name')`.
     * Kết luận "không còn chỗ nào đọc plaintext" đúng hôm nay và im lặng sai vào
     * ngày có người thêm một màn hình mới.
     */
    public function test_khong_con_truy_van_nao_doc_cot_plaintext(): void
    {
        $roots = [base_path('Modules'), base_path('app')];
        $offenders = [];

        // Chỉ bắt dạng CÓ TIỀN TỐ BẢNG/ALIAS (`wc.customer_name`,
        // `warranty_case.customer_phone`). Chuỗi trần `'customer_name'` vẫn hợp lệ:
        // đó là tên thuộc tính ứng dụng dùng sau khi giải mã, và là khoá đầu vào
        // của WarrantyCasePii::encode().
        $pattern = '/[\'"](?:wc|warranty_case|warranty_cases)\.customer_(?:name|phone|address)[\'"]/';

        // Hai tệp SỞ HỮU chuỗi "warranty_cases.customer_*": đó là AAD gắn
        // ciphertext vào đúng cột (PiiCrypto::encrypt), KHÔNG phải tên cột trong
        // câu SQL. Chúng phải khớp nhau từng ký tự nên cố ý viết tường minh ở cả
        // hai nơi thay vì import chéo giữa lớp Support và lớp Command.
        $allowed = [
            'Modules/Warehouse/app/Support/WarrantyCasePii.php',
            'app/Console/Commands/PiiWarrantyCases.php',
            // Model cũng khai chuỗi context AAD cho accessor/mutator Eloquent —
            // ba nơi này phải khớp nhau từng ký tự, nên cố ý viết tường minh.
            'Modules/Warehouse/app/Models/WarrantyCase.php',
        ];

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $content = (string) file_get_contents($file->getPathname());

                if (preg_match($pattern, $content) !== 1) {
                    continue;
                }

                $relative = str_replace(
                    [base_path().DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR],
                    ['', '/'],
                    $file->getPathname(),
                );

                if (! in_array($relative, $allowed, true)) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Cột plaintext không còn tồn tại trong schema — các tệp sau sẽ ném lỗi khi chạy:\n".implode("\n", $offenders),
        );
    }

    /**
     * Hàng rào thứ hai — và là hàng rào ĐÃ CỨU MỘT LẦN.
     *
     * Bài học 08/09/2026: sau khi Phase 6 xoá ba cột plaintext, **13 seeder và
     * fixture test** vẫn `DB::table('warranty_case')->insert(['customer_name' =>
     * ...])`. Hậu quả không phải một test đỏ mà là 75 test đỏ, vì gần như mọi
     * test đều seed. Guard cũ không bắt được: nó chỉ soi chuỗi CÓ TIỀN TỐ BẢNG
     * (`wc.customer_name`), còn writer dùng khoá TRẦN `'customer_name' =>`.
     *
     * Test này soi đúng cái còn thiếu: mỗi lệnh ghi vào `warranty_case` /
     * `warranty_cases` phải được bọc `WarrantyCasePii::encode(`.
     *
     * Cố tình KHÔNG dùng regex tìm cả file rồi kết luận: file có thể có nhiều
     * lệnh ghi, chỉ một cái quên bọc. Phải soi TỪNG lệnh.
     */
    public function test_moi_lenh_ghi_warranty_case_deu_di_qua_encode(): void
    {
        $roots = [base_path('tests'), base_path('Modules'), base_path('database'), base_path('app')];
        $offenders = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace(
                    [base_path().DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR],
                    ['', '/'],
                    $file->getPathname(),
                );

                // Migration là nơi DUY NHẤT được phép chạm cột thô: chính chúng
                // tạo/xoá cột và nạp lại dữ liệu bằng PiiCrypto trực tiếp.
                if (str_contains($relative, '/migrations/')) {
                    continue;
                }
                // Bỏ qua CHÍNH tệp này: chuỗi regex bên dưới chứa nguyên mẫu
                // "table('warranty_case')->insert(" nên nó tự khớp chính nó.
                if ($file->getPathname() === __FILE__) {
                    continue;
                }
                // Hai tệp sở hữu lớp mã hoá.
                if (in_array($relative, [
                    'Modules/Warehouse/app/Support/WarrantyCasePii.php',
                    'app/Console/Commands/PiiWarrantyCases.php',
                ], true)) {
                    continue;
                }

                $content = (string) file_get_contents($file->getPathname());

                if (! str_contains($content, 'warranty_case')) {
                    continue;
                }

                $pattern = '/table\([\'"]warranty_cases?[\'"]\)[^;]*?->(?:insert|update|updateOrInsert|upsert|insertOrIgnore)\(\s*(WarrantyCasePii::encode\()?/';

                if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === 0) {
                    continue;
                }

                foreach ($matches as $index => $match) {
                    if (($match[1] ?? '') === '') {
                        $offenders[] = $relative.' (lệnh ghi #'.($index + 1).')';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Ghi thẳng vào warranty_case mà không qua WarrantyCasePii::encode() sẽ ném '
            ."\"no column named customer_name\" (cột đã xoá ở Phase 6):\n".implode("\n", $offenders),
        );
    }

    // ------------------------------------------------------------------

    private function createCase(?string $name = null, ?string $phone = null): string
    {
        $id = (string) Str::uuid();

        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => $id,
            'warranty_case_code' => 'WC-SEC06-'.Str::upper(Str::random(6)),
            'identification_status' => 'TEMP_ONLY',
            'temporary_item_code' => 'TEMP-'.Str::upper(Str::random(8)),
            'missing_code_reason' => 'test',
            'manual_product_description' => 'test',
            'status' => 'RECEIVED',
            'customer_name' => $name ?? self::NAME,
            'customer_phone' => $phone ?? self::PHONE,
            'customer_address' => self::ADDRESS,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $id;
    }

    /** @return array<int, string> */
    private function searchIds(string $keyword): array
    {
        $query = DB::table('warranty_case');
        $query->where(function ($nested) use ($keyword): void {
            WarrantyCasePii::applyKeyword($nested, $keyword);
        });

        return $query->pluck('warranty_case_id')->map(fn ($id): string => (string) $id)->all();
    }

    private function relation(): string
    {
        return Schema::hasTable('warranty_cases') ? 'warranty_cases' : 'warranty_case';
    }

    private function key(string $seed): string
    {
        return 'base64:'.base64_encode(hash('sha256', 'wwhn-sec-06-warranty-'.$seed, true));
    }
}
