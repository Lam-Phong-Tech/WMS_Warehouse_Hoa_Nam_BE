<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\Sku;
use Tests\TestCase;

/**
 * Mini App NFC adapter — worklist hiện vật, giữ chỗ ghi chip, xác nhận và tra cứu
 * ngược từ chip.
 *
 * Bất biến quan trọng nhất được khoá ở đây: chuỗi ghi vào chip (`nfc_payload`)
 * PHẢI là payload QR hợp lệ của chính hiện vật đó, nhờ vậy quét NFC dùng lại
 * được mọi endpoint scan sẵn có mà không phải sửa luồng nghiệp vụ nào.
 */
class MiniAppNfcAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_code_returns_payload_to_write_into_chip(): void
    {
        $headers = $this->authHeaders();
        [$sku, $item] = $this->seedItem('PROD-MINI-NFC-001', 'SER-MINI-NFC-001');

        $this->postJson('/api/v1/mini-app/nfc/resolve-code', [
            'raw_code' => 'SER-MINI-NFC-001',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.assignable', true)
            ->assertJsonPath('data.assign_block_code', 'ASSIGNABLE')
            ->assertJsonPath('data.item.item_id', $item->item_id)
            ->assertJsonPath('data.sku.sku_code', $sku->sku_code)
            ->assertJsonPath('data.nfc_status', 'UNASSIGNED')
            ->assertJsonPath('data.nfc', null)
            ->assertJsonPath('data.nfc_payload', 'HN1|SKU=PROD-MINI-NFC-001|ITEM=SER-MINI-NFC-001')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.physical_code_created', false);
    }

    public function test_resolve_code_reports_unknown_item_without_writing_anything(): void
    {
        $this->postJson('/api/v1/mini-app/nfc/resolve-code', [
            'raw_code' => 'KHONG-CO-TRONG-HE-THONG',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.resolved', false)
            ->assertJsonPath('data.assignable', false)
            ->assertJsonPath('data.assign_block_code', 'ITEM_NOT_FOUND')
            ->assertJsonPath('data.nfc_payload', null);

        $this->assertSame(0, DB::table('physical_codes')->where('carrier_type', 'NFC')->count());
    }

    public function test_items_worklist_reports_nfc_status_and_filters_unassigned(): void
    {
        $headers = $this->authHeaders();
        [$sku, $tagged] = $this->seedItem('PROD-MINI-NFC-002', 'SER-MINI-NFC-002A');
        ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SER-MINI-NFC-002B',
            'item_status' => 'IN_STOCK',
        ]);

        $this->getJson('/api/v1/mini-app/items?nfc_status=UNASSIGNED', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.nfc_status', 'UNASSIGNED')
            ->assertJsonPath('data.0.nfc_payload', 'HN1|SKU=PROD-MINI-NFC-002|ITEM=SER-MINI-NFC-002A');

        $this->assignTag($headers, (string) $tagged->item_id, 'UID-WORKLIST-002');

        $this->getJson('/api/v1/mini-app/items?nfc_status=UNASSIGNED', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.serial_number', 'SER-MINI-NFC-002B');

        $this->getJson('/api/v1/mini-app/items?nfc_status=ASSIGNED', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.item_id', $tagged->item_id)
            ->assertJsonPath('data.0.nfc_status', 'ASSIGNED')
            ->assertJsonPath('data.0.nfc.hardware_uid', 'UID-WORKLIST-002');
    }

    public function test_prepare_holds_the_item_and_returns_payload_with_expiry(): void
    {
        $headers = $this->authHeaders();
        [, $item] = $this->seedItem('PROD-MINI-NFC-003', 'SER-MINI-NFC-003');

        $prepared = $this->postJson("/api/v1/mini-app/items/{$item->item_id}/nfc/prepare", [], $headers);

        $prepared->assertOk()
            ->assertJsonPath('data.assignable', true)
            ->assertJsonPath('data.assign_block_code', 'ASSIGNABLE')
            ->assertJsonPath('data.nfc_status', 'UNASSIGNED')
            ->assertJsonPath('data.nfc_payload', 'HN1|SKU=PROD-MINI-NFC-003|ITEM=SER-MINI-NFC-003')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.physical_code_created', false);

        $this->assertNotEmpty($prepared->json('data.reservation_token'));
        $this->assertNotEmpty($prepared->json('data.expires_at'));
        $this->assertSame(0, DB::table('physical_codes')->where('carrier_type', 'NFC')->count());
    }

    public function test_prepare_refuses_when_another_operator_holds_the_reservation(): void
    {
        [, $item] = $this->seedItem('PROD-MINI-NFC-004', 'SER-MINI-NFC-004');

        $this->postJson("/api/v1/mini-app/items/{$item->item_id}/nfc/prepare", [], $this->authHeaders())
            ->assertOk();

        // Máy thứ hai: chặn TRƯỚC khi nhân viên kịp ghi và dán chip lên sản phẩm.
        $this->postJson("/api/v1/mini-app/items/{$item->item_id}/nfc/prepare", [], $this->authHeaders())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'NFC_WRITE_RESERVED_BY_OTHER');
    }

    public function test_confirm_is_stock_neutral_and_idempotent_by_hardware_uid(): void
    {
        $headers = $this->authHeaders();
        [, $item] = $this->seedItem('PROD-MINI-NFC-005', 'SER-MINI-NFC-005');

        $token = (string) $this->postJson("/api/v1/mini-app/items/{$item->item_id}/nfc/prepare", [], $headers)
            ->json('data.reservation_token');

        $created = $this->patchJson("/api/v1/mini-app/items/{$item->item_id}/nfc", [
            'hardware_uid' => '04a1b2c3d4e5f6',
            'written_payload' => 'HN1|SKU=PROD-MINI-NFC-005|ITEM=SER-MINI-NFC-005',
            'reservation_token' => $token,
        ], $headers + ['Idempotency-Key' => 'mini-nfc-confirm-005']);

        $created->assertCreated()
            ->assertJsonPath('data.item.item_id', $item->item_id)
            ->assertJsonPath('data.nfc_status', 'ASSIGNED')
            ->assertJsonPath('data.nfc.carrier_type', 'NFC')
            ->assertJsonPath('data.nfc.hardware_uid', '04A1B2C3D4E5F6')
            ->assertJsonPath('data.nfc.code_value', 'NFC-04A1B2C3D4E5F6')
            ->assertJsonPath('data.nfc.status', 'ACTIVE')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false)
            ->assertJsonPath('data.nfc_required_for_post', false);

        $this->assertSame(0, DB::table('stock_movements')->count());

        // Key mới + chạm lại đúng chip cũ: khoá tự nhiên hardware_uid vẫn chặn,
        // không sinh dòng physical_codes thứ hai.
        $this->patchJson("/api/v1/mini-app/items/{$item->item_id}/nfc", [
            'hardware_uid' => '04A1B2C3D4E5F6',
        ], $headers + ['Idempotency-Key' => 'mini-nfc-confirm-005-lan-2'])
            ->assertOk()
            ->assertJsonPath('data.nfc.physical_code_id', $created->json('data.nfc.physical_code_id'));

        $this->assertSame(1, DB::table('physical_codes')->where('carrier_type', 'NFC')->count());
    }

    public function test_confirm_requires_idempotency_key(): void
    {
        $headers = $this->authHeaders();
        [, $item] = $this->seedItem('PROD-MINI-NFC-006', 'SER-MINI-NFC-006');

        $this->patchJson("/api/v1/mini-app/items/{$item->item_id}/nfc", [
            'hardware_uid' => 'UID-NO-KEY-006',
        ], $headers)
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        $this->assertSame(0, DB::table('physical_codes')->where('carrier_type', 'NFC')->count());
    }

    public function test_confirm_rejects_a_chip_written_for_another_item(): void
    {
        $headers = $this->authHeaders();
        [, $item] = $this->seedItem('PROD-MINI-NFC-007', 'SER-MINI-NFC-007');
        $this->seedItem('PROD-MINI-NFC-008', 'SER-MINI-NFC-008');

        $this->patchJson("/api/v1/mini-app/items/{$item->item_id}/nfc", [
            'hardware_uid' => 'UID-MISMATCH-007',
            'written_payload' => 'HN1|SKU=PROD-MINI-NFC-008|ITEM=SER-MINI-NFC-008',
        ], $headers + ['Idempotency-Key' => 'mini-nfc-mismatch-007'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NFC_PAYLOAD_ITEM_MISMATCH');

        $this->assertSame(0, DB::table('physical_codes')->where('carrier_type', 'NFC')->count());
    }

    public function test_chip_payload_resolves_through_existing_scan_surfaces(): void
    {
        $headers = $this->authHeaders();
        [, $item] = $this->seedItem('PROD-MINI-NFC-009', 'SER-MINI-NFC-009', 'ISSUED');

        $payload = (string) $this->postJson('/api/v1/mini-app/nfc/resolve-code', [
            'raw_code' => 'SER-MINI-NFC-009',
        ], $headers)->json('data.nfc_payload');

        // Đúng chuỗi ghi vào chip, đưa vào endpoint scan có sẵn (không sửa gì ở đó)
        // vẫn resolve về đúng hiện vật — đây là lý do NFC thay được QR.
        $this->postJson('/api/v1/mini-app/warranty/resolve-code', [
            'raw_code' => $payload,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.item_id', $item->item_id);
    }

    public function test_same_chip_cannot_be_moved_to_another_item(): void
    {
        $headers = $this->authHeaders();
        [, $first] = $this->seedItem('PROD-MINI-NFC-010', 'SER-MINI-NFC-010');
        [, $other] = $this->seedItem('PROD-MINI-NFC-011', 'SER-MINI-NFC-011');

        $this->assignTag($headers, (string) $first->item_id, 'UID-SHARED-010');

        $this->patchJson("/api/v1/mini-app/items/{$other->item_id}/nfc", [
            'hardware_uid' => 'UID-SHARED-010',
        ], $headers + ['Idempotency-Key' => 'mini-nfc-shared-010'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'NFC_TAG_ALREADY_ASSIGNED');
    }

    public function test_replacing_a_chip_requires_explicit_flag_and_keeps_history(): void
    {
        $headers = $this->authHeaders();
        [, $item] = $this->seedItem('PROD-MINI-NFC-012', 'SER-MINI-NFC-012');

        $firstId = $this->assignTag($headers, (string) $item->item_id, 'UID-OLD-012');

        $this->patchJson("/api/v1/mini-app/items/{$item->item_id}/nfc", [
            'hardware_uid' => 'UID-NEW-012',
        ], $headers + ['Idempotency-Key' => 'mini-nfc-replace-012-a'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ITEM_ALREADY_HAS_NFC');

        // prepare cũng phải chặn sớm, không để nhân viên ghi chip rồi mới biết.
        $this->postJson("/api/v1/mini-app/items/{$item->item_id}/nfc/prepare", [], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ITEM_ALREADY_HAS_NFC');

        $second = $this->patchJson("/api/v1/mini-app/items/{$item->item_id}/nfc", [
            'hardware_uid' => 'UID-NEW-012',
            'replace_existing' => true,
            'reason' => 'Chip cu bong khoi vo may',
        ], $headers + ['Idempotency-Key' => 'mini-nfc-replace-012-b']);

        $second->assertCreated()->assertJsonPath('data.replaced_physical_code_id', $firstId);

        $old = DB::table('physical_codes')->where('physical_code_id', $firstId)->first();
        $this->assertSame('INACTIVE', (string) $old->status);
        $this->assertSame((string) $second->json('data.nfc.physical_code_id'), (string) $old->replaced_by_code_id);
    }

    public function test_resolve_tag_returns_item_from_the_chip_uid(): void
    {
        $headers = $this->authHeaders();
        [$sku, $item] = $this->seedItem('PROD-MINI-NFC-013', 'SER-MINI-NFC-013', 'ISSUED');

        $this->assignTag($headers, (string) $item->item_id, 'UID-TRACE-013');

        $this->postJson('/api/v1/mini-app/nfc/resolve-tag', [
            'hardware_uid' => 'uid-trace-013',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.resolve_code', 'RESOLVED')
            ->assertJsonPath('data.item.item_id', $item->item_id)
            ->assertJsonPath('data.sku.sku_code', $sku->sku_code)
            ->assertJsonPath('data.nfc.hardware_uid', 'UID-TRACE-013')
            ->assertJsonPath('data.stock_effect', 'NONE');

        $this->postJson('/api/v1/mini-app/nfc/resolve-tag', [
            'hardware_uid' => 'UID-CHUA-DANG-KY',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.resolved', false)
            ->assertJsonPath('data.resolve_code', 'NFC_TAG_NOT_REGISTERED');
    }

    public function test_deactivate_frees_the_item_for_a_new_chip(): void
    {
        $headers = $this->authHeaders();
        [, $item] = $this->seedItem('PROD-MINI-NFC-014', 'SER-MINI-NFC-014');

        $tagId = $this->assignTag($headers, (string) $item->item_id, 'UID-LOST-014');

        $this->postJson("/api/v1/mini-app/nfc-tags/{$tagId}/deactivate", [
            'status' => 'LOST',
            'reason' => 'Chip roi trong qua trinh van chuyen',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.nfc.status', 'LOST')
            ->assertJsonPath('data.nfc_status', 'UNASSIGNED')
            ->assertJsonPath('data.stock_effect', 'NONE');

        // Hiện vật quay lại worklist thay vì biến mất vì "đã từng có chip".
        $this->getJson('/api/v1/mini-app/items?nfc_status=UNASSIGNED&keyword=SER-MINI-NFC-014', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assignTag($headers, (string) $item->item_id, 'UID-REPLACEMENT-014');

        $this->getJson("/api/v1/mini-app/nfc-tags?item_id={$item->item_id}", $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    private function assignTag(array $headers, string $itemId, string $hardwareUid): string
    {
        $response = $this->patchJson("/api/v1/mini-app/items/{$itemId}/nfc", [
            'hardware_uid' => $hardwareUid,
        ], $headers + ['Idempotency-Key' => 'mini-nfc-'.Str::lower(Str::random(12))]);

        $response->assertCreated();

        return (string) $response->json('data.nfc.physical_code_id');
    }

    /** @return array{0: Sku, 1: ItemInstance} */
    private function seedItem(string $skuCode, string $serial, string $itemStatus = 'IN_STOCK'): array
    {
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => 'Mini App NFC product '.$skuCode,
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
        $item = ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'serial_number' => $serial,
            'item_status' => $itemStatus,
        ]);

        return [$sku, $item];
    }

    private function authHeaders(): array
    {
        // firstOrCreate: reservation test cần HAI người dùng khác nhau trong cùng
        // một case, mà role_code là unique.
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'Super Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE'],
        );
        $user = User::create([
            'username' => 'mini-nfc-'.Str::lower(Str::random(6)),
            'display_name' => 'Mini NFC Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }
}
