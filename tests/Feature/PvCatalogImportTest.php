<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\PvRelations;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * App PV — importer JSON hàng loạt SKU + hồ sơ trình bày (catalogue DongCheng).
 * Khác `POST /api/v1/skus/import-excel` (chỉ tạo `sku`): endpoint này tạo ĐỒNG THỜI
 * SKU + hồ sơ App PV (display_name/description/ảnh) atomic, published_to_app
 * luôn false khi tạo (publish là quyền pv.publish riêng).
 */
class PvCatalogImportTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/pv-admin/catalog-import';

    public function test_dry_run_reports_would_insert_without_writing(): void
    {
        [$headers] = $this->fixtures();

        $response = $this->postJson(self::URL, [
            'dry_run' => true,
            'items' => [$this->itemPayload('DC-DRY-001')],
        ], $headers)->assertOk();

        $this->assertTrue($response->json('data.dry_run'));
        $this->assertFalse($response->json('data.committed'));
        $this->assertSame(1, $response->json('data.would_insert'));
        $this->assertSame(0, DB::table('sku')->where('sku_code', 'DC-DRY-001')->count());
    }

    public function test_commit_creates_sku_and_pv_profile_unpublished_with_images(): void
    {
        [$headers] = $this->fixtures();

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-REAL-001', [
                'description' => 'Mô tả chi tiết',
                'media_url' => ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'],
            ])],
        ], $headers)->assertOk();

        $this->assertTrue($response->json('data.committed'));
        $this->assertSame(1, $response->json('data.inserted'));

        $sku = DB::table('sku')->where('sku_code', 'DC-REAL-001')->first();
        $this->assertNotNull($sku);
        $this->assertFalse((bool) $sku->published_to_app);
        $this->assertSame('ACTIVE', $sku->status);

        $relation = PvRelations::profile();
        $profile = DB::table($relation['table'])->where($relation['sku'], $sku->sku_id)->first();
        $this->assertNotNull($profile, 'Import phải tạo hồ sơ App PV.');
        $this->assertSame('Máy khoan DongCheng test', $profile->display_name);
        $this->assertSame(1, DB::table($relation['table'])->where($relation['sku'], $sku->sku_id)->count(), 'Chỉ đúng một hồ sơ App PV/SKU (không double-write do trigger publish state).');

        $profileId = $profile->{$relation['key']};
        $this->assertSame(2, DB::table('pv_product_images')->where('profile_id', $profileId)->count());
    }

    public function test_forbidden_price_field_is_rejected_and_nothing_written(): void
    {
        [$headers] = $this->fixtures();

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-FORBID-001') + ['price' => 100000]],
        ], $headers)->assertStatus(422);

        $this->assertSame('FORBIDDEN_FIELD', $response->json('errors.0.code'));
        $this->assertSame(0, DB::table('sku')->where('sku_code', 'DC-FORBID-001')->count());
    }

    public function test_unknown_brand_code_is_rejected_with_clear_row_error(): void
    {
        [$headers] = $this->fixtures();

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-NOBRAND-001', ['brand_code' => 'NOSUCHBRAND'])],
        ], $headers)->assertStatus(422);

        $this->assertSame('UNKNOWN_MASTER_CODE', $response->json('errors.0.code'));
        $this->assertSame('brand_code', $response->json('errors.0.field'));
    }

    public function test_duplicate_product_code_in_same_batch_is_rejected(): void
    {
        [$headers] = $this->fixtures();

        $response = $this->postJson(self::URL, [
            'items' => [
                $this->itemPayload('DC-DUP-001'),
                $this->itemPayload('DC-DUP-001'),
            ],
        ], $headers)->assertStatus(422);

        $this->assertSame('DUPLICATE_CODE_IN_BATCH', $response->json('errors.0.code'));
        $this->assertSame(0, DB::table('sku')->where('sku_code', 'DC-DUP-001')->count());
    }

    public function test_missing_permission_is_rejected(): void
    {
        $headers = $this->headersForPermissions(['sku.manage']);

        $this->postJson(self::URL, ['items' => [$this->itemPayload('DC-PERM-001')]], $headers)
            ->assertStatus(403);
    }

    /**
     * @return array{0: array<string, string>}
     */
    private function fixtures(): array
    {
        $brandId = (string) Str::uuid();
        $categoryId = (string) Str::uuid();
        DB::table('brands')->insert([
            'id' => $brandId, 'brand_code' => 'DONGCHENG', 'brand_name' => 'DongCheng',
            'status' => 'ACTIVE', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_categories')->insert([
            'id' => $categoryId, 'category_code' => 'DRILL_FASTEN_DC', 'category_name' => 'Khoan và siết DC',
            'status' => 'ACTIVE', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // ProductMaster::resolveMaster() đọc product_masters. Trên PostgreSQL trigger
        // đồng bộ canonical → product_masters đã tạo sẵn hai hàng này; sqlite không có
        // trigger đó nên fixture phải tự tạo để lookup brand_code/category_code thoả mãn
        // (cùng pattern MasterDataGovernanceTest).
        if (DB::getDriverName() !== 'pgsql') {
            DB::table('product_masters')->insert([
                ['master_id' => $brandId, 'master_type' => 'BRAND', 'code' => 'DONGCHENG', 'name' => 'DongCheng', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
                ['master_id' => $categoryId, 'master_type' => 'CATEGORY', 'code' => 'DRILL_FASTEN_DC', 'name' => 'Khoan và siết DC', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        Config::set('public_api.domains.POWER_TOOLS.categories', ['DRILL_FASTEN_DC']);

        $headers = $this->headersForPermissions(['sku.manage', 'pv.master.manage']);

        return [$headers];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itemPayload(string $productCode, array $overrides = []): array
    {
        return array_merge([
            'product_code' => $productCode,
            'name' => 'Máy khoan DongCheng test',
            'brand_code' => 'DONGCHENG',
            'category_code' => 'DRILL_FASTEN_DC',
            'domain' => 'POWER_TOOLS',
        ], $overrides);
    }

    /**
     * Copy nguyên khối từ Wwhn252PvContractTest::headersForPermissions() — chưa có
     * trong PreparesOutboundFixtures nên không tái dùng chéo được. Role tối thiểu
     * đúng bằng danh sách permission truyền vào, chứng minh gate là permission cụ
     * thể chứ không phải "có role gì đó".
     *
     * @param  array<int, string>  $permissionCodes
     * @return array<string, string>
     */
    private function headersForPermissions(array $permissionCodes): array
    {
        $headers = $this->superAdminHeaders();
        $userId = $headers['__user_id'];
        unset($headers['__user_id']);

        $roleId = (string) Str::uuid();
        $roleCode = 'PV-IMPORT-TEST-'.Str::upper(Str::random(6));
        DB::table('role')->insert([
            'role_id' => $roleId,
            'role_code' => $roleCode,
            'role_name' => $roleCode,
            'scope_type' => 'FUNCTION',
            'status' => 'ACTIVE',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($permissionCodes as $code) {
            $permissionId = DB::table('permission')->where('permission_key', $code)->value('permission_id');
            $this->assertNotNull($permissionId, "Permission [{$code}] chưa được seed.");
            DB::table('role_permission')->insert([
                'role_permission_id' => (string) Str::uuid(),
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('user_role')->where('user_id', $userId)->delete();
        DB::table('user_role')->insert([
            'user_role_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => now(),
        ]);

        return $headers;
    }
}
