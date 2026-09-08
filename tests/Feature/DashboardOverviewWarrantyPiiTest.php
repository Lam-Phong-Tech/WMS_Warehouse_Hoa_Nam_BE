<?php

namespace Tests\Feature;

use App\Support\Security\PiiCrypto;
use App\Support\Security\PiiKeyring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\WarrantyCasePii;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * `/reports/dashboard-overview` — màn "Tổng quan vận hành".
 *
 * SỰ CỐ 08/09/2026 mà bộ test này chặn tái diễn: Phase 6 của WWHN-SEC-06 xoá cột
 * plaintext `warranty_cases.customer_name`, nhưng mẫu "ca bảo hành quá hạn" của
 * endpoint này vẫn `->get([... 'customer_name' ...])`. Kết quả: MÀN CHÍNH của ứng
 * dụng trả 500 (SQLSTATE 42703 Undefined column) cho MỌI vai.
 *
 * Vì sao không có hàng rào nào bắt được:
 *
 *   · hàng rào tĩnh ở `WwhnSec06WarrantyPiiTest` chỉ soi dạng CÓ TIỀN TỐ BẢNG
 *     (`wc.customer_name`), còn đây là chuỗi trần trong mệnh đề select — đúng
 *     dạng mà hàng rào CỐ Ý cho qua vì trùng tên thuộc tính ứng dụng;
 *   · và trước sự cố này KHÔNG có một test nào gọi `dashboard-overview`.
 *
 * Nên hàng rào đúng chỗ là một lần gọi THẬT vào endpoint, có sẵn ca quá hạn để
 * nhánh đọc PII thực sự chạy.
 */
class DashboardOverviewWarrantyPiiTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const NAME = 'Nguyễn Thị Hường';

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

    public function test_tong_quan_van_hanh_van_tra_200_khi_co_ca_bao_hanh_qua_han(): void
    {
        $this->createOverdueCase();

        $response = $this->getJson('/api/v1/reports/dashboard-overview', $this->superAdminHeaders());

        // Trước khi sửa: 500 INTERNAL_SERVER_ERROR vì câu SQL còn chạm cột đã xoá.
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertGreaterThan(
            0,
            (int) $response->json('data.warranty.overdue_count'),
            'Ca quá hạn vừa tạo không được đếm — test không còn chạy qua nhánh đọc PII.',
        );
        $this->assertNotEmpty(
            $response->json('data.warranty.overdue_sample'),
            'overdue_sample rỗng nên nhánh giải mã tên khách không hề chạy.',
        );
    }

    /**
     * Tên khách ở endpoint này phải LUÔN mask, không phụ thuộc quyền
     * `warranty.pii.view` như màn RPT-WAR-01.
     *
     * Lý do là cái bẫy cache: payload được cache dưới khoá KHÔNG mang danh tính
     * người gọi (`…:dashboard-overview:…:scope-global:v1`). Nếu giá trị phụ thuộc
     * quyền thì chỉ cần một người có quyền xem PII nạp cache là mọi vai khác đọc
     * được tên thật từ đúng cache đó — kể cả vai không mở được màn bảo hành.
     */
    public function test_ten_khach_trong_mau_qua_han_luon_bi_mask(): void
    {
        $this->createOverdueCase();

        // SUPER_ADMIN là vai CÓ quyền xem PII — vẫn phải nhận bản mask.
        $response = $this->getJson('/api/v1/reports/dashboard-overview', $this->superAdminHeaders());

        $response->assertOk();
        $sample = $response->json('data.warranty.overdue_sample');
        $names = array_column($sample, 'customer_name');

        $this->assertNotContains(self::NAME, $names, 'Tên khách trả về ở dạng rõ trên màn Dashboard.');
        $this->assertContains('N*** T*** H***', $names, 'Tên khách không được mask theo đúng §13.8.');

        // Cột kỹ thuật không được rò ra response: `customer_phone_lookup` là vân tay
        // HMAC tất định, client giữ nó có thể đối chiếu "cùng một khách" giữa hai hồ sơ.
        $encoded = (string) json_encode($sample);
        $this->assertStringNotContainsString('ciphertext', $encoded);
        $this->assertStringNotContainsString('lookup', $encoded);
    }

    private function createOverdueCase(): void
    {
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => (string) Str::uuid(),
            'warranty_case_code' => 'WC-DASH-'.Str::upper(Str::random(6)),
            'identification_status' => 'TEMP_ONLY',
            'temporary_item_code' => 'TEMP-'.Str::upper(Str::random(8)),
            'missing_code_reason' => 'test',
            'manual_product_description' => 'test',
            'status' => 'RECEIVED',
            'customer_name' => self::NAME,
            'customer_phone' => '0912345678',
            'customer_address' => '12 Lê Lợi, Quận 1, TP.HCM',
            // Hạn dự kiến đã trôi qua + status chưa kết thúc = đúng định nghĩa "quá hạn".
            'estimated_completion_date' => now()->subDays(3)->toDateString(),
            'received_at' => now()->subDays(10),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function key(string $seed): string
    {
        return 'base64:'.base64_encode(hash('sha256', 'wwhn-dashboard-overview-'.$seed, true));
    }
}
