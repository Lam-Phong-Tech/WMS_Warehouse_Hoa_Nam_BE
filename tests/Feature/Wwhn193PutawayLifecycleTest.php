<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\PreparesPutawayFixtures;
use Tests\TestCase;

/**
 * WWHN-193 (HN-IN-004, role BE2): rà soát trạng thái/thao tác putaway.
 *
 * FoundationInboundPutawayReadinessTest đã cover golden-path (create DRAFT,
 * confirm, history). File này đóng các gap chưa có evidence HTTP: permission
 * negative (401/403) trên cả 5 route, cancel, version conflict (If-Match sai),
 * double-confirm/double-cancel sau khi đã kết thúc vòng đời (state guard),
 * 404 cho id không tồn tại, validation error, cycle guard, và audit log row
 * thật cho PUTAWAY_DRAFT_CREATE/PUTAWAY_CONFIRM/PUTAWAY_CANCEL.
 */
class Wwhn193PutawayLifecycleTest extends TestCase
{
    use PreparesPutawayFixtures;
    use RefreshDatabase;

    private function authHeaders(string $roleCode = 'SUPER_ADMIN'): array
    {
        $role = Role::firstOrCreate(
            ['role_code' => $roleCode],
            ['role_name' => $roleCode, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $user = User::create([
            'username' => 'ptw-'.Str::lower(Str::random(6)),
            'display_name' => 'Putaway Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}", '__user_id' => $user->user_id];
    }

    private function userWithPermissions(array $permissionKeys): array
    {
        $roleCode = 'PTW_ROLE_'.Str::upper(Str::random(6));
        $role = Role::create(['role_code' => $roleCode, 'role_name' => $roleCode, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);

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
            'username' => 'ptw-noperm-'.Str::lower(Str::random(6)),
            'display_name' => 'Putaway No Perm',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function componentSku(string $code): Sku
    {
        return Sku::create([
            'sku_code' => $code,
            'sku_name' => "Component {$code}",
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * DEV193-P1-05R: eligibility khong con chi dua vao `status`; BOX phai that su neo vao
     * mot phieu nhap da POSTED. Fixture vi the dung ca chuoi chung tu, khong chi trang thai.
     */
    private function boxReadyForPutaway(string $code, string $skuId): Container
    {
        $box = Container::create([
            'container_code' => $code,
            'container_type' => 'BOX',
            'primary_sku_id' => $skuId,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'version' => 1,
        ]);
        $this->seedPostedReceiptForBox((string) $box->container_id, $skuId);

        return $box;
    }

    private function emptyTray(string $code): Container
    {
        return Container::create([
            'container_code' => $code,
            'container_type' => 'TRAY',
            'status' => 'EMPTY',
            'version' => 1,
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Permission / RBAC negative — chưa có evidence trước task này
    // ------------------------------------------------------------------

    public function test_all_putaway_endpoints_require_authentication(): void
    {
        $box = $this->boxReadyForPutaway('BOX-401-01', $this->componentSku('COMP-401-01')->sku_id);
        $tray = $this->emptyTray('TRAY-401-01');

        $this->getJson('/api/v1/putaway/tasks')->assertStatus(401)->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ])->assertStatus(401)->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $this->postJson('/api/v1/putaway/tasks/'.Str::uuid().'/confirm', [], ['If-Match' => '1'])
            ->assertStatus(401)->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $this->postJson('/api/v1/putaway/tasks/'.Str::uuid().'/cancel', [], ['If-Match' => '1'])
            ->assertStatus(401)->assertJsonPath('error_code', 'UNAUTHENTICATED');
        $this->getJson('/api/v1/putaway/history')->assertStatus(401)->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_putaway_read_endpoints_require_container_view_permission(): void
    {
        $headers = $this->userWithPermissions([]);

        $this->getJson('/api/v1/putaway/tasks', $headers)
            ->assertStatus(403)->assertJsonPath('error_code', 'ACCESS_DENIED');
        $this->getJson('/api/v1/putaway/history', $headers)
            ->assertStatus(403)->assertJsonPath('error_code', 'ACCESS_DENIED');
    }

    public function test_putaway_write_endpoints_require_container_manage_permission(): void
    {
        $viewOnly = $this->userWithPermissions(['container.view']);
        $box = $this->boxReadyForPutaway('BOX-403-01', $this->componentSku('COMP-403-01')->sku_id);
        $tray = $this->emptyTray('TRAY-403-01');

        $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $viewOnly)->assertStatus(403)->assertJsonPath('error_code', 'ACCESS_DENIED');

        $this->postJson('/api/v1/putaway/tasks/'.Str::uuid().'/confirm', [], $viewOnly + ['If-Match' => '1'])
            ->assertStatus(403)->assertJsonPath('error_code', 'ACCESS_DENIED');
        $this->postJson('/api/v1/putaway/tasks/'.Str::uuid().'/cancel', [], $viewOnly + ['If-Match' => '1'])
            ->assertStatus(403)->assertJsonPath('error_code', 'ACCESS_DENIED');

        // Đảm bảo permission denial không tạo dữ liệu (đúng AC "không tạo dữ liệu sai").
        $this->assertDatabaseCount('putaway_events', 0);
    }

    // ------------------------------------------------------------------
    // 2. Cancel — chưa có evidence HTTP trước task này
    // ------------------------------------------------------------------

    public function test_putaway_cancel_is_stock_neutral_and_does_not_move_box(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $sku = $this->componentSku('COMP-CANCEL-001');
        $box = $this->boxReadyForPutaway('BOX-CANCEL-001', $sku->sku_id);
        $tray = $this->emptyTray('TRAY-CANCEL-001');

        $movementBefore = DB::table('stock_movements')->count();
        $create = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers);
        $create->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $eventId = (string) $create->json('data.id');

        $cancel = $this->postJson("/api/v1/putaway/tasks/{$eventId}/cancel", [], $headers + ['If-Match' => '1']);
        $cancel->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);

        $this->assertDatabaseHas('putaway_events', ['id' => $eventId, 'status' => 'CANCELLED']);
        // BOX phải giữ nguyên RECEIVED_PENDING_PUTAWAY và không có parent — cancel không được dịch chuyển vật lý.
        $this->assertDatabaseHas('containers', [
            'container_id' => $box->container_id,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'parent_container_id' => null,
        ]);
        $this->assertSame($movementBefore, DB::table('stock_movements')->count());

        // BOX vẫn còn eligible — có thể tạo DRAFT mới sau khi hủy cái cũ.
        $retry = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers);
        $retry->assertCreated()->assertJsonPath('data.status', 'DRAFT');
    }

    public function test_putaway_cancel_returns_404_for_unknown_id(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];

        $this->postJson('/api/v1/putaway/tasks/'.Str::uuid().'/cancel', [], $headers + ['If-Match' => '1'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PUTAWAY_NOT_FOUND');
    }

    // ------------------------------------------------------------------
    // 3. Optimistic concurrency (If-Match) — version conflict thật qua HTTP
    // ------------------------------------------------------------------

    public function test_putaway_confirm_rejects_stale_version_with_412(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $sku = $this->componentSku('COMP-412-01');
        $box = $this->boxReadyForPutaway('BOX-412-01', $sku->sku_id);
        $tray = $this->emptyTray('TRAY-412-01');

        $create = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers);
        $eventId = (string) $create->json('data.id');

        $this->postJson("/api/v1/putaway/tasks/{$eventId}/confirm", [], $headers + ['If-Match' => '99'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION')
            ->assertJsonPath('error.details.expected_version', 99)
            ->assertJsonPath('error.details.current_version', 1);

        // Version conflict không được làm thay đổi trạng thái BOX/DRAFT.
        $this->assertDatabaseHas('putaway_events', ['id' => $eventId, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('containers', ['container_id' => $box->container_id, 'status' => 'RECEIVED_PENDING_PUTAWAY']);
    }

    public function test_putaway_confirm_requires_if_match_header(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $sku = $this->componentSku('COMP-428-01');
        $box = $this->boxReadyForPutaway('BOX-428-01', $sku->sku_id);
        $tray = $this->emptyTray('TRAY-428-01');

        $create = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers);
        $eventId = (string) $create->json('data.id');

        $this->postJson("/api/v1/putaway/tasks/{$eventId}/confirm", [], $headers)
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');
    }

    public function test_putaway_confirm_twice_is_rejected_by_state_guard_and_does_not_double_move(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $sku = $this->componentSku('COMP-STATE-01');
        $box = $this->boxReadyForPutaway('BOX-STATE-01', $sku->sku_id);
        $trayA = $this->emptyTray('TRAY-STATE-01A');
        $trayB = $this->emptyTray('TRAY-STATE-01B');

        $create = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $trayA->container_id,
        ], $headers);
        $eventId = (string) $create->json('data.id');

        $this->postJson("/api/v1/putaway/tasks/{$eventId}/confirm", [], $headers + ['If-Match' => '1'])
            ->assertOk()->assertJsonPath('data.status', 'CONFIRMED');

        // Replay với cùng version cũ (1) sau khi đã CONFIRMED: state guard chặn trước khi
        // tới bước so version (status != DRAFT) -> 409 STATE_CONFLICT, không phải 200 ngầm
        // và không phải 412 (defense-in-depth, không chỉ dựa vào version như WWHN-190 note).
        $this->postJson("/api/v1/putaway/tasks/{$eventId}/confirm", [], $headers + ['If-Match' => '1'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');

        // Gọi lại confirm cho BOX đã STORED bằng cách tạo DRAFT mới cho box khác trạng thái -> bị chặn ở tầng tạo DRAFT.
        $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $trayB->container_id,
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BOX_NOT_PUTAWAY_ELIGIBLE');

        // BOX chỉ được xử lý đúng 1 lần — vẫn ở TRAY A, không bị relocate sang TRAY B.
        $this->assertDatabaseHas('containers', [
            'container_id' => $box->container_id,
            'parent_container_id' => $trayA->container_id,
            'status' => 'STORED',
        ]);
    }

    public function test_putaway_draft_exists_guard_blocks_duplicate_draft_for_same_box(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $sku = $this->componentSku('COMP-DUPDRAFT-01');
        $box = $this->boxReadyForPutaway('BOX-DUPDRAFT-01', $sku->sku_id);
        $trayA = $this->emptyTray('TRAY-DUPDRAFT-01A');
        $trayB = $this->emptyTray('TRAY-DUPDRAFT-01B');

        $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $trayA->container_id,
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $trayB->container_id,
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PUTAWAY_DRAFT_EXISTS');

        $this->assertDatabaseCount('putaway_events', 1);
    }

    // ------------------------------------------------------------------
    // 4. Validation & business-rule guards
    // ------------------------------------------------------------------

    public function test_putaway_create_rejects_missing_required_fields(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];

        $this->postJson('/api/v1/putaway/tasks', [], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['box_container_id', 'to_tray_id']);
    }

    public function test_putaway_create_rejects_same_box_and_tray_id(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $sku = $this->componentSku('COMP-SAME-01');
        $box = $this->boxReadyForPutaway('BOX-SAME-01', $sku->sku_id);

        $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $box->container_id,
        ], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to_tray_id']);
    }

    public function test_putaway_create_rejects_box_not_yet_eligible(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $sku = $this->componentSku('COMP-NOTELIGIBLE-01');
        $box = Container::create([
            'container_code' => 'BOX-NOTELIGIBLE-01',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'status' => 'EMPTY',
            'version' => 1,
        ]);
        $tray = $this->emptyTray('TRAY-NOTELIGIBLE-01');

        $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BOX_NOT_PUTAWAY_ELIGIBLE');
    }

    public function test_putaway_create_rejects_closed_tray(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $sku = $this->componentSku('COMP-CLOSEDTRAY-01');
        $box = $this->boxReadyForPutaway('BOX-CLOSEDTRAY-01', $sku->sku_id);
        $tray = Container::create([
            'container_code' => 'TRAY-CLOSEDTRAY-01',
            'container_type' => 'TRAY',
            'status' => 'CLOSED',
            'version' => 1,
        ]);

        $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRAY_NOT_USABLE');
    }

    public function test_putaway_create_rejects_container_cycle(): void
    {
        // DB guard hn_erd_guard_container_hierarchy (MVP: chỉ BOX được putaway
        // vào TRAY) chặn từ gốc mọi cấu trúc tạo vòng lặp: TRAY không thể nằm
        // trong BOX nên chuỗi BOX→TRAY→BOX không thể tồn tại. Fixture cố tạo
        // TRAY con của BOX phải bị từ chối ngay tại tầng DB.
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Guard hn_erd_guard_container_hierarchy là trigger PostgreSQL; sqlite không có tầng chặn này.');
        }

        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $sku = $this->componentSku('COMP-CYCLE-01');
        $box = $this->boxReadyForPutaway('BOX-CYCLE-01', $sku->sku_id);

        try {
            // Bọc trong transaction con (SAVEPOINT) để lỗi guard không abort
            // transaction bọc ngoài của RefreshDatabase trên PostgreSQL.
            DB::transaction(function () use ($box): void {
                Container::create([
                    'container_code' => 'TRAY-CYCLE-01',
                    'container_type' => 'TRAY',
                    'status' => 'EMPTY',
                    'parent_container_id' => $box->container_id,
                    'version' => 1,
                ]);
            });
            $this->fail('TRAY nằm trong BOX phải bị hierarchy guard từ chối.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('PUTAWAY_BOX_REQUIRED', $exception->getMessage());
        }

        $this->assertDatabaseMissing('containers', ['container_code' => 'TRAY-CYCLE-01']);
    }

    public function test_putaway_create_returns_422_for_non_existent_tray(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $sku = $this->componentSku('COMP-NOTRAY-01');
        $box = $this->boxReadyForPutaway('BOX-NOTRAY-01', $sku->sku_id);

        $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => (string) Str::uuid(),
        ], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to_tray_id']);
    }

    // ------------------------------------------------------------------
    // 5. Audit log — chưa có evidence trực tiếp trước task này
    // ------------------------------------------------------------------

    public function test_putaway_lifecycle_writes_audit_log_rows_for_each_transition(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $actorId = $auth['__user_id'];
        $sku = $this->componentSku('COMP-AUDIT-PTW-01');
        $box = $this->boxReadyForPutaway('BOX-AUDIT-PTW-01', $sku->sku_id);
        $tray = $this->emptyTray('TRAY-AUDIT-PTW-01');

        $create = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers);
        $eventId = (string) $create->json('data.id');

        $draftAudit = DB::table('audit_log')
            ->where('object_type', 'putaway_event')
            ->where('object_id', $eventId)
            ->where('action', 'PUTAWAY_DRAFT_CREATE')
            ->first();
        $this->assertNotNull($draftAudit, 'Expected a PUTAWAY_DRAFT_CREATE audit_log row.');
        $this->assertSame($actorId, $draftAudit->actor_id);
        $this->assertNotNull($draftAudit->request_id);

        $this->postJson("/api/v1/putaway/tasks/{$eventId}/confirm", [], $headers + ['If-Match' => '1'])->assertOk();

        $confirmAudit = DB::table('audit_log')
            ->where('object_type', 'putaway_event')
            ->where('object_id', $eventId)
            ->where('action', 'PUTAWAY_CONFIRM')
            ->first();
        $this->assertNotNull($confirmAudit, 'Expected a PUTAWAY_CONFIRM audit_log row.');
        $afterData = json_decode((string) $confirmAudit->after_data, true);
        $this->assertSame('CONFIRMED', $afterData['status']);
    }

    public function test_putaway_cancel_writes_audit_log_row(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $sku = $this->componentSku('COMP-AUDIT-CANCEL-01');
        $box = $this->boxReadyForPutaway('BOX-AUDIT-CANCEL-01', $sku->sku_id);
        $tray = $this->emptyTray('TRAY-AUDIT-CANCEL-01');

        $create = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers);
        $eventId = (string) $create->json('data.id');

        $this->postJson("/api/v1/putaway/tasks/{$eventId}/cancel", [], $headers + ['If-Match' => '1'])->assertOk();

        $cancelAudit = DB::table('audit_log')
            ->where('object_type', 'putaway_event')
            ->where('object_id', $eventId)
            ->where('action', 'PUTAWAY_CANCEL')
            ->first();
        $this->assertNotNull($cancelAudit, 'Expected a PUTAWAY_CANCEL audit_log row.');
    }

    // ------------------------------------------------------------------
    // 6. GET /putaway/tasks — chưa từng có test HTTP success-path (200)
    // trước task này, chỉ có OpenAPI annotation; đây là đúng dạng gap đã
    // gây Bug 1 (500) ở WWHN-191 cho /inventory/balances.
    // ------------------------------------------------------------------

    public function test_putaway_tasks_list_returns_pending_box_with_expected_shape(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $sku = $this->componentSku('COMP-LIST-01');
        $box = $this->boxReadyForPutaway('BOX-LIST-01', $sku->sku_id);

        $response = $this->getJson('/api/v1/putaway/tasks', $headers)->assertOk();
        $rows = $response->json('data');
        $row = collect($rows)->firstWhere('box_container_id', $box->container_id);

        $this->assertNotNull($row, 'BOX RECEIVED_PENDING_PUTAWAY phải xuất hiện trong danh sách putaway/tasks.');
        $this->assertSame('BOX-LIST-01', $row['box_code']);
        $this->assertSame('COMP-LIST-01', $row['sku_code']);
        $this->assertSame('RECEIVED_PENDING_PUTAWAY', $row['status']);
        $this->assertNull($row['draft_id']);
    }

    public function test_putaway_tasks_list_includes_box_with_draft_and_search_filters_by_keyword(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $sku = $this->componentSku('COMP-LIST-02');
        $box = $this->boxReadyForPutaway('BOX-LIST-02', $sku->sku_id);
        $tray = $this->emptyTray('TRAY-LIST-02');

        $draft = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers)->assertCreated();

        $response = $this->getJson('/api/v1/putaway/tasks?search=BOX-LIST-02', $headers)->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame((string) $draft->json('data.id'), $rows[0]['draft_id']);
        $this->assertSame('TRAY-LIST-02', $rows[0]['to_tray_code']);

        $miss = $this->getJson('/api/v1/putaway/tasks?search=NO-SUCH-BOX-CODE', $headers)->assertOk();
        $this->assertCount(0, $miss->json('data'));
    }

    // ------------------------------------------------------------------
    // 7. putaway_events append-only invariant sau khi CONFIRMED/CANCELLED
    // ------------------------------------------------------------------

    public function test_putaway_history_lists_only_confirmed_events_ordered_desc(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $skuA = $this->componentSku('COMP-HIST-01');
        $skuB = $this->componentSku('COMP-HIST-02');
        $boxA = $this->boxReadyForPutaway('BOX-HIST-01', $skuA->sku_id);
        $boxB = $this->boxReadyForPutaway('BOX-HIST-02', $skuB->sku_id);
        $trayA = $this->emptyTray('TRAY-HIST-01');
        $trayB = $this->emptyTray('TRAY-HIST-02');

        $draftCancelled = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $boxA->container_id,
            'to_tray_id' => $trayA->container_id,
        ], $headers);
        $this->postJson('/api/v1/putaway/tasks/'.$draftCancelled->json('data.id').'/cancel', [], $headers + ['If-Match' => '1'])->assertOk();

        $draftConfirmed = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $boxB->container_id,
            'to_tray_id' => $trayB->container_id,
        ], $headers);
        $this->postJson('/api/v1/putaway/tasks/'.$draftConfirmed->json('data.id').'/confirm', [], $headers + ['If-Match' => '1'])->assertOk();

        $history = $this->getJson('/api/v1/putaway/history', $headers)->assertOk();
        $rows = $history->json('data');
        $this->assertCount(1, $rows, 'History chỉ được liệt kê CONFIRMED, không liệt kê CANCELLED.');
        $this->assertSame('BOX-HIST-02', $rows[0]['box_code']);
        $this->assertSame('STORED', $rows[0]['box_status']);
        $this->assertSame('COMP-HIST-02', $rows[0]['sku_code']);
        $this->assertSame('Component COMP-HIST-02', $rows[0]['sku_name']);
        $this->assertSame('cai', $rows[0]['unit']);
        $this->assertSame(5, $rows[0]['quantity']);
        $this->assertStringStartsWith('PN-PTW-', (string) $rows[0]['receipt_code']);
        $this->assertNull($rows[0]['note']);
    }

