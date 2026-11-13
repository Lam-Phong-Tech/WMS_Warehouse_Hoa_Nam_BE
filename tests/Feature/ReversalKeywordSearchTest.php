<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Support\WarrantyCasePii;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ReversalKeywordSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_movement_keyword_matches_sku_and_original_documents(): void
    {
        [$viewer, $warehouseId] = $this->fixtures();
        $this->movements($viewer, $warehouseId);
        foreach (['SEARCH-SKU', 'search motor', 'PX-20261112022252', 'PN-SEARCH-001', 'CID-SEARCH-001'] as $keyword) {
            $found = $this->search($viewer, ['keyword' => $keyword, 'per_page' => 1], 'stock-movements')->assertOk();
            $this->assertGreaterThan(0, $found->json('meta.total'), $keyword);
        }
        $this->assertSame(0, $this->search($viewer, ['keyword' => 'PX-20261112022252', 'movement_type' => 'RECEIPT'], 'stock-movements')->assertOk()->json('meta.total'));
        $foreign = $this->viewer((string) Str::uuid());
        $this->assertSame(0, $this->search($foreign, ['keyword' => 'PX-20261112022252'], 'stock-movements')->assertOk()->json('meta.total'));
    }

    public function test_reversal_creation_lookup_finds_and_returns_actual_source_document_numbers(): void
    {
        [$viewer, $warehouseId] = $this->fixtures();
        $this->movements($viewer, $warehouseId);
        foreach (['PX-20261112022252', 'PN-SEARCH-001', 'CID-SEARCH-001'] as $code) {
            $found = $this->search($viewer, ['search' => strtolower($code), 'reversible_only' => true], 'stock-movements/reversible')->assertOk();
            $this->assertCount(1, $found->json('data'), $code);
            $this->assertSame($code, $found->json('data.0.source_document_code'));
        }
    }

    private function movements(User $viewer, string $warehouseId): void
    {
        $sku = Sku::create(['sku_code' => 'SEARCH-SKU', 'sku_name' => 'Search motor', 'sku_type' => 'COMPONENT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        foreach (DB::table('reversal_documents')->where('created_by', $viewer->user_id)->orderBy('reversal_no')->get() as $index => $document) {
            $type = ['ISSUE', 'RECEIPT', 'COMPONENT_ISSUE'][$index];
            DB::table('stock_movements')->insert([
                'id' => (string) Str::uuid(), 'movement_no' => 'MV-SEARCH-'.$index, 'business_key' => 'search-'.$index,
                'movement_type' => $type, 'warehouse_id' => $warehouseId, 'product_id' => $sku->sku_id, 'sku_id' => $sku->sku_id,
                'stock_status' => 'AVAILABLE', 'quantity' => 1, 'qty_before' => 2, 'qty_delta' => $index === 1 ? 1 : -1, 'qty_after' => $index === 1 ? 3 : 1,
                'stock_document_id' => $index === 2 ? null : $document->original_document_id,
                'source_type' => $type, 'source_id' => $document->original_document_id, 'created_by' => $viewer->user_id,
                'occurred_at' => now(), 'created_at' => now(),
            ]);
        }
    }

    public function test_keyword_finds_the_displayed_stock_and_component_source_codes_before_pagination(): void
    {
        [$viewer, $warehouseId, $ids] = $this->fixtures();
        foreach (['PX-20261112022252', 'px-20261112022252', '  20261112022252  ', 'CID-SEARCH-001', 'PN-SEARCH-001'] as $keyword) {
            $response = $this->search($viewer, ['keyword' => $keyword, 'per_page' => 1])->assertOk();
            $this->assertSame(1, $response->json('meta.total'), $keyword);
            $this->assertCount(1, $response->json('data'));
        }
        $response = $this->search($viewer, ['keyword' => 'SEARCH-001', 'per_page' => 1])->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
        $second = $this->search($viewer, ['keyword' => 'SEARCH-001', 'per_page' => 1, 'page' => 2])->assertOk();
        $this->assertNotSame($response->json('data.0.id'), $second->json('data.0.id'));
        foreach (['REV-SEARCH-1', 'damaged'] as $keyword) {
            $this->assertNotEmpty($this->search($viewer, ['keyword' => $keyword])->assertOk()->json('data'));
        }
    }

    public function test_source_keyword_preserves_status_and_warehouse_scope(): void
    {
        [$viewer, $warehouseId] = $this->fixtures();
        $this->assertSame([$warehouseId], $viewer->warehouseScopeIds());
        $this->assertSame(0, $this->search($viewer, ['keyword' => 'PX-20261112022252', 'status' => 'DRAFT'])->assertOk()->json('meta.total'), 'Status must intersect keyword');
        $foreignId = (string) Str::uuid();
        $foreign = $this->viewer($foreignId);
        $this->assertSame([$foreignId], $foreign->warehouseScopeIds());
        $this->assertSame(0, $this->search($foreign, ['keyword' => 'PX-20261112022252'])->assertOk()->json('meta.total'), 'Warehouse scope must intersect keyword');
    }

    private function fixtures(): array
    {
        $warehouseId = (string) $this->ensureActiveWarehouse('KHO-TONG-HN', 'Kho tổng Hoa Nam')->getKey();
        $viewer = $this->viewer($warehouseId);
        $caseId = (string) Str::uuid();
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => $caseId, 'warranty_case_code' => 'WC-REV-SEARCH', 'identification_status' => 'TEMP_ONLY',
            'temporary_item_code' => 'TEMP-REV-SEARCH', 'status' => 'RECEIVED', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $ids = [];
        foreach (['PX-20261112022252', 'PN-SEARCH-001', 'CID-SEARCH-001'] as $index => $code) {
            $sourceId = (string) Str::uuid();
            $component = $index === 2;
            if ($component) {
                DB::table('component_issue_documents')->insert([
                    'id' => $sourceId, 'component_issue_no' => $code, 'warehouse_id' => $warehouseId,
                    'warranty_case_id' => $caseId, 'document_date' => '2026-11-12', 'status' => 'POSTED', 'purpose' => 'WARRANTY',
                    'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('stock_documents')->insert(['id' => $sourceId, 'doc_no' => $code, 'doc_type' => $index === 0 ? 'issue' : 'receipt',
                    'doc_date' => '2026-11-12', 'status' => 'POSTED', 'created_by' => $viewer->user_id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $id = (string) Str::uuid();
            DB::table('reversal_document')->insert([
                'reversal_document_id' => $id, 'document_no' => 'REV-SEARCH-'.$index,
                'stock_document_id' => $component ? null : $sourceId, 'original_document_id' => $sourceId,
                'original_document_type' => $component ? 'COMPONENT_ISSUE' : ($index === 0 ? 'OUTBOUND' : 'INBOUND'),
                'warehouse_id' => $warehouseId, 'status' => 'POSTED', 'reason' => 'Damaged goods', 'reason_text' => 'Damaged goods',
                'created_by' => $viewer->user_id, 'version' => 1, 'created_at' => now()->subMinutes($index), 'updated_at' => now(),
            ]);
            $ids[] = $id;
        }

        return [$viewer, $warehouseId, $ids];
    }

    private function viewer(string $warehouseId): User
    {
        $viewer = User::create(['username' => 'rev-search-'.Str::random(8), 'display_name' => 'Search viewer',
            'password_hash' => bcrypt(Str::random(24)), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'REV_SEARCH_'.Str::random(8), 'role_name' => 'Search viewer', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        foreach (['inventory.view', 'inventory.reversal.post'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
            $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        }
        $viewer->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouseId]);

        return $viewer;
    }

    private function search(User $viewer, array $filters, string $path = 'reversal-documents')
    {
        $this->app['auth']->forgetGuards();
        JWTAuth::unsetToken();
        $token = auth('api')->login($viewer);

        return $this->getJson('/api/v1/'.$path.'?'.http_build_query($filters), ['Authorization' => 'Bearer '.$token]);
    }
}
