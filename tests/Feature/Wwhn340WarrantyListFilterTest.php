<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * WWHN-340 · UAT TC_FUNC_02 — bộ lọc "Nhận diện" và "Bệnh/lỗi" của WAR-01
 * (Bảo hành & linh kiện → Danh sách bảo hành).
 *
 * LỖI GỐC: `warranty_case` là VIEW trên PostgreSQL, mà `Schema::hasColumn()` chỉ
 * liệt kê BASE TABLE nên trả FALSE cho mọi cột của view. Lớp gác
 *
 *     if ($request->filled($col) && Schema::hasColumn($table, $col)) { where(...) }
 *
 * vì thế BỎ QUA cả hai bộ lọc và trả về TOÀN BỘ danh sách chưa lọc dưới vỏ
 * 200 OK — người dùng chọn điều kiện xong thấy danh sách y nguyên.
 *
 * BỘ TEST NÀY CHẠY SQLITE, nơi `hasColumn()` trả TRUE cho view, nên nó KHÔNG
 * tái hiện được lỗi gốc. Vì vậy file gồm hai lớp:
 *
 *   1. Lớp hành vi — khoá ngữ nghĩa lọc (đơn, kết hợp, bệnh phụ, lọc + tìm kiếm,
 *      lọc + phân trang) để không ai làm hỏng logic khi refactor.
 *   2. Lớp hàng rào tĩnh — đọc chính mã nguồn `CanonicalMvpController` và bắt lỗi
 *      nếu ai đó quay về `hasColumn()`, hoặc bỏ nhánh báo lỗi khi cột lọc vắng
 *      mặt. Đây là lớp duy nhất thật sự chặn được lỗi gốc trên bộ test sqlite.
 */
class Wwhn340WarrantyListFilterTest extends TestCase
{
    use RefreshDatabase;

    private const DEFECT_A = 'DEF-TC02-A';

    private const DEFECT_B = 'DEF-TC02-B';

    public function test_loc_nhan_dien_chi_tra_ve_dung_loai_da_chon(): void
    {
        $headers = $this->viewerHeaders('war340_ident');
        $this->seedCases();

        $identified = $this->getJson('/api/v1/warranty-cases?per_page=50&identification_status=IDENTIFIED', $headers)
            ->assertOk();
        $this->assertSame(
            ['IDENTIFIED'],
            collect($identified->json('data'))->pluck('identification_status')->unique()->values()->all(),
            'Lọc IDENTIFIED mà vẫn lọt hồ sơ TEMP_ONLY — bộ lọc đang bị bỏ qua.',
        );
        $this->assertSame(2, $identified->json('meta.total'));

        $temp = $this->getJson('/api/v1/warranty-cases?per_page=50&identification_status=TEMP_ONLY', $headers)
            ->assertOk();
        $this->assertSame(
            ['WC-TC02-TEMP'],
            collect($temp->json('data'))->pluck('warranty_case_code')->all(),
        );

        // Không gửi bộ lọc: đủ cả ba hồ sơ. Đây là mốc đối chứng — nếu bộ lọc bị
        // bỏ qua thì hai lời gọi trên cũng ra đúng con số 3 này.
        $this->assertSame(3, $this->getJson('/api/v1/warranty-cases?per_page=50', $headers)->json('meta.total'));
    }

    public function test_loc_benh_loi_khop_ca_benh_chinh_lan_benh_phu(): void
    {
        $headers = $this->viewerHeaders('war340_defect');
        $this->seedCases();
        $defectB = $this->defectId(self::DEFECT_B);

        // WC-TC02-MULTI mang DEF-TC02-B ở vị trí PHỤ (is_primary = false) và cột
        // defect_id của view chỉ giữ bệnh CHÍNH — lọc theo cột đơn sẽ bỏ sót nó,
        // đúng thứ người dùng nhìn thấy ở cột "Bệnh/lỗi" nhưng lọc không ra.
        $page = $this->getJson('/api/v1/warranty-cases?per_page=50&defect_id='.$defectB, $headers)->assertOk();

        $this->assertEqualsCanonicalizing(
            ['WC-TC02-TEMP', 'WC-TC02-MULTI'],
            collect($page->json('data'))->pluck('warranty_case_code')->all(),
            'Lọc bệnh/lỗi phải khớp cả hồ sơ mang bệnh đó ở vị trí phụ.',
        );

        // Hồ sơ chưa gắn bệnh nào không được lọt vào bất kỳ bộ lọc bệnh nào.
        $this->assertNotContains(
            'WC-TC02-NODEFECT',
            collect($page->json('data'))->pluck('warranty_case_code')->all(),
        );
    }

    public function test_hai_bo_loc_ket_hop_theo_dung_logic_va(): void
    {
        $headers = $this->viewerHeaders('war340_combo');
        $this->seedCases();
        $defectB = $this->defectId(self::DEFECT_B);

        $combo = $this->getJson(
            '/api/v1/warranty-cases?per_page=50&identification_status=IDENTIFIED&defect_id='.$defectB,
            $headers,
        )->assertOk();

        // Hai hồ sơ mang DEF-TC02-B, nhưng chỉ MULTI là IDENTIFIED.
        $this->assertSame(
            ['WC-TC02-MULTI'],
            collect($combo->json('data'))->pluck('warranty_case_code')->all(),
        );
        $this->assertSame(1, $combo->json('meta.total'));
    }