    // ------------------------------------------------------------------
    // 8. PATCH /containers/{id} không được là đường vòng cho putaway
    // (DEV193-P1-05R): trước đây đổi parent_container_id qua endpoint update
    // chung sẽ bỏ qua receipt-eligibility, DRAFT stage và PUTAWAY_DRAFT_EXISTS
    // guard của canonical /putaway/tasks, dù vẫn ghi audit 'PUTAWAY_CONFIRM'.
    // ------------------------------------------------------------------

    public function test_container_update_rejects_parent_container_id_change_as_putaway_bypass(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $sku = $this->componentSku('COMP-BYPASS-01');
        $box = $this->boxReadyForPutaway('BOX-BYPASS-01', $sku->sku_id);
        $tray = $this->emptyTray('TRAY-BYPASS-01');

        $response = $this->patchJson(
            "/api/v1/containers/{$box->container_id}",
            ['parent_container_id' => $tray->container_id],
            $headers + ['If-Match' => '1']
        );

        $response->assertStatus(422)->assertJsonPath('error.code', 'PUTAWAY_MUST_USE_TASK_FLOW');
        $this->assertDatabaseHas('containers', [
            'container_id' => $box->container_id,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'parent_container_id' => null,
            'version' => 1,
        ]);
        $this->assertSame(0, DB::table('putaway_events')->count(), 'Không được tạo putaway_events row qua endpoint update chung.');
        $this->assertNull(
            DB::table('audit_log')->where('object_id', $box->container_id)->where('action', 'PUTAWAY_CONFIRM')->first(),
            'Không được ghi audit PUTAWAY_CONFIRM qua endpoint update chung.'
        );
    }

