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
