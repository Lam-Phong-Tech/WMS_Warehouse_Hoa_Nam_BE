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
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class MiniAppDashboardSummaryTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/mini-app/dashboard/summary';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-11 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_counts_waiting_approval_snapshot_and_documents_posted_on_the_requested_day(): void
    {
        $headers = $this->superAdminHeaders();
        $actorId = (string) $headers['__user_id'];
        $warehouseId = (string) $this->warehouseFixture()->id;
        $skuId = (string) $this->productSkuFixture('PROD-MINI-DASH-001')->sku_id;

        $this->document('receipt', 'SCANNING', $warehouseId, $actorId, $skuId, planned: 2, scanned: 2);
        $this->document('receipt', 'SCANNING', $warehouseId, $actorId, $skuId, planned: 2, scanned: 1);
        $this->document('receipt', 'SCANNING', $warehouseId, $actorId, null);
        $this->document('receipt', 'POSTED', $warehouseId, $actorId, $skuId, planned: 1, scanned: 1, postedAt: '2026-09-11 00:00:00');
        $this->document('receipt', 'POSTED', $warehouseId, $actorId, $skuId, planned: 1, scanned: 1, postedAt: '2026-09-11 23:59:59');
        $this->document('receipt', 'POSTED', $warehouseId, $actorId, $skuId, planned: 1, scanned: 1, postedAt: '2026-09-10 23:59:59');
        $this->document('issue', 'SCANNING', $warehouseId, $actorId, $skuId, planned: 1, scanned: 1);
        $this->document('issue', 'POSTED', $warehouseId, $actorId, $skuId, planned: 1, scanned: 1, postedAt: '2026-09-11 09:30:00');
        $this->document('issue', 'CANCELLED', $warehouseId, $actorId, $skuId, planned: 1, scanned: 0);

        $this->getJson(self::URL, $headers)
            ->assertOk()
            ->assertJsonPath('data.date', '2026-09-11')
            ->assertJsonPath('data.data_scope', 'GLOBAL')
            ->assertJsonPath('data.inbound.pending_approval_count', 1)
            ->assertJsonPath('data.inbound.posted_count', 2)
            ->assertJsonPath('data.outbound.pending_approval_count', 1)
            ->assertJsonPath('data.outbound.posted_count', 1)
            ->assertJsonPath('data.pending_approval_count', 2)
            ->assertJsonPath('data.posted_count', 3);

        // Badge "Chờ duyệt" phải bằng đúng số dòng của hàng chờ duyệt mà Mini App mở ra.
        $this->assertCount(1, $this->getJson('/api/v1/mini-app/inbound-documents?status=WAITING_APPROVAL', $headers)->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/mini-app/outbound-documents?ready_for_issue=1', $headers)->assertOk()->json('data'));

        $this->getJson(self::URL.'?date=2026-09-10', $headers)
            ->assertOk()
            ->assertJsonPath('data.inbound.posted_count', 1)
            ->assertJsonPath('data.outbound.posted_count', 0)
            ->assertJsonPath('data.pending_approval_count', 2);
    }

    public function test_section_the_actor_cannot_view_is_null_and_left_out_of_totals(): void
    {
        $warehouseId = (string) $this->warehouseFixture()->id;
        $skuId = (string) $this->productSkuFixture('PROD-MINI-DASH-002')->sku_id;
        $user = $this->userWithPermissions('mini-dash-inbound', ['inbound.view']);
        $this->document('receipt', 'POSTED', $warehouseId, $user->user_id, $skuId, planned: 1, scanned: 1, postedAt: '2026-09-11 08:00:00');
        $this->document('issue', 'POSTED', $warehouseId, $user->user_id, $skuId, planned: 1, scanned: 1, postedAt: '2026-09-11 08:00:00');

        $this->getJson(self::URL, $this->bearer($user))
            ->assertOk()
            ->assertJsonPath('data.inbound.posted_count', 1)
            ->assertJsonPath('data.outbound', null)
            ->assertJsonPath('data.posted_count', 1);
    }

    public function test_actor_without_inbound_or_outbound_view_is_forbidden(): void
    {
        $user = $this->userWithPermissions('mini-dash-none', ['sku.view']);

        $this->getJson(self::URL, $this->bearer($user))->assertForbidden();
    }

    public function test_warehouse_scoped_actor_counts_only_its_warehouse_and_cannot_ask_for_another(): void
    {
        $ownWarehouseId = (string) $this->warehouseFixture()->id;
        $otherWarehouseId = (string) Warehouse::create(['code' => 'KHO-KHAC-DASH', 'name' => 'Kho khác', 'status' => 'INACTIVE'])->id;
        $skuId = (string) $this->productSkuFixture('PROD-MINI-DASH-003')->sku_id;
        $user = $this->userWithPermissions('mini-dash-scoped', ['inbound.view', 'outbound.view'], $ownWarehouseId);
        $this->document('receipt', 'POSTED', $ownWarehouseId, $user->user_id, $skuId, planned: 1, scanned: 1, postedAt: '2026-09-11 08:00:00');
        $this->document('receipt', 'POSTED', $otherWarehouseId, $user->user_id, $skuId, planned: 1, scanned: 1, postedAt: '2026-09-11 08:00:00');
        $headers = $this->bearer($user);

        $this->getJson(self::URL, $headers)
            ->assertOk()
            ->assertJsonPath('data.data_scope', 'WAREHOUSE_SCOPED')
            ->assertJsonPath('data.inbound.posted_count', 1);

        $this->getJson(self::URL.'?warehouse_id='.$otherWarehouseId, $headers)
            ->assertForbidden()
            ->assertJsonPath('error_code', 'DATA_SCOPE_DENIED');
    }

    public function test_malformed_date_is_rejected(): void
    {
        $this->getJson(self::URL.'?date=11-09-2026', $this->superAdminHeaders())->assertUnprocessable();
    }

    private function document(
        string $docType,
        string $status,
        string $warehouseId,
        string $actorId,
        ?string $skuId,
        int $planned = 0,
        int $scanned = 0,
        ?string $postedAt = null,
    ): string {
        $id = (string) Str::uuid();
        DB::table('stock_documents')->insert([
            'id' => $id,
            'doc_type' => $docType,
            'doc_no' => 'DASH-'.strtoupper(Str::random(10)),
            'doc_date' => '2026-09-11',
            'status' => $status,
            // Required by the canonical outbound mirror in the isolated test DB.
            'recipient_name' => $docType === 'issue' ? 'Isolated dashboard recipient' : null,
            ($docType === 'receipt' ? 'dst_warehouse_id' : 'src_warehouse_id') => $warehouseId,
            'posted_by' => $postedAt === null ? null : $actorId,
            'posted_at' => $postedAt,
            'created_by' => $actorId,
            'created_at' => '2026-09-11 07:00:00',
            'updated_at' => '2026-09-11 07:00:00',
        ]);

        if ($skuId !== null) {
            DB::table('stock_document_lines')->insert([
                'id' => (string) Str::uuid(),
                'stock_document_id' => $id,
                'line_no' => 1,
                'product_id' => $skuId,
                'qty_planned' => $planned,
                'qty_actual' => $planned,
                'scanned_qty' => $scanned,
                'created_at' => '2026-09-11 07:00:00',
                'updated_at' => '2026-09-11 07:00:00',
            ]);
        }

        return $id;
    }

    private function userWithPermissions(string $username, array $permissions, ?string $warehouseScope = null): User
    {
        $role = Role::query()->create([
            'role_code' => strtoupper(str_replace('-', '_', $username)),
            'role_name' => $username,
            'scope_type' => $warehouseScope === null ? 'SYSTEM' : 'WAREHOUSE',
            'status' => 'ACTIVE',
        ]);
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
            );
            $role->permissions()->attach($permission->permission_id, [
                'role_permission_id' => (string) Str::uuid(),
            ]);
        }

        $user = User::query()->create([
            'username' => $username,
            'display_name' => $username,
            'email' => "{$username}@mini-dash.test",
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, [
            'user_role_id' => (string) Str::uuid(),
            'scope_ref' => $warehouseScope,
        ]);

        return $user;
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }
}
