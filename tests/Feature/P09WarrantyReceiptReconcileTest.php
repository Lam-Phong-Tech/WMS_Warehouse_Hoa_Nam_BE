<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class P09WarrantyReceiptReconcileTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    public function test_unknown_request_id_returns_not_found_and_allows_new_request(): void
    {
        $headers = $this->authHeaders();

        $this->getJson('/api/v1/mini-app/warranty-requests/'.Str::uuid(), $headers)
            ->assertOk()
            ->assertJsonPath('data.receipt.state', 'NOT_FOUND')
            ->assertJsonPath('data.receipt.error_code', 'REQUEST_NOT_RECORDED')
            ->assertJsonPath('data.receipt.retry_policy', 'NEW_REQUEST_ALLOWED')
            ->assertJsonPath('data.receipt.may_create_new_request', true);
    }

    public function test_receipt_from_another_session_stays_unknown_and_blocked(): void
    {
        $headersA = $this->authHeaders();
        $requestId = (string) Str::uuid();

        $this->postJson('/api/v1/mini-app/warranty-cases', $this->casePayload(), $headersA + [
            'Idempotency-Key' => 'receipt-foreign-'.Str::lower(Str::random(12)),
            'X-Request-ID' => $requestId,
        ])->assertCreated()->assertJsonPath('data.receipt.state', 'SUCCEEDED');

        $headersB = $this->authHeaders();

        $this->getJson('/api/v1/mini-app/warranty-requests/'.$requestId, $headersB)
            ->assertOk()
            ->assertJsonPath('data.receipt.state', 'UNKNOWN')
            ->assertJsonPath('data.receipt.may_create_new_request', false);
    }

    public function test_validation_4xx_persists_failed_receipt_with_field_errors(): void
    {
        $headers = $this->authHeaders();
        $requestId = (string) Str::uuid();

        $this->postJson('/api/v1/mini-app/warranty-cases', $this->casePayload() + ['item_id' => 'not-a-uuid'], $headers + [
            'Idempotency-Key' => 'receipt-validation-'.Str::lower(Str::random(12)),
            'X-Request-ID' => $requestId,
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.receipt.state', 'FAILED')
            ->assertJsonPath('data.receipt.may_create_new_request', true)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['message', 'errors' => ['item_id']]);

        $this->getJson('/api/v1/mini-app/warranty-requests/'.$requestId, $headers)
            ->assertOk()
            ->assertJsonPath('data.receipt.state', 'FAILED')
            ->assertJsonPath('data.receipt.may_create_new_request', true)
            ->assertJsonPath('data.receipt.error_code', 'VALIDATION_ERROR');
    }

    public function test_missing_if_match_persists_failed_receipt(): void
    {
        $headers = $this->authHeaders();
        $created = $this->postJson('/api/v1/mini-app/warranty-cases', $this->casePayload(), $headers + [
            'Idempotency-Key' => 'receipt-ifmatch-create-'.Str::lower(Str::random(12)),
            'X-Request-ID' => (string) Str::uuid(),
        ])->assertCreated();

        $requestId = (string) Str::uuid();

        $this->patchJson('/api/v1/mini-app/warranty-cases/'.$created->json('data.warranty_case_id'), [
            'status' => 'CHECKING',
        ], $headers + [
            'Idempotency-Key' => 'receipt-ifmatch-'.Str::lower(Str::random(12)),
            'X-Request-ID' => $requestId,
        ])
            ->assertStatus(428)
            ->assertJsonPath('data.receipt.state', 'FAILED')
            ->assertJsonPath('data.receipt.may_create_new_request', true)
            ->assertJsonPath('error_code', 'PRECONDITION_REQUIRED');

        $this->getJson('/api/v1/mini-app/warranty-requests/'.$requestId, $headers)
            ->assertOk()
            ->assertJsonPath('data.receipt.state', 'FAILED')
            ->assertJsonPath('data.receipt.may_create_new_request', true)
            ->assertJsonPath('data.receipt.error_code', 'PRECONDITION_REQUIRED');
    }

    private function casePayload(): array
    {
        return [
            'customer_name' => 'Receipt Reconcile Customer',
            'customer_phone' => '0901234567',
            'description' => 'Receipt reconcile test',
            'missing_code_reason' => 'Receipt reconcile test',
            'manual_product_description' => 'Receipt reconcile product',
        ];
    }

    private function authHeaders(): array
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'Super Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE'],
        );
        $user = User::create([
            'username' => 'receipt-'.Str::lower(Str::random(8)),
            'email' => 'receipt-'.Str::lower(Str::random(8)).'@wms.test',
            'display_name' => 'Receipt Reconcile Actor',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hoa Nam', 'status' => 'ACTIVE'],
        );
        $context = $this->postJson('/api/v1/auth/switch-context', [
            'context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id,
        ], ['Authorization' => 'Bearer '.(string) $login->json('data.access_token')])->assertOk();

        return ['Authorization' => 'Bearer '.(string) $context->json('data.access_token')];
    }
}
