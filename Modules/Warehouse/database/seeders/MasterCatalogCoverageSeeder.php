<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phủ kín dữ liệu gốc cho 3 màn danh mục — yêu cầu người dùng 04/09: mọi giá
 * trị của mọi bộ lọc phải ra dữ liệu, mọi cột phải có nội dung.
 *
 * Trạng thái danh mục sau bộ seeder cũ chỉ có ACTIVE, nên bộ lọc "Trạng thái =
 * Ngừng sử dụng" của 5 tab màn "Danh mục sản phẩm" (Hãng / Nhóm hàng / Model /
 * Nguồn năng lượng / Quy cách đóng gói), màn "Danh mục bệnh/lỗi" và màn "Danh
 * mục đại lý / nơi nhận" đều trả rỗng. Riêng nơi nhận còn thiếu 3/4 "Loại đơn
 * nhận" (chỉ có AGENCY).
 *
 * Nguyên tắc:
 *  - CHỈ THÊM bản ghi mới; KHÔNG hạ ACTIVE→INACTIVE các bản ghi đang được SKU/
 *    chứng từ tham chiếu (sẽ làm hỏng luồng tạo phiếu và các bộ E2E đang chốt).
 *    Bản ghi INACTIVE ở đây là danh mục "đã ngừng kinh doanh" — đúng nghiệp vụ
 *    và không có SKU nào trỏ vào.
 *  - Mã/tên bám catalog thật của Hoa Nam (nhà phân phối DongCheng).
 *  - Idempotent theo mã tự nhiên (updateOrInsert), chạy lại không nhân bản.
 *
 * PHẠM VI (thu hẹp sau khi có danh mục thật): seeder này CHỈ còn lo từ vựng dùng
 * chung của kho — nguồn năng lượng, quy cách đóng gói, danh mục bệnh/lỗi và nơi
 * nhận. Hãng / nhóm hàng / dòng máy do DongChengCatalogSeeder nạp từ catalog thật.
 */
class MasterCatalogCoverageSeeder extends Seeder
{
    /** @var list<array{0:string,1:string,2:string,3:string,4:int}> [code, name, description, status, sort_order] */
    private const POWER_SOURCES = [
        ['HYBRID', 'Hybrid xăng - điện', 'Máy chạy song song động cơ xăng và mô-tơ điện.', 'ACTIVE', 10],
        ['PNEUMATIC', 'Khí nén', 'Dụng cụ chạy bằng khí nén từ máy nén khí.', 'ACTIVE', 11],
        ['MANUAL', 'Cơ tay (không động cơ)', 'Dụng cụ cầm tay hoàn toàn cơ khí.', 'ACTIVE', 12],
        ['KEROSENE', 'Dầu hoả (ngừng KD)', 'Nguồn năng lượng đời cũ, không còn dòng máy nào sử dụng.', 'INACTIVE', 80],
    ];

    /** @var list<array{0:string,1:string,2:string,3:string,4:int}> [code, name, description, status, sort_order] */
    private const PACKAGE_VARIANTS = [
        ['DUAL_BATTERY', 'Dual battery — kèm 2 pin + sạc nhanh', 'Máy kèm 2 pin dung lượng cao và sạc nhanh.', 'ACTIVE', 10],
        ['PRO_CASE', 'Pro case — kèm vali nhựa', 'Máy đóng trong vali nhựa chuyên dụng kèm bộ phụ kiện.', 'ACTIVE', 11],
        ['REFURB', 'Hàng tân trang (ngừng KD)', 'Quy cách hàng tân trang — đã dừng bán từ 06/2026.', 'INACTIVE', 80],
    ];

