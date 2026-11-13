<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Agency;
use Tests\TestCase;

class AgencyTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $role = Role::create(['role_code' => 'SUPER_ADMIN', 'role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'tester-'.Str::random(6),
            'display_name' => 'Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_recipient_keyword_zero_searches_promised_fields_before_pagination(): void
    {
        $headers = $this->authHeaders();
        foreach (['agency_code', 'agency_name', 'market', 'region'] as $index => $field) {
            Agency::forceCreate(array_replace([
                'agency_code' => 'MATCH-'.chr(65 + $index),
                'agency_name' => 'Recipient '.chr(65 + $index),
                'recipient_type' => 'AGENCY',
                'status' => 'ACTIVE',
            ], [$field => '0']));
        }
        Agency::create([
            'agency_code' => 'UNRELATED', 'agency_name' => 'Unrelated recipient',
            'recipient_type' => 'AGENCY', 'status' => 'ACTIVE',
        ]);

        foreach (['recipients', 'agencies'] as $route) {
            $this->getJson("/api/v1/{$route}?keyword=0&per_page=2&page=2", $headers)
                ->assertOk()
                ->assertJsonPath('meta.total', 4)
                ->assertJsonPath('meta.last_page', 2)
                ->assertJsonCount(2, 'data');
        }
    }

    /**
     * GĐ1-REQ-001 (5c64d42 + 652fc7d, 25/08/2026; khối agencies/recipients trong
     * Modules/Warehouse/routes/api.php): /agencies là alias tương thích của master "nơi nhận"
     * 4 loại do BaM1RecipientController phục vụ, nên `recipient_type` là bắt buộc khi tạo —
     * thiếu thì 422, không ngầm gán loại mặc định.
     */
    public function test_agency_can_be_created_listed_and_updated(): void
    {
        $headers = $this->authHeaders();

        $this->postJson('/api/v1/agencies', [
            'agency_code' => 'DL-0001',
            'agency_name' => 'Đại lý Hoa Nam Q1',
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BUSINESS_RULE_ERROR')
            ->assertJsonStructure(['errors' => ['recipient_type']]);
        $this->assertFalse(Agency::query()->where('agency_code', 'DL-0001')->exists());

        $create = $this->postJson('/api/v1/agencies', [
            'agency_code' => 'DL-0001',
            'agency_name' => 'Đại lý Hoa Nam Q1',
            'recipient_type' => 'AGENCY',
        ], $headers);

        $create->assertCreated()
            ->assertJsonPath('data.agency_code', 'DL-0001')
            ->assertJsonPath('data.recipient_type', 'AGENCY');
        $id = $create->json('data.agency_id');

        $list = $this->getJson('/api/v1/agencies?keyword=Hoa+Nam', $headers);
        $list->assertOk();

        $update = $this->patchJson("/api/v1/agencies/{$id}", ['agency_name' => 'Đại lý Hoa Nam Q3'], $headers);
        $update->assertOk()->assertJsonPath('data.agency_name', 'Đại lý Hoa Nam Q3');
    }

    public function test_agency_code_must_be_unique(): void
    {
        $headers = $this->authHeaders();
        Agency::create(['agency_code' => 'DL-DUP', 'agency_name' => 'Đại lý gốc', 'status' => 'ACTIVE']);

        $response = $this->postJson('/api/v1/agencies', [
            'agency_code' => 'DL-DUP',
            'agency_name' => 'Đại lý trùng code',
            // GĐ1-REQ-001 (5c64d42 + 652fc7d): thiếu recipient_type cũng ra 422 — gửi đủ để
            // 422 dưới đây chỉ có thể đến từ luật trùng mã, không phải từ trường bắt buộc mới.
            'recipient_type' => 'AGENCY',
        ], $headers);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'BUSINESS_RULE_ERROR')
            ->assertJsonStructure(['errors' => ['agency_code']])
            ->assertJsonMissingPath('errors.recipient_type');
        $this->assertSame(1, Agency::query()->where('agency_code', 'DL-DUP')->count());
    }

    public function test_agency_detail_for_unknown_id_returns_404(): void
    {
        $headers = $this->authHeaders();

        $response = $this->getJson('/api/v1/agencies/'.Str::uuid(), $headers);

        $response->assertStatus(404)->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    /**
     * BR-AGY-01 + BR-MST "deactivate thay vì xóa": không có endpoint xoá đại lý;
     * ngừng dùng bằng PATCH status=INACTIVE và bản ghi vẫn còn để chứng từ cũ
     * giữ nguyên tham chiếu.
     */
    public function test_agency_has_no_delete_route_and_is_retired_by_deactivating(): void
    {
        $headers = $this->authHeaders();
        $agency = Agency::create(['agency_code' => 'DL-DEL', 'agency_name' => 'Đại lý ngừng dùng', 'status' => 'ACTIVE']);

        $this->deleteJson("/api/v1/agencies/{$agency->agency_id}", [], $headers)
            ->assertStatus(405);

        $this->patchJson("/api/v1/agencies/{$agency->agency_id}", ['status' => 'INACTIVE'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'INACTIVE');
        $this->assertNotNull(Agency::find($agency->agency_id));
    }

    /**
     * GĐ1-REQ-001 (5c64d42 + 652fc7d): handler nơi nhận ghi audit theo đối tượng `recipient`
     * với hành động RECIPIENT_CREATE (BaM1RecipientController::audit), thay cho cặp
     * `agency`/CREATE của handler cũ.
     */
    public function test_agency_create_writes_audit_log(): void
    {
        $headers = $this->authHeaders();

        $create = $this->postJson('/api/v1/agencies', [
            'agency_code' => 'DL-AUDIT',
            'agency_name' => 'Đại lý có audit',
            'recipient_type' => 'AGENCY',
        ], $headers);

        // Phải tạo được thật: request hỏng thì object_id null và khẳng định audit bên dưới
        // mất nghĩa.
        $create->assertCreated();
        $agencyId = (string) $create->json('data.agency_id');
        $this->assertNotSame('', $agencyId);

        $this->assertDatabaseHas('audit_log', [
            'object_type' => 'recipient',
            'object_id' => $agencyId,
            'action' => 'RECIPIENT_CREATE',
        ]);
    }
}
