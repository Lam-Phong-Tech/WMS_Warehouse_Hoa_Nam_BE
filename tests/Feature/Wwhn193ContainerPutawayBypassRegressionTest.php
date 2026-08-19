<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\PreparesPutawayFixtures;
use Tests\TestCase;

/**
 * WWHN-193 · DEV193-P1-05R — regression cho đường vòng putaway qua generic container API.
 *
 * Phát hiện của reviewer: PATCH /containers/{id} đã chặn đổi `parent_container_id`, nhưng
 * POST /containers vẫn nhận `parent_container_id` rồi INSERT thẳng một `putaway_events`
 * status = CONFIRMED, và nhận cả `status` vòng đời. Đường đó bỏ qua TOÀN BỘ guard của
 * luồng canonical: eligibility BOX (RECEIVED_PENDING_PUTAWAY), linkage phiếu nhập POSTED,
 * PUTAWAY_DRAFT_EXISTS, If-Match/version, và bước DRAFT -> CONFIRMED của SRS §6.
 *
 * Thêm nữa, `POST /putaway-tasks` chỉ đọc Posted Receipt như metadata tuỳ chọn
 * (`$receipt?->id`), nên eligibility thực chất chỉ dựa vào `containers.status` — mà chính
 * status đó lại đặt được qua hai endpoint CRUD trên. File này khoá cả hai nửa.
 */
