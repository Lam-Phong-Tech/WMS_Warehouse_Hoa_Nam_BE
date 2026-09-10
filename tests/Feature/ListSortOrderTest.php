<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Support\WarrantyCasePii;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * "Sắp xếp theo" của ba màn danh sách phiếu (IN-01 / OUT-01 / WAR-01):
 * `?sort_order=desc` = Mới nhất (phiếu TẠO hoặc SỬA gần nhất lên đầu, khoá
 * COALESCE(updated_at, created_at)), `asc` = Cũ nhất. KHÔNG gửi tham số thì
 * giữ nguyên thứ tự cũ (created_at desc) — client cũ/Mini App không đổi hành vi.
 * Giá trị lạ trả 422 thay vì lặng lẽ bỏ qua.
 *
 * Kịch bản phân biệt "tạo" với "tạo/sửa": phiếu EDITED tạo GIỮA chừng nhưng
 * được sửa GẦN ĐÂY NHẤT — sort mới phải đẩy nó lên đầu chiều desc, trong khi
 * thứ tự mặc định cũ (created_at desc) vẫn xếp NEW trước nó.
 */
class ListSortOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_danh_sach_phieu_nhap_sap_xep_theo_tao_sua_gan_nhat(): void
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-SORT-A', 'Kho Sort A');
        $viewer = $this->createUser('sort_inbound_viewer', $this->createRole('SORT_INBOUND', ['inbound.view']));

        $base = ['dst_warehouse_id' => $warehouse->getKey(), 'created_by' => $viewer->user_id];
        $this->makeStockDocument('receipt', 'PN-SORT-OLD', $base + ['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);
        $this->makeStockDocument('receipt', 'PN-SORT-EDITED', $base + ['created_at' => now()->subDays(8), 'updated_at' => now()->subDay()]);
        $this->makeStockDocument('receipt', 'PN-SORT-NEW', $base + ['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);

        $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($viewer)];

        // Mới nhất: phiếu vừa được SỬA (EDITED) đứng trên phiếu vừa được TẠO (NEW).
        $desc = $this->getJson('/api/v1/inbound-documents?sort_order=desc', $headers)->assertOk();
        $this->assertSame(
            ['PN-SORT-EDITED', 'PN-SORT-NEW', 'PN-SORT-OLD'],
            collect($desc->json('data'))->pluck('doc_no')->all(),
        );

        // Cũ nhất: đảo đúng chiều.
        $asc = $this->getJson('/api/v1/inbound-documents?sort_order=asc', $headers)->assertOk();
        $this->assertSame(
            ['PN-SORT-OLD', 'PN-SORT-NEW', 'PN-SORT-EDITED'],
            collect($asc->json('data'))->pluck('doc_no')->all(),
        );

        // Không gửi tham số: giữ nguyên hợp đồng cũ created_at desc (NEW trước EDITED).
        $default = $this->getJson('/api/v1/inbound-documents', $headers)->assertOk();
        $this->assertSame(
            ['PN-SORT-NEW', 'PN-SORT-EDITED', 'PN-SORT-OLD'],
            collect($default->json('data'))->pluck('doc_no')->all(),
        );

        // Giá trị lạ phải chết to, không âm thầm trả thứ tự mặc định.
        $this->getJson('/api/v1/inbound-documents?sort_order=bogus', $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_danh_sach_phieu_xuat_sap_xep_va_cache_tach_theo_chieu(): void
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-SORT-B', 'Kho Sort B');
        $viewer = $this->createUser('sort_outbound_viewer', $this->createRole('SORT_OUTBOUND', ['outbound.view']));

        $base = ['src_warehouse_id' => $warehouse->getKey(), 'created_by' => $viewer->user_id];
        $this->makeStockDocument('issue', 'PX-SORT-OLD', $base + ['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);
        $this->makeStockDocument('issue', 'PX-SORT-EDITED', $base + ['created_at' => now()->subDays(8), 'updated_at' => now()->subDay()]);
        $this->makeStockDocument('issue', 'PX-SORT-NEW', $base + ['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);

        $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($viewer)];

        // Gọi desc TRƯỚC rồi asc NGAY SAU: endpoint này có read-cache
        // (CachesReadPayload), nếu khoá cache không mang sort_order thì lần asc
        // sẽ trả nhầm payload desc vừa cache.
        $desc = $this->getJson('/api/v1/outbound-documents?sort_order=desc', $headers)->assertOk();
        $this->assertSame(
            ['PX-SORT-EDITED', 'PX-SORT-NEW', 'PX-SORT-OLD'],
            collect($desc->json('data'))->pluck('doc_no')->all(),
        );

        $asc = $this->getJson('/api/v1/outbound-documents?sort_order=asc', $headers)->assertOk();
        $this->assertSame(
            ['PX-SORT-OLD', 'PX-SORT-NEW', 'PX-SORT-EDITED'],
            collect($asc->json('data'))->pluck('doc_no')->all(),
        );

        $this->getJson('/api/v1/outbound-documents?sort_order=moi-nhat', $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_danh_sach_bao_hanh_sap_xep_theo_tao_sua_gan_nhat(): void
    {
        $viewer = $this->createUser('sort_warranty_viewer', $this->createRole('SORT_WARRANTY', ['warranty.view']));

        $this->makeWarrantyCase('WC-SORT-OLD', now()->subDays(10), now()->subDays(10));
        $this->makeWarrantyCase('WC-SORT-EDITED', now()->subDays(8), now()->subDay());
        $this->makeWarrantyCase('WC-SORT-NEW', now()->subDays(2), now()->subDays(2));

        $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($viewer)];

        $desc = $this->getJson('/api/v1/warranty-cases?sort_order=desc', $headers)->assertOk();
        $this->assertSame(
            ['WC-SORT-EDITED', 'WC-SORT-NEW', 'WC-SORT-OLD'],
            collect($desc->json('data'))->pluck('warranty_case_code')->all(),
        );

        $asc = $this->getJson('/api/v1/warranty-cases?sort_order=asc', $headers)->assertOk();
        $this->assertSame(
            ['WC-SORT-OLD', 'WC-SORT-NEW', 'WC-SORT-EDITED'],
            collect($asc->json('data'))->pluck('warranty_case_code')->all(),
        );

        // Không gửi tham số: hành vi cũ created_at desc.
        $default = $this->getJson('/api/v1/warranty-cases', $headers)->assertOk();
        $this->assertSame(
            ['WC-SORT-NEW', 'WC-SORT-EDITED', 'WC-SORT-OLD'],
            collect($default->json('data'))->pluck('warranty_case_code')->all(),
        );
    }

    private function makeStockDocument(string $docType, string $docNo, array $overrides): void
    {
        DB::table('stock_documents')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'doc_type' => $docType,
            'doc_no' => $docNo,
            'doc_date' => now()->toDateString(),
            'status' => 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function makeWarrantyCase(string $code, Carbon $createdAt, Carbon $updatedAt): void
    {
        // WWHN-SEC-06: mọi lệnh ghi warranty_case đi qua encode(), kể cả fixture không mang PII.
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => (string) Str::uuid(),
            'warranty_case_code' => $code,
            'identification_status' => 'TEMP_ONLY',
            'temporary_item_code' => 'TEMP-'.Str::upper(Str::random(8)),
            'status' => 'RECEIVED',
            'version' => 1,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]));
    }

    private function createRole(string $code, array $permissions): Role
    {
        $role = Role::create([
            'role_code' => $code,
            'role_name' => $code,
            'scope_type' => 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);

        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE'],
            );
            DB::table('role_permission')->insert([
                'role_permission_id' => (string) Str::uuid(),
                'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $role;
    }

    private function createUser(string $username, ?Role $role = null): User
    {
        $user = User::create([
            'username' => $username,
            'display_name' => Str::headline($username),
            'email' => "{$username}@wms-hoanam.test",
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        if ($role !== null) {
            $user->roles()->attach($role->role_id, [
                'user_role_id' => (string) Str::uuid(),
            ]);
        }

        return $user;
    }
}
