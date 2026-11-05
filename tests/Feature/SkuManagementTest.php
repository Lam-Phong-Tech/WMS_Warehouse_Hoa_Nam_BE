<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Brand;
use Modules\Warehouse\Models\ProductMaster;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

class SkuManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_information_status_combines_with_usage_brand_type_and_keyword(): void
    {
        $headers = $this->authHeaders();
        $links = [];
        foreach (['BRAND', 'CATEGORY', 'MODEL'] as $kind) {
            $links[strtolower($kind).'_id'] = ProductMaster::create([
                'master_type' => $kind, 'code' => $kind, 'name' => $kind, 'status' => 'ACTIVE',
            ])->master_id;
        }
        foreach (range(0, 7) as $mask) {
            $attributes = [];
            foreach (array_keys($links) as $bit => $field) {
                $attributes[$field] = ($mask & (1 << $bit)) ? $links[$field] : null;
            }
            foreach (['ACTIVE', 'INACTIVE'] as $status) {
                Sku::create($attributes + [
                    'sku_code' => "MATCH-{$mask}-{$status}", 'sku_name' => 'Matrix',
                    'sku_type' => 'PRODUCT', 'unit' => 'cai', 'status' => $status,
                ]);
            }
        }
        Sku::create(['sku_code' => 'OTHER', 'sku_name' => 'Other', 'sku_type' => 'COMPONENT', 'status' => 'ACTIVE']);

        $base = '/api/v1/products?per_page=100&keyword=MATCH&sku_type=PRODUCT';
        $pending = $this->getJson($base.'&information_status=PENDING&status=ACTIVE', $headers)->assertOk();
        $this->assertEqualsCanonicalizing(
            array_map(fn ($mask) => "MATCH-{$mask}-ACTIVE", range(0, 6)),
            array_column($pending->json('data'), 'sku_code'),
        );
        $complete = $this->getJson($base.'&information_status=COMPLETE', $headers)->assertOk();
        $this->assertEqualsCanonicalizing(['MATCH-7-ACTIVE', 'MATCH-7-INACTIVE'], array_column($complete->json('data'), 'sku_code'));
        $brandPending = $this->getJson($base.'&information_status=PENDING&status=INACTIVE&brand_id='.$links['brand_id'], $headers)->assertOk();
        $this->assertEqualsCanonicalizing(['MATCH-1-INACTIVE', 'MATCH-3-INACTIVE', 'MATCH-5-INACTIVE'], array_column($brandPending->json('data'), 'sku_code'));
        $this->getJson('/api/v1/products?information_status=UNKNOWN', $headers)->assertUnprocessable();

        // Dashboard uses exactly the active pending subset (plus OTHER).
        $this->getJson('/api/v1/reports/dashboard-overview', $headers)
            ->assertOk()->assertJsonPath('data.issues.sku_pending_info', 8);
    }

    public function test_pending_information_is_filtered_before_server_pagination(): void
    {
        $headers = $this->authHeaders();
        foreach (range(1, 105) as $number) {
            Sku::create(['sku_code' => "PENDING-{$number}", 'sku_name' => 'Pending', 'sku_type' => 'PRODUCT', 'status' => 'ACTIVE']);
        }
        $first = $this->getJson('/api/v1/products?information_status=PENDING&per_page=100&page=1', $headers)->assertOk();
        $second = $this->getJson('/api/v1/products?information_status=PENDING&per_page=100&page=2', $headers)->assertOk();
        $first->assertJsonCount(100, 'data');
        $second->assertJsonCount(5, 'data');
        $this->assertCount(105, array_unique(array_merge(array_column($first->json('data'), 'sku_id'), array_column($second->json('data'), 'sku_id'))));
    }

    private function authHeaders(): array
    {
        $role = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'tester-'.Str::random(6),
            'display_name' => 'Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_product_keyword_search_finds_sku_code_and_brand_name(): void
    {
        $headers = $this->authHeaders();
        // SQLite retains the legacy SKU FK to product_masters; production
        // PostgreSQL repoints it to brands in the canonical-master migration.
        $legacyBrand = ProductMaster::create([
            'master_type' => 'BRAND',
            'code' => 'DCC',
            'name' => 'DongCheng',
            'status' => 'ACTIVE',
        ]);
        $brand = Brand::create([
            'id' => $legacyBrand->master_id,
            'brand_code' => 'DCC',
            'brand_name' => 'DongCheng',
            'status' => 'ACTIVE',
        ]);
        $sku = Sku::create([
            'sku_code' => 'DCC-20V-500',
            'sku_name' => 'Máy khoan',
            'sku_type' => 'PRODUCT',
            'unit' => 'cái',
            'brand_id' => $brand->id,
            'status' => 'ACTIVE',
        ]);

        $this->getJson('/api/v1/products?keyword=DCC-20V-500&per_page=50', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.sku_id', $sku->sku_id);
        $this->getJson('/api/v1/products?keyword=DongCheng&per_page=50', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.sku_id', $sku->sku_id);
    }

    public function test_sku_update_sets_brand_category_model_and_package_variant(): void
    {
        $headers = $this->authHeaders();
        $brand = ProductMaster::create(['master_type' => 'BRAND', 'code' => 'BR-1', 'name' => 'Brand 1', 'status' => 'ACTIVE']);
        $sku = Sku::create(['sku_code' => 'SKU-UPD', 'sku_name' => 'SKU update', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'status' => 'ACTIVE']);

        $response = $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'brand_id' => $brand->master_id,
            'package_variant' => 'KIT',
            'specification' => ['power' => '750W'],
        ], $headers);

        $response->assertOk()
            ->assertJsonPath('data.brand_id', $brand->master_id)
            ->assertJsonPath('data.package_variant', 'KIT')
            ->assertJsonPath('data.specification.power', '750W');
    }

    public function test_sku_update_writes_audit_log(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create(['sku_code' => 'SKU-AUDIT', 'sku_name' => 'SKU audit', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'status' => 'ACTIVE']);

        $this->patchJson("/api/v1/products/{$sku->sku_id}", ['sku_name' => 'SKU audit v2'], $headers);

        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'sku',
            'object_id' => $sku->sku_id,
            'action' => 'UPDATE',
        ]);
    }

    private function seedPostedMovement(Sku $sku): void
    {
        $warehouse = Warehouse::create(['code' => 'WH-BR10', 'name' => 'Kho BR-SKU-10', 'status' => 'ACTIVE']);
        $actor = User::create([
            'username' => 'ledger-'.Str::random(6),
            'display_name' => 'Ledger actor',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        StockMovement::create([
            'movement_no' => 'MOV-'.Str::random(10),
            'business_key' => 'BR-SKU-10-'.Str::random(10),
            'movement_type' => StockMovement::RECEIPT_POSTED,
            'warehouse_id' => $warehouse->id,
            'product_id' => $sku->sku_id,
            'qty_before' => 0,
            'qty_delta' => 5,
            'qty_after' => 5,
            // ERD bảng 30: quantity/stock_status/occurred_at NOT NULL.
            'quantity' => 5,
            'stock_status' => 'AVAILABLE',
            'occurred_at' => now(),
            'created_by' => $actor->user_id,
        ]);
    }

    public function test_sku_code_is_locked_after_stock_movement_exists(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create(['sku_code' => 'SKU-LOCK-CODE', 'sku_name' => 'SKU locked code', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $this->seedPostedMovement($sku);

        $response = $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'sku_code' => 'SKU-LOCK-CODE-RENAMED',
        ], $headers);

        $response->assertStatus(409)->assertJsonPath('error.code', 'SKU_IDENTITY_IMMUTABLE_AFTER_STOCK');
        $this->assertSame('SKU-LOCK-CODE', $sku->fresh()->sku_code);
    }

    public function test_is_serial_tracked_is_locked_after_stock_movement_exists(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create(['sku_code' => 'SKU-LOCK-TRACK', 'sku_name' => 'SKU locked tracking', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $this->seedPostedMovement($sku);

        $response = $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'is_serial_tracked' => true,
        ], $headers);

        $response->assertStatus(409)->assertJsonPath('error.code', 'SKU_IDENTITY_IMMUTABLE_AFTER_STOCK');
        $this->assertFalse((bool) $sku->fresh()->is_serial_tracked);
    }

    public function test_sku_update_of_unrelated_fields_still_succeeds_after_stock_movement_exists(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create(['sku_code' => 'SKU-LOCK-OTHER', 'sku_name' => 'SKU other', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $this->seedPostedMovement($sku);

        $response = $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'sku_name' => 'SKU other v2',
            'sku_code' => 'SKU-LOCK-OTHER',
            'is_serial_tracked' => false,
        ], $headers);

        $response->assertOk()->assertJsonPath('data.sku_name', 'SKU other v2');
    }

    /**
     * Định mức tồn (báo cáo định mức tồn): min >= 0 — 0 là định mức HỢP LỆ,
     * không phải "chưa đặt"; max >= 1; max > min.
     */
    public function test_sku_accepts_zero_min_stock_and_persists_thresholds(): void
    {
        $headers = $this->authHeaders();

        $response = $this->postJson('/api/v1/products', [
            'sku_code' => 'SKU-THRESHOLD-OK',
            'sku_name' => 'SKU định mức hợp lệ',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'min_stock' => 0,
            'max_stock' => 1,
        ], $headers);

        $response->assertCreated();

        $skuId = $response->json('data.sku_id') ?? $response->json('data.id');
        $row = DB::table('sku')->where('sku_id', $skuId)->first();

        $this->assertNotNull($row->min_stock, '0 là định mức hợp lệ, không được lưu thành NULL.');
        $this->assertEqualsWithDelta(0.0, (float) $row->min_stock, 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $row->max_stock, 0.0001);
    }

    public function test_sku_rejects_max_stock_below_one(): void
    {
        $headers = $this->authHeaders();

        $this->postJson('/api/v1/products', [
            'sku_code' => 'SKU-THRESHOLD-MAX0',
            'sku_name' => 'SKU max 0',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'min_stock' => 0,
            'max_stock' => 0,
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('max_stock');
    }

    public function test_sku_rejects_negative_min_stock(): void
    {
        $headers = $this->authHeaders();

        $this->postJson('/api/v1/products', [
            'sku_code' => 'SKU-THRESHOLD-NEG',
            'sku_name' => 'SKU min âm',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'min_stock' => -1,
            'max_stock' => 10,
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('min_stock');
    }

    public function test_sku_rejects_max_stock_equal_to_min_stock(): void
    {
        $headers = $this->authHeaders();

        $this->postJson('/api/v1/products', [
            'sku_code' => 'SKU-THRESHOLD-EQ',
            'sku_name' => 'SKU max bằng min',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'min_stock' => 10,
            'max_stock' => 10,
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('max_stock');
    }

    /**
     * PATCH chỉ gửi MỘT vế: vế còn lại phải lấy giá trị đang lưu, nếu không cặp
     * min/max chéo nhau sẽ lọt xuống DB và vỡ CHECK chk_skus_stock_thresholds.
     */
    public function test_sku_partial_update_compares_against_stored_threshold(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create([
            'sku_code' => 'SKU-THRESHOLD-PATCH',
            'sku_name' => 'SKU định mức patch',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'status' => 'ACTIVE',
            'min_stock' => 5,
            'max_stock' => 20,
        ]);

        $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'min_stock' => 50,
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('min_stock');

        $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'max_stock' => 3,
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('max_stock');

        $updated = $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'max_stock' => 30,
        ], $headers)->assertOk();

        $this->assertEqualsWithDelta(30.0, (float) $updated->json('data.max_stock'), 0.0001);
    }

    public function test_sku_thresholds_can_be_cleared_back_to_null(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create([
            'sku_code' => 'SKU-THRESHOLD-CLEAR',
            'sku_name' => 'SKU xoá định mức',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'status' => 'ACTIVE',
            'min_stock' => 5,
            'max_stock' => 20,
        ]);

        $this->patchJson("/api/v1/products/{$sku->sku_id}", [
            'min_stock' => null,
            'max_stock' => null,
        ], $headers)->assertOk()
            ->assertJsonPath('data.min_stock', null)
            ->assertJsonPath('data.max_stock', null);
    }
}
