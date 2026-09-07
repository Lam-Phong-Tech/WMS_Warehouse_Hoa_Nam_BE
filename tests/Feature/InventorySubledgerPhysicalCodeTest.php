<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Chi tiết tồn kho (subledger): một item mang NHIỀU mã vật lý (mã in barcode/QR
 * + thẻ RFID) chỉ được tính/hiển thị là MỘT sản phẩm — không double-count như
 * khi join 1-nhiều (2 dòng cho 1 item, item_count = 2 dù tồn = 1).
 */
class InventorySubledgerPhysicalCodeTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_item_nhieu_ma_vat_ly_chi_la_mot_dong_va_tach_rfid(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('SUBLEDGER-DUP-01');

        // Một item duy nhất, gắn cả mã in (QR/barcode) lẫn thẻ RFID.
        $itemId = $this->seedItemInStock($sku->sku_id);
        $this->seedScanCode($itemId, 'SN-SUBLEDGER-DUP-01');
        $this->seedRfidCode($itemId);
        $this->seedAvailableBalance((string) $warehouse->id, (string) $sku->sku_id, 1);

        $balanceId = (string) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $sku->sku_id)
            ->value('id');

        $res = $this
            ->getJson("/api/v1/inventory/balances/{$balanceId}/subledger", $headers)
            ->assertOk();

        // Một item = một dòng, item_count = 1 (khớp tồn), không phải 2.
        $res->assertJsonPath('data.item_count', 1);
        $this->assertCount(1, $res->json('data.items'));

        // Mã in hiển thị ở physical_code; RFID tách riêng ở rfid_code.
        $this->assertSame('SN-SUBLEDGER-DUP-01', $res->json('data.items.0.physical_code'));
        $this->assertStringStartsWith('RFID-', (string) $res->json('data.items.0.rfid_code'));

        // Chưa dán chip thì cột "Mã NFC" của web phải rỗng, không mượn mã khác.
        $this->assertNull($res->json('data.items.0.nfc_code'));
        $this->assertSame('UNASSIGNED', $res->json('data.items.0.nfc_status'));
    }

    public function test_chip_nfc_tach_rieng_va_khong_lot_vao_o_ma_vat_ly(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('SUBLEDGER-NFC-01');

        // Hiện vật CHỈ mang chip NFC — chưa có mã in nào. Đây là ca dễ sai nhất:
        // nếu NFC không bị loại khỏi nhánh "mã in", alias `NFC-<uid>` sẽ hiện ở
        // cột "Mã vật lý" và người dùng đọc nhầm thành mã dán trên máy.
        $itemId = $this->seedItemInStock($sku->sku_id);
        $codeValue = $this->seedNfcTag($itemId);
        $this->seedAvailableBalance((string) $warehouse->id, (string) $sku->sku_id, 1);

        $balanceId = (string) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $sku->sku_id)
            ->value('id');

        $res = $this
            ->getJson("/api/v1/inventory/balances/{$balanceId}/subledger", $headers)
            ->assertOk();

        $res->assertJsonPath('data.item_count', 1);
        $this->assertSame($codeValue, $res->json('data.items.0.nfc_code'));
        $this->assertStringStartsWith('NFCUID', (string) $res->json('data.items.0.nfc_hardware_uid'));
        $this->assertSame('ASSIGNED', $res->json('data.items.0.nfc_status'));
        $this->assertNull($res->json('data.items.0.physical_code'));
    }

    public function test_chip_da_thu_hoi_khong_con_tinh_la_da_dan(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('SUBLEDGER-NFC-02');

        // Chip đã INACTIVE (mất/thay) nghĩa là hiện vật đang trần và phải quay lại
        // worklist dán chip — cùng predicate với MiniAppNfcController::listItems.
        $itemId = $this->seedItemInStock($sku->sku_id);
        $this->seedNfcTag($itemId, 'INACTIVE');
        $this->seedAvailableBalance((string) $warehouse->id, (string) $sku->sku_id, 1);

        $balanceId = (string) DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $sku->sku_id)
            ->value('id');

        $res = $this
            ->getJson("/api/v1/inventory/balances/{$balanceId}/subledger", $headers)
            ->assertOk();

        $this->assertNull($res->json('data.items.0.nfc_code'));
        $this->assertSame('UNASSIGNED', $res->json('data.items.0.nfc_status'));
        // Chip thu hồi vẫn không được rơi xuống ô "Mã vật lý".
        $this->assertNull($res->json('data.items.0.physical_code'));
    }
}
