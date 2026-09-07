<?php

namespace Modules\Warehouse\Database\Seeders;

/**
 * Mã hàng THẬT mà các seeder nghiệp vụ neo vào.
 *
 * Trước đây mỗi seeder tự đặt một mã demo (`HN-PRD-SEED-001`, `HN-PV-BASE-002`,
 * `HN-COMP-RPTW-001`...) rồi tự tạo SKU bịa tên nếu chưa có. Sau khi danh mục
 * thật DongCheng vào hệ, các mã đó là dữ liệu rác: chúng hiện lên màn "Danh sách
 * SKU", App PV và mọi báo cáo cạnh hàng thật.
 *
 * Nay mọi seeder tham chiếu hằng số ở đây. `DongChengCatalogSeeder` chạy TRƯỚC
 * nên các mã này đã tồn tại với đầy đủ thuộc tính thật (ảnh, thông số, nhóm
 * hàng, sê-ri); `ensureSku()` của các seeder sau chỉ tra ra và dùng lại, KHÔNG
 * ghi đè. Muốn đổi mã neo thì đổi ở đây, không rải rác từng file.
 *
 * Chọn mã theo đúng vai trò kỹ thuật mà seeder cần:
 *  - vai PRODUCT: máy DongCheng (tracking SERIAL, có bảo hành) → sinh được
 *    item_instance/serial, mở được hồ sơ bảo hành.
 *  - vai COMPONENT: pin/sạc/phụ kiện (tracking QUANTITY) → xuất linh kiện, mã
 *    hộp `BOX-<SKU>-<seq>`.
 */
final class DongChengAnchorSkus
{
    private function __construct() {}

    /** SKU nền của hệ (MST-01 tối thiểu phải có) — dụng cụ cầm tay theo số lượng. */
    public const BASELINE_PRODUCT = 'D070106';          // Kìm điện đa năng 6 inch

    /** Máy pin serial, dùng cho luồng nhập/xuất/bảo hành mẫu. */
    public const PRODUCT_1 = 'DCPL2045';                // Máy vặn vít pin không chổi than

    public const PRODUCT_2 = 'DCZC02-26';               // Khoan búa pin không chổi than

    public const PRODUCT_3 = 'DCSM03-100';              // Máy mài góc pin không chổi than

    public const PRODUCT_4 = 'DCPB358';                 // Máy siết bu lông tác động pin

    public const PRODUCT_5 = 'DCJZ1240';                // Máy khoan/vặn vít pin

    /** Máy dùng cho bộ dữ liệu báo cáo vận hành. */
    public const PRODUCT_REPORT = 'DCZC13';             // Khoan búa dùng pin

    /**
     * Linh kiện theo SỐ LƯỢNG — luồng nhập theo hộp (`BOX-<SKU>-<seq>`) và xuất
     * linh kiện bảo hành đều đếm theo số lượng, KHÔNG theo serial.
     */
    public const COMPONENT_1 = 'DCA-BITSET';            // Bộ mũi vít thay thế

    public const COMPONENT_2 = 'DCC-20V-2A';            // Sạc nhanh 20V MAX 2A

    public const COMPONENT_3 = 'DCA-CHUCK13';           // Đầu kẹp mũi khoan 13mm

    public const COMPONENT_BOX = 'DCA-DUSTBAG';         // Túi hứng bụi — mã hộp BOX-DCA-DUSTBAG-0001

    public const COMPONENT_REPORT = 'DCA-CARBONBRUSH';  // Chổi than thay thế

    public const COMPONENT_WARRANTY = 'DCA-SWITCH';     // Công tắc nguồn thay thế

    /**
     * Linh kiện theo SERIAL — pin DongCheng có số máy riêng, dùng cho luồng
     * xuất linh kiện truy vết từng cục pin (HoaNamBulkDemoSeeder).
     */
    public const COMPONENT_SERIAL_1 = 'DCB-20V-20';     // Pin Li-ion 20V MAX 2.0Ah

    public const COMPONENT_SERIAL_2 = 'DCB-20V-40';     // Pin Li-ion 20V MAX 4.0Ah

    /** @var list<string> Mọi mã neo — luôn được nạp kể cả khi cắt bớt danh mục. */
    public const ALL = [
        self::BASELINE_PRODUCT,
        self::PRODUCT_1,
        self::PRODUCT_2,
        self::PRODUCT_3,
        self::PRODUCT_4,
        self::PRODUCT_5,
        self::PRODUCT_REPORT,
        self::COMPONENT_1,
        self::COMPONENT_2,
        self::COMPONENT_3,
        self::COMPONENT_BOX,
        self::COMPONENT_REPORT,
        self::COMPONENT_WARRANTY,
        self::COMPONENT_SERIAL_1,
        self::COMPONENT_SERIAL_2,
    ];

    /**
     * Tiền tố mã SKU demo của bộ seeder cũ — dùng để dọn dữ liệu rác trên CSDL
     * phát triển đã seed từ trước (xem RetireDemoCatalogSeeder).
     *
     * @var list<string>
     */
    public const LEGACY_DEMO_PREFIXES = [
        'HN-PV-BASE-', 'HN-PRD-SEED-', 'HN-COMP-SEED-', 'HN-COMP-BASE-', 'HN-COMP-RPTW-',
    ];
}
