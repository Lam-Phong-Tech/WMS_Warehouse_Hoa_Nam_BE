<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Support\WarrantyCasePii;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ComponentIssueDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    private string $warehouseId;

    private string $caseId;

    protected function setUp(): void
    {
        parent::setUp();
        $viewer = User::create(['username' => 'issue_dates', 'display_name' => 'Issue dates', 'email' => 'issue-dates@example.test',
            'password_hash' => bcrypt(Str::random(24)), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'ISSUE_DATES', 'role_name' => 'Issue dates', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $permission = Permission::firstOrCreate(['permission_key' => 'warranty.component_issue'], ['permission_name' => 'Issue components', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        $viewer->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $this->headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($viewer)];
        $this->warehouseId = $this->ensureActiveWarehouse('DATE-ISSUES', 'Date issues')->getKey();
        $this->caseId = (string) Str::uuid();
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => $this->caseId, 'warranty_case_code' => 'BH-DATE', 'identification_status' => 'TEMP_ONLY',
            'temporary_item_code' => 'DATE-TEMP', 'status' => 'RECEIVED', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));
        foreach (['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04'] as $date) {
            $this->document($date, 'POSTED');
        }
        $this->document('2026-11-03', 'DRAFT');
    }

    public function test_dates_are_inclusive_and_filter_before_pagination_with_existing_filters(): void
    {
        $response = $this->search(['date_from' => '2026-11-02', 'date_to' => '2026-11-03', 'status' => 'POSTED', 'warranty_case_id' => $this->caseId, 'per_page' => 1])->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertCount(1, $response->json('data'));
        $second = $this->search(['date_from' => '2026-11-02', 'date_to' => '2026-11-03', 'status' => 'POSTED', 'per_page' => 1, 'page' => 2])->assertOk();
        $this->assertEqualsCanonicalizing(['2026-11-02', '2026-11-03'], [$response->json('data.0.document_date'), $second->json('data.0.document_date')]);
    }

    public function test_one_sided_and_same_day_ranges_use_document_date_instead_of_creation_date(): void
    {
        $this->assertSame(2, $this->search(['date_from' => '2026-11-03', 'status' => 'POSTED'])->assertOk()->json('meta.total'));
        $this->assertSame(2, $this->search(['date_to' => '2026-11-02', 'status' => 'POSTED'])->assertOk()->json('meta.total'));
        $this->assertSame(2, $this->search(['date_from' => '2026-11-03', 'date_to' => '2026-11-03'])->assertOk()->json('meta.total'));
    }

    public function test_invalid_or_reversed_dates_are_rejected(): void
    {
        foreach ([['date_from' => 'garbage'], ['date_to' => '2026-02-30'], ['date_from' => '2026-11-04', 'date_to' => '2026-11-02']] as $filters) {
            $this->search($filters)->assertUnprocessable();
        }
    }

    private function search(array $filters)
    {
        return $this->getJson('/api/v1/warranty-component-issues?'.http_build_query($filters), $this->headers);
    }

    private function document(string $date, string $status): void
    {
        DB::table('component_issue_documents')->insert([
            'id' => (string) Str::uuid(), 'component_issue_no' => 'CID-'.$date.'-'.$status,
            'warehouse_id' => $this->warehouseId, 'warranty_case_id' => $this->caseId,
            'document_date' => $date, 'status' => $status, 'purpose' => 'WARRANTY', 'version' => 1,
            'created_at' => '2026-11-10 12:00:00', 'updated_at' => '2026-11-10 12:00:00',
        ]);
    }
}
