<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\PvRelations;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-252 / HN-PV-001 — khoá hợp đồng App PV theo SRS/FRD v1.12.
 *
 * Bao phủ:
 * - REQ-SRS-017/018/036 + §15 OP-09: list/detail ẩn danh, chỉ active+published,
 *   available_qty kho tổng, zero-stock vẫn hiển thị, không rò dữ liệu vận hành.
 * - REQ-SRS-019/033: gợi ý theo rule (cả ba selector sku/category/tag).
 * - REQ-SRS-020 + §3: publish là quyền RIÊNG pv.publish, sku.manage không đủ.
 * - Annex C error set của nhóm PV ẩn danh đúng {400,404,429,500} — không có 422.
 * - Annex C updatePvAdminResource: If-Match bắt buộc (428) và lệch version (412).
 */
class Wwhn252PvContractTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Public catalog
    // ------------------------------------------------------------------

    public function test_public_list_returns_only_active_published_sku_with_available_qty(): void
    {
        $warehouse = $this->warehouseFixture();
        $published = $this->publishedSkuFixture('SKU-PV-001', 'Máy khoan PV');
        $this->seedAvailableBalance($warehouse->id, $published->sku_id, 7);

        $this->skuFixture('SKU-PV-002', 'SKU chưa publish');

        $inactive = $this->publishedSkuFixture('SKU-PV-003', 'SKU đã publish rồi tắt');
        DB::table('sku')->where('sku_id', $inactive->sku_id)->update(['status' => 'INACTIVE']);

        $response = $this->getJson('/api/v1/pv/products')->assertOk();

        $codes = collect($response->json('data'))->pluck('sku_code')->all();
        $this->assertSame(['SKU-PV-001'], $codes);
        $this->assertSame(7, $response->json('data.0.available_qty'));
        $this->assertSame('IN_STOCK', $response->json('data.0.stock_status'));
    }

    /** DEC-CATALOG-001 / REQ-SRS-036: publish độc lập tồn kho; tồn 0 hiện "Chưa có hàng". */
    public function test_zero_stock_published_sku_stays_visible_with_out_of_stock_label(): void
    {
        $this->publishedSkuFixture('SKU-PV-ZERO', 'SKU chưa nhập hàng');

        $response = $this->getJson('/api/v1/pv/products')->assertOk();

        $this->assertSame('SKU-PV-ZERO', $response->json('data.0.sku_code'));
        $this->assertSame(0, $response->json('data.0.available_qty'));
        $this->assertSame('OUT_OF_STOCK', $response->json('data.0.stock_status'));
        $this->assertSame('Chưa có hàng', $response->json('data.0.stock_label'));
    }

    /** §15 OP-09: response ẩn danh không được chứa dữ liệu vận hành. */
    public function test_public_payload_never_leaks_operational_data(): void
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->publishedSkuFixture('SKU-PV-LEAK', 'SKU kiểm rò rỉ');
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 3);
        $this->seedItemInStock($sku->sku_id);

        $forbidden = ['warehouse_id', 'item_id', 'container_id', 'serial_number', 'movement', 'cost', 'reserved_qty', 'on_hand_qty'];

        foreach (['/api/v1/pv/products', "/api/v1/pv/products/{$sku->sku_id}"] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $body, "PV response tại {$url} lộ khoá vận hành [{$needle}].");
            }
        }
    }

    /** NFR-SRS-016 + §13.7: PV phải phát data_as_of và ngưỡng staleness. */
    public function test_public_response_exposes_freshness_meta(): void
    {
        $this->publishedSkuFixture('SKU-PV-FRESH', 'SKU freshness');

        $meta = $this->getJson('/api/v1/pv/products')->assertOk()->json('meta');

        $this->assertNotEmpty($meta['data_as_of'] ?? null);
        $this->assertSame(60, $meta['max_staleness_seconds'] ?? null);
        $this->assertSame('LIVE', $meta['inventory_freshness'] ?? null);
    }

    /** §13.8: deep-link tới SKU chưa publish phải bị mask bằng 404, không phải 403. */
    public function test_detail_masks_unpublished_sku_with_404(): void
    {
        $hidden = $this->skuFixture('SKU-PV-HIDDEN', 'SKU nội bộ');

        $this->getJson("/api/v1/pv/products/{$hidden->sku_id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    /**
     * Annex C: error set của nhóm PV ẩn danh là {400,404,429,500}. Tham số sai
     * phải là 400 INVALID_QUERY/INVALID_PATH chứ không được rơi xuống 422.
     */
    public function test_public_parameter_errors_use_400_not_422(): void
    {
        $this->getJson('/api/v1/pv/products?per_page=500')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_QUERY');

        $this->getJson('/api/v1/pv/products?industry_id=not-a-uuid')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_QUERY');

        $this->getJson('/api/v1/pv/products/not-a-uuid')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_PATH');

        $this->getJson('/api/v1/pv/recommendations')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_QUERY');
    }

    public function test_industry_and_tag_filter_narrow_the_public_list(): void
    {
        $matched = $this->publishedSkuFixture('SKU-PV-IND-1', 'SKU thuộc ngành');
        $other = $this->publishedSkuFixture('SKU-PV-IND-2', 'SKU ngoài ngành');

        $industryId = $this->industryFixture('IND-PV-1', 'Cơ khí');
        $tagId = $this->tagFixture('TAG-PV-1', 'Khuyến nghị');
        $profileId = $this->profileIdForSku($matched->sku_id);
        $this->mapProfileToIndustry($profileId, $industryId);
        $this->mapProfileToTag($profileId, $tagId);

        $byIndustry = $this->getJson('/api/v1/pv/products?industry_id='.$industryId)->assertOk();
        $this->assertSame(['SKU-PV-IND-1'], collect($byIndustry->json('data'))->pluck('sku_code')->all());

        $byTag = $this->getJson('/api/v1/pv/products?tag_id='.$tagId)->assertOk();
        $this->assertSame(['SKU-PV-IND-1'], collect($byTag->json('data'))->pluck('sku_code')->all());

        $this->assertNotNull($other->sku_id);
    }

    /** BR-PV-MST: ngành nghề INACTIVE bị loại khỏi mặt công khai. */
    public function test_inactive_industry_is_hidden_from_public_list(): void
    {
        $active = $this->industryFixture('IND-PV-ON', 'Ngành đang bật');
        $inactive = $this->industryFixture('IND-PV-OFF', 'Ngành đã tắt');
        DB::table(PvRelations::READ_INDUSTRY)->where('industry_id', $inactive)->update(['status' => 'INACTIVE']);

        $codes = collect($this->getJson('/api/v1/pv/industries')->assertOk()->json('data'))
            ->pluck('industry_code')->all();

        $this->assertContains('IND-PV-ON', $codes);
        $this->assertNotContains('IND-PV-OFF', $codes);
        $this->assertNotNull($active);
    }

    /**
     * ERD bảng 57 cho phép rule chọn theo sku / category / tag. Bản cũ chỉ JOIN
     * theo product_id nên rule category/tag bị bỏ qua âm thầm.
     */
    public function test_recommendations_resolve_sku_category_and_tag_selectors(): void
    {
        $industryId = $this->industryFixture('IND-REC', 'Ngành gợi ý');
        $tagId = $this->tagFixture('TAG-REC', 'Tag gợi ý');
        $categoryId = $this->categoryFixture('CAT-REC', 'Nhóm gợi ý');

        $bySku = $this->publishedSkuFixture('SKU-REC-1', 'Gợi ý theo SKU');
        $byCategory = $this->publishedSkuFixture('SKU-REC-2', 'Gợi ý theo nhóm hàng', $categoryId);
        $byTag = $this->publishedSkuFixture('SKU-REC-3', 'Gợi ý theo tag');
        $this->mapProfileToTag($this->profileIdForSku($byTag->sku_id), $tagId);

        $this->ruleFixture($industryId, ['sku_id' => $bySku->sku_id], 1);
        $this->ruleFixture($industryId, ['category_id' => $categoryId], 2);
        $this->ruleFixture($industryId, ['tag_id' => $tagId], 3);

        $codes = collect($this->getJson('/api/v1/pv/recommendations?industry_id='.$industryId)->assertOk()->json('data'))
            ->pluck('sku_code')->all();

        $this->assertSame(['SKU-REC-1', 'SKU-REC-2', 'SKU-REC-3'], $codes);
    }

    // ------------------------------------------------------------------
    // Publish permission split (FE252-P1-03)
    // ------------------------------------------------------------------

    /** §3: publish App PV là permission key riêng; sku.manage KHÔNG thay thế được. */
    public function test_publish_requires_pv_publish_and_rejects_sku_manage_only(): void
    {
        $sku = $this->skuFixture('SKU-PV-PERM', 'SKU kiểm quyền publish');

        $skuManageOnly = $this->headersForPermissions(['sku.view', 'sku.manage']);
        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => true], $skuManageOnly + ['If-Match' => '0'])
            ->assertStatus(403);

        $pvPublisher = $this->headersForPermissions(['sku.view', 'pv.publish']);
        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => true], $pvPublisher + ['If-Match' => '0'])
            ->assertOk()
            ->assertJsonPath('data.published_to_app', true);
    }

    /** Publish rồi là thấy ngay trên mặt công khai — cùng hành vi trên cả hai driver. */
    public function test_publish_makes_sku_visible_on_public_catalog(): void
    {
        $sku = $this->skuFixture('SKU-PV-FLOW', 'SKU publish end to end');

        $this->getJson('/api/v1/pv/products')->assertOk()->assertJsonCount(0, 'data');

        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => true], $this->superAdminHeaders() + ['If-Match' => '0'])
            ->assertOk();

        $this->getJson('/api/v1/pv/products')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku_code', 'SKU-PV-FLOW');

        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => false], $this->superAdminHeaders() + ['If-Match' => '1'])
            ->assertOk();

        $this->getJson('/api/v1/pv/products')->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------------
    // Publish precondition — FE252-P1-08 (Annex C: If-Match + 412 + 428)
    // ------------------------------------------------------------------

    /**
     * Annex C khai cột header `If-Match` cho publishPvProduct và có 428 trong
     * error set. Thiếu header = 428 PRECONDITION_REQUIRED và trạng thái publish
     * KHÔNG được đổi — nếu không, hai người bấm công tắc cùng lúc sẽ ghi đè nhau.
     */
    public function test_publish_without_if_match_returns_428_and_changes_nothing(): void
    {
        $sku = $this->skuFixture('SKU-PV-428', 'SKU thiếu precondition');

        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => true], $this->superAdminHeaders())
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED')
            ->assertJsonPath('error.details.current_version', 0);

        $this->assertFalse((bool) DB::table('sku')->where('sku_id', $sku->sku_id)->value('published_to_app'));
        $this->getJson('/api/v1/pv/products')->assertOk()->assertJsonCount(0, 'data');
    }

    /** §8.2: version lệch = 412 STALE_VERSION, kèm expected/current để FE tải lại. */
    public function test_publish_with_stale_profile_version_returns_412(): void
    {
        $sku = $this->skuFixture('SKU-PV-412', 'SKU lệch version');

        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => true], $this->superAdminHeaders() + ['If-Match' => '0'])
            ->assertOk();

        // Version hồ sơ giờ là 1; gửi lại 0 chính là kịch bản màn hình mở từ trước.
        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => false], $this->superAdminHeaders() + ['If-Match' => '0'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION')
            ->assertJsonPath('error.details.expected_version', 0)
            ->assertJsonPath('error.details.current_version', 1);

        $this->assertTrue((bool) DB::table('sku')->where('sku_id', $sku->sku_id)->value('published_to_app'));
    }

    /**
     * Version hồ sơ phải TĂNG sau mỗi lần bật/tắt, nếu không optimistic lock chỉ
     * là hình thức: hai request cũ cùng version sẽ cùng thành công. Response trả
     * `pv_profile_version` để FE cập nhật tại chỗ.
     */
    public function test_publish_bumps_pv_profile_version_and_returns_it(): void
    {
        $sku = $this->skuFixture('SKU-PV-VER', 'SKU tăng version hồ sơ');
        $relation = PvRelations::profile();

        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => true], $this->superAdminHeaders() + ['If-Match' => '0'])
            ->assertOk()
            ->assertJsonPath('data.pv_profile_version', 1);

        $this->assertSame(1, (int) DB::table($relation['table'])->where($relation['sku'], $sku->sku_id)->value('version'));

        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => false], $this->superAdminHeaders() + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.pv_profile_version', 2);

        $this->assertSame(2, (int) DB::table($relation['table'])->where($relation['sku'], $sku->sku_id)->value('version'));

        // Version của dòng đang mở trên SCR-PV-ADM-01 chính là số FE phải gửi.
        $this->getJson('/api/v1/pv-admin/resources/product-profiles', $this->headersForPermissions(['pv.master.manage']))
            ->assertOk()
            ->assertJsonPath('data.0.version', 2);
    }

    /** If-Match không phải số = 422 INVALID_VERSION (không phải 500/400 chung chung). */
    public function test_publish_with_non_numeric_if_match_returns_422(): void
    {
        $sku = $this->skuFixture('SKU-PV-INV', 'SKU If-Match sai định dạng');

        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => true], $this->superAdminHeaders() + ['If-Match' => '*'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_VERSION');
    }

    // ------------------------------------------------------------------
    // PV Admin resources (REQ-SRS-033)
    // ------------------------------------------------------------------

    public function test_pv_admin_requires_dedicated_permission(): void
    {
        $this->getJson('/api/v1/pv-admin/resources/industries', $this->headersForPermissions(['sku.manage']))
            ->assertStatus(403);

        $this->getJson('/api/v1/pv-admin/resources/industries', $this->headersForPermissions(['pv.master.manage']))
            ->assertOk();
    }

    public function test_pv_admin_industry_crud_enforces_if_match(): void
    {
        $headers = $this->headersForPermissions(['pv.master.manage']);

        $created = $this->postJson('/api/v1/pv-admin/resources/industries', [
            'industry_code' => 'IND-ADM-1',
            'industry_name' => 'Ngành quản trị',
            'sort_order' => 1,
        ], $headers)->assertCreated();

        $id = $created->json('data.id');
        $version = $created->json('data.version');

        // Thiếu If-Match: §8.2 → 428 PRECONDITION_REQUIRED.
        $this->patchJson("/api/v1/pv-admin/resources/industries/{$id}", [
            'industry_code' => 'IND-ADM-1',
            'industry_name' => 'Ngành quản trị v2',
        ], $headers)->assertStatus(428)->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        // Version lệch: 412 STALE_VERSION.
        $this->patchJson("/api/v1/pv-admin/resources/industries/{$id}", [
            'industry_code' => 'IND-ADM-1',
            'industry_name' => 'Ngành quản trị v2',
        ], $headers + ['If-Match' => (string) ($version + 5)])
            ->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');

        $this->patchJson("/api/v1/pv-admin/resources/industries/{$id}", [
            'industry_code' => 'IND-ADM-1',
            'industry_name' => 'Ngành quản trị v2',
        ], $headers + ['If-Match' => (string) $version])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ngành quản trị v2')
            ->assertJsonPath('data.version', $version + 1);
    }

    /** BR-PV-MST-01..03: mã master là duy nhất. */
    public function test_pv_admin_rejects_duplicate_industry_code(): void
    {
        $headers = $this->headersForPermissions(['pv.master.manage']);
        $payload = ['industry_code' => 'IND-DUP', 'industry_name' => 'Ngành trùng'];

        $this->postJson('/api/v1/pv-admin/resources/industries', $payload, $headers)->assertCreated();
        $this->postJson('/api/v1/pv-admin/resources/industries', $payload, $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    /** ERD bảng 57: rule phải có ít nhất một selector và priority duy nhất trong ngành. */
    public function test_pv_admin_rule_requires_selector_and_unique_priority(): void
    {
        $headers = $this->headersForPermissions(['pv.master.manage']);
        $industryId = $this->industryFixture('IND-RULE', 'Ngành rule');
        $sku = $this->publishedSkuFixture('SKU-RULE', 'SKU rule');

        $this->postJson('/api/v1/pv-admin/resources/recommendation-rules', [
            'industry_id' => $industryId,
            'priority' => 1,
        ], $headers)->assertStatus(422)->assertJsonPath('error.code', 'BUSINESS_RULE_VIOLATION');

        $this->postJson('/api/v1/pv-admin/resources/recommendation-rules', [
            'industry_id' => $industryId,
            'sku_id' => $sku->sku_id,
            'priority' => 1,
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/pv-admin/resources/recommendation-rules', [
            'industry_id' => $industryId,
            'sku_id' => $sku->sku_id,
            'priority' => 1,
        ], $headers)->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');
    }

    /**
     * FE252-P1-03 ở tầng BE: pv.master.manage quản lý mapping/hiển thị nhưng
     * KHÔNG được bật/tắt publish — đó là quyền pv.publish ở endpoint riêng.
     */
    public function test_pv_admin_profile_cannot_toggle_publish(): void
    {
        $headers = $this->headersForPermissions(['pv.master.manage']);
        $sku = $this->skuFixture('SKU-PROFILE', 'SKU hồ sơ PV');
        $industryId = $this->industryFixture('IND-PROFILE', 'Ngành hồ sơ');

        $created = $this->postJson('/api/v1/pv-admin/resources/product-profiles', [
            'sku_id' => $sku->sku_id,
            'display_name' => 'Tên hiển thị PV',
            'industry_ids' => [$industryId],
        ], $headers)->assertCreated();

        $this->assertFalse($created->json('data.published'));
        $this->assertSame([$industryId], $created->json('data.industry_ids'));

        $this->patchJson('/api/v1/pv-admin/resources/product-profiles/'.$created->json('data.id'), [
            'display_name' => 'Tên hiển thị PV',
            'published' => true,
        ], $headers + ['If-Match' => (string) $created->json('data.version')])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BUSINESS_RULE_VIOLATION');
    }

    public function test_pv_admin_rejects_unknown_resource_type(): void
    {
        $this->getJson('/api/v1/pv-admin/resources/unknown-thing', $this->headersForPermissions(['pv.master.manage']))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    // ------------------------------------------------------------------
    // Ảnh sản phẩm App PV (MST-PV-01) — client upload lên CDN rồi gửi URL
    // ------------------------------------------------------------------

    /**
     * Vòng đời `images`: tạo kèm ảnh → đọc lại đúng THỨ TỰ đã gửi. Thứ tự trong
     * mảng chính là `sort_order`, nên hoán vị hai phần tử phải đổi thứ tự đọc ra
     * chứ không phải trả về theo thứ tự chèn ban đầu.
     */
    public function test_pv_admin_profile_stores_and_returns_images(): void
    {
        $headers = $this->headersForPermissions(['pv.master.manage']);
        $sku = $this->skuFixture('SKU-PV-IMG', 'SKU có ảnh App PV');

        $created = $this->postJson('/api/v1/pv-admin/resources/product-profiles', [
            'sku_id' => $sku->sku_id,
            'display_name' => 'Sản phẩm có ảnh',
            'images' => [
                ['url' => 'https://res.cloudinary.com/demo/image/upload/v1/a.jpg', 'alt_text' => 'Ảnh chính', 'is_primary' => true],
                ['url' => 'https://res.cloudinary.com/demo/image/upload/v1/b.jpg', 'alt_text' => null, 'is_primary' => false],
            ],
        ], $headers)->assertCreated();

        $created->assertJsonPath('data.images.0.url', 'https://res.cloudinary.com/demo/image/upload/v1/a.jpg')
            ->assertJsonPath('data.images.0.alt_text', 'Ảnh chính')
            ->assertJsonPath('data.images.0.is_primary', true)
            ->assertJsonPath('data.images.1.is_primary', false);

        $this->patchJson('/api/v1/pv-admin/resources/product-profiles/'.$created->json('data.id'), [
            'display_name' => 'Sản phẩm có ảnh',
            'images' => [
                ['url' => 'https://res.cloudinary.com/demo/image/upload/v1/b.jpg', 'alt_text' => 'Ảnh phụ lên đầu', 'is_primary' => true],
                ['url' => 'https://res.cloudinary.com/demo/image/upload/v1/a.jpg', 'alt_text' => 'Ảnh cũ', 'is_primary' => false],
            ],
        ], $headers + ['If-Match' => (string) $created->json('data.version')])
            ->assertOk()
            ->assertJsonPath('data.images.0.url', 'https://res.cloudinary.com/demo/image/upload/v1/b.jpg')
            ->assertJsonPath('data.images.0.is_primary', true);
    }

    /**
     * Khoá `images` VẮNG MẶT = giữ nguyên ảnh; `images: []` = xoá hết. Nhập nhèm
     * hai ca này nghĩa là mỗi lần sửa tên hiển thị trên SCR-PV-ADM-01 (màn không
     * gửi `images`) sẽ âm thầm xoá sạch ảnh của hồ sơ.
     */
    public function test_pv_admin_profile_images_absent_keeps_and_empty_clears(): void
    {
        $headers = $this->headersForPermissions(['pv.master.manage']);
        $sku = $this->skuFixture('SKU-PV-IMG2', 'SKU giữ ảnh');

        $created = $this->postJson('/api/v1/pv-admin/resources/product-profiles', [
            'sku_id' => $sku->sku_id,
            'display_name' => 'Giữ ảnh',
            'images' => [['url' => 'https://cdn.hoanam.vn/a.jpg', 'is_primary' => true]],
        ], $headers)->assertCreated();

        $kept = $this->patchJson('/api/v1/pv-admin/resources/product-profiles/'.$created->json('data.id'), [
            'display_name' => 'Đổi mỗi tên hiển thị',
        ], $headers + ['If-Match' => (string) $created->json('data.version')])->assertOk();
        $this->assertCount(1, $kept->json('data.images'));

        $cleared = $this->patchJson('/api/v1/pv-admin/resources/product-profiles/'.$created->json('data.id'), [
            'display_name' => 'Đổi mỗi tên hiển thị',
            'images' => [],
        ], $headers + ['If-Match' => (string) $kept->json('data.version')])->assertOk();
        $this->assertSame([], $cleared->json('data.images'));
    }

    /**
     * `javascript:` / `data:` phải chết ở tầng validate: App PV render chuỗi này
     * thẳng vào `<img src>`, nên URL như vậy lọt xuống DB là mã chạy trên máy
     * NGƯỜI XEM mặt công khai.
     */
    public function test_pv_admin_profile_rejects_non_http_image_url(): void
    {
        $headers = $this->headersForPermissions(['pv.master.manage']);
        $sku = $this->skuFixture('SKU-PV-XSS', 'SKU ảnh xấu');

        $this->postJson('/api/v1/pv-admin/resources/product-profiles', [
            'sku_id' => $sku->sku_id,
            'display_name' => 'Ảnh xấu',
            'images' => [['url' => 'javascript:alert(1)', 'is_primary' => true]],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    /** UNIQUE ux_erd_pv_primary_image: hai ảnh đại diện phải là 422, không phải 500. */
    public function test_pv_admin_profile_rejects_two_primary_images(): void
    {
        $headers = $this->headersForPermissions(['pv.master.manage']);
        $sku = $this->skuFixture('SKU-PV-2PRIM', 'SKU hai ảnh chính');

        $this->postJson('/api/v1/pv-admin/resources/product-profiles', [
            'sku_id' => $sku->sku_id,
            'display_name' => 'Hai ảnh chính',
            'images' => [
                ['url' => 'https://cdn.hoanam.vn/a.jpg', 'is_primary' => true],
                ['url' => 'https://cdn.hoanam.vn/b.jpg', 'is_primary' => true],
            ],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    /** Không đánh dấu ảnh nào thì ảnh đầu tiên thành ảnh đại diện. */
    public function test_pv_admin_profile_defaults_first_image_as_primary(): void
    {
        $headers = $this->headersForPermissions(['pv.master.manage']);
        $sku = $this->skuFixture('SKU-PV-NOPRIM', 'SKU không đánh dấu ảnh chính');

        $this->postJson('/api/v1/pv-admin/resources/product-profiles', [
            'sku_id' => $sku->sku_id,
            'display_name' => 'Không đánh dấu',
            'images' => [
                ['url' => 'https://cdn.hoanam.vn/a.jpg'],
                ['url' => 'https://cdn.hoanam.vn/b.jpg'],
            ],
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.images.0.is_primary', true)
            ->assertJsonPath('data.images.1.is_primary', false);
    }

    /**
     * HỒI QUY: hồ sơ App PV do trigger publish tạo mang `version = 0` (default
     * của cột). `assertIfMatch` từng đòi `>= 1`, nên KHÔNG số nào PATCH được
     * những hồ sơ đó — nút "Sửa" của SCR-PV-ADM-01 và MST-PV-01 luôn 422
     * INVALID_VERSION. Khoá lạc quan không bị nới: version lệch vẫn 412.
     */
    public function test_pv_admin_profile_accepts_if_match_zero(): void
    {
        $headers = $this->headersForPermissions(['pv.master.manage']);
        $sku = $this->skuFixture('SKU-PV-V0', 'SKU hồ sơ version 0');
        $relation = PvRelations::profile();

        $created = $this->postJson('/api/v1/pv-admin/resources/product-profiles', [
            'sku_id' => $sku->sku_id,
            'display_name' => 'Hồ sơ version 0',
        ], $headers)->assertCreated();

        // Ép về 0 để dựng đúng hình dạng dòng do trigger publish tạo.
        DB::table($relation['table'])->where($relation['key'], $created->json('data.id'))->update(['version' => 0]);

        $this->getJson('/api/v1/pv-admin/resources/product-profiles', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.version', 0);

        $this->patchJson('/api/v1/pv-admin/resources/product-profiles/'.$created->json('data.id'), [
            'display_name' => 'Sửa được hồ sơ version 0',
        ], $headers + ['If-Match' => '0'])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Sửa được hồ sơ version 0')
            ->assertJsonPath('data.version', 1);

        $this->patchJson('/api/v1/pv-admin/resources/product-profiles/'.$created->json('data.id'), [
            'display_name' => 'Lần hai với version cũ',
        ], $headers + ['If-Match' => '0'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION');
    }

    /**
     * HỒI QUY: `GET /pv-admin/resources/product-profiles` cache payload dưới
     * namespace 'master:version', còn `PATCH /pv/products/{id}/publish` trước đây
     * chỉ bump 'stock:version'. Hệ quả: bật/tắt công tắc hiển thị báo thành công
     * nhưng danh sách đọc lại vẫn ra trạng thái CŨ cho tới hết TTL — trên UI là
     * công tắc bật xong tự nhảy về.
     *
     * Test phải GỌI GET TRƯỚC để nạp cache, nếu không nó không chứng minh được gì.
     */
    public function test_publish_invalidates_pv_admin_profile_list_cache(): void
    {
        $headers = $this->headersForPermissions(['pv.master.manage', 'pv.publish']);
        $sku = $this->skuFixture('SKU-PV-CACHE', 'SKU kiểm tra cache publish');

        $created = $this->postJson('/api/v1/pv-admin/resources/product-profiles', [
            'sku_id' => $sku->sku_id,
            'display_name' => 'Sản phẩm kiểm tra cache',
        ], $headers)->assertCreated();

        // Nạp cache với trạng thái CHƯA publish.
        $this->getJson('/api/v1/pv-admin/resources/product-profiles', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.published', false);

        $profileVersion = (int) $created->json('data.version');
        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => true],
            $headers + ['If-Match' => (string) $profileVersion])->assertOk();

        $this->getJson('/api/v1/pv-admin/resources/product-profiles', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.published', true);
    }

    // ------------------------------------------------------------------
    // Upload ảnh sản phẩm (MST-PV-01) — object storage của hệ thống
    // ------------------------------------------------------------------

    /**
     * Vòng đời một tấm ảnh: upload có xác thực → ghi vào disk cấu hình → URL trả
     * về đọc được KHÔNG cần token (App PV là mặt ẩn danh).
     */
    public function test_pv_product_image_upload_stores_file_and_serves_it_anonymously(): void
    {
        Storage::fake('s3');

        $response = $this->post(
            '/api/v1/pv-admin/product-images',
            ['file' => UploadedFile::fake()->image('may-khoan.jpg', 40, 30)],
            $this->headersForPermissions(['pv.master.manage'])
        )->assertCreated();

        $storageKey = (string) $response->json('data.storage_key');
        $this->assertStringStartsWith('pv-product-images/', $storageKey);
        Storage::disk('s3')->assertExists($storageKey);

        $url = (string) $response->json('data.url');
        $this->assertStringContainsString('/api/v1/pv/product-images/', $url);

        // Đọc lại KHÔNG kèm Authorization — đúng bản chất mặt công khai.
        $this->get(parse_url($url, PHP_URL_PATH))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    /** Upload là hành vi ghi lên mặt công khai — phải gate bằng pv.master.manage. */
    public function test_pv_product_image_upload_requires_pv_master_manage(): void
    {
        Storage::fake('s3');

        $this->post(
            '/api/v1/pv-admin/product-images',
            ['file' => UploadedFile::fake()->image('a.jpg')],
            $this->headersForPermissions(['pv.publish'])
        )->assertStatus(403);
    }

    /**
     * Đổi tên `.php` thành `.jpg` là thứ đầu tiên người ta thử với mọi ô upload.
     * Kiểm MIME THẬT bằng magic bytes chứ không tin phần mở rộng.
     */
    public function test_pv_product_image_upload_rejects_non_image_content(): void
    {
        Storage::fake('s3');

        $this->post(
            '/api/v1/pv-admin/product-images',
            ['file' => UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo 1;')],
            $this->headersForPermissions(['pv.master.manage'])
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ATTACHMENT_CONTENT_TYPE_INVALID');

        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    /**
     * Route công khai chỉ nhận key dạng `<uuid>.<ext>`. Không có đường ghép ra
     * đường dẫn trỏ sang tệp khác trong bucket, và key lạ trả 404 chứ không 500.
     */
    public function test_pv_product_image_route_rejects_keys_outside_the_image_prefix(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('warranty-attachments/secret.jpg', 'private');

        $this->get('/api/v1/pv/product-images/..%2f..%2fwarranty-attachments%2fsecret.jpg')->assertNotFound();
        $this->get('/api/v1/pv/product-images/not-a-uuid.jpg')->assertNotFound();
        $this->get('/api/v1/pv/product-images/'.Str::uuid().'.php')->assertNotFound();
        $this->get('/api/v1/pv/product-images/'.Str::uuid().'.jpg')->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function skuFixture(string $code, string $name, ?string $categoryId = null): Sku
    {
        return Sku::create([
            'sku_code' => $code,
            'sku_name' => $name,
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
            'category_id' => $categoryId,
        ]);
    }

    private function publishedSkuFixture(string $code, string $name, ?string $categoryId = null): Sku
    {
        $sku = $this->skuFixture($code, $name, $categoryId);
        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => true], $this->superAdminHeaders() + ['If-Match' => '0'])
            ->assertOk();

        return $sku;
    }

    private function profileIdForSku(string $skuId): string
    {
        $relation = PvRelations::profile();
        $profileId = DB::table($relation['table'])->where($relation['sku'], $skuId)->value($relation['key']);
        $this->assertNotNull($profileId, 'Publish phải tạo hồ sơ App PV trên mọi driver.');

        return (string) $profileId;
    }

    private function industryFixture(string $code, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table(PvRelations::READ_INDUSTRY)->insert([
            'industry_id' => $id,
            'industry_code' => $code,
            'industry_name' => $name,
            'status' => 'ACTIVE',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function tagFixture(string $code, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table(PvRelations::READ_TAG)->insert([
            'tag_id' => $id,
            'tag_code' => $code,
            'tag_name' => $name,
            'status' => 'ACTIVE',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function categoryFixture(string $code, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('product_categories')->insert([
            'id' => $id,
            'category_code' => $code,
            'category_name' => $name,
            'status' => 'ACTIVE',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // sku.category_id trỏ product_masters trên baseline sqlite; PostgreSQL đã
        // có trigger đồng bộ canonical → product_masters.
        if (DB::getDriverName() !== 'pgsql') {
            DB::table('product_masters')->insert([
                'master_id' => $id,
                'master_type' => 'CATEGORY',
                'code' => $code,
                'name' => $name,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function mapProfileToIndustry(string $profileId, string $industryId): void
    {
        DB::table(PvRelations::productIndustryTable())->insert([
            'profile_id' => $profileId,
            'industry_id' => $industryId,
            'priority' => 1,
            'created_at' => now(),
        ]);
    }

    private function mapProfileToTag(string $profileId, string $tagId): void
    {
        DB::table(PvRelations::productTagTable())->insert([
            'profile_id' => $profileId,
            'tag_id' => $tagId,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, string>  $selector
     */
    private function ruleFixture(string $industryId, array $selector, int $priority): void
    {
        $relation = PvRelations::rule();
        DB::table($relation['table'])->insert([
            $relation['key'] => (string) Str::uuid(),
            'industry_id' => $industryId,
            $relation['sku'] => $selector['sku_id'] ?? null,
            'category_id' => $selector['category_id'] ?? null,
            'tag_id' => $selector['tag_id'] ?? null,
            'priority' => $priority,
            'status' => 'ACTIVE',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Role tối thiểu đúng bằng danh sách permission truyền vào — dùng để chứng
     * minh gate là permission cụ thể chứ không phải "có role gì đó".
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
        $roleCode = 'PV-TEST-'.Str::upper(Str::random(6));
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