    /**
     * Danh mục bệnh/lỗi bám thực tế bảo hành máy nông nghiệp + dụng cụ cầm tay.
     *
     * @var list<array{0:string,1:string,2:string,3:string}> [code, name, description, status]
     */
    private const DEFECTS = [
        ['DEF-006', 'Rò rỉ nhớt/nhiên liệu', 'Rò dầu ở gioăng nắp máy, bơm cao áp hoặc đường ống nhiên liệu.', 'ACTIVE'],
        ['DEF-007', 'Máy nóng bất thường', 'Quá nhiệt do két nước, quạt gió hoặc hệ thống làm mát hỏng.', 'ACTIVE'],
        ['DEF-008', 'Hỏng hệ thống khởi động', 'Đề không quay, rơ-le hoặc củ đề hỏng, dây khởi động đứt.', 'ACTIVE'],
        ['DEF-009', 'Kêu to / rung lắc bất thường', 'Bạc đạn mòn, mất cân bằng trục hoặc lỏng chân máy.', 'ACTIVE'],
        ['DEF-010', 'Yếu công suất', 'Máy chạy yếu, khói đen, hao nhiên liệu do buồng đốt hoặc kim phun.', 'ACTIVE'],
        ['DEF-011', 'Chai pin / không sạc được', 'Pin không giữ điện, mạch sạc hỏng ở dòng dụng cụ dùng pin.', 'ACTIVE'],
        ['DEF-012', 'Mòn/gãy lưỡi cắt', 'Lưỡi cắt, xích cưa, dao xới bị mòn hoặc gãy răng.', 'ACTIVE'],
        ['DEF-013', 'Chập điện / cháy mô-tơ', 'Cháy cuộn dây mô-tơ, chập mạch điều khiển.', 'ACTIVE'],
        ['DEF-014', 'Sai lệch thông số đo', 'Dụng cụ đo lệch chuẩn ngoài dung sai cho phép.', 'ACTIVE'],
        ['DEF-015', 'Hư hỏng do vận chuyển', 'Móp méo, cong vênh phát sinh trên đường vận chuyển.', 'ACTIVE'],
        ['DEF-901', 'Lỗi tem nhãn (ngừng dùng)', 'Mã lỗi cũ, đã gộp vào "Thiếu linh kiện" từ 06/2026.', 'INACTIVE'],
        ['DEF-902', 'Lỗi bao bì (ngừng dùng)', 'Mã lỗi cũ dùng cho khiếu nại bao bì, không còn áp dụng.', 'INACTIVE'],
        ['DEF-903', 'Lỗi không xác định (ngừng dùng)', 'Mã tạm thời thời kỳ đầu, đã thay bằng bộ mã chi tiết.', 'INACTIVE'],
    ];

