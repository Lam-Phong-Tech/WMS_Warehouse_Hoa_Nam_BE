<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Support\WarrantyCasePii;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Luồng 01 · Hệ thống & danh mục — SRS/FRD v1.12 REQ-SRS-001/002/020/026/027/028/029,
 * BR-AGY-01, BR-DEF-01..04, BR-MST-01..07, ERD bảng 9/15/16, Annex C §8.1.
 */
class Flow01SystemAndCatalogAlignmentTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $user = User::create([
            'username' => 'flow01-'.Str::random(6),
            'display_name' => 'Flow 01 tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function catalogMaster(string $table, array $row): string
    {
        $id = (string) Str::uuid();
        DB::table($table)->insert($row + [
            'id' => $id,
            'status' => 'ACTIVE',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // ---------------------------------------------------------------- P0

    /**
     * ERD bảng 16: `version` là optimistic lock, `version_code` là master phiên bản
     * của SKU (REQ-SRS-029) và là hậu tố trong quy ước mã SKU. Lock KHÔNG được ghi
     * đè version_code — trước đây mỗi lần lưu đẩy V1 -> V2.
     */
    public function test_if_match_bumps_version_without_touching_version_code(): void
    {
        $headers = $this->adminHeaders();
        $sku = Sku::create([
            'sku_code' => 'SKU-VER-01',
            'sku_name' => 'SKU version guard',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'status' => 'ACTIVE',
            'version_code' => 'V1',
        ]);

        $before = $this->getJson("/api/v1/products/{$sku->sku_id}", $headers)->assertOk();
        $currentVersion = (int) $before->json('data.version');

        $this->patchJson(
            "/api/v1/products/{$sku->sku_id}",
            ['sku_name' => 'SKU version guard v2'],
            $headers + ['If-Match' => (string) $currentVersion]
        )
            ->assertOk()
            ->assertJsonPath('data.version', $currentVersion + 1)
            ->assertJsonPath('data.version_code', 'V1');

        $this->assertSame('V1', (string) DB::table('sku')->where('sku_id', $sku->sku_id)->value('version_code'));
    }

    public function test_stale_if_match_on_sku_returns_412(): void
    {
        $headers = $this->adminHeaders();
        $sku = Sku::create([
            'sku_code' => 'SKU-VER-02',
            'sku_name' => 'SKU stale lock',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'status' => 'ACTIVE',
        ]);

        $this->patchJson(
            "/api/v1/products/{$sku->sku_id}",
            ['sku_name' => 'Ghi đè trái phép'],
            $headers + ['If-Match' => '999']
        )
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION');

        $this->assertSame('SKU stale lock', (string) DB::table('sku')->where('sku_id', $sku->sku_id)->value('sku_name'));
    }

    // ---------------------------------------------------------------- P1-2

    /** ERD bảng 16: skus.power_source_id / skus.package_variant_id là FK thật. */
    public function test_sku_links_power_source_and_package_variant_by_master_id(): void
    {
        $headers = $this->adminHeaders();
        $powerId = $this->catalogMaster('power_sources', [
            'power_source_code' => 'MAINS_DIRECT',
            'power_source_name' => 'Điện lưới trực tiếp',
        ]);
        $packageId = $this->catalogMaster('package_variants', [
            'package_code' => 'KIT',
            'package_name' => 'Kit đầy đủ',
            'variant_code' => 'KIT',
            'variant_name' => 'Kit đầy đủ',
        ]);

        $created = $this->postJson('/api/v1/products', [
            'sku_code' => 'SKU-FK-01',
            'sku_name' => 'SKU gắn master',
            'sku_type' => 'PRODUCT',
            'power_source_id' => $powerId,
            'package_variant_id' => $packageId,
        ], $headers)->assertCreated();

        $skuId = $created->json('data.sku_id') ?? $created->json('data.id');
        $row = DB::table('sku')->where('sku_id', $skuId)->first();

        $this->assertSame($powerId, (string) $row->power_source_id);
        $this->assertSame($packageId, (string) $row->package_variant_id);
        $this->assertSame('MAINS_DIRECT', (string) $row->power_or_voltage);
        $this->assertSame('KIT', (string) $row->package_variant);
    }

    /** AC-SRS-029: gán theo mã master, không cần biết uuid. */
    public function test_sku_links_master_by_code_and_rejects_inactive_master(): void
    {
        $headers = $this->adminHeaders();
        $this->catalogMaster('power_sources', [
            'power_source_code' => 'BATTERY',
            'power_source_name' => 'Pin/ắc quy',
        ]);
        $inactiveId = (string) Str::uuid();
        DB::table('power_sources')->insert([
            'id' => $inactiveId,
            'power_source_code' => 'RETIRED',
            'power_source_name' => 'Ngừng dùng',
            'status' => 'INACTIVE',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = $this->postJson('/api/v1/products', [
            'sku_code' => 'SKU-FK-02',
            'sku_name' => 'SKU gắn master theo mã',
            'power_source_code' => 'BATTERY',
        ], $headers)->assertCreated();

        $skuId = $created->json('data.sku_id') ?? $created->json('data.id');
        $this->assertNotNull(DB::table('sku')->where('sku_id', $skuId)->value('power_source_id'));

        $this->postJson('/api/v1/products', [
            'sku_code' => 'SKU-FK-03',
            'sku_name' => 'SKU gắn master INACTIVE',
            'power_source_code' => 'RETIRED',
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MASTER_NOT_ACTIVE');
    }

    // ---------------------------------------------------------------- P1-3

    /**
     * BR-AGY-01: đại lý INACTIVE không chọn được cho chứng từ mới. Sau khi gỡ
     * POST /outbound-documents (§8.1) guard nằm ở bước Confirm toa xuất.
     */
    public function test_inactive_agency_cannot_be_selected_for_new_outbound_document(): void
    {
        $headers = $this->superAdminHeaders();
        $this->warehouseFixture();
        $sku = $this->productSkuFixture('SKU-AGY-01');

        $agencyId = (string) Str::uuid();
        DB::table('agencies')->insert([
            'agency_id' => $agencyId,
            'agency_code' => 'AG-OFF',
            'agency_name' => 'Đại lý ngừng hoạt động',
            'status' => 'INACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $import = $this->postJson('/api/v1/outbound-request-imports', [
            'rows' => [[
                'request_no' => 'REQ-AGY-01',
                'customer_name' => 'Khách A',
                'agency_id' => $agencyId,
                'sku_id' => $sku->sku_id,
                'required_qty' => 1,
            ]],
        ], $headers);
        $import->assertCreated()->assertJsonPath('data.import.status', 'VALIDATED');
        $importId = (string) $import->json('data.import.id');

        $this->postJson("/api/v1/outbound-request-imports/{$importId}/confirm", [], $headers + [
            'If-Match' => (string) ((int) $import->json('data.import.version')),
            'Idempotency-Key' => (string) Str::uuid(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'AGENCY_INACTIVE');
    }

    /** BR-DEF-01..04: bệnh/lỗi INACTIVE không gán được, kể cả qua TEMP_ONLY. */
    public function test_inactive_defect_cannot_be_attached_on_temp_only(): void
    {
        $headers = $this->adminHeaders();
        $defectId = (string) Str::uuid();
        DB::table('defect_catalogs')->insert([
            'defect_id' => $defectId,
            'defect_code' => 'DF-OFF',
            'defect_name' => 'Lỗi cũ không dùng',
            'status' => 'INACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // WWHN-310: fixture phải thoả CHECK chk_erd_warranty_identification_shape —
        // IDENTIFIED bắt buộc có item_id; TEMP_ONLY bắt buộc có temporary_item_code +
        // missing_code_reason + manual_product_description. Bản cũ khai IDENTIFIED mà
        // không có item nên chỉ chạy được trên sqlite (nhánh không có CHECK này).
        $caseId = (string) Str::uuid();
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => $caseId,
            'warranty_case_code' => 'WC-DEF-OFF',
            'identification_status' => 'TEMP_ONLY',
            'temporary_item_code' => 'TEMP-0000',
            'missing_code_reason' => 'Chưa có mã khi tiếp nhận',
            'manual_product_description' => 'Máy khoan chưa xác định model',
            'customer_name' => 'Khách B',
            'customer_phone' => '0901234567',
            'status' => 'RECEIVED',
            'version' => 1,
            'legal_hold_flag' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->postJson("/api/v1/warranty-cases/{$caseId}/temp-only", [
            'temporary_item_code' => 'TEMP-0001',
            'missing_code_reason' => 'Mất tem',
            'manual_product_description' => 'Máy khoan không rõ model',
            'defect_id' => $defectId,
            'condition_status' => 'WAITING_CHECK',
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DEFECT_INACTIVE');
    }

    // ---------------------------------------------------------------- P1-4

    /** BR-MST-01..07 + §8.1 "immutable code after use" áp cho cả 5 loại master. */
    public function test_power_source_code_is_immutable_after_sku_reference(): void
    {
        $headers = $this->adminHeaders();
        $powerId = $this->catalogMaster('power_sources', [
            'power_source_code' => 'MAINS_DIRECT',
            'power_source_name' => 'Điện lưới trực tiếp',
        ]);
        Sku::create([
            'sku_code' => 'SKU-PWR-LOCK',
            'sku_name' => 'SKU tham chiếu nguồn điện',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'status' => 'ACTIVE',
            'power_source_id' => $powerId,
        ]);

        $this->patchJson("/api/v1/power-sources/{$powerId}", [
            'power_source_code' => 'MAINS_NEW',
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MASTER_CODE_IMMUTABLE_AFTER_USE');

        // Deactivate thay vì đổi mã vẫn phải được phép.
        $this->patchJson("/api/v1/power-sources/{$powerId}", ['status' => 'INACTIVE'], $headers)->assertOk();
    }

    public function test_package_variant_code_is_immutable_after_sku_reference(): void
    {
        $headers = $this->adminHeaders();
        $packageId = $this->catalogMaster('package_variants', [
            'package_code' => 'KIT',
            'package_name' => 'Kit đầy đủ',
            'variant_code' => 'KIT',
            'variant_name' => 'Kit đầy đủ',
        ]);
        Sku::create([
            'sku_code' => 'SKU-PKG-LOCK',
            'sku_name' => 'SKU tham chiếu quy cách',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'status' => 'ACTIVE',
            'package_variant_id' => $packageId,
        ]);

        $this->patchJson("/api/v1/package-variants/{$packageId}", [
            'package_code' => 'KIT-NEW',
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MASTER_CODE_IMMUTABLE_AFTER_USE');
    }

    // ---------------------------------------------------------------- P1-5

    /** Annex C listDefects / createDefect / updateDefect. */
    public function test_annex_c_defects_path_serves_the_defect_catalog(): void
    {
        $headers = $this->adminHeaders();

        $created = $this->postJson('/api/v1/defects', [
            'defect_code' => 'DF-MOTOR-01',
            'defect_name' => 'Không chạy motor',
            'description' => 'Motor không quay khi cấp nguồn',
        ], $headers)->assertCreated();

        $id = $created->json('data.defect_id');
        $this->getJson('/api/v1/defects', $headers)->assertOk();
        $this->patchJson("/api/v1/defects/{$id}", ['status' => 'INACTIVE'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'INACTIVE');
    }

    /** Annex C listCatalogMasterEntries / createCatalogMasterEntry / updateCatalogMasterEntry. */
    public function test_annex_c_catalog_masters_dispatcher_covers_every_master_type(): void
    {
        $headers = $this->adminHeaders();

        foreach (['brands', 'product-categories', 'product-models', 'power-sources', 'package-variants'] as $index => $type) {
            $created = $this->postJson("/api/v1/catalog-masters/{$type}", [
                'code' => 'GEN-'.$index,
                'name' => 'Master chung '.$index,
            ], $headers)->assertCreated();

            $id = $created->json('data.id');
            $this->getJson("/api/v1/catalog-masters/{$type}", $headers)->assertOk();
            $this->patchJson("/api/v1/catalog-masters/{$type}/{$id}", ['status' => 'INACTIVE'], $headers)
                ->assertOk()
                ->assertJsonPath('data.status', 'INACTIVE');
        }

        $this->getJson('/api/v1/catalog-masters/khong-ton-tai', $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNKNOWN_MASTER_TYPE');
    }

    /** AC-SRS-001: tạo được kho, nhưng kho ACTIVE thứ hai bị chặn 409. */
    public function test_create_warehouse_is_available_but_second_active_warehouse_is_rejected(): void
    {
        $headers = $this->adminHeaders();
        $this->ensureActiveWarehouse('KHO-TONG-HN', 'Kho tổng Hoa Nam');

        $this->postJson('/api/v1/warehouses', [
            'code' => 'KHO-PHU',
            'name' => 'Kho phụ',
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');

        Warehouse::query()->where('status', 'ACTIVE')->update(['status' => 'INACTIVE']);

        $this->postJson('/api/v1/warehouses', [
            'code' => 'KHO-MOI',
            'name' => 'Kho tổng mới',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.code', 'KHO-MOI');
    }
}
