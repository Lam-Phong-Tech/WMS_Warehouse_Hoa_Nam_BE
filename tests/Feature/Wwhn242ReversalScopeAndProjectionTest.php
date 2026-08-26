<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\TestCase;

/**
 * WWHN-242 (HN-FE-006 · REV-00/REV-01) — hai lỗi gốc phía BE mà FE không thể tự vá.
 *
 * 1. DATA SCOPE. Bảy route `reversal-documents` chỉ có `permission:` mà không có ràng
 *    buộc phạm vi kho, trong khi nhóm inventory (WWHN-211) và inbound (WWHN-289) đã có.
 *    Hệ quả nặng nhất không phải lộ dữ liệu mà là GHI VƯỢT BIÊN: actor có
 *    `inventory.reversal.post` nhưng `user_role.scope_ref` trỏ kho khác vẫn Post được
 *    reversal (sinh movement + đổi tồn). SRS §8.1 "Exact x-permission + data scope;
 *    default deny". Đóng bằng `EnforceReversalWarehouseScope` (tầng route) +
 *    `ReversalDocumentController::applyWarehouseScope` (tầng query, để phân trang đúng).
 *
 * 2. PROJECTION. `index()/show()` không chiếu `physical_return_status`,
 *    `condition_status`, `cancelled_at`, `cancel_reason` (ERD bảng 33 + ERD-AMD-002).
 *    FE REV-00/REV-01 đọc đúng bốn cột này: thiếu chúng thì bước CONFIRMED của §6 không
 *    bao giờ hiện, nút "Xác nhận hàng vật lý đã về" không render, và Reverse Issue luôn
 *    chết ở 409 PHYSICAL_RETURN_REQUIRED (BR-REV-06).
 *
 * Ghi chú fixture: MVP chỉ có ĐÚNG 01 kho tổng (REQ-SRS-001, guard
 * `INBOUND_WAREHOUSE_NOT_CANONICAL`), nên ca "ngoài phạm vi" được dựng bằng một
 * `user_role.scope_ref` trỏ tới warehouse id KHÁC — đúng cơ chế mà
 * `User::warehouseScopeIds()` đọc — chứ không dựng kho thứ hai.
 *
 * Chứng từ được dựng qua HTTP thật (như Wwhn207InboundReversalFlowTest) thay vì INSERT
 * thẳng, vì trên sqlite `reversal_documents` là VIEW chỉ đọc (số ít/số nhiều đảo chiều
 * theo driver — quyết định kiến trúc của 2026_08_10_000791).
 */
class Wwhn242ReversalScopeAndProjectionTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use RefreshDatabase;

    /** Warehouse id nằm ngoài mọi phạm vi hợp lệ — dùng làm scope_ref của actor "kho khác". */
    private const FOREIGN_WAREHOUSE_ID = '00000000-0000-4000-8000-000000000242';

    private function superAdminHeaders(): array
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'Super Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $user = User::create([
            'username' => 'wwhn242-admin-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-242 Admin',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    /**
     * Actor bị giới hạn đúng một kho qua `user_role.scope_ref`.
     *
     * @param  list<string>  $permissionKeys
     */
    private function scopedHeaders(string $warehouseId, array $permissionKeys): array
    {
        $roleCode = 'WWHN242_SCOPED_'.Str::upper(Str::random(6));
        $role = Role::create([
            'role_code' => $roleCode,
            'role_name' => $roleCode,
            'scope_type' => 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $permissionKey],
                ['permission_name' => $permissionKey, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
            );
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->role_id, 'permission_id' => $permission->permission_id],
                ['role_permission_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $user = User::create([
            'username' => 'wwhn242-scoped-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-242 Scoped Actor',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(),
            'scope_ref' => $warehouseId,
        ]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function canonicalWarehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE']
        );
    }

    /**
     * Dựng một reversal POSTED thật trong kho tổng và trả về id chứng từ liên quan.
     *
     * @return array{reversal_id: string, warehouse_id: string, document_id: string, sku_code: string}
     */
    private function postedReversal(array $adminHeaders, string $skuCode): array
    {
        $warehouse = $this->canonicalWarehouse();
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => 'WWHN-242 '.$skuCode,
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        // Contract GĐ1 scan-driven: header-only + component-lines + Box + đếm thực tế.
        $document = $this->createInboundHeader($adminHeaders, (string) $warehouse->id, [
            'source_name' => 'WWHN-242 scope fixture',
        ]);
        $documentId = $document['document_id'];

        $received = $this->receiveComponentQuantity(
            $adminHeaders,
            $documentId,
            (string) $sku->sku_id,
            4,
            $document['version']
        );

        $post = $this->postInboundReceipt($adminHeaders, $documentId, $received['version'], 'wwhn242-receipt-'.Str::random(12));
        $post->assertOk();
        $postedVersion = (int) ($post->json('data.version') ?? ($received['version'] + 1));

        $movementId = (string) DB::table('stock_movements')
            ->where('stock_document_id', $documentId)
            ->where('movement_type', 'RECEIPT')
            ->value('id');

        $reverse = $this->postJson("/api/v1/inbound-documents/{$documentId}/reverse", [
            'reason' => 'WWHN-242 fixture reversal',
            'lines' => [['movement_id' => $movementId, 'quantity' => 1]],
        ], $adminHeaders + [
            'If-Match' => (string) $postedVersion,
            'Idempotency-Key' => 'wwhn242-reverse-'.Str::random(12),
        ]);
        $reverse->assertOk();

        return [
            'reversal_id' => (string) $reverse->json('data.reversal_document_id'),
            'warehouse_id' => (string) $warehouse->id,
            'document_id' => $documentId,
            'sku_code' => $skuCode,
        ];
    }

    public function test_list_hides_reversal_documents_outside_warehouse_scope(): void
    {
        $admin = $this->superAdminHeaders();
        $fixture = $this->postedReversal($admin, 'WWHN242-SKU-LIST');

        $foreign = $this->scopedHeaders(self::FOREIGN_WAREHOUSE_ID, ['inventory.view']);
        $list = $this->getJson('/api/v1/reversal-documents?per_page=100', $foreign);
        $list->assertOk();

        $ids = collect($list->json('data'))->pluck('id')->map(fn ($v): string => (string) $v)->all();
        $this->assertNotContains($fixture['reversal_id'], $ids, 'Chứng từ ngoài scope không được lộ trong danh sách.');

        // Lọc phải nằm TRONG query, không phải lọc sau phản hồi: nếu lọc sau, meta.total
        // vẫn đếm cả chứng từ ngoài phạm vi và người dùng thấy "Tổng 1 phiếu" với bảng rỗng.
        $this->assertSame(0, (int) $list->json('meta.total'));
    }

    public function test_list_still_returns_documents_inside_scope(): void
    {
        $admin = $this->superAdminHeaders();
        $fixture = $this->postedReversal($admin, 'WWHN242-SKU-INSCOPE');

        // Ca đối chứng chống guard chặn nhầm.
        $inScope = $this->scopedHeaders($fixture['warehouse_id'], ['inventory.view']);
        $list = $this->getJson('/api/v1/reversal-documents?per_page=100', $inScope);
        $list->assertOk();

        $ids = collect($list->json('data'))->pluck('id')->map(fn ($v): string => (string) $v)->all();
        $this->assertContains($fixture['reversal_id'], $ids);
    }

    public function test_detail_and_lines_outside_scope_are_denied(): void
    {
        $admin = $this->superAdminHeaders();
        $fixture = $this->postedReversal($admin, 'WWHN242-SKU-DETAIL');

        $foreign = $this->scopedHeaders(self::FOREIGN_WAREHOUSE_ID, ['inventory.view']);

        $this->getJson('/api/v1/reversal-documents/'.$fixture['reversal_id'], $foreign)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'DATA_SCOPE_DENIED');

        $this->getJson('/api/v1/reversal-documents/'.$fixture['reversal_id'].'/lines', $foreign)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'DATA_SCOPE_DENIED');
    }

    public function test_lifecycle_write_outside_scope_is_denied_without_any_stock_effect(): void
    {
        $admin = $this->superAdminHeaders();
        $fixture = $this->postedReversal($admin, 'WWHN242-SKU-WRITE');

        // Actor CÓ đủ function permission nhưng scope trỏ kho khác. Đây chính là ca ghi
        // vượt biên: trước bản vá, request này đi thẳng vào service.
        $foreign = $this->scopedHeaders(self::FOREIGN_WAREHOUSE_ID, [
            'inventory.view', 'inventory.reversal.post',
        ]);

        $movementsBefore = DB::table('stock_movements')->count();

        foreach (['confirm', 'post', 'cancel'] as $step) {
            $this->postJson(
                "/api/v1/reversal-documents/{$fixture['reversal_id']}/{$step}",
                $step === 'cancel' ? ['reason' => 'thu huy ngoai scope'] : [],
                $foreign + ['If-Match' => '1', 'Idempotency-Key' => 'wwhn242-'.$step.'-'.Str::random(10)]
            )
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'DATA_SCOPE_DENIED');
        }

        $this->assertSame(
            $movementsBefore,
            DB::table('stock_movements')->count(),
            'Từ chối theo data scope phải là no-write: không movement nào được sinh thêm.'
        );
    }

    public function test_create_reversal_for_reference_document_outside_scope_is_denied(): void
    {
        $admin = $this->superAdminHeaders();
        $fixture = $this->postedReversal($admin, 'WWHN242-SKU-CREATE');

        $foreign = $this->scopedHeaders(self::FOREIGN_WAREHOUSE_ID, [
            'inventory.view', 'inventory.reversal.post',
        ]);
        $reversalCountBefore = DB::table('reversal_documents')->count();

        $this->postJson('/api/v1/reversal-documents', [
            'reference_document_id' => $fixture['document_id'],
            'reference_document_type' => 'INBOUND',
            'reason' => 'tao hoan dao ngoai scope',
            'lines' => [['sku' => $fixture['sku_code'], 'reversal_qty' => 1]],
        ], $foreign + ['If-Match' => '1', 'Idempotency-Key' => 'wwhn242-create-'.Str::random(10)])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'DATA_SCOPE_DENIED');

        $this->assertSame($reversalCountBefore, DB::table('reversal_documents')->count());
    }

    public function test_unrestricted_scope_still_reaches_every_reversal_document(): void
    {
        $admin = $this->superAdminHeaders();
        $fixture = $this->postedReversal($admin, 'WWHN242-SKU-ADMIN');

        // warehouseScopeIds() === null phải giữ nguyên hành vi cũ, không bị guard chặn nhầm.
        $list = $this->getJson('/api/v1/reversal-documents?per_page=100', $admin);
        $list->assertOk();
        $ids = collect($list->json('data'))->pluck('id')->map(fn ($v): string => (string) $v)->all();
        $this->assertContains($fixture['reversal_id'], $ids);

        $this->getJson('/api/v1/reversal-documents/'.$fixture['reversal_id'], $admin)->assertOk();
        $this->getJson('/api/v1/reversal-documents/'.$fixture['reversal_id'].'/lines', $admin)->assertOk();
    }

    public function test_read_projection_exposes_physical_return_and_cancel_columns(): void
    {
        $admin = $this->superAdminHeaders();
        $fixture = $this->postedReversal($admin, 'WWHN242-SKU-PROJ');

        // ERD bảng 33 + ERD-AMD-002: bốn cột này là hợp đồng đọc của REV-00/REV-01.
        // Thiếu chúng thì bước CONFIRMED của §6 không tồn tại trên UI (BR-REV-06).
        $this->getJson('/api/v1/reversal-documents/'.$fixture['reversal_id'], $admin)
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['physical_return_status', 'condition_status', 'cancelled_at', 'cancel_reason'],
            ]);

        $this->getJson('/api/v1/reversal-documents?per_page=100', $admin)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'physical_return_status', 'condition_status', 'cancelled_at', 'cancel_reason']],
            ]);
    }
}
