<?php

namespace Tests\Feature;

use App\Support\Security\BlindIndex;
use App\Support\Security\PiiCrypto;
use App\Support\Security\PiiKeyring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\PublicQuoteRequest;
use RuntimeException;
use Tests\TestCase;

/**
 * WWHN-SEC-06 — bằng chứng cho lớp mã hoá PII.
 *
 * NGUYÊN TẮC CỦA BỘ TEST NÀY: không assert chuỗi cấu hình. Mỗi test phải chứng
 * minh một HÀNH VI mà kẻ tấn công quan tâm:
 *
 *   - đọc được bản dump thì có đọc được tên/số điện thoại không?
 *   - có khoá blind index thì mới tra ngược được, hay chỉ cần từ điển số ĐT?
 *   - lấy được ciphertext của ô này chép sang ô khác có xài được không?
 *   - mất khoá cũ thì dữ liệu cũ còn đọc được không?
 *
 * Một test kiểu `assertSame('aes-256-gcm', config('pii.cipher'))` không trả lời
 * được câu nào trong số đó và sẽ xanh cả khi lớp mã hoá bị gỡ bỏ.
 */
class WwhnSec06PiiEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'Nguyen Van Test';

    private const PHONE = '0912345678';

    private const NOTE = 'Ghi chu rieng tu cua khach';

    protected function setUp(): void
    {
        parent::setUp();

        // Hai keyring RIÊNG BIỆT, cố định cho test — không phụ thuộc APP_KEY của
        // máy đang chạy, nên kết quả tái lập được ở CI.
        $this->useKeyrings(
            encryption: ['v1' => $this->key('enc-v1')],
            blindIndex: ['v1' => $this->key('idx-v1')],
        );
    }

    public function test_pii_khong_con_ton_tai_o_dang_ro_trong_csdl(): void
    {
        $model = $this->createRequest();

        // Đọc THẲNG từ bảng bằng query builder — bỏ qua toàn bộ accessor của
        // Eloquent. Đây chính là góc nhìn của kẻ cầm bản dump trong tay.
        $raw = DB::table('public_quote_requests')->where('id', $model->id)->first();
        $serialized = json_encode((array) $raw, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::NAME, $serialized);
        $this->assertStringNotContainsString(self::PHONE, $serialized);
        $this->assertStringNotContainsString(self::NOTE, $serialized);

        // Và các cột plaintext phải KHÔNG CÒN TỒN TẠI, không chỉ là rỗng: một
        // cột rỗng hôm nay là một cột đầy dữ liệu sau lần INSERT tiếp theo của
        // code viết ở chỗ khác.
        foreach (['full_name', 'phone', 'phone_normalized', 'note'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('public_quote_requests', $column),
                "Cột plaintext {$column} vẫn còn trong schema — Phase 6 chưa hoàn tất.",
            );
        }
    }

    public function test_ung_dung_van_doc_lai_dung_gia_tri_da_ma_hoa(): void
    {
        $model = $this->createRequest();

        $reloaded = PublicQuoteRequest::query()->findOrFail($model->id);

        $this->assertSame(self::NAME, $reloaded->full_name);
        $this->assertSame(self::PHONE, $reloaded->phone);
        $this->assertSame(self::NOTE, $reloaded->note);
    }

    /**
     * Đây là test quan trọng nhất của blind index: tìm kiếm PHẢI còn hoạt động
     * sau khi mã hoá, nếu không thì "bảo mật" vừa phá mất một chức năng nghiệp vụ.
     */
    public function test_blind_index_van_tra_cuu_duoc_moi_cach_go_so_dien_thoai(): void
    {
        $model = $this->createRequest();

        foreach (['0912345678', '0912 345 678', '091.234.5678', '+84912345678', '84912345678', '0084912345678'] as $typed) {
            $found = PublicQuoteRequest::query()->wherePhone($typed)->pluck('id')->all();

            $this->assertSame([$model->id], $found, "Không tìm thấy khi người dùng gõ: {$typed}");
        }

        $this->assertSame(0, PublicQuoteRequest::query()->wherePhone('0999888777')->count());
        // Chuỗi rác không được biến thành "khớp tất cả".
        $this->assertSame(0, PublicQuoteRequest::query()->wherePhone('khong-phai-so')->count());
    }

    /**
     * Không gian số ĐT Việt Nam nhỏ (~10^8). Nếu vân tay chỉ là SHA-256 trần thì
     * ai cầm bản dump cũng dựng được bảng tra ngược trong vài giây. Test này
     * chứng minh vân tay PHỤ THUỘC KHOÁ, không phải chỉ phụ thuộc giá trị.
     */
    public function test_blind_index_khong_phai_bam_tran_va_doi_theo_khoa(): void
    {
        $withKeyA = BlindIndex::phone(self::PHONE);

        $this->assertStringNotContainsString(hash('sha256', self::PHONE), (string) $withKeyA);
        $this->assertStringNotContainsString(self::PHONE, (string) $withKeyA);

        $this->useKeyrings(
            encryption: ['v1' => $this->key('enc-v1')],
            blindIndex: ['v1' => $this->key('idx-KHAC')],
        );

        $this->assertNotSame($withKeyA, BlindIndex::phone(self::PHONE));
    }

    public function test_ciphertext_chep_sang_cot_khac_thi_khong_dung_duoc(): void
    {
        $cipher = PiiCrypto::encrypt(self::PHONE, 'public_quote_requests.phone');

        // Kẻ tấn công có quyền GHI vào DB nhưng KHÔNG có khoá: chép ciphertext
        // của cột phone sang ô full_name để hoán vị dữ liệu giữa các hồ sơ.
        $this->expectException(RuntimeException::class);
        PiiCrypto::decrypt($cipher, 'public_quote_requests.full_name');
    }

    public function test_khong_co_khoa_thi_khong_giai_ma_duoc(): void
    {
        $cipher = PiiCrypto::encrypt(self::NAME, 'public_quote_requests.full_name');

        // Mô phỏng: lấy được cả CSDL lẫn mã nguồn, nhưng không lấy được keyring.
        $this->useKeyrings(
            encryption: ['v1' => $this->key('khoa-hoan-toan-khac')],
            blindIndex: ['v1' => $this->key('idx-v1')],
        );

        $this->expectException(RuntimeException::class);
        PiiCrypto::decrypt($cipher, 'public_quote_requests.full_name');
    }

    /**
     * Xoay khoá mà mất dữ liệu cũ thì không ai dám xoay. Test này chứng minh
     * ciphertext ký bằng khoá cũ VẪN đọc được sau khi đã chuyển sang khoá mới.
     */
    public function test_xoay_khoa_khong_lam_mat_du_lieu_cu(): void
    {
        $old = PiiCrypto::encrypt(self::NAME, 'public_quote_requests.full_name');
        $this->assertStringStartsWith('v1$', (string) $old);

        // Thêm v2 và chuyển active sang v2 — v1 VẪN nằm trong keyring.
        $this->useKeyrings(
            encryption: ['v1' => $this->key('enc-v1'), 'v2' => $this->key('enc-v2')],
            blindIndex: ['v1' => $this->key('idx-v1'), 'v2' => $this->key('idx-v2')],
            activeEncryption: 'v2',
            activeBlindIndex: 'v2',
        );

        $this->assertSame(self::NAME, PiiCrypto::decrypt($old, 'public_quote_requests.full_name'));
        $this->assertStringStartsWith('v2$', (string) PiiCrypto::encrypt(self::NAME, 'public_quote_requests.full_name'));

        // Trong cửa sổ xoay khoá, tra cứu phải phủ CẢ vân tay cũ lẫn mới, nếu
        // không thì hàng chưa re-index sẽ biến mất khỏi kết quả tìm kiếm.
        $candidates = BlindIndex::phoneCandidates(self::PHONE);
        $this->assertCount(2, $candidates);
        $this->assertContains('v1', array_map(static fn (string $c): string => explode(':', $c)[0], $candidates));
        $this->assertContains('v2', array_map(static fn (string $c): string => explode(':', $c)[0], $candidates));
    }

    public function test_serialize_model_khong_lam_ro_ri_pii(): void
    {
        $model = $this->createRequest();

        $payload = json_encode(PublicQuoteRequest::query()->findOrFail($model->id)->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::NAME, (string) $payload);
        $this->assertStringNotContainsString(self::PHONE, (string) $payload);
        $this->assertStringNotContainsString(self::NOTE, (string) $payload);
    }

    public function test_gia_tri_rong_khong_sinh_ra_ciphertext(): void
    {
        // "Chưa có dữ liệu" phải nhìn thấy được bằng mắt trên DB. Mã hoá chuỗi
        // rỗng tạo ra một giá trị KHÁC NULL và làm mọi truy vấn IS NULL nói dối.
        $this->assertNull(PiiCrypto::encrypt(null, 'public_quote_requests.note'));
        $this->assertNull(PiiCrypto::encrypt('', 'public_quote_requests.note'));
        $this->assertNull(BlindIndex::phone(null));
        $this->assertNull(BlindIndex::phone('   '));
    }

    // ------------------------------------------------------------------

    private function createRequest(): PublicQuoteRequest
    {
        return PublicQuoteRequest::create([
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'test'),
            'product_id' => (string) Str::uuid(),
            'variant_id' => null,
            'full_name' => self::NAME,
            'phone' => self::PHONE,
            'province_code' => '01',
            'note' => self::NOTE,
            'consent' => true,
            'privacy_version' => '1.0.0',
            'status' => PublicQuoteRequest::STATUS_RECEIVED,
            'source' => 'PUBLIC_APP',
            'request_ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);
    }

    /**
     * @param  array<string, string>  $encryption
     * @param  array<string, string>  $blindIndex
     */
    private function useKeyrings(
        array $encryption,
        array $blindIndex,
        string $activeEncryption = 'v1',
        string $activeBlindIndex = 'v1',
    ): void {
        config()->set('pii.encryption.keys', (string) json_encode($encryption));
        config()->set('pii.encryption.active', $activeEncryption);
        config()->set('pii.blind_index.keys', (string) json_encode($blindIndex));
        config()->set('pii.blind_index.active', $activeBlindIndex);

        // Keyring và Encrypter được cache theo tiến trình để không phải parse
        // JSON mỗi request; test đổi config nên phải dọn cache thủ công.
        PiiKeyring::flush();
        PiiCrypto::flush();
    }

    private function key(string $seed): string
    {
        return 'base64:'.base64_encode(hash('sha256', 'wwhn-sec-06-test-'.$seed, true));
    }
}
