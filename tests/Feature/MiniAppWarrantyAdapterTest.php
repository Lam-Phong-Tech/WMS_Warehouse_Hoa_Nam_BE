<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\TestCase;

class MiniAppWarrantyAdapterTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    public function test_mini_app_warranty_resolves_code_creates_case_and_transitions_status(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create([
            'sku_code' => 'PROD-MINI-WAR-001',
            'sku_name' => 'Mini App warranty product',
            'sku_type' => 'PRODUCT',
            'unit' => 'cai',
            'is_serial_tracked' => true,
            'status' => 'ACTIVE',
        ]);
        $item = ItemInstance::create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SER-MINI-WAR-001',
            'item_status' => 'ISSUED',
        ]);
        $warehouse = Warehouse::where('code', 'KHO-TONG-HN')->firstOrFail();
        $movementId = (string) Str::uuid();
        $movement = [
            'id' => $movementId, 'movement_no' => 'MINI-WAR-'.Str::upper(Str::random(8)),
            'business_key' => 'MINI-WAR-'.$movementId, 'movement_type' => 'OPENING',
            'warehouse_id' => $warehouse->id, 'product_id' => $sku->sku_id,
            'stock_status' => 'AVAILABLE', 'qty_before' => 0, 'qty_delta' => 1,
            'qty_after' => 1, 'created_by' => auth('api')->user()?->user_id,
            'item_id' => $item->item_id, 'occurred_at' => now(), 'created_at' => now(),
        ];
        foreach (['sku_id' => $sku->sku_id, 'quantity' => 1, 'source_type' => 'OPENING', 'source_id' => $movementId] as $field => $value) {
            if (Schema::hasColumn('stock_movements', $field)) {
                $movement[$field] = $value;
            }
        }
        DB::table('stock_movements')->insert($movement);

        $this->postJson('/api/v1/mini-app/warranty/resolve-code', [
            'raw_code' => 'SER-MINI-WAR-001',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.eligible_for_warranty', true)
            ->assertJsonPath('data.eligibility_code', 'ELIGIBLE')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.wms_case_created', false);

        $create = $this->postJson('/api/v1/mini-app/warranty-cases', [
            'item_code' => 'SER-MINI-WAR-001',
            'customer_name' => 'Khach Mini Warranty',
            'customer_phone' => '0901234567',
            'customer_address' => '12 Test Street',
            'description' => 'May khong len nguon',
            'reported_defect' => 'Bam nut nguon khong chay',
            'accessories_received' => 'Sac va hop',
            'received_condition' => 'May tray nhe',
        ], $headers + ['Idempotency-Key' => 'mini-war-create-001']);

        $create->assertCreated()
            ->assertJsonPath('data.receipt.state', 'SUCCEEDED')
            ->assertJsonPath('data.receipt.status', 'RECEIVED')
            ->assertJsonPath('data.receipt.stock_effect', 'NONE');
        $caseId = (string) $create->json('data.warranty_case_id');
        $caseCode = (string) DB::table('warranty_case')->where('warranty_case_id', $caseId)->value('warranty_case_code');
        $this->assertMatchesRegularExpression('/^BH-[0-9]{14}$/D', $caseCode);
        $create->assertJsonPath('data.warranty_case_code', $caseCode)
            ->assertJsonPath('data.case_no', $caseCode)
            ->assertJsonPath('data.receipt.warranty_case_code', $caseCode)
            ->assertJsonPath('data.receipt.case_no', $caseCode);

        $this->getJson("/api/v1/mini-app/warranty-cases/{$caseId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $caseId)
            ->assertJsonPath('data.version', 1);

        $this->postJson("/api/v1/mini-app/warranty-cases/{$caseId}/status", [
            'status' => 'CHECKING',
            'note' => 'Mini App bat dau kiem tra',
        ], $headers + [
            'If-Match' => '1',
            'Idempotency-Key' => 'mini-war-status-001',
        ])
            ->assertOk()
            ->assertJsonPath('data.receipt.state', 'SUCCEEDED')
            ->assertJsonPath('data.receipt.status', 'CHECKING')
            ->assertJsonPath('data.receipt.version', 2)
            ->assertJsonPath('data.receipt.stock_effect', 'NONE');

        $this->getJson('/api/v1/mini-app/warranty-cases?status=CHECKING', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.status', 'CHECKING');

        $events = $this->getJson("/api/v1/mini-app/warranty-cases/{$caseId}/events", $headers)
            ->assertOk();
        $this->assertGreaterThanOrEqual(2, count($events->json('data')));
    }

    public function test_mini_app_warranty_resolve_recommends_temp_only_when_item_is_not_found(): void
    {
        $this->postJson('/api/v1/mini-app/warranty/resolve-code', [
            'raw_code' => 'UNKNOWN-MINI-WAR',
        ], $this->authHeaders())
            ->assertNotFound()
            ->assertJsonPath('error_code', 'WARRANTY_ITEM_NOT_FOUND');
    }

    public function test_status_counts_exclude_only_the_status_filter(): void
    {
        $headers = $this->authHeaders();
        $caseCodes = [];

        foreach (['RECEIVED', 'CHECKING', 'CHECKING'] as $index => $status) {
            $created = $this->postJson('/api/v1/mini-app/warranty-cases', [
                'customer_name' => 'Status Count '.$index,
                'customer_phone' => '0901234567',
                'description' => 'Status count test',
                'missing_code_reason' => 'Status count test',
                'manual_product_description' => 'Status count product',
            ], $headers + [
                'Idempotency-Key' => 'status-count-'.$index.'-'.Str::lower(Str::random(8)),
                'X-Request-ID' => (string) Str::uuid(),
            ])->assertCreated();

            $caseCodes[] = (string) $created->json('data.warranty_case_code');
            if ($status !== 'RECEIVED') {
                DB::table('warranty_case')->where('warranty_case_id', $created->json('data.warranty_case_id'))
                    ->update(['status' => $status]);
            }
        }

        // A case owned by another warehouse must not contribute to this facet.
        $otherWarehouse = Warehouse::create(['code' => 'KHO-COUNT-OTHER', 'name' => 'Other count warehouse', 'status' => 'ACTIVE']);
        $outside = $this->postJson('/api/v1/mini-app/warranty-cases', [
            'customer_name' => 'Status Count Outside',
            'customer_phone' => '0901234567',
            'description' => 'Status count outside',
            'missing_code_reason' => 'Status count outside',
            'manual_product_description' => 'Status count outside product',
        ], $headers + [
            'Idempotency-Key' => 'status-count-outside-'.Str::lower(Str::random(8)),
            'X-Request-ID' => (string) Str::uuid(),
        ])->assertCreated();
        DB::table('warranty_case')->where('warranty_case_id', $outside->json('data.warranty_case_id'))->update(['status' => 'COMPLETED']);
        DB::table('warranty_case_warehouse_owners')->where('warranty_case_id', $outside->json('data.warranty_case_id'))
            ->update(['warehouse_id' => $otherWarehouse->id]);

        $this->getJson('/api/v1/mini-app/warranty-cases?per_page=100', $headers)->assertOk()
            ->assertJsonPath('meta.status_counts.ALL', 3)
            ->assertJsonPath('meta.status_counts.RECEIVED', 1)
            ->assertJsonPath('meta.status_counts.CHECKING', 2)
            ->assertJsonPath('meta.status_counts.COMPLETED', 0);

        $this->getJson('/api/v1/mini-app/warranty-cases?per_page=100&status=CHECKING', $headers)->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.status_counts.ALL', 3)
            ->assertJsonPath('meta.status_counts.CHECKING', 2);

        $this->getJson('/api/v1/mini-app/warranty-cases?per_page=100&search='.urlencode($caseCodes[0]), $headers)->assertOk()
            ->assertJsonPath('meta.status_counts.ALL', 1)
            ->assertJsonPath('meta.status_counts.RECEIVED', 1);
    }

    private function authHeaders(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'mini-war-'.Str::lower(Str::random(6)),
            'email' => 'mini-war-'.Str::lower(Str::random(6)).'@wms-hoanam.test',
            'display_name' => 'Mini Warranty Tester',
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
}
