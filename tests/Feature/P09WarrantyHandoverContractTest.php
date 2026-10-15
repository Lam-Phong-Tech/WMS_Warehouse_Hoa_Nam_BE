<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

final class P09WarrantyHandoverContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'warranty.view', 'warranty.manage', 'warranty.temp_only', 'warehouse.view',
        'documents.read', 'warranty.component_issue', 'warranty.pii.view',
    ];

    public function test_owner_patch_persists_structured_handover_and_replays_same_key(): void
    {
        $warehouse = $this->ensureActiveWarehouse('P09-HN');
        [$actor, $token] = $this->actor((string) $warehouse->id);
        $token = $this->sessionToken($actor, $warehouse);
        $caseId = $this->createCase($token, $warehouse);

        $this->patchStep($token, $caseId, ['status' => 'CHECKING', 'warehouse_id' => $warehouse->id], 1);
        $this->patchStep($token, $caseId, ['status' => 'REPAIRING', 'confirmed_defect' => 'Da sua', 'warehouse_id' => $warehouse->id], 2);
        $this->patchStep($token, $caseId, ['status' => 'COMPLETED', 'note' => 'Da sua xong', 'warehouse_id' => $warehouse->id], 3);

        $payload = [
            'status' => 'RETURNED', 'warehouse_id' => (string) $warehouse->id,
            'receiver' => 'Nguyen Van A', 'handover_date' => now()->toDateString(),
            'handover_checked' => true, 'note' => 'Khach da kiem tra va ky nhan.',
        ];
        $stale = $this->patchWith($token, $caseId, $payload, 3, (string) Str::uuid(), (string) Str::uuid());
        $stale->assertStatus(412)->assertJsonPath('data.receipt.state', 'FAILED')
            ->assertJsonPath('error.code', 'STALE_VERSION');
        $this->assertDatabaseHas('warranty_case', ['warranty_case_id' => $caseId, 'status' => 'COMPLETED', 'version' => 4]);

        $key = 'p09-return-'.Str::lower(Str::random(12));
        $requestId = (string) Str::uuid();
        $first = $this->patchWith($token, $caseId, $payload, 4, $key, $requestId)
            ->assertOk()->assertJsonPath('data.receipt.state', 'SUCCEEDED')
            ->assertJsonPath('data.receipt.status', 'RETURNED')
            ->assertJsonPath('data.receipt.stock_effect', 'NONE');
        $second = $this->patchWith($token, $caseId, $payload, 4, $key, $requestId)
            ->assertOk()->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.receipt.state', 'SUCCEEDED');
        $this->assertSame($first->json('data.receipt.version'), $second->json('data.receipt.version'));

        $detail = $this->getJson('/api/v1/mini-app/warranty-cases/'.$caseId.'?warehouse_id='.$warehouse->id, $this->headers($token))
            ->assertOk()->json('data');
        $this->assertSame('Nguyen Van A', $detail['handover']['receiver']);
        $this->assertSame(now()->toDateString(), $detail['handover']['handover_date']);
        $this->assertTrue($detail['handover']['handover_checked']);
        $this->assertSame('Khach da kiem tra va ky nhan.', $detail['handover']['note']);
        $this->assertNotNull($detail['returned_at']);
        $this->assertSame('warranty_case.returned_at_server_time', $detail['handover']['returned_at_source']);
        $this->assertDatabaseCount('warranty_handovers', 1);
        $this->assertDatabaseCount('warranty_event', 5);
    }

    public function test_non_posted_component_issue_blocks_return_until_posted(): void
    {
        $warehouse = $this->ensureActiveWarehouse('P09-COMP');
        [$actor] = $this->actor((string) $warehouse->id);
        $token = $this->sessionToken($actor, $warehouse);
        $caseId = $this->createCase($token, $warehouse);
        $this->patchStep($token, $caseId, ['status' => 'CHECKING', 'warehouse_id' => $warehouse->id], 1);
        $this->patchStep($token, $caseId, ['status' => 'REPAIRING', 'confirmed_defect' => 'Da sua', 'warehouse_id' => $warehouse->id], 2);
        $this->patchStep($token, $caseId, ['status' => 'COMPLETED', 'note' => 'Da sua xong', 'warehouse_id' => $warehouse->id], 3);

        $documentId = (string) Str::uuid();
        DB::table('component_issue_documents')->insert([
            'id' => $documentId, 'component_issue_no' => 'P09-COMP-'.Str::upper(Str::random(8)),
            'warehouse_id' => (string) $warehouse->id, 'warranty_case_id' => $caseId,
            'document_date' => now()->toDateString(), 'status' => 'DRAFT',
            'created_at' => now(), 'version' => 1,
        ]);
        $payload = ['status' => 'RETURNED', 'warehouse_id' => (string) $warehouse->id,
            'receiver' => 'Nguoi nhan', 'handover_date' => now()->toDateString(),
            'handover_checked' => true, 'note' => 'Chua duoc phep ban giao'];
        $blocked = $this->patchWith($token, $caseId, $payload, 4, 'p09-block-'.Str::lower(Str::random(10)), (string) Str::uuid());
        $blocked->assertStatus(409)->assertJsonPath('data.receipt.state', 'FAILED')
            ->assertJsonPath('error.code', 'WARRANTY_COMPONENT_ISSUES_NOT_POSTED');

        DB::table('component_issue_documents')->where('id', $documentId)->update(['status' => 'POSTED', 'posted_at' => now()]);
        $ok = $this->patchWith($token, $caseId, $payload, 4, 'p09-posted-'.Str::lower(Str::random(10)), (string) Str::uuid());
        $ok->assertOk()->assertJsonPath('data.receipt.state', 'SUCCEEDED');
    }

    private function createCase(string $token, object $warehouse): string
    {
        return (string) $this->postJson('/api/v1/mini-app/warranty-cases', [
            'warehouse_id' => (string) $warehouse->id, 'customer_name' => 'P09 Customer',
            'customer_phone' => '0901234567', 'description' => 'P09 handover test',
            'missing_code_reason' => 'P09 test', 'manual_product_description' => 'P09 test product',
        ], $this->headers($token) + ['Idempotency-Key' => 'p09-create-'.Str::lower(Str::random(10)), 'X-Request-ID' => (string) Str::uuid()])
            ->assertCreated()->json('data.warranty_case_id');
    }

    private function patchStep(string $token, string $caseId, array $body, int $version): void
    {
        $this->patchWith($token, $caseId, $body, $version, 'p09-step-'.Str::lower(Str::random(10)), (string) Str::uuid())
            ->assertOk();
    }

    private function patchWith(string $token, string $caseId, array $body, int $version, string $key, string $requestId)
    {
        return $this->patchJson('/api/v1/mini-app/warranty-cases/'.$caseId, $body,
            $this->headers($token) + ['If-Match' => (string) $version, 'Idempotency-Key' => $key, 'X-Request-ID' => $requestId]);
    }

    private function actor(string $warehouseId): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::create(['username' => 'p09-'.$suffix, 'display_name' => 'P09 test actor',
            'email' => 'p09-'.$suffix.'@wms.test', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P09_'.$suffix, 'role_name' => 'P09 test role',
            'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouseId]);
        foreach (self::PERMISSIONS as $permissionKey) {
            $permission = Permission::firstOrCreate(['permission_key' => $permissionKey], [
                'permission_name' => $permissionKey, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE',
            ]);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(),
                'role_id' => $role->role_id, 'permission_id' => $permission->permission_id,
                'created_at' => now(), 'updated_at' => now()]);
        }

        return [$user, auth('api')->login($user)];
    }

    private function sessionToken(User $actor, object $warehouse): string
    {
        $this->resetAuth();
        $login = $this->browserLogin($actor->email, 'secret123')->assertOk();

        return $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id],
            $this->headers($login->json('data.access_token')))->assertOk()->json('data.access_token');
    }

    private function headers(string $token): array
    {
        $this->resetAuth();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
    }
}