class Wwhn193ContainerPutawayBypassRegressionTest extends TestCase
{
    use PreparesPutawayFixtures;
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'SUPER_ADMIN', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $user = User::create([
            'username' => 'ptw-bypass-'.Str::lower(Str::random(6)),
            'display_name' => 'Putaway Bypass Tester',
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

    private function tray(string $code): Container
    {
        return Container::create([
            'container_code' => $code,
            'container_type' => 'TRAY',
            'status' => 'EMPTY',
            'version' => 1,
        ]);
    }

    // ── 1. POST /containers không được tạo placement ────────────────────────

    public function test_container_create_rejects_parent_container_id_and_writes_nothing(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->componentSku('COMP-BYPASS-01');
        $tray = $this->tray('TRAY-BYPASS-01');

        $response = $this->postJson('/api/v1/containers', [
            'container_code' => 'BOX-BYPASS-01',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'parent_container_id' => $tray->container_id,
        ], $headers);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'PUTAWAY_MUST_USE_TASK_FLOW')
            ->assertJsonPath('error.details.surface', 'POST /containers');

        // Không container, không putaway_event, không movement.
        $this->assertDatabaseMissing('containers', ['container_code' => 'BOX-BYPASS-01']);
        $this->assertSame(0, DB::table('putaway_events')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_container_create_rejects_lifecycle_status(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->componentSku('COMP-BYPASS-02');

        foreach (['STORED', 'RECEIVED_PENDING_PUTAWAY', 'ISSUED'] as $index => $status) {
            $response = $this->postJson('/api/v1/containers', [
                'container_code' => 'BOX-BYPASS-STATUS-'.$index,
                'container_type' => 'BOX',
                'primary_sku_id' => $sku->sku_id,
                'status' => $status,
            ], $headers);

            $response->assertStatus(422)
                ->assertJsonPath('error.code', 'CONTAINER_STATUS_NOT_CREATABLE')
                ->assertJsonPath('error.details.requested_status', $status);
            $this->assertDatabaseMissing('containers', ['container_code' => 'BOX-BYPASS-STATUS-'.$index]);
        }

        // Đường hợp lệ vẫn mở: tạo container rỗng.
        $this->postJson('/api/v1/containers', [
            'container_code' => 'BOX-BYPASS-OK',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'status' => 'EMPTY',
        ], $headers)->assertCreated()->assertJsonPath('data.status', 'EMPTY');
    }

    // ── 2. PATCH /containers không được đặt trạng thái vòng đời ─────────────

    public function test_container_patch_rejects_lifecycle_status_and_leaves_state_unchanged(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->componentSku('COMP-BYPASS-03');

        $created = $this->postJson('/api/v1/containers', [
            'container_code' => 'BOX-BYPASS-PATCH',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
        ], $headers)->assertCreated();
        $containerId = (string) $created->json('data.container_id');

        $this->patchJson("/api/v1/containers/{$containerId}", [
            'status' => 'STORED',
        ], $headers + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CONTAINER_STATUS_LIFECYCLE_MANAGED')
            ->assertJsonPath('error.details.requested_status', 'STORED');

        $this->assertDatabaseHas('containers', [
            'container_id' => $containerId,
            'status' => 'EMPTY',
            'version' => 1,
        ]);
        $this->assertSame(0, DB::table('putaway_events')->count());
    }

    public function test_container_patch_still_rejects_parent_container_change(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->componentSku('COMP-BYPASS-04');
        $tray = $this->tray('TRAY-BYPASS-04');

        $created = $this->postJson('/api/v1/containers', [
            'container_code' => 'BOX-BYPASS-PARENT',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
        ], $headers)->assertCreated();
        $containerId = (string) $created->json('data.container_id');

        $this->patchJson("/api/v1/containers/{$containerId}", [
            'parent_container_id' => $tray->container_id,
        ], $headers + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PUTAWAY_MUST_USE_TASK_FLOW');

        $this->assertDatabaseHas('containers', [
            'container_id' => $containerId,
            'parent_container_id' => null,
        ]);
        $this->assertSame(0, DB::table('putaway_events')->count());
    }

    // ── 3. Canonical store đòi linkage phiếu nhập POSTED ────────────────────

    public function test_putaway_task_create_requires_posted_receipt_even_when_status_looks_eligible(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->componentSku('COMP-BYPASS-05');
        $tray = $this->tray('TRAY-BYPASS-05');

        // BOX được đặt trạng thái "trông đủ điều kiện" bằng đường ngoài API (giả lập dữ liệu
        // cũ hoặc một đường ghi khác). Guard mới không tin trạng thái, nó tra phiếu.
        $box = Container::create([
            'container_code' => 'BOX-BYPASS-NORECEIPT',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'version' => 1,
        ]);

        $this->postJson('/api/v1/putaway-tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BOX_NOT_PUTAWAY_ELIGIBLE')
            ->assertJsonPath('error.details.reason', 'NO_POSTED_RECEIPT');

        $this->assertSame(0, DB::table('putaway_events')->count());

        // Gắn phiếu nhập POSTED thật -> mở khoá, và DRAFT phải mang stock_document_id.
        $receipt = $this->seedPostedReceiptForBox((string) $box->container_id, (string) $sku->sku_id);
        $draft = $this->postJson('/api/v1/putaway-tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers)->assertCreated();

        $this->assertDatabaseHas('putaway_events', [
            'id' => (string) $draft->json('data.id'),
            'status' => 'DRAFT',
            'stock_document_id' => $receipt['document_id'],
        ]);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /**
     * DRAFT tồn dư: sinh ra trước khi linkage trở thành bắt buộc (`stock_document_id` NULL)
     * và BOX không có phiếu POSTED nào. Confirm phải chặn ở tầng dữ liệu chứ không tin
     * trạng thái DRAFT có sẵn — nếu không, mọi DRAFT cũ vẫn là một cửa hậu.
     */
    public function test_putaway_confirm_rejects_legacy_draft_without_posted_receipt_linkage(): void
    {
        $headers = $this->authHeaders();
        $sku = $this->componentSku('COMP-BYPASS-06');
        $tray = $this->tray('TRAY-BYPASS-06');
        $box = Container::create([
            'container_code' => 'BOX-BYPASS-LEGACYDRAFT',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'version' => 1,
        ]);
        $receipt = $this->seedPostedReceiptForBox((string) $box->container_id, (string) $sku->sku_id);

        $draft = $this->postJson('/api/v1/putaway-tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
        ], $headers)->assertCreated();
        $eventId = (string) $draft->json('data.id');

        // Hạ DRAFT về hình dạng "tồn dư": mất linkage chứng từ, và BOX không còn dòng quét
        // nào trỏ tới phiếu POSTED. Không đụng vào `stock_documents` vì PostgreSQL có
        // trigger hn_reject_posted_document_change() giữ phiếu POSTED bất biến.
        DB::table('putaway_events')->where('id', $eventId)->update(['stock_document_id' => null]);
        DB::table('inbound_scan_entries')->where('id', $receipt['scan_entry_id'])->delete();

        $this->postJson("/api/v1/putaway-tasks/{$eventId}/confirm", [], $headers + ['If-Match' => '1'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BOX_NOT_PUTAWAY_ELIGIBLE')
            ->assertJsonPath('error.details.reason', 'NO_POSTED_RECEIPT');

        $this->assertDatabaseHas('putaway_events', ['id' => $eventId, 'status' => 'DRAFT', 'version' => 1]);
        $this->assertDatabaseHas('containers', [
            'container_id' => $box->container_id,
            'parent_container_id' => null,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
        ]);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }
}
