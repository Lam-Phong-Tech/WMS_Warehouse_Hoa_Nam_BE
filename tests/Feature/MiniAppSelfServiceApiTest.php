<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Notification\Services\NotificationService;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * API tự phục vụ cho Mini App (10/09/2026): sửa hồ sơ, thiết bị đang đăng nhập,
 * chi tiết thông báo, lịch sử chip NFC và bộ lọc lịch sử quét của chính mình.
 *
 * Bất biến chung khoá ở đây: KHÔNG endpoint nào nhận user_id từ client để đọc dữ
 * liệu người khác. Dữ liệu của người khác trả 404 — không lộ việc nó có tồn tại.
 *
 * Lưu ý khi viết thêm test ở file này: feature test dùng CHUNG một app cho mọi
 * request, và guard JWT giữ token của lần đăng nhập gần nhất. Mỗi khi đổi người
 * gọi, hãy đăng nhập người đó NGAY TRƯỚC request của họ.
 */
class MiniAppSelfServiceApiTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // PATCH /auth/me
    // ------------------------------------------------------------------

    public function test_sua_ho_so_doi_ten_va_so_dien_thoai_va_tang_version(): void
    {
        $user = $this->selfServiceUser('profile_edit');

        $this->patchJson('/api/v1/auth/me', [
            'display_name' => '  Nguyễn Văn Kho  ',
            'phone' => '0912345678',
        ], ['Authorization' => 'Bearer '.auth('api')->login($user), 'If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Nguyễn Văn Kho')
            ->assertJsonPath('data.phone', '0912345678')
            ->assertJsonPath('data.version', 2)
            ->assertHeader('ETag', '"2"');

        $fresh = $user->fresh();
        $this->assertSame('Nguyễn Văn Kho', $fresh->display_name);
        $this->assertSame(2, (int) $fresh->version);
    }

    /**
     * Trường lạ là LỖI chứ không bị bỏ qua: client gửi `roles` mà nhận 200 sẽ tưởng
     * đã tự đổi được vai trò.
     */
    public function test_sua_ho_so_tu_choi_vai_tro_quyen_email_va_khong_ghi_gi(): void
    {
        $user = $this->selfServiceUser('profile_forbidden');

        $this->patchJson('/api/v1/auth/me', [
            'display_name' => 'Tên mới',
            'roles' => ['SUPER_ADMIN'],
            'email' => 'khac@wms-hoanam.test',
        ], ['Authorization' => 'Bearer '.auth('api')->login($user)])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'PROFILE_FIELD_NOT_EDITABLE');

        $fresh = $user->fresh();
        $this->assertSame('Nguoi dung profile_forbidden', $fresh->display_name);
        $this->assertSame(1, (int) $fresh->version);
        $this->assertSame(0, $fresh->roles()->count());
    }

    public function test_sua_ho_so_if_match_lech_version_tra_412(): void
    {
        $user = $this->selfServiceUser('profile_stale');

        $this->patchJson('/api/v1/auth/me', ['display_name' => 'A'], [
            'Authorization' => 'Bearer '.auth('api')->login($user),
            'If-Match' => '7',
        ])
            ->assertStatus(412)
            ->assertJsonPath('error_code', 'STALE_VERSION');
    }

    // ------------------------------------------------------------------
    // GET/DELETE /auth/sessions
    // ------------------------------------------------------------------

    public function test_thiet_bi_dang_nhap_chi_liet_ke_phien_cua_toi_va_danh_dau_dung_phien_hien_tai(): void
    {
        $owner = $this->selfServiceUser('session_owner');
        $stranger = $this->selfServiceUser('session_stranger');

        // Người lạ đăng nhập TRƯỚC; phiên của họ không được lọt vào danh sách.
        $this->browserLogin($stranger->email, 'secret123')->assertOk();
        $firstToken = (string) $this->browserLogin($owner->email, 'secret123')->assertOk()->json('data.access_token');
        $secondToken = (string) $this->browserLogin($owner->email, 'secret123')->assertOk()->json('data.access_token');

        $firstSid = $this->sidOfJwt($firstToken);
        $secondSid = $this->sidOfJwt($secondToken);
        $this->assertNotNull($firstSid, 'Access token cấp khi đăng nhập phải mang claim sid.');
        $this->assertNotSame($firstSid, $secondSid);

        $items = $this->getJson('/api/v1/auth/sessions', ['Authorization' => 'Bearer '.$secondToken])
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->json('data');

        $this->assertEqualsCanonicalizing([$firstSid, $secondSid], array_column($items, 'id'));

        $current = array_values(array_filter($items, fn (array $item): bool => $item['is_current'] === true));
        $this->assertCount(1, $current, 'Đúng một phiên được đánh dấu là phiên hiện tại.');
        $this->assertSame($secondSid, $current[0]['id']);

        foreach ($items as $item) {
            $this->assertArrayNotHasKey('token_hash', $item);
            $this->assertArrayNotHasKey('refresh_token', $item);
        }
    }

    public function test_dang_xuat_thiet_bi_nguoi_khac_tra_404_con_thiet_bi_cua_minh_thi_khong_lam_moi_duoc_nua(): void
    {
        $owner = $this->selfServiceUser('revoke_owner');
        $stranger = $this->selfServiceUser('revoke_stranger');

        $strangerSid = (string) $this->sidOfJwt(
            (string) $this->browserLogin($stranger->email, 'secret123')->assertOk()->json('data.access_token')
        );

        $ownerLogin = $this->browserLogin($owner->email, 'secret123')->assertOk();
        $ownerToken = (string) $ownerLogin->json('data.access_token');
        $ownerSid = (string) $this->sidOfJwt($ownerToken);
        $ownerRefresh = $this->refreshTokenFromCookie($ownerLogin);
        $headers = ['Authorization' => 'Bearer '.$ownerToken];

        // Phiên của người khác: 404, và phiên đó còn nguyên.
        $this->deleteJson("/api/v1/auth/sessions/{$strangerSid}", [], $headers)->assertNotFound();
        $this->assertNull(RefreshToken::query()->findOrFail($strangerSid)->revoked_at);

        // id rác cũng 404, không 500.
        $this->deleteJson('/api/v1/auth/sessions/khong-phai-uuid', [], $headers)->assertNotFound();

        $this->deleteJson("/api/v1/auth/sessions/{$ownerSid}", [], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'CURRENT_SESSION_PROTECTED');
        $this->assertNull(RefreshToken::query()->findOrFail($ownerSid)->revoked_at);

        $otherToken = $this->browserLogin($owner->email, 'secret123')->assertOk()->json('data.access_token');
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $otherHeaders = ['Authorization' => 'Bearer '.$otherToken];
        $this->deleteJson("/api/v1/auth/sessions/{$ownerSid}", [], $otherHeaders)
            ->assertOk()
            ->assertJsonPath('data.revoked', true)
            ->assertJsonPath('data.already_revoked', false)
            ->assertJsonPath('data.was_current', false);

        // Bấm lại không lỗi.
        $this->deleteJson("/api/v1/auth/sessions/{$ownerSid}", [], $otherHeaders)
            ->assertOk()
            ->assertJsonPath('data.already_revoked', true);

        // Thiết bị đó không làm mới token được nữa.
        $this->browserRefresh($ownerRefresh)->assertUnauthorized();
    }

    // ------------------------------------------------------------------
    // GET /notifications/{id}
    // ------------------------------------------------------------------

    public function test_chi_tiet_thong_bao_chi_chu_so_huu_doc_duoc_va_khong_tu_danh_dau_da_doc(): void
    {
        $owner = $this->selfServiceUser('notify_owner');
        $stranger = $this->selfServiceUser('notify_stranger');

        app(NotificationService::class)->notify(
            eventKey: 'warranty.case_created',
            title: 'Tiếp nhận hồ sơ bảo hành mới',
            body: 'Hồ sơ WC-TEST-001 đang ở trạng thái RECEIVED.',
            userIds: [$owner->user_id],
            subjectType: 'warranty_case',
            subjectId: (string) Str::uuid(),
        );

        $ownerHeaders = ['Authorization' => 'Bearer '.auth('api')->login($owner)];
        $id = (string) $this->getJson('/api/v1/notifications', $ownerHeaders)->assertOk()->json('data.0.id');
        $this->assertNotSame('', $id);

        $this->getJson("/api/v1/notifications/{$id}", $ownerHeaders)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.subject_type', 'warranty_case')
            ->assertJsonPath('data.read_at', null);

        // `{id}` không được nuốt route unread-count.
        $this->getJson('/api/v1/notifications/unread-count', $ownerHeaders)->assertOk();

        $strangerHeaders = ['Authorization' => 'Bearer '.auth('api')->login($stranger)];
        $this->getJson("/api/v1/notifications/{$id}", $strangerHeaders)->assertNotFound();
        $this->getJson('/api/v1/notifications/khong-phai-id', $strangerHeaders)->assertNotFound();
    }

    // ------------------------------------------------------------------
    // GET /mini-app/nfc-tags/{id}/events
    // ------------------------------------------------------------------

    public function test_lich_su_chip_nfc_tra_lan_gan_va_lan_thu_hoi_moi_nhat_truoc(): void
    {
        $admin = $this->selfServiceUser('nfc_history', superAdmin: true);
        $warehouse = $this->warehouseFixture();
        $token = $this->browserLogin($admin->email, 'secret123')->assertOk()->json('data.access_token');
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], $headers)->assertOk();

        $sku = Sku::create([
            'sku_code' => 'PROD-NFC-HISTORY-001',
            'sku_name' => 'Máy thử lịch sử NFC',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
        $item = ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SER-NFC-HISTORY-001',
            'item_status' => 'IN_STOCK',
        ]);
        $movementId = (string) Str::uuid();
        $movement = ['id' => $movementId, 'movement_no' => 'NF-'.$movementId,
            'business_key' => 'NFC-HISTORY-'.$movementId, 'movement_type' => 'OPENING',
            'warehouse_id' => $warehouse->id, 'product_id' => $sku->sku_id, 'stock_status' => 'AVAILABLE',
            'qty_before' => 0, 'qty_delta' => 1, 'qty_after' => 1, 'created_by' => $admin->user_id,
            'occurred_at' => now(), 'created_at' => now()];
        foreach (['item_id' => $item->item_id, 'sku_id' => $sku->sku_id, 'quantity' => 1,
            'source_type' => 'OPENING', 'source_id' => $movementId] as $field => $value) {
            if (Schema::hasColumn('stock_movements', $field)) {
                $movement[$field] = $value;
            }
        }
        DB::table('stock_movements')->insert($movement);

        $prepared = $this->postJson("/api/v1/mini-app/items/{$item->item_id}/nfc/prepare", [], $headers)->assertOk();
        $physicalCodeId = (string) $this->patchJson("/api/v1/mini-app/items/{$item->item_id}/nfc", [
            'hardware_uid' => '04ABCDEF123456',
            'reservation_token' => $prepared->json('data.reservation_token'),
            'written_payload' => $prepared->json('data.nfc_payload'),
        ], $headers + ['Idempotency-Key' => 'nfc-history-assign'])
            ->assertCreated()
            ->json('data.nfc.physical_code_id');

        // Tách hai sự kiện sang hai giây khác nhau để thứ tự "mới nhất trước" xác định.
        $this->travel(2)->seconds();

        $this->postJson("/api/v1/mini-app/nfc-tags/{$physicalCodeId}/deactivate", [
            'status' => 'LOST',
            'reason' => 'Chip rơi khi vận chuyển',
        ], $headers)->assertOk();

        $events = $this->getJson("/api/v1/mini-app/nfc-tags/{$physicalCodeId}/events", $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->json('data');

        $this->assertSame(['REVOKE_ITEM_NFC', 'ASSIGN_ITEM_NFC'], array_column($events, 'action'));
        $this->assertSame('Chip rơi khi vận chuyển', $events[0]['reason']);
        $this->assertSame((string) $admin->user_id, $events[0]['actor_id']);
        $this->assertSame('LOST', $events[0]['after_data']['status'] ?? null);

        $this->getJson('/api/v1/mini-app/nfc-tags/'.Str::uuid().'/events', $headers)->assertNotFound();
        $this->getJson('/api/v1/mini-app/nfc-tags/khong-phai-uuid/events', $headers)->assertNotFound();
    }

    // ------------------------------------------------------------------
    // GET /scan/events — bộ lọc mới
    // ------------------------------------------------------------------

    public function test_lich_su_quet_mine_chi_ra_luot_quet_cua_chinh_minh_va_loc_duoc_theo_ngay(): void
    {
        $me = $this->selfServiceUser('scan_me', superAdmin: true);
        $other = $this->selfServiceUser('scan_other', superAdmin: true);
        $warehouse = $this->warehouseFixture();

        $mineBatch = (string) Str::uuid();
        $otherBatch = (string) Str::uuid();
        foreach ([[$mineBatch, $me], [$otherBatch, $other]] as [$batchId, $owner]) {
            DB::table('scan_batches')->insert([
                'scan_batch_id' => $batchId,
                'source_type' => 'INBOUND',
                'warehouse_id' => $warehouse->id,
                'created_by' => $owner->user_id,
                'created_at' => now(),
            ]);
        }

        DB::table('scan_record_events')->insert([
            ['scan_event_id' => (string) Str::uuid(), 'scan_batch_id' => $mineBatch, 'raw_code' => 'SCAN-MINE-TODAY', 'result_status' => 'SUCCESS', 'created_at' => now()],
            ['scan_event_id' => (string) Str::uuid(), 'scan_batch_id' => $mineBatch, 'raw_code' => 'SCAN-MINE-OLD', 'result_status' => 'SUCCESS', 'created_at' => now()->subDays(10)],
            ['scan_event_id' => (string) Str::uuid(), 'scan_batch_id' => $otherBatch, 'raw_code' => 'SCAN-OTHER-TODAY', 'result_status' => 'SUCCESS', 'created_at' => now()],
        ]);

        $headers = ['Authorization' => 'Bearer '.auth('api')->login($me)];
        $codes = fn (string $query): array => collect(
            $this->getJson('/api/v1/scan/events?'.$query, $headers)->assertOk()->json('data')
        )->pluck('raw_code')->sort()->values()->all();

        $this->assertSame(['SCAN-MINE-OLD', 'SCAN-MINE-TODAY'], $codes('mine=1&per_page=50'));

        $today = now()->toDateString();
        $this->assertSame(['SCAN-MINE-TODAY'], $codes("mine=1&from={$today}&to={$today}&per_page=50"));

        // Không có đường nào đọc lịch sử quét của người khác: user_id bị bỏ qua hoàn toàn.
        $this->assertSame(
            ['SCAN-MINE-OLD', 'SCAN-MINE-TODAY'],
            $codes('mine=1&user_id='.$other->user_id.'&per_page=50')
        );

        $this->getJson('/api/v1/scan/events?from=2026-09-10&to=2026-09-01', $headers)->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function selfServiceUser(string $username, bool $superAdmin = false): User
    {
        $user = User::create([
            'username' => $username,
            'display_name' => 'Nguoi dung '.$username,
            'email' => $username.'@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        if ($superAdmin) {
            $role = Role::firstOrCreate(
                ['role_code' => 'SUPER_ADMIN'],
                ['role_name' => 'Super Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE'],
            );
            $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        }

        return $user;
    }

    /** Claim `sid` đọc thẳng từ payload JWT, không phụ thuộc trạng thái guard của test. */
    private function sidOfJwt(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        $payload = json_decode((string) base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);

        return is_array($payload) && isset($payload['sid']) ? (string) $payload['sid'] : null;
    }
}
