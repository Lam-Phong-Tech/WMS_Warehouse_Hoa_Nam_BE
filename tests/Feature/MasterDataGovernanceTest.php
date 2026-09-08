<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\WarrantyCasePii;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * §8.1 Master ("Unique, active/inactive, immutable code after use") +
 * BR-MST-01..07, BR-AGY-01, BR-DEF-01..04 và Annex C updateCatalogMasterEntry.
 */
class MasterDataGovernanceTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_catalog_master_can_be_updated_and_deactivated(): void
    {
        $headers = $this->superAdminHeaders();

        $brandId = (string) $this->postJson('/api/v1/brands', [
            'brand_code' => 'BRAND-GOV-001',
            'brand_name' => 'Brand Governance',
        ], $headers)->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/brands/{$brandId}", [
            'brand_name' => 'Brand Governance v2',
            'status' => 'INACTIVE',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.brand_name', 'Brand Governance v2')
            ->assertJsonPath('data.status', 'INACTIVE');
    }

    public function test_catalog_master_code_is_immutable_after_sku_reference(): void
    {
        $headers = $this->superAdminHeaders();
        $brandId = (string) $this->postJson('/api/v1/brands', [
            'brand_code' => 'BRAND-GOV-002',
            'brand_name' => 'Brand Referenced',
        ], $headers)->assertCreated()->json('data.id');

        // sku.brand_id FK trỏ product_masters. Trên PostgreSQL trigger đồng bộ
        // canonical → product_masters đã tạo sẵn hàng này; sqlite không có trigger
        // đó nên fixture phải tự tạo để FK thoả mãn.
        if (DB::getDriverName() !== 'pgsql') {
            DB::table('product_masters')->insert([
                'master_id' => $brandId,
                'master_type' => 'BRAND',
                'code' => 'BRAND-GOV-002',
                'name' => 'Brand Referenced',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Sku::create([
            'code' => 'SKU-BRAND-REF', 'name' => 'SKU dùng brand', 'sku_type' => 'PRODUCT',
            'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE', 'brand_id' => $brandId,
        ]);

        $this->patchJson("/api/v1/brands/{$brandId}", ['brand_code' => 'BRAND-GOV-002-NEW'], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MASTER_CODE_IMMUTABLE_AFTER_USE');

        // Đổi tên/ngừng kích hoạt vẫn được — chỉ mã bị khoá.
        $this->patchJson("/api/v1/brands/{$brandId}", ['brand_name' => 'Tên mới hợp lệ'], $headers)->assertOk();
    }

    public function test_catalog_master_update_honours_if_match(): void
    {
        $headers = $this->superAdminHeaders();
        $brandId = (string) $this->postJson('/api/v1/brands', [
            'brand_code' => 'BRAND-GOV-003',
            'brand_name' => 'Brand Version',
        ], $headers)->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/brands/{$brandId}", ['brand_name' => 'Sai version'], $headers + ['If-Match' => '99'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION');

        $this->patchJson("/api/v1/brands/{$brandId}", ['brand_name' => 'Đúng version'], $headers + ['If-Match' => '0'])
            ->assertOk();
    }

    public function test_agency_code_is_immutable_once_used_on_document(): void
    {
        $headers = $this->superAdminHeaders();
        $agencyId = (string) $this->postJson('/api/v1/agencies', [
            'agency_code' => 'AGY-GOV-001',
            'agency_name' => 'Đại lý test',
            // GĐ1-REQ-001: recipient_type (4 loại nơi nhận) là trường bắt buộc.
            'recipient_type' => 'AGENCY',
        ], $headers)->assertCreated()->json('data.agency_id');

        DB::table('stock_documents')->insert([
            'id' => (string) Str::uuid(),
            'doc_no' => 'OUT-AGY-GOV-001',
            'doc_type' => 'issue',
            'doc_date' => now()->toDateString(),
            'status' => 'DRAFT',
            'agency_id' => $agencyId,
            'src_warehouse_id' => $this->warehouseFixture()->id,
            // Mirror outbound_documents yêu cầu recipient_name NOT NULL.
            'recipient_name' => 'Người nhận test',
            'version' => 1,
            'created_by' => $headers['__user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // BaM1RecipientController (GĐ1): mã bất biến sau khi dùng trả 422
        // AGENCY_CODE_IMMUTABLE (thay cho 409 AGENCY_CODE_IMMUTABLE_AFTER_USE cũ).
        $this->patchJson("/api/v1/agencies/{$agencyId}", ['agency_code' => 'AGY-GOV-001-NEW'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'AGENCY_CODE_IMMUTABLE');
    }

    public function test_agency_update_honours_if_match(): void
    {
        $headers = $this->superAdminHeaders();
        $agencyId = (string) $this->postJson('/api/v1/agencies', [
            'agency_code' => 'AGY-GOV-002',
            'agency_name' => 'Đại lý version',
            // GĐ1-REQ-001: recipient_type (4 loại nơi nhận) là trường bắt buộc.
            'recipient_type' => 'AGENCY',
        ], $headers)->assertCreated()->json('data.agency_id');

        $this->patchJson("/api/v1/agencies/{$agencyId}", ['agency_name' => 'Sai version'], $headers + ['If-Match' => '77'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION');
    }

    public function test_defect_code_is_immutable_once_used_by_warranty_case(): void
    {
        $headers = $this->superAdminHeaders();
        $defectId = (string) $this->postJson('/api/v1/defect-catalogs', [
            'defect_code' => 'DEF-GOV-001',
            'defect_name' => 'Lỗi test',
        ], $headers)->assertCreated()->json('data.defect_id');

        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => (string) Str::uuid(),
            'warranty_case_code' => 'WC-GOV-001',
            'identification_status' => 'TEMP_ONLY',
            'temporary_item_code' => 'TMP-GOV-001',
            'missing_code_reason' => 'Máy mất tem',
            'manual_product_description' => 'Máy khoan không rõ model',
            'defect_id' => $defectId,
            'customer_name' => 'Khách test',
            'customer_phone' => '0900000001',
            'status' => 'RECEIVED',
            'sales_trace_status' => 'NOT_TRACEABLE',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->patchJson("/api/v1/defect-catalogs/{$defectId}", ['defect_code' => 'DEF-GOV-001-NEW'], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DEFECT_CODE_IMMUTABLE_AFTER_USE');
    }

    public function test_publish_endpoint_uses_dedicated_permission_and_blocks_inactive_sku(): void
    {
        $headers = $this->superAdminHeaders();
        $sku = Sku::create([
            'code' => 'SKU-PV-PUB', 'name' => 'SKU publish', 'sku_type' => 'PRODUCT',
            'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE',
        ]);

        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => true], $headers + ['If-Match' => '0'])
            ->assertOk()
            ->assertJsonPath('data.published_to_app', true)
            ->assertJsonPath('data.pv_profile_version', 1);

        // REQ-SRS-020: SKU không ACTIVE thì không được publish.
        $this->patchJson("/api/v1/products/{$sku->sku_id}", ['status' => 'INACTIVE'], $headers)->assertOk();
        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => true], $headers + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SKU_PUBLISH_REQUIRES_ACTIVE');
    }

    public function test_warehouse_status_update_keeps_single_active_rule(): void
    {
        $headers = $this->superAdminHeaders();
        $active = $this->warehouseFixture();

        // insertGetId trả rowid trên sqlite nên phải tự sinh id để test chạy được
        // trên cả hai driver CI dùng (sqlite full-suite + pgsql ERD contract).
        $second = (string) Str::uuid();
        DB::table('warehouses')->insert([
            'id' => $second,
            'code' => 'KHO-GOV-2',
            'name' => 'Kho phụ',
            'status' => 'INACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // REQ-SRS-001: bật ACTIVE kho thứ hai phải bị chặn bằng 409, không phải 500.
        $this->patchJson("/api/v1/warehouses/{$second}", ['status' => 'ACTIVE'], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');

        $this->assertDatabaseHas('warehouses', ['id' => $active->id, 'status' => 'ACTIVE']);
    }

    public function test_inbound_scan_entries_are_readable_after_reload(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('PROD-SCAN-ENTRY');

        // Hợp đồng GĐ1 scan-driven: phiếu header-only, dòng + bằng chứng quét
        // sinh ra từ chính composite QR scan (không còn tạo phiếu kèm lines).
        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
        ], $headers)->assertCreated();
        $documentId = (string) $create->json('data.id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scans", [
            'quantity' => 1,
            'code_value' => 'HN1|SKU=PROD-SCAN-ENTRY|ITEM=SN-SCAN-ENTRY-0001',
        ], $headers + ['If-Match' => '1'])->assertOk();

        $rows = $this->getJson("/api/v1/inbound-documents/{$documentId}/scan-entries", $headers)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('CAMERA_OR_MANUAL_CODE', $rows[0]['scan_source']);
        $this->assertSame($sku->sku_id, $rows[0]['product_id']);
    }
}
