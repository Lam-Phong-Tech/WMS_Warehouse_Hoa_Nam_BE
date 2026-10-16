<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

/**
 * WWHN-247 — §13.8: bề mặt ĐỌC danh sách phải khai báo trạng thái mask giống bề mặt chi
 * tiết.
 *
 * Trước đây `GET /warranty-cases` mask giá trị nhưng KHÔNG trả cờ nào, còn
 * `GET /warranty-cases/{id}` thì có `pii_masked`. Client không có cách nào biết chuỗi
 * đang cầm là thô hay đã mask nên buộc phải suy từ danh sách quyền phía mình — đúng lớp
 * lỗi mà §13.8 cấm, và là lý do FE render thẳng payload suốt thời gian dài.
 */
class Wwhn247WarrantyPiiContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const CUSTOMER_NAME = 'Nguyen Van Bao Mat';

    private const CUSTOMER_PHONE = '0912349922';

    private const CUSTOMER_ADDRESS = '99 Lang Ha, Dong Da, Ha Noi';

    private function authHeaders(string $roleCode, ?array $permissions = null): array
    {
        $role = Role::create([
            'role_code' => $permissions === null ? 'SUPER_ADMIN' : $roleCode,
            'role_name' => $roleCode,
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);

        foreach ($permissions ?? [] as $key) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $key],
                ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE'],
            );
            DB::table('role_permission')->insert([
                'role_permission_id' => (string) Str::uuid(),
                'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $user = User::create([
            'username' => Str::lower($roleCode).'-'.Str::lower(Str::random(6)),
            'email' => Str::lower($roleCode).'-'.Str::lower(Str::random(6)).'@wms-hoanam.test',
            'display_name' => $roleCode,
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $warehouse = Warehouse::firstOrCreate(['code' => 'KHO-TONG-HN'], ['name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE']);
        $context = $this->postJson('/api/v1/auth/switch-context', [
            'context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id,
        ], ['Authorization' => 'Bearer '.(string) $login->json('data.access_token')])->assertOk();

        return ['Authorization' => 'Bearer '.(string) $context->json('data.access_token')];
    }

    private function seedCase(array $adminHeaders): string
    {
        $create = $this->postJson('/api/v1/warranty-cases', [
            'customer_name' => self::CUSTOMER_NAME,
            'customer_phone' => self::CUSTOMER_PHONE,
            'customer_address' => self::CUSTOMER_ADDRESS,
            'description' => 'Khong len nguon',
            'missing_code_reason' => 'Mat tem ma',
            'manual_product_description' => 'May mai mau do',
        ], $adminHeaders + ['Idempotency-Key' => 'wwhn247-'.(string) Str::uuid()]);
        $create->assertCreated();

        return (string) $create->json('data.id');
    }

    public function test_list_declares_pii_masked_true_and_hides_every_raw_value(): void
    {
        $admin = $this->authHeaders('SUPER_ADMIN_W247A');
        $this->seedCase($admin);

        $reader = $this->authHeaders('WARRANTY_READER_W247A', ['warranty.view']);
        $row = $this->getJson('/api/v1/warranty-cases', $reader)->assertOk()->json('data.0');

        $this->assertTrue(
            $row['pii_masked'],
            'Danh sách phải khai báo pii_masked=true để client không phải tự đoán.'
        );
        $this->assertSame('N*** V*** B*** M***', $row['customer_name']);
        $this->assertSame('*******922', $row['customer_phone']);

        $serialized = json_encode($row, JSON_UNESCAPED_UNICODE);
        foreach ([self::CUSTOMER_NAME, self::CUSTOMER_PHONE, self::CUSTOMER_ADDRESS] as $raw) {
            $this->assertStringNotContainsString($raw, (string) $serialized);
        }
    }

    public function test_list_declares_pii_masked_false_for_authorized_actor(): void
    {
        $admin = $this->authHeaders('SUPER_ADMIN_W247B');
        $this->seedCase($admin);

        $row = $this->getJson('/api/v1/warranty-cases', $admin)->assertOk()->json('data.0');

        $this->assertFalse($row['pii_masked']);
        $this->assertSame(self::CUSTOMER_NAME, $row['customer_name']);
        $this->assertSame(self::CUSTOMER_PHONE, $row['customer_phone']);
    }

    /** Cờ ở list và ở detail phải nhất quán cho cùng một actor. */
    public function test_list_and_detail_agree_on_the_masking_flag(): void
    {
        $admin = $this->authHeaders('SUPER_ADMIN_W247C');
        $caseId = $this->seedCase($admin);
        $reader = $this->authHeaders('WARRANTY_READER_W247C', ['warranty.view']);

        $listRow = $this->getJson('/api/v1/warranty-cases', $reader)->assertOk()->json('data.0');
        $detail = $this->getJson("/api/v1/warranty-cases/{$caseId}", $reader)->assertOk()->json('data');

        $this->assertSame($detail['pii_masked'], $listRow['pii_masked']);
        $this->assertSame($detail['customer_name'], $listRow['customer_name']);
        $this->assertSame($detail['customer_phone'], $listRow['customer_phone']);
    }

    /**
     * §13.8: mask phải đi kèm chặn LIKE trên cột PII, nếu không keyword search trở thành
     * oracle dò tên/SĐT đầy đủ từng ký tự.
     */
    public function test_masked_actor_cannot_use_keyword_as_a_pii_oracle(): void
    {
        $admin = $this->authHeaders('SUPER_ADMIN_W247D');
        $this->seedCase($admin);
        $reader = $this->authHeaders('WARRANTY_READER_W247D', ['warranty.view']);

        foreach (['Bao Mat', '0912349922', 'Lang Ha'] as $probe) {
            $found = $this->getJson('/api/v1/warranty-cases?keyword='.urlencode($probe), $reader)
                ->assertOk()
                ->json('data');
            $this->assertCount(0, $found, "keyword={$probe} không được dò ra case qua cột PII.");
        }
    }
}
