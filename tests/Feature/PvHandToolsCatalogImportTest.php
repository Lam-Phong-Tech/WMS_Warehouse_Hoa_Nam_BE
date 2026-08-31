<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\PvRelations;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Hand Tools DongCheng (P0) — mở rộng PvCatalogImportTest cho những gì catalogue
 * này cần thêm mà Power Tools không cần: power_source bắt buộc null, family/
 * variant, media role + host allowlist, và sanitize barcode khỏi text tự do.
 *
 * KHÔNG lặp lại các test đã có ở PvCatalogImportTest (dry-run/forbidden/duplicate
 * cơ bản) — bộ đó đã khoá hành vi chung của importer cho mọi domain.
 */
class PvHandToolsCatalogImportTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/pv-admin/catalog-import';

    private const ALLOWED_IMAGE = 'https://images.bigk.click/Handtool/p006_y0389_l0_product.jpg';

    private const ALLOWED_GALLERY = 'https://images.bigk.click/Handtool/p006_y0389_l0_overview.jpg';

    public function test_power_source_null_is_accepted_for_hand_tools(): void
    {
        [$headers] = $this->fixtures();

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-001')],
        ], $headers)->assertOk();

        $this->assertTrue($response->json('data.committed'));
        $sku = DB::table('sku')->where('sku_code', 'DC-HT-001')->first();
        $this->assertNull($sku->power_source_id);
    }

    public function test_power_source_non_null_is_rejected_for_hand_tools(): void
    {
        [$headers] = $this->fixtures();

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-002', ['power_source' => 'BATTERY'])],
        ], $headers)->assertStatus(422);

        $this->assertSame('POWER_SOURCE_NOT_APPLICABLE', $response->json('errors.0.code'));
        $this->assertSame(0, DB::table('sku')->where('sku_code', 'DC-HT-002')->count());
    }

    public function test_family_and_variant_label_are_stored_and_shared_across_variants(): void
    {
        [$headers] = $this->fixtures();

        $response = $this->postJson(self::URL, [
            'items' => [
                $this->itemPayload('DC-HT-010', ['family_name' => 'Kìm điện đa năng', 'variant_label' => '6 inch']),
                $this->itemPayload('DC-HT-011', ['family_name' => 'Kìm điện đa năng', 'variant_label' => '7 inch']),
            ],
        ], $headers)->assertOk();

        $this->assertTrue($response->json('data.committed'));

        $a = DB::table('sku')->where('sku_code', 'DC-HT-010')->first();
        $b = DB::table('sku')->where('sku_code', 'DC-HT-011')->first();
        $this->assertSame('Kìm điện đa năng', $a->family_name);
        $this->assertSame('Kìm điện đa năng', $b->family_name);
        $this->assertSame('6 inch', $a->variant_label);
        $this->assertSame('7 inch', $b->variant_label);
    }

    public function test_variant_label_without_family_name_is_rejected(): void
    {
        [$headers] = $this->fixtures();

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-012', ['variant_label' => '6 inch'])],
        ], $headers)->assertStatus(422);

        $this->assertSame('VARIANT_WITHOUT_FAMILY', $response->json('errors.0.code'));
    }

    public function test_structured_media_role_sets_is_primary_flag_correctly(): void
    {
        [$headers] = $this->fixtures();

        Http::fake([
            'images.bigk.click/*' => Http::response('', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '2048']),
        ]);

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-020', [
                'media' => [
                    ['url' => self::ALLOWED_IMAGE, 'role' => 'PRIMARY'],
                    ['url' => self::ALLOWED_GALLERY, 'role' => 'GALLERY'],
                ],
            ])],
        ], $headers)->assertOk();

        $this->assertTrue($response->json('data.committed'));

        $sku = DB::table('sku')->where('sku_code', 'DC-HT-020')->first();
        $profileId = DB::table(PvRelations::profile()['table'])->where(PvRelations::profile()['sku'], $sku->sku_id)->value(PvRelations::profile()['key']);
        $images = DB::table('pv_product_images')->where('profile_id', $profileId)->orderBy('sort_order')->get();

        $this->assertCount(2, $images);
        $this->assertSame(self::ALLOWED_IMAGE, $images[0]->image_url);
        $this->assertTrue((bool) $images[0]->is_primary);
        $this->assertSame(self::ALLOWED_GALLERY, $images[1]->image_url);
        $this->assertFalse((bool) $images[1]->is_primary);
    }

    public function test_duplicate_primary_media_role_is_rejected(): void
    {
        [$headers] = $this->fixtures();

        // Không cần fake HTTP thật: DUPLICATE_PRIMARY_MEDIA bị chặn trước khi
        // validate từng URL, nhưng vẫn fake() để chắc chắn không lỡ gọi mạng thật.
        Http::fake();

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-021', [
                'media' => [
                    ['url' => self::ALLOWED_IMAGE, 'role' => 'PRIMARY'],
                    ['url' => self::ALLOWED_GALLERY, 'role' => 'PRIMARY'],
                ],
            ])],
        ], $headers)->assertStatus(422);

        $this->assertSame('DUPLICATE_PRIMARY_MEDIA', $response->json('errors.0.code'));
    }

    public function test_media_url_must_be_https(): void
    {
        [$headers] = $this->fixtures();

        Http::fake();

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-030', ['media' => [['url' => 'http://images.bigk.click/x.jpg', 'role' => 'PRIMARY']]])],
        ], $headers)->assertStatus(422);

        $this->assertSame('INVALID_MEDIA_URL', $response->json('errors.0.code'));
    }

    public function test_media_host_outside_allowlist_is_rejected(): void
    {
        [$headers] = $this->fixtures();

        Http::fake();

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-031', ['media' => [['url' => 'https://evil.example.com/x.jpg', 'role' => 'PRIMARY']]])],
        ], $headers)->assertStatus(422);

        $this->assertSame('MEDIA_HOST_NOT_ALLOWED', $response->json('errors.0.code'));
    }

    public function test_media_redirect_to_disallowed_host_is_rejected(): void
    {
        [$headers] = $this->fixtures();

        Http::fake([
            'images.bigk.click/redirect*' => Http::response('', 302, ['Location' => 'https://evil.example.com/x.jpg']),
        ]);

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-032', ['media' => [['url' => 'https://images.bigk.click/redirect.jpg', 'role' => 'PRIMARY']]])],
        ], $headers)->assertStatus(422);

        $this->assertSame('MEDIA_REDIRECT_NOT_ALLOWED', $response->json('errors.0.code'));
        $this->assertSame(0, DB::table('sku')->where('sku_code', 'DC-HT-032')->count());
    }

    public function test_media_non_image_content_type_is_rejected(): void
    {
        [$headers] = $this->fixtures();

        Http::fake([
            'images.bigk.click/not-image*' => Http::response('', 200, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-033', ['media' => [['url' => 'https://images.bigk.click/not-image.jpg', 'role' => 'PRIMARY']]])],
        ], $headers)->assertStatus(422);

        $this->assertSame('MEDIA_NOT_IMAGE', $response->json('errors.0.code'));
    }

    public function test_media_over_size_limit_is_rejected(): void
    {
        [$headers] = $this->fixtures();

        Config::set('public_api.media_max_bytes', 1000);
        Http::fake([
            'images.bigk.click/big*' => Http::response('', 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '999999']),
        ]);

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-034', ['media' => [['url' => 'https://images.bigk.click/big.jpg', 'role' => 'PRIMARY']]])],
        ], $headers)->assertStatus(422);

        $this->assertSame('MEDIA_TOO_LARGE', $response->json('errors.0.code'));
    }

    public function test_barcode_text_is_stripped_from_description_and_specifications_and_never_persisted(): void
    {
        [$headers] = $this->fixtures();

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-040', [
                'description' => 'Kẹp giữ chi tiết. Mã vạch: 6927073993418 phần này phải biến mất.',
                'specifications' => ['package' => 'Đóng gói: 1/10/100; Mã vạch: 6927073993418'],
            ])],
        ], $headers)->assertOk();

        $this->assertTrue($response->json('data.committed'));

        $sku = DB::table('sku')->where('sku_code', 'DC-HT-040')->first();
        $spec = json_decode((string) $sku->specification, true);
        $this->assertStringNotContainsString('6927073993418', (string) $spec['package']);
        $this->assertStringNotContainsString('Mã vạch', (string) $spec['package']);
        $this->assertStringContainsString('Đóng gói: 1/10/100', (string) $spec['package']);

        $relation = PvRelations::profile();
        $profile = DB::table($relation['table'])->where($relation['sku'], $sku->sku_id)->first();
        $this->assertStringNotContainsString('6927073993418', (string) $profile->description);
        $this->assertStringNotContainsString('Mã vạch', (string) $profile->description);

        // Rò rỉ tuyệt đối không: quét cả response HTTP thô của chính lần commit này.
        $this->assertStringNotContainsString('6927073993418', $response->getContent());
    }

    #[DataProvider('internalAuditFieldProvider')]
    public function test_internal_audit_fields_are_hard_rejected(string $field, mixed $value): void
    {
        [$headers] = $this->fixtures();

        Http::fake();

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-050') + [$field => $value]],
        ], $headers)->assertStatus(422);

        $this->assertSame('FORBIDDEN_FIELD', $response->json('errors.0.code'));
        $this->assertSame(0, DB::table('sku')->where('sku_code', 'DC-HT-050')->count());
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function internalAuditFieldProvider(): array
    {
        return [
            'page_pdf' => ['page_pdf', 6],
            'catalog_page' => ['catalog_page', 3],
            'source_url' => ['source_url', 'https://internal.example.com/catalog.pdf'],
            'source_path' => ['source_path', '/mnt/catalog/p006.pdf'],
            'ocr_confidence' => ['ocr_confidence', 0.87],
            'bounding_box' => ['bounding_box', [0, 0, 10, 10]],
        ];
    }

    public function test_specifications_forbidden_key_is_rejected(): void
    {
        [$headers] = $this->fixtures();

        $response = $this->postJson(self::URL, [
            'items' => [$this->itemPayload('DC-HT-051', ['specifications' => ['ocr_confidence' => 0.9]])],
        ], $headers)->assertStatus(422);

        $this->assertSame('FORBIDDEN_FIELD', $response->json('errors.0.code'));
        $this->assertSame('specifications.ocr_confidence', $response->json('errors.0.field'));
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
            'id' => $categoryId, 'category_code' => 'CLAMPING_TOOLS', 'category_name' => 'Dụng cụ kẹp giữ',
            'status' => 'ACTIVE', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        if (DB::getDriverName() !== 'pgsql') {
            DB::table('product_masters')->insert([
                ['master_id' => $brandId, 'master_type' => 'BRAND', 'code' => 'DONGCHENG', 'name' => 'DongCheng', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
                ['master_id' => $categoryId, 'master_type' => 'CATEGORY', 'code' => 'CLAMPING_TOOLS', 'name' => 'Dụng cụ kẹp giữ', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        Config::set('public_api.domains.HAND_TOOLS.categories', ['CLAMPING_TOOLS']);
        Config::set('public_api.media_host_allowlist', ['images.bigk.click']);

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
            'name' => 'Kìm điện đa năng test',
            'brand_code' => 'DONGCHENG',
            'category_code' => 'CLAMPING_TOOLS',
            'domain' => 'HAND_TOOLS',
        ], $overrides);
    }

    /**
     * Copy nguyên khối từ PvCatalogImportTest — chưa có trong PreparesOutboundFixtures
     * nên không tái dùng chéo được (xem comment ở đó).
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
        $roleCode = 'PV-HT-IMPORT-TEST-'.Str::upper(Str::random(6));
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
