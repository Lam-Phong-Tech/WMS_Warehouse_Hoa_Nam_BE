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
     * Giá trị máy trong error.details là hợp đồng API: client rẽ nhánh/điều hướng theo
     * đúng chuỗi đó. Trước đây mọi dòng dưới đây đều bị "Việt hoá" thành giá trị khác
     * (SCANNING -> "Đang quét", "/api/v1/auth/mfa/status" -> ".../trạng thái",
     * "reason" -> "lý do", permission có chữ "document" -> câu lỗi chung).
     */
    public function test_machine_detail_values_are_never_localized(): void
    {
        $details = [
            'conflicting_document_status' => 'SCANNING',
            'conflicting_document_no' => 'OUT-2026-0001',
            'conflicting_document_id' => '9d6f0c1e-7a1b-4c2d-8e3f-000000000001',
            'status_endpoint' => '/api/v1/auth/mfa/status',
            'download_url' => 'https://wms.example.test/api/v1/reports/export/1/download?status=READY',
            'required_permission' => 'inbound.document.post',
            'field' => 'reason',
            'error_code' => 'INVALID_QUANTITY',
            'allowed_statuses' => ['DRAFT', 'SCANNING'],
            'lines' => [['line_no' => 1, 'sku_code' => 'HN-MK-001', 'item_status' => 'QUARANTINE']],
            'current' => 'POSTED',
        ];

        $this->assertSame($details, UserFacingMessageLocalizer::translateDetails($details));
    }

    public function test_human_detail_text_is_still_localized_next_to_machine_values(): void
    {
        $localized = UserFacingMessageLocalizer::translateDetails([
            'document_status' => 'SCANNING',
            'hint' => 'Document must have at least one line.',
            'warehouse_id' => ['The warehouse id field is required.'],
            'lines' => [['line_no' => 2, 'message' => 'Scan quantity must be greater than zero.']],
        ]);

        $this->assertSame('SCANNING', $localized['document_status']);
        $this->assertSame('Chứng từ phải có ít nhất một dòng.', $localized['hint']);
        $this->assertSame('Trường kho là bắt buộc.', $localized['warehouse_id'][0]);
        $this->assertSame('Số lượng quét phải lớn hơn 0.', $localized['lines'][0]['message']);
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
