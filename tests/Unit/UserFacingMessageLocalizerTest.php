<?php

namespace Tests\Unit;

use App\Support\UserFacingMessageLocalizer;
use PHPUnit\Framework\TestCase;

class UserFacingMessageLocalizerTest extends TestCase
{
    public function test_it_localizes_legacy_business_messages_without_changing_codes(): void
    {
        $this->assertSame(
            'Chứng từ phải có ít nhất một dòng.',
            UserFacingMessageLocalizer::translate('Document must have at least one line.')
        );
        $this->assertSame(
            'Số lượng quét phải lớn hơn 0.',
            UserFacingMessageLocalizer::translate('Scan quantity must be greater than zero.')
        );
        // Localizer thay thuật ngữ "Post Issue" → "Hoàn tất xuất kho" giữ nguyên
        // hoa/thường của cụm thuật ngữ (không hạ chữ cái đầu theo vị trí trong câu).
        $this->assertSame(
            'Không thể Hoàn tất xuất kho khi phiếu đang ở trạng thái "Đang quét".',
            UserFacingMessageLocalizer::translate('Không thể Post Issue khi phiếu đang ở trạng thái "SCANNING".')
        );
    }

    /**
     * Lỗi UAT 04-09-2026: dialog "Cập nhật trạng thái bảo hành" hiện nguyên câu
     * "Warranty case version mismatch." — câu này không chứa từ khoá nào của
     * stillLooksEnglish() nên lưới fail-closed không bắt được, và người đọc không
     * biết phải làm gì. Mọi message 412 STALE_VERSION phải là tiếng Việt và nói rõ
     * hành động (tải lại rồi thao tác lại).
     */
    public function test_it_localizes_optimistic_locking_messages_with_an_actionable_sentence(): void
    {
        $this->assertSame(
            'Hồ sơ bảo hành vừa được cập nhật ở nơi khác. Hãy tải lại rồi thao tác lại.',
            UserFacingMessageLocalizer::translate('Warranty case version mismatch.')
        );
        $this->assertSame(
            'Nhãn đóng gói vừa được cập nhật ở nơi khác. Hãy tải lại rồi thao tác lại.',
            UserFacingMessageLocalizer::translate('Packing Label version mismatch.')
        );
        // Đối tượng chưa liệt kê ở EXACT vẫn ra câu tiếng Việt nói rõ hành động.
        $this->assertSame(
            'Dữ liệu vừa được cập nhật ở nơi khác. Hãy tải lại rồi thao tác lại.',
            UserFacingMessageLocalizer::translate('Stocktake session version mismatch.')
        );
    }

    public function test_it_localizes_laravel_validation_detail_values_and_preserves_field_keys(): void
    {
        $details = [
            'update_existing' => [
                'The update existing field must be true or false.',
            ],
            'sku_code' => [
                'The sku code field is required.',
            ],
        ];

        $localized = UserFacingMessageLocalizer::translateDetails($details);

        $this->assertArrayHasKey('update_existing', $localized);
        $this->assertArrayHasKey('sku_code', $localized);
        $this->assertSame(
            'Trường cho phép cập nhật dữ liệu hiện có phải là đúng hoặc sai.',
            $localized['update_existing'][0]
        );
        $this->assertSame('Trường mã SKU là bắt buộc.', $localized['sku_code'][0]);
    }

    /**
     * Phát hiện 26/08: "permission" và "product" là từ mượn tiếng Anh dùng phổ biến
     * TRONG câu tiếng Việt hợp lệ của chính codebase (RoleController::assignPermissions,
     * ProductMasterController) — nếu 2 từ này còn nằm trong danh sách kích hoạt
     * `stillLooksEnglish()`, message THÀNH CÔNG hợp lệ bị thay nhầm bằng câu lỗi
     * chung, khiến UI báo sai kết quả dù request đã xử lý đúng.
     */
    public function test_vietnamese_messages_using_permission_or_product_as_loanwords_pass_through_unchanged(): void
    {
        $this->assertSame(
            'Gán permission cho role thành công',
            UserFacingMessageLocalizer::translate('Gán permission cho role thành công')
        );
        $this->assertSame(
            'Tạo product master thành công',
            UserFacingMessageLocalizer::translate('Tạo product master thành công')
        );
        $this->assertSame(
            'Không tìm thấy permission.',
            UserFacingMessageLocalizer::translate('Không tìm thấy permission.')
        );
    }

    public function test_unknown_english_package_message_fails_closed_to_vietnamese(): void
    {
        $this->assertSame(
            'Không thể thực hiện yêu cầu do dữ liệu hoặc trạng thái hiện tại không hợp lệ.',
            UserFacingMessageLocalizer::translate(
                'The external provider error should only be shown when the server is ready.'
            )
        );
    }

    public function test_it_keeps_raw_contract_values_outside_user_facing_messages(): void
    {
        $payloadContract = [
            'error_code' => 'STATE_CONFLICT',
            'status' => 'POSTED',
            'permission' => 'inbound.post',
        ];

        $this->assertSame('STATE_CONFLICT', $payloadContract['error_code']);
        $this->assertSame('POSTED', $payloadContract['status']);
        $this->assertSame('inbound.post', $payloadContract['permission']);
    }
}