    /**
     * Nơi nhận — phủ đủ 4 loại đơn nhận × 2 trạng thái × 3 vùng thị trường.
     *
     * @var list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string}>
     *                                                                                                    [code, name, recipient_type, status, market, region, contact_name, phone, address]
     */
    private const AGENCIES = [
        // Nhà phân phối (DISTRIBUTOR)
        ['NPP-001', 'Nhà phân phối Hoa Nam Miền Bắc', 'DISTRIBUTOR', 'ACTIVE', 'MIEN_BAC', 'HA_NOI', 'Nguyễn Đức Thắng', '0901234501', 'Lô B4 KCN Sài Đồng, Long Biên, Hà Nội'],
        ['NPP-002', 'Nhà phân phối Trường Phát Miền Trung', 'DISTRIBUTOR', 'ACTIVE', 'MIEN_TRUNG', 'DA_NANG', 'Trần Quang Vinh', '0901234502', '218 Điện Biên Phủ, Thanh Khê, Đà Nẵng'],
        ['NPP-003', 'Nhà phân phối Tây Nguyên Xanh', 'DISTRIBUTOR', 'ACTIVE', 'MIEN_TRUNG', 'DAK_LAK', 'Lê Thị Ngọc Diệp', '0901234503', '45 Nguyễn Tất Thành, TP. Buôn Ma Thuột, Đắk Lắk'],
        ['NPP-004', 'Nhà phân phối Đồng Bằng Sông Cửu Long', 'DISTRIBUTOR', 'ACTIVE', 'MIEN_NAM', 'CAN_THO', 'Phạm Hoàng Vũ', '0901234504', '77 Trần Hưng Đạo, Ninh Kiều, Cần Thơ'],
        ['NPP-090', 'Nhà phân phối Bắc Sơn (ngừng hợp tác)', 'DISTRIBUTOR', 'INACTIVE', 'MIEN_BAC', 'BAC_NINH', 'Đỗ Văn Kiên', '0901234590', 'KCN Tiên Sơn, Tiên Du, Bắc Ninh'],

        // Đại lý (AGENCY) — bổ sung ngoài AG-001..005 sẵn có
        ['AG-006', 'Đại lý Hoa Nam Nghệ An', 'AGENCY', 'ACTIVE', 'MIEN_TRUNG', 'NGHE_AN', 'Hoàng Văn Sơn', '0901234506', '132 Lê Lợi, TP. Vinh, Nghệ An'],
        ['AG-007', 'Đại lý Hoa Nam Bình Dương', 'AGENCY', 'ACTIVE', 'MIEN_NAM', 'BINH_DUONG', 'Nguyễn Thị Thanh', '0901234507', '89 Đại lộ Bình Dương, Thủ Dầu Một, Bình Dương'],
        ['AG-008', 'Đại lý Hoa Nam Lâm Đồng', 'AGENCY', 'ACTIVE', 'MIEN_TRUNG', 'LAM_DONG', 'Vũ Minh Tuấn', '0901234508', '21 Phan Đình Phùng, TP. Đà Lạt, Lâm Đồng'],
        ['AG-009', 'Đại lý Hoa Nam Thái Bình', 'AGENCY', 'ACTIVE', 'MIEN_BAC', 'THAI_BINH', 'Bùi Thị Hoà', '0901234509', '58 Lý Thường Kiệt, TP. Thái Bình'],
        ['AG-090', 'Đại lý Hoa Nam Quảng Ninh (ngừng hợp tác)', 'AGENCY', 'INACTIVE', 'MIEN_BAC', 'QUANG_NINH', 'Trịnh Văn Hải', '0901234590', '12 Trần Phú, TP. Hạ Long, Quảng Ninh'],
        ['AG-091', 'Đại lý Hoa Nam Tiền Giang (ngừng hợp tác)', 'AGENCY', 'INACTIVE', 'MIEN_NAM', 'TIEN_GIANG', 'Lý Thị Bảy', '0901234591', '5 Ấp Bắc, TP. Mỹ Tho, Tiền Giang'],

        // Khách hàng dự án (PROJECT_CUSTOMER)
        ['KHDA-001', 'Nông trường cà phê Ea Kar', 'PROJECT_CUSTOMER', 'ACTIVE', 'MIEN_TRUNG', 'DAK_LAK', 'Nguyễn Hữu Đạt', '0901234511', 'Thôn 3, xã Ea Kar, huyện Ea Kar, Đắk Lắk'],
        ['KHDA-002', 'Hợp tác xã lúa gạo Thới Lai', 'PROJECT_CUSTOMER', 'ACTIVE', 'MIEN_NAM', 'CAN_THO', 'Trần Văn Bé Tư', '0901234512', 'Ấp Thới Thuận, xã Thới Tân, Thới Lai, Cần Thơ'],
        ['KHDA-003', 'Trang trại chăn nuôi Ba Vì', 'PROJECT_CUSTOMER', 'ACTIVE', 'MIEN_BAC', 'HA_NOI', 'Phan Thị Hồng', '0901234513', 'Xã Vân Hoà, huyện Ba Vì, Hà Nội'],
        ['KHDA-004', 'Dự án tưới tiêu Krông Pắc', 'PROJECT_CUSTOMER', 'ACTIVE', 'MIEN_TRUNG', 'DAK_LAK', 'Ngô Quang Trung', '0901234514', 'Thị trấn Phước An, Krông Pắc, Đắk Lắk'],
        ['KHDA-090', 'Dự án nhà kính Mộc Châu (đã kết thúc)', 'PROJECT_CUSTOMER', 'INACTIVE', 'MIEN_BAC', 'SON_LA', 'Đinh Văn Chiến', '0901234590', 'Tiểu khu 4, thị trấn Mộc Châu, Sơn La'],

        // Khách lẻ (RETAIL_CUSTOMER)
        ['KHL-001', 'Khách lẻ - Nguyễn Văn Tám', 'RETAIL_CUSTOMER', 'ACTIVE', 'MIEN_NAM', 'HO_CHI_MINH', 'Nguyễn Văn Tám', '0901234521', '145 Lê Văn Việt, TP. Thủ Đức, TP.HCM'],
        ['KHL-002', 'Khách lẻ - Trần Thị Chín', 'RETAIL_CUSTOMER', 'ACTIVE', 'MIEN_BAC', 'HAI_PHONG', 'Trần Thị Chín', '0901234522', '30 Tô Hiệu, Lê Chân, Hải Phòng'],
        ['KHL-003', 'Khách lẻ - Lê Hoàng Mười', 'RETAIL_CUSTOMER', 'ACTIVE', 'MIEN_TRUNG', 'QUANG_NAM', 'Lê Hoàng Mười', '0901234523', 'Khối phố 5, TP. Tam Kỳ, Quảng Nam'],
        ['KHL-004', 'Khách lẻ - Phạm Thị Mười Một', 'RETAIL_CUSTOMER', 'ACTIVE', 'MIEN_NAM', 'DONG_NAI', 'Phạm Thị Mười Một', '0901234524', '78 Phạm Văn Thuận, Biên Hoà, Đồng Nai'],
        ['KHL-090', 'Khách lẻ - Hồ Văn Sáu (ngừng giao dịch)', 'RETAIL_CUSTOMER', 'INACTIVE', 'MIEN_NAM', 'HO_CHI_MINH', 'Hồ Văn Sáu', '0901234590', '9 Nguyễn Oanh, Gò Vấp, TP.HCM'],
    ];

    private ?string $actorId = null;

