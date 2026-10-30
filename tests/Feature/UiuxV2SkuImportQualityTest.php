<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\ImportReport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Tests\TestCredentials;
use Tymon\JWTAuth\Facades\JWTAuth;

class UiuxV2SkuImportQualityTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthDatabaseSeeder::class);
        $this->token = JWTAuth::fromUser(User::query()->where('email', 'test-super-admin@gmail.com')->firstOrFail());
    }

    public function test_error_rows_are_paginated_and_mask_pii(): void
    {
        $report = $this->report('bad.xlsx', 'REJECTED', [
            ['row' => 8, 'field' => 'sku_name', 'code' => 'INVALID_NAME', 'message' => 'Thiếu tên', 'value' => 'someone@example.com'],
            ['row' => 9, 'field' => 'sku_code', 'code' => 'DUPLICATE_SKU_CODE_IN_FILE', 'message' => 'Trùng mã', 'value' => '=HYPERLINK("evil")'],
        ]);

        $this->withToken($this->token)
            ->getJson('/api/v1/skus/import-reports/'.$report->getKey().'/rows?per_page=1&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.row_no', 8)
            ->assertJsonPath('data.0.error_code', 'INVALID_NAME')
            ->assertJsonPath('data.0.raw_value', 'so***@***');

        $this->withToken($this->token)
            ->getJson('/api/v1/skus/import-reports/'.$report->getKey().'/rows?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.row_no', 9)
            ->assertJsonPath('data.0.severity', 'CRITICAL');
    }

    public function test_export_uses_sku_import_reports_and_screen_filters(): void
    {
        $this->report('good.xlsx', 'COMMITTED', []);
        $bad = $this->report('bad.xlsx', 'REJECTED', [
            ['row' => 2, 'field' => 'sku_name', 'code' => 'INVALID_NAME', 'message' => 'Thiếu tên', 'value' => '=1+1'],
        ]);
        $this->report('duplicate-only.xlsx', 'REJECTED', [
            ['row' => 2, 'field' => 'sku_code', 'code' => 'DUPLICATE_SKU_CODE_IN_FILE', 'message' => 'Trùng mã', 'value' => 'ABC'],
        ]);

        $response = $this->withToken($this->token)
            ->get('/api/v1/skus/import-reports/export-excel?result=FAILED&issues_only=1');
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $response->headers->get('Content-Type'));

        $tmp = tempnam(sys_get_temp_dir(), 'sku-quality-');
        file_put_contents($tmp, $response->streamedContent());
        try {
            $sheet = IOFactory::load($tmp)->getActiveSheet();
            $cells = implode(' ', array_map('strval', array_merge(...$sheet->toArray())));
            $this->assertStringContainsString($bad->getKey(), $cells);
            $this->assertStringContainsString('bad.xlsx', $cells);
            $this->assertStringNotContainsString('good.xlsx', $cells);
            $this->assertStringNotContainsString('duplicate-only.xlsx', $cells);
            $this->assertStringContainsString("'=1+1", $cells);
        } finally {
            @unlink($tmp);
        }

        $duplicateExport = $this->withToken($this->token)
            ->get('/api/v1/skus/import-reports/export-excel?result=DUPLICATE');
        $duplicateExport->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'sku-duplicate-');
        file_put_contents($tmp, $duplicateExport->streamedContent());
        try {
            $cells = implode(' ', array_map('strval', array_merge(...IOFactory::load($tmp)->getActiveSheet()->toArray())));
            $this->assertStringContainsString('duplicate-only.xlsx', $cells);
            $this->assertStringNotContainsString('bad.xlsx', $cells);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_rows_and_export_obey_actor_scope_and_permission(): void
    {
        $owner = $this->skuManager('quality-owner', true);
        $other = $this->skuManager('quality-other', true);
        $withoutPermission = $this->skuManager('quality-no-permission', false);
        $owned = $this->report('owner.xlsx', 'REJECTED', [
            ['row' => 2, 'field' => 'sku_name', 'code' => 'INVALID_NAME', 'message' => 'Lỗi', 'value' => 'bad'],
        ]);
        $owned->update(['actor_id' => $owner->getKey()]);
        $foreign = $this->report('other.xlsx', 'REJECTED', [
            ['row' => 3, 'field' => 'sku_name', 'code' => 'INVALID_NAME', 'message' => 'Lỗi', 'value' => 'bad'],
        ]);
        $foreign->update(['actor_id' => $other->getKey()]);

        $this->withToken(JWTAuth::fromUser($owner))
            ->getJson('/api/v1/skus/import-reports/'.$owned->getKey().'/rows')
            ->assertOk()->assertJsonPath('meta.data_scope', 'ACTOR_SCOPED');
        $this->withToken(JWTAuth::fromUser($owner))
            ->getJson('/api/v1/skus/import-reports/'.$foreign->getKey().'/rows')
            ->assertNotFound();

        $export = $this->withToken(JWTAuth::fromUser($owner))
            ->get('/api/v1/skus/import-reports/export-excel');
        $export->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'sku-scope-');
        file_put_contents($tmp, $export->streamedContent());
        try {
            $cells = implode(' ', array_map('strval', array_merge(...IOFactory::load($tmp)->getActiveSheet()->toArray())));
            $this->assertStringContainsString('owner.xlsx', $cells);
            $this->assertStringNotContainsString('other.xlsx', $cells);
        } finally {
            @unlink($tmp);
        }

        $this->withToken(JWTAuth::fromUser($withoutPermission))
            ->getJson('/api/v1/skus/import-reports/'.$owned->getKey().'/rows')
            ->assertForbidden();
        $this->withToken(JWTAuth::fromUser($withoutPermission))
            ->get('/api/v1/skus/import-reports/export-excel')
            ->assertForbidden();
    }

    private function skuManager(string $username, bool $canManage): User
    {
        $role = Role::create(['role_code' => strtoupper(str_replace('-', '_', $username)), 'role_name' => $username, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
        if ($canManage) {
            $permission = Permission::firstOrCreate(['permission_key' => 'sku.manage'], ['permission_name' => 'sku.manage', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        }
        $user = User::create(['username' => $username, 'display_name' => $username, 'password_hash' => Hash::make(TestCredentials::PASSWORD), 'status' => 'ACTIVE', 'version' => 1]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => (string) Str::uuid()]);

        return $user;
    }

    private function report(string $name, string $status, array $errors): ImportReport
    {
        return ImportReport::create([
            'import_type' => 'SKU', 'mode' => 'DRY_RUN', 'status' => $status,
            'file_name' => $name, 'file_sha256' => str_repeat('a', 64),
            'total_rows' => 2, 'valid_rows' => $errors === [] ? 2 : 1,
            'error_rows' => count($errors), 'row_errors' => $errors,
        ]);
    }
}