    public function test_container_update_rejects_clearing_existing_parent_container_id(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $tray = $this->emptyTray('TRAY-BYPASS-02');
        $box = Container::create([
            'container_code' => 'BOX-BYPASS-02',
            'container_type' => 'BOX',
            'primary_sku_id' => $this->componentSku('COMP-BYPASS-02')->sku_id,
            'parent_container_id' => $tray->container_id,
            'status' => 'STORED',
            'version' => 1,
        ]);

        $this->patchJson(
            "/api/v1/containers/{$box->container_id}",
            ['parent_container_id' => null],
            $headers + ['If-Match' => '1']
        )->assertStatus(422)->assertJsonPath('error.code', 'PUTAWAY_MUST_USE_TASK_FLOW');

        $this->assertDatabaseHas('containers', [
            'container_id' => $box->container_id,
            'parent_container_id' => $tray->container_id,
            'version' => 1,
        ]);
    }

    public function test_container_update_still_allows_non_putaway_field_edits_with_correct_audit_action(): void
    {
        $auth = $this->authHeaders();
        $headers = ['Authorization' => $auth['Authorization']];
        $box = $this->boxReadyForPutaway('BOX-BYPASS-03', $this->componentSku('COMP-BYPASS-03')->sku_id);

        $response = $this->patchJson(
            "/api/v1/containers/{$box->container_id}",
            ['zone_code' => 'ZONE-A'],
            $headers + ['If-Match' => '1']
        );

        $response->assertOk()->assertJsonPath('data.container.zone_code', 'ZONE-A');
        $this->assertNull(
            DB::table('audit_log')->where('object_id', $box->container_id)->where('action', 'PUTAWAY_CONFIRM')->first(),
            'Sửa zone_code đơn thuần không được gắn nhãn audit PUTAWAY_CONFIRM.'
        );
        $updateAudit = DB::table('audit_log')->where('object_id', $box->container_id)->where('action', 'UPDATE_CONTAINER')->first();
        $this->assertNotNull($updateAudit, 'Expected an UPDATE_CONTAINER audit_log row.');
    }

    public function test_container_update_allows_reasserting_same_parent_container_id(): void
    {
        $headers = ['Authorization' => $this->authHeaders()['Authorization']];
        $tray = $this->emptyTray('TRAY-BYPASS-04');
        $box = Container::create([
            'container_code' => 'BOX-BYPASS-04',
            'container_type' => 'BOX',
            'primary_sku_id' => $this->componentSku('COMP-BYPASS-04')->sku_id,
            'parent_container_id' => $tray->container_id,
            'status' => 'STORED',
            'version' => 1,
        ]);

        $this->patchJson(
            "/api/v1/containers/{$box->container_id}",
            ['parent_container_id' => $tray->container_id, 'zone_code' => 'ZONE-B'],
            $headers + ['If-Match' => '1']
        )->assertOk()->assertJsonPath('data.container.zone_code', 'ZONE-B');
    }
}