    public function test_bo_loc_khong_bi_o_tim_kiem_vo_hieu_hoa(): void
    {
        $headers = $this->viewerHeaders('war340_keyword', ['warranty.view', 'warranty.pii.view']);
        $this->seedCases();

        // Nhánh OR của keyword phải nằm trong MỘT nhóm where lồng nhau; nếu tràn
        // ra ngoài nó sẽ nuốt luôn điều kiện identification_status.
        $page = $this->getJson('/api/v1/warranty-cases?per_page=50&identification_status=TEMP_ONLY&keyword=WC-TC02', $headers)
            ->assertOk();

        $this->assertSame(
            ['WC-TC02-TEMP'],
            collect($page->json('data'))->pluck('warranty_case_code')->all(),
            'Ô tìm kiếm đang làm bộ lọc Nhận diện mất tác dụng.',
        );
    }

    public function test_tong_so_dong_theo_dung_bo_loc_khi_phan_trang(): void
    {
        $headers = $this->viewerHeaders('war340_paging');
        $this->seedCases();

        // Người dùng đối chiếu "Tổng N phiếu" trên toolbar với danh sách; N phải
        // là tổng SAU khi lọc, không phải tổng của cả bảng.
        $page = $this->getJson('/api/v1/warranty-cases?per_page=1&page=1&identification_status=IDENTIFIED', $headers)
            ->assertOk();

        $this->assertCount(1, $page->json('data'));
        $this->assertSame(2, $page->json('meta.total'));
    }

    /**
     * HÀNG RÀO TĨNH — lớp duy nhất chặn được lỗi gốc trên bộ test sqlite.
     *
     * sqlite trả TRUE cho `hasColumn()` trên view nên không một test hành vi nào
     * đỏ lên khi ai đó đổi ngược `hasRelationColumn()` về `hasColumn()`. Đọc
     * thẳng mã nguồn là cách duy nhất giữ được hợp đồng này.
     */
    public function test_controller_khong_duoc_gac_bo_loc_bang_has_column(): void
    {
        $source = file_get_contents(
            base_path('Modules/Warehouse/app/Http/Controllers/CanonicalMvpController.php')
        );

        $this->assertStringNotContainsString('SchemaCache::hasColumn(', $source,
            'Quan hệ của controller này có VIEW (`warranty_case`); hasColumn() trả false cho mọi cột của view '
            .'nên bộ lọc sẽ biến mất im lặng. Dùng SchemaCache::hasRelationColumn().');
        $this->assertStringNotContainsString('Schema::hasColumn(', $source,
            'Như trên — facade Schema cũng chỉ nhìn thấy BASE TABLE.');
        $this->assertStringContainsString('SchemaCache::hasRelationColumn($table, $column)', $source,
            'Lớp gác cột lọc của tablePage() đã biến mất.');
        $this->assertStringContainsString("ApiError::abort(422, 'FILTER_NOT_SUPPORTED'", $source,
            'Bộ lọc đã gửi mà quan hệ thiếu cột thì phải báo lỗi, KHÔNG được lặng lẽ trả danh sách chưa lọc.');
    }

    /**
     * Ba hồ sơ đủ để tách bạch mọi nhánh:
     *   WC-TC02-IDENT     IDENTIFIED, bệnh chính A
     *   WC-TC02-MULTI     IDENTIFIED, bệnh chính A + bệnh PHỤ B
     *   WC-TC02-TEMP      TEMP_ONLY,  bệnh chính B
     *   WC-TC02-NODEFECT  TEMP_ONLY,  chưa gắn bệnh nào (không nằm trong 3 trên)
     */
    private function seedCases(): void
    {
        $defectA = $this->makeDefect(self::DEFECT_A, 'Kêu to bất thường');
        $defectB = $this->makeDefect(self::DEFECT_B, 'Chập điện');

        $ident = $this->makeCase('WC-TC02-IDENT', 'IDENTIFIED', $defectA);
        $multi = $this->makeCase('WC-TC02-MULTI', 'IDENTIFIED', $defectA);
        $temp = $this->makeCase('WC-TC02-TEMP', 'TEMP_ONLY', $defectB);

        $this->linkDefect($ident, $defectA, true);
        $this->linkDefect($multi, $defectA, true);
        $this->linkDefect($multi, $defectB, false);
        $this->linkDefect($temp, $defectB, true);
    }

    private function makeDefect(string $code, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('defect_catalogs')->insert([
            'defect_id' => $id,
            'defect_code' => $code,
            'defect_name' => $name,
            'status' => 'ACTIVE',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function defectId(string $code): string
    {
        return (string) DB::table('defect_catalogs')->where('defect_code', $code)->value('defect_id');
    }

    private function makeCase(string $code, string $identification, ?string $defectId): string
    {
        $id = (string) Str::uuid();
        DB::table('warranty_case')->insert([
            'warranty_case_id' => $id,
            'warranty_case_code' => $code,
            'identification_status' => $identification,
            'temporary_item_code' => $identification === 'TEMP_ONLY' ? 'TEMP-'.Str::upper(Str::random(8)) : null,
            'defect_id' => $defectId,
            'status' => 'RECEIVED',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function linkDefect(string $caseId, string $defectId, bool $primary): void
    {
        DB::table('warranty_case_defects')->insert([
            'warranty_case_defect_id' => (string) Str::uuid(),
            'warranty_case_id' => $caseId,
            'defect_id' => $defectId,
            'is_primary' => $primary,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  list<string>  $permissions */
    private function viewerHeaders(string $username, array $permissions = ['warranty.view']): array
    {
        $role = Role::create([
            'role_code' => Str::upper($username),
            'role_name' => $username,
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

        $user = User::create([
            'username' => $username,
            'display_name' => Str::headline($username),
            'email' => "{$username}@wms-hoanam.test",
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }
}
