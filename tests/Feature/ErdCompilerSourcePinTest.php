<?php

namespace Tests\Feature;

use App\Support\HoaNamErdSchema;
use Tests\TestCase;

class ErdCompilerSourcePinTest extends TestCase
{
    /**
     * 59 → 60 bảng từ ERD-AMD-009 (`60. document_attachments`, ghi bù 10/09/2026 cho thay
     * đổi ở 368943c). Sign-off BA + Tech Lead của amendment đó còn PENDING — xem §0.0 của
     * ERD và docs/evidence/WWHN-310/ERD_AMENDMENT_REVIEW_RECORD.md. Con số vẫn pin cứng:
     * thêm bảng thứ 61 phải đi kèm một dòng ERD-AMD mới và sửa đúng dòng này.
     */
    public function test_erd_document_compiles_to_exactly_60_canonical_tables_after_erd_amd_009(): void
    {
        $schema = HoaNamErdSchema::parse();

        $this->assertCount(HoaNamErdSchema::TABLE_COUNT, $schema);
        $this->assertSame(60, HoaNamErdSchema::TABLE_COUNT);
        $this->assertArrayHasKey('document_attachments', $schema);
        $this->assertSame('HN-WMS-ERD-DD-001', HoaNamErdSchema::DOCUMENT_ID);
        $this->assertSame('docs/HoaNam_ERD_Moi_Nhat.md', HoaNamErdSchema::DOCUMENT_PATH);
    }
}