    public function run(): void
    {
        $this->actorId = DB::table('app_user')->where('username', 'admin')->value('user_id');

        // Hãng / dòng máy / nhóm hàng nay đến TỪ CATALOG THẬT (DongChengCatalogSeeder):
        // Hoa Nam là nhà phân phối DongCheng, chưa bao giờ bán Kubota/Yanmar/Honda/
        // Husqvarna/Makita/Stihl/Mitsubishi/Total/Robin/Sumo hay máy gặt/máy kéo. Ba
        // hằng số CATEGORIES/BRANDS/MODELS cũ đã bị gỡ khỏi seeder này; phần còn lại
        // (nguồn năng lượng, quy cách, bệnh-lỗi, nơi nhận) là từ vựng dùng chung của
        // kho nên vẫn giữ nguyên.
        $this->seedRefTable('power_sources', 'power_source_code', 'power_source_name', self::POWER_SOURCES);
        $this->seedRefTable('package_variants', 'variant_code', 'variant_name', self::PACKAGE_VARIANTS, [
            'package_code' => 0, 'package_name' => 1,
        ]);
        $this->seedDefects();
        $this->seedAgencies();

        $this->command?->info(
            'MasterCatalogCoverageSeeder: danh mục sản phẩm / bệnh-lỗi / nơi nhận đã đủ mọi trạng thái và loại đơn nhận.'
        );
    }

    /**
     * @param  list<array{0:string,1:string,2:string,3:string,4:int}>  $rows
     * @param  array<string,int>  $mirrorColumns  cột phụ cần chép từ vị trí thứ n của $row
     */
    private function seedRefTable(string $table, string $codeColumn, string $nameColumn, array $rows, array $mirrorColumns = []): void
    {
        foreach ($rows as [$code, $name, $description, $status, $sortOrder]) {
            $attributes = [
                $nameColumn => $name,
                'description' => $description,
                'status' => $status,
                'sort_order' => $sortOrder,
                'updated_at' => now(),
                'updated_by' => $this->actorId,
            ];
            foreach ($mirrorColumns as $column => $sourceIndex) {
                $attributes[$column] = [$code, $name, $description, $status, $sortOrder][$sourceIndex];
            }

            $this->upsert($table, [$codeColumn => $code], $attributes);
        }
    }


    private function seedDefects(): void
    {
        foreach (self::DEFECTS as [$code, $name, $description, $status]) {
            $exists = DB::table('defect_catalogs')->where('defect_code', $code)->exists();
            if ($exists) {
                DB::table('defect_catalogs')->where('defect_code', $code)->update([
                    'defect_name' => $name,
                    'description' => $description,
                    'status' => $status,
                    'updated_at' => now(),
                ]);

                continue;
            }

            $id = (string) Str::uuid();
            DB::table('defect_catalogs')->insert([
                'defect_id' => $id,
                'id' => $id,
                'defect_code' => $code,
                'defect_name' => $name,
                'description' => $description,
                'status' => $status,
                'created_by' => $this->actorId,
                'updated_by' => $this->actorId,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedAgencies(): void
    {
        foreach (self::AGENCIES as [$code, $name, $type, $status, $market, $region, $contact, $phone, $address]) {
            $attributes = [
                'agency_name' => $name,
                'recipient_type' => $type,
                'status' => $status,
                'market' => $market,
                'region' => $region,
                'contact_name' => $contact,
                'phone' => $phone,
                'address' => $address,
                'updated_at' => now(),
                'updated_by' => $this->actorId,
            ];

            if (DB::table('agencies')->where('agency_code', $code)->exists()) {
                DB::table('agencies')->where('agency_code', $code)->update($attributes);

                continue;
            }

            $id = (string) Str::uuid();
            DB::table('agencies')->insert([
                'agency_id' => $id,
                'id' => $id,
                'agency_code' => $code,
                'created_by' => $this->actorId,
                'version' => 1,
                'created_at' => now(),
                ...$attributes,
            ]);
        }

        // Nơi nhận gốc AG-001..005 chưa có "loại đơn nhận" đa dạng; giữ nguyên
        // AGENCY (đúng bản chất) nhưng bảo đảm cột liên hệ/thị trường đủ dữ liệu.
        DB::table('agencies')
            ->whereNull('market')
            ->update(['market' => 'MIEN_BAC', 'region' => 'HA_NOI', 'updated_at' => now()]);
    }

    /** @param array<string,mixed> $attributes */
    private function upsert(string $table, array $key, array $attributes): void
    {
        if (DB::table($table)->where($key)->exists()) {
            DB::table($table)->where($key)->update($attributes);

            return;
        }

        DB::table($table)->insert([
            'id' => (string) Str::uuid(),
            'created_at' => now(),
            'created_by' => $this->actorId,
            'version' => 1,
            ...$key,
            ...$attributes,
        ]);
    }
}
