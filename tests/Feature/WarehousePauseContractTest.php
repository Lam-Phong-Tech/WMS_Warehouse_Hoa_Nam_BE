<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Màn Hệ thống → Cấu hình kho, bốn mệnh đề nghiệp vụ BA chốt 07/09/2026:
 *
 *  Q01. Hoa Nam vận hành ĐÚNG 01 kho tổng.
 *  Q02. Chỉ Super Admin được chuyển kho Đang hoạt động → Ngừng hoạt động.
 *  Q03. Kho tạm dừng: khoá toàn bộ nghiệp vụ GHI/THAY ĐỔI dữ liệu kho, nhưng
 *       View/Search/Report/Audit vẫn chạy.
 *  Q04. Chuyển ngược Ngừng hoạt động → Đang hoạt động cũng chỉ Super Admin.
 *       (Dialog xác nhận là nghĩa vụ của FE — xem WarehouseConfigScreen.test.tsx.)
 *
 * Vì sao gom vào một lớp: bốn mệnh đề này chỉ đúng khi đi CÙNG NHAU. Tách ra thì rất
 * dễ có tình trạng Q02 xanh (đổi được trạng thái) trong khi Q03 đỏ (kho tạm dừng vẫn
 * ghi được) — tức trạng thái "tạm dừng" tồn tại trên giấy mà không có tác dụng gì.
 */
class WarehousePauseContractTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private function auth(array $headers): array
    {
        unset($headers['__user_id']);

        return $headers;
    }

    private function pauseWarehouse(): Warehouse
    {
        $warehouse = $this->warehouseFixture();
        $warehouse->forceFill(['status' => 'INACTIVE'])->save();

        return $warehouse;
    }

    // ── Q01. Đúng 01 kho tổng ────────────────────────────────────────────────

    public function test_creating_a_second_warehouse_is_rejected(): void
    {
        $headers = $this->auth($this->superAdminHeaders());
        $this->warehouseFixture();

        $this->postJson('/api/v1/warehouses', [
            'code' => 'KHO-TONG-2',
            'name' => 'Kho tổng thứ hai',
        ], $headers)->assertStatus(409)->assertJsonPath('error_code', 'STATE_CONFLICT');

        $this->assertSame(1, Warehouse::query()->count(), 'Hoa Nam chỉ vận hành đúng 01 kho tổng.');
    }

    // ── Q02 / Q04. Đổi trạng thái chỉ Super Admin ────────────────────────────

    public function test_super_admin_can_pause_and_resume_the_only_warehouse(): void
    {
        $headers = $this->auth($this->superAdminHeaders());
        $warehouse = $this->warehouseFixture();

        $this->patchJson("/api/v1/warehouses/{$warehouse->id}", ['status' => 'INACTIVE'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'INACTIVE');

        // Q04: đường quay lại phải còn sống. Nếu PATCH này cũng bị đóng băng thì "tạm
        // dừng" là trạng thái một chiều, chỉ sửa được bằng tay dưới database.
        $this->patchJson("/api/v1/warehouses/{$warehouse->id}", ['status' => 'ACTIVE'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_admin_with_warehouse_manage_cannot_change_status(): void
    {
        $headers = $this->auth($this->authHeadersWithRole('ADMIN'));
        $warehouse = $this->warehouseFixture();

        $this->patchJson("/api/v1/warehouses/{$warehouse->id}", ['status' => 'INACTIVE'], $headers)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'ACCESS_DENIED');

        $this->assertSame(
            'ACTIVE',
            (string) $warehouse->fresh()->status,
            'ADMIN có warehouse.manage nhưng KHÔNG được bật/tắt kho — đó là công tắc đóng băng cả hệ thống.'
        );
    }

    public function test_admin_can_still_edit_warehouse_details(): void
    {
        $headers = $this->auth($this->authHeadersWithRole('ADMIN'));
        $warehouse = $this->warehouseFixture();

        // Gửi kèm `status` KHÔNG đổi — đúng như FE gửi nguyên form ở mọi lần lưu.
        // Chặn theo "có mặt field status" thay vì "status thực sự đổi" sẽ khoá nhầm
        // đường sửa tên kho của ADMIN.
        $this->patchJson("/api/v1/warehouses/{$warehouse->id}", [
            'name' => 'Kho tổng Hoa Nam (đã đổi tên)',
            'status' => 'ACTIVE',
        ], $headers)->assertOk()->assertJsonPath('data.name', 'Kho tổng Hoa Nam (đã đổi tên)');
    }

    // ── Q03. Kho tạm dừng: khoá GHI, mở ĐỌC ──────────────────────────────────

    public function test_paused_warehouse_blocks_business_writes(): void
    {
        $headers = $this->auth($this->superAdminHeaders());
        $this->pauseWarehouse();

        // Ba bề mặt ghi thuộc ba nhóm nghiệp vụ khác nhau; trước đợt này chỉ scan và
        // mini-app tự kiểm trạng thái kho nên hai cái đầu vẫn chạy khi kho tạm dừng.
        foreach ([
            ['post', '/api/v1/inbound-documents'],
            ['post', '/api/v1/putaway-tasks'],
            ['post', '/api/v1/skus'],
        ] as [$method, $uri]) {
            $this->json(strtoupper($method), $uri, [], $headers)
                ->assertStatus(409)
                ->assertJsonPath('error_code', 'WAREHOUSE_INACTIVE');
        }
    }

    public function test_paused_warehouse_still_allows_read_search_report_and_audit(): void
    {
        $headers = $this->auth($this->superAdminHeaders());
        $this->pauseWarehouse();

        foreach ([
            '/api/v1/warehouses',
            '/api/v1/skus?keyword=SP',
            '/api/v1/inventory/balances',
            '/api/v1/reports/hub',
            '/api/v1/audit-logs',
        ] as $uri) {
            $response = $this->getJson($uri, $headers);

            $this->assertNotSame(
                409,
                $response->getStatusCode(),
                "[{$uri}] là đường ĐỌC — kho tạm dừng không được chặn View/Search/Report/Audit."
            );
        }
    }

    public function test_paused_warehouse_still_allows_report_export(): void
    {
        $headers = $this->auth($this->superAdminHeaders());
        $this->pauseWarehouse();

        // POST nhưng thuộc vế "Report" của yêu cầu: chỉ tạo bản ghi job xuất, không
        // đụng dữ liệu kho. Đường đồng bộ (GET reports/*\/export-excel) vốn không bị
        // đóng băng vì là GET — chặn đường job nền sẽ khiến cùng một việc "xuất báo
        // cáo" lúc chạy lúc không tuỳ báo cáo.
        $response = $this->postJson('/api/v1/reports/export', [
            'report_code' => 'inventory-balance',
            'format' => 'xlsx',
        ], $headers + ['Idempotency-Key' => (string) Str::uuid()]);

        $this->assertNotSame(
            409,
            $response->getStatusCode(),
            'Xuất báo cáo phải sống khi kho tạm dừng — yêu cầu ghi rõ "vẫn cho phép Report".'
        );
    }

    public function test_paused_warehouse_still_allows_login_and_iam_writes(): void
    {
        $headers = $this->auth($this->superAdminHeaders());
        $this->pauseWarehouse();

        // Đóng băng luôn nhóm auth/IAM thì không ai đăng nhập vào để bật kho lại được.
        $login = $this->postJson('/api/v1/auth/login', [
            'username' => 'khong-ton-tai',
            'password' => 'sai-mat-khau',
        ]);
        $this->assertNotSame(409, $login->getStatusCode(), 'Đăng nhập phải sống khi kho tạm dừng.');

        $iam = $this->postJson('/api/v1/users', [], $headers);
        $this->assertNotSame(409, $iam->getStatusCode(), 'Quản trị tài khoản không phải "dữ liệu kho".');

        // Reverb khai bắt tay websocket ở HAI đường; chặn một cái là kênh thông báo
        // đứt suốt thời gian kho tạm dừng, mà thông báo là thứ ĐỌC.
        foreach (['/api/v1/broadcasting/auth', '/broadcasting/auth'] as $uri) {
            $this->assertNotSame(
                409,
                $this->postJson($uri, [], $headers)->getStatusCode(),
                "[{$uri}] là bắt tay realtime, không phải ghi dữ liệu kho."
            );
        }
    }

    public function test_resume_path_survives_the_write_freeze(): void
    {
        $headers = $this->auth($this->superAdminHeaders());
        $warehouse = $this->pauseWarehouse();

        // Chính là bài kiểm của Q04 ở tầng middleware: PATCH warehouses/{id} phải nằm
        // ngoài vùng đóng băng, nếu không kho tạm dừng sẽ không bao giờ mở lại được.
        $this->patchJson("/api/v1/warehouses/{$warehouse->id}", ['status' => 'ACTIVE'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_empty_warehouse_table_is_not_treated_as_paused(): void
    {
        $headers = $this->auth($this->superAdminHeaders());
        Warehouse::query()->delete();

        // Hệ thống MỚI DỰNG chưa có kho nào — đó không phải "tạm dừng". Coi bảng rỗng
        // là tạm dừng sẽ khoá luôn POST /warehouses (một route GHI), tức không có cách
        // nào tạo kho đầu tiên qua API: phải có kho ACTIVE mới tạo được kho.
        $this->postJson('/api/v1/warehouses', [
            'code' => 'KHO-TONG-HN',
            'name' => 'Kho tổng Hoa Nam',
        ], $headers)->assertStatus(201)->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_write_freeze_lifts_once_the_warehouse_is_active_again(): void
    {
        $headers = $this->auth($this->superAdminHeaders());
        $warehouse = $this->pauseWarehouse();

        $this->postJson('/api/v1/skus', [], $headers)->assertStatus(409);

        $warehouse->forceFill(['status' => 'ACTIVE'])->save();

        // Không còn 409 WAREHOUSE_INACTIVE; payload rỗng nên 422 là kết quả đúng.
        $this->postJson('/api/v1/skus', [], $headers)->assertStatus(422);
    }
}
