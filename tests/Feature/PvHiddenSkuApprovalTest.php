<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\PvRelations;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class PvHiddenSkuApprovalTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_new_sku_is_listed_as_hidden_then_approval_publishes_and_hiding_removes_it_from_preview(): void
    {
        $headers = $this->superAdminHeaders();
        $sku = $this->postJson('/api/v1/products', [
            'sku_code' => 'PV-FLOW', 'sku_name' => 'New SKU', 'published_to_app' => false,
        ], $headers)->assertCreated()->json('data');
        $list = '/api/v1/pv-admin/resources/product-profiles?keyword=PV-FLOW';
        $profile = $this->getJson($list, $headers)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.published', false)->json('data.0');
        $this->getJson('/api/v1/pv/products?keyword=PV-FLOW')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/pv/products/'.$sku['sku_id'])->assertNotFound();

        $saved = $this->postJson('/api/v1/pv-admin/products/'.$sku['sku_id'].'/approve', [
            'display_name' => 'Approved display name', 'description' => 'Customer description',
        ], $headers + ['If-Match' => (string) $profile['version']])->assertOk()
            ->assertJsonPath('data.id', $profile['id'])->assertJsonPath('data.published', true)->json('data');
        $this->getJson($list, $headers)->assertOk()->assertJsonPath('data.0.published', true);
        $this->getJson('/api/v1/pv/products?keyword=PV-FLOW')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/pv/products/'.$sku['sku_id'])->assertOk();

        // An edit made from the pre-approval snapshot must not overwrite the approved metadata.
        $this->patchJson('/api/v1/pv-admin/resources/product-profiles/'.$profile['id'], [
            'display_name' => 'Stale edit',
        ], $headers + ['If-Match' => (string) $profile['version']])->assertStatus(412);

        $this->patchJson('/api/v1/pv/products/'.$sku['sku_id'].'/publish', ['published' => false],
            $headers + ['If-Match' => (string) $saved['version']])->assertOk();
        $this->getJson('/api/v1/pv/products?keyword=PV-FLOW')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/pv/products/'.$sku['sku_id'])->assertNotFound();
        $this->getJson($list, $headers)->assertOk()->assertJsonPath('data.0.published', false);
    }

    public function test_creation_defaults_to_hidden_and_explicit_publication_still_works(): void
    {
        $headers = $this->superAdminHeaders();
        foreach ([false, true] as $published) {
            $code = $published ? 'PV-CREATE-PUBLIC' : 'PV-CREATE-DEFAULT';
            $this->postJson('/api/v1/products', ['sku_code' => $code, 'sku_name' => $code]
                + ($published ? ['published_to_app' => true] : []), $headers)->assertCreated();
            $this->getJson('/api/v1/pv-admin/resources/product-profiles?keyword='.$code, $headers)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.published', $published);
        }
    }

    public function test_hidden_active_candidates_are_filtered_before_pagination(): void
    {
        $headers = $this->superAdminHeaders();
        foreach (['A', 'B', 'C'] as $suffix) {
            $this->postJson('/api/v1/products', ['sku_code' => 'PV-CHOICE-'.$suffix, 'sku_name' => $suffix, 'published_to_app' => false], $headers)->assertCreated();
        }
        $this->postJson('/api/v1/products', ['sku_code' => 'PV-CHOICE-PUBLIC', 'sku_name' => 'Public', 'published_to_app' => true], $headers)->assertCreated();
        $inactive = Sku::create(['sku_code' => 'PV-CHOICE-INACTIVE', 'sku_name' => 'Inactive', 'status' => 'INACTIVE', 'published_to_app' => false]);
        $r = PvRelations::profile();
        if (! DB::table($r['table'])->where($r['sku'], $inactive->sku_id)->exists()) {
            DB::table($r['table'])->insert([$r['key'] => (string) Str::uuid(), $r['sku'] => $inactive->sku_id,
                'display_name' => 'Inactive', 'published' => false, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $url = '/api/v1/pv-admin/resources/product-profiles?published=0&sku_status=ACTIVE&keyword=PV-CHOICE&per_page=1';
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.sku_code', 'PV-CHOICE-A');
        $this->getJson($url.'&page=3', $headers)->assertOk()->assertJsonPath('data.0.sku_code', 'PV-CHOICE-C');
    }

    public function test_approval_checks_versions_and_rolls_back_invalid_metadata_without_publishing(): void
    {
        $headers = $this->superAdminHeaders();
        $sku = $this->postJson('/api/v1/products', ['sku_code' => 'PV-GUARD', 'sku_name' => 'Original', 'published_to_app' => false], $headers)->assertCreated()->json('data');
        $profile = $this->getJson('/api/v1/pv-admin/resources/product-profiles?keyword=PV-GUARD', $headers)->assertOk()->json('data.0');
        $this->assertNotNull($profile);
        $url = '/api/v1/pv-admin/products/'.$sku['sku_id'].'/approve';
        $this->postJson($url, ['display_name' => 'Changed'], $headers)->assertStatus(428);
        $this->postJson($url, ['display_name' => 'Changed'], $headers + ['If-Match' => (string) ($profile['version'] + 10)])->assertStatus(412);
        $this->postJson($url, ['display_name' => 'Changed', 'images' => [['url' => 'javascript:invalid']]],
            $headers + ['If-Match' => (string) $profile['version']])->assertUnprocessable();
        $this->getJson('/api/v1/pv-admin/resources/product-profiles?keyword=PV-GUARD', $headers)
            ->assertOk()->assertJsonPath('data.0.display_name', 'Original')->assertJsonPath('data.0.published', false)
            ->assertJsonPath('data.0.version', $profile['version']);
    }

    public function test_approval_requires_both_profile_management_and_publish_permissions(): void
    {
        $admin = $this->superAdminHeaders();
        $sku = $this->postJson('/api/v1/products', ['sku_code' => 'PV-PERM', 'sku_name' => 'Original', 'published_to_app' => false], $admin)->assertCreated()->json('data');
        foreach (['pv.master.manage', 'pv.publish'] as $permission) {
            $headers = $this->superAdminHeaders();
            $role = (string) Str::uuid();
            DB::table('role')->insert(['role_id' => $role, 'role_code' => 'PV-'.Str::random(8), 'role_name' => 'PV test', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role,
                'permission_id' => DB::table('permission')->where('permission_key', $permission)->value('permission_id'), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_role')->where('user_id', $headers['__user_id'])->delete();
            DB::table('user_role')->insert(['user_role_id' => (string) Str::uuid(), 'user_id' => $headers['__user_id'], 'role_id' => $role, 'created_at' => now()]);
            Cache::flush();
            $this->postJson('/api/v1/pv-admin/products/'.$sku['sku_id'].'/approve', ['display_name' => 'Forbidden'], $headers + ['If-Match' => '0'])->assertForbidden();
        }
    }

    public function test_aggregate_sku_creation_also_provisions_hidden_administration_profile(): void
    {
        $headers = $this->superAdminHeaders();
        $this->getJson('/api/v1/pv-admin/resources/product-profiles?keyword=PV-AGGREGATE', $headers)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/skus', ['code' => 'PV-AGGREGATE', 'name' => 'Aggregate SKU'],
            $headers + ['Idempotency-Key' => 'pv-aggregate-create'])->assertCreated();
        $this->getJson('/api/v1/pv-admin/resources/product-profiles?keyword=PV-AGGREGATE', $headers)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.published', false);
    }

    public function test_excel_imported_hidden_sku_is_available_in_administration_and_approval_candidates(): void
    {
        $headers = $this->superAdminHeaders();
        $list = '/api/v1/pv-admin/resources/product-profiles?keyword=PV-IMPORTED&published=0&sku_status=ACTIVE';
        $this->getJson($list, $headers)->assertOk()->assertJsonCount(0, 'data');
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['code', 'name', 'status'], ['PV-IMPORTED', 'Imported hidden SKU', 'ACTIVE']]);
        $path = sys_get_temp_dir().'/pv-hidden-'.bin2hex(random_bytes(8)).'.xlsx';
        try {
            (new Xlsx($book))->save($path);
            $file = new UploadedFile($path, 'skus.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $this->post('/api/v1/skus/import-excel', ['file' => $file], $headers + ['Accept' => 'application/json'])
                ->assertOk()->assertJsonPath('data.committed', true);
            $this->getJson($list, $headers)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.sku_code', 'PV-IMPORTED')->assertJsonPath('data.0.published', false);
        } finally {
            $book->disconnectWorksheets();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_approval_preserves_existing_images_and_never_duplicates_the_profile(): void
    {
        $headers = $this->superAdminHeaders();
        $sku = $this->postJson('/api/v1/products', ['sku_code' => 'PV-IMAGES', 'sku_name' => 'Images', 'published_to_app' => false], $headers)->assertCreated()->json('data');
        $profile = $this->getJson('/api/v1/pv-admin/resources/product-profiles?keyword=PV-IMAGES', $headers)->assertOk()->json('data.0');
        $images = [['url' => 'https://res.cloudinary.com/demo/image/upload/v1/pv.jpg', 'alt_text' => 'Existing image', 'is_primary' => true]];
        $saved = $this->patchJson('/api/v1/pv-admin/resources/product-profiles/'.$profile['id'], [
            'display_name' => 'Existing name', 'images' => $images,
        ], $headers + ['If-Match' => (string) $profile['version']])->assertOk()->json('data');
        $this->postJson('/api/v1/pv-admin/products/'.$sku['sku_id'].'/approve', ['display_name' => 'Approved'],
            $headers + ['If-Match' => (string) $saved['version']])->assertOk()
            ->assertJsonPath('data.id', $profile['id'])->assertJsonPath('data.images', $images)->assertJsonPath('data.published', true);
        $r = PvRelations::profile();
        $this->assertSame(1, DB::table($r['table'])->where($r['sku'], $sku['sku_id'])->count());
    }

    public function test_inactive_and_already_visible_skus_cannot_be_approved(): void
    {
        $headers = $this->superAdminHeaders();
        foreach ([false, true] as $published) {
            $code = $published ? 'PV-ALREADY-VISIBLE' : 'PV-INACTIVE';
            $sku = $this->postJson('/api/v1/products', ['sku_code' => $code, 'sku_name' => $code, 'published_to_app' => $published], $headers)->assertCreated()->json('data');
            $profile = $this->getJson('/api/v1/pv-admin/resources/product-profiles?keyword='.$code, $headers)->assertOk()->json('data.0');
            if (! $published) {
                $this->patchJson('/api/v1/products/'.$sku['sku_id'], ['status' => 'INACTIVE'], $headers)->assertOk();
            }
            $this->postJson('/api/v1/pv-admin/products/'.$sku['sku_id'].'/approve', ['display_name' => 'Do not overwrite'],
                $headers + ['If-Match' => (string) $profile['version']])->assertStatus($published ? 409 : 422);
        }
    }
}
