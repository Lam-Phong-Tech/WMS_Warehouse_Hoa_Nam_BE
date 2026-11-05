<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class GoodsTraceReportTest extends TestCase
{
    use RefreshDatabase;

    private function headersFor(?string $scope = null, bool $permission = true): array
    {
        $role = Role::create(['role_code' => 'TRACE'.Str::random(8), 'role_name' => 'Trace', 'scope_type' => $scope ? 'WAREHOUSE' : 'SYSTEM', 'status' => 'ACTIVE']);
        if ($permission) {
            $p = Permission::firstOrCreate(['permission_key' => 'report.traceability.view'], ['permission_name' => 'Trace', 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            $role->permissions()->attach($p->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        }
        $u = User::create(['username' => 'trace'.Str::random(8), 'display_name' => 'Trace', 'password_hash' => bcrypt('fixture'), 'status' => 'ACTIVE', 'version' => 1]);
        $u->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $scope]);

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($u), 'Accept' => 'application/json'];
    }

    public function test_permission_is_required(): void
    {
        $this->getJson('/api/v1/reports/goods-trace', $this->headersFor(null, false))->assertForbidden();
    }

    public function test_recognized_empty_sku_is_distinct_from_unknown(): void
    {
        Sku::create(['code' => 'EMPTY', 'name' => 'Empty', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $h = $this->headersFor();
        $this->getJson('/api/v1/reports/goods-trace?code=EMPTY&code_type=sku', $h)->assertOk()->assertJsonPath('data.resolved', true)->assertJsonPath('data.total_count', 0);
        $this->getJson('/api/v1/reports/goods-trace?code=UNKNOWN', $h)->assertOk()->assertJsonPath('data.resolved', false);
    }

    public function test_scope_snapshot_and_full_history_are_independent(): void
    {
        $a = Warehouse::create(['code' => 'A', 'name' => 'A', 'status' => 'ACTIVE']);
        $b = Warehouse::create(['code' => 'B', 'name' => 'B', 'status' => 'INACTIVE']);
        $s = Sku::create(['code' => 'SKU-TRACE', 'name' => 'Trace', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $this->headersFor();
        $actor = User::first()->user_id;
        foreach ([$a, $b] as $w) {
            for ($i = 0; $i < 51; $i++) {
                StockMovement::create(['created_by' => $actor, 'movement_no' => 'M'.Str::random(16), 'business_key' => 'trace:'.Str::uuid(), 'movement_type' => 'RECEIPT', 'warehouse_id' => $w->id, 'product_id' => $s->id, 'stock_status' => 'AVAILABLE', 'qty_before' => 0, 'qty_delta' => 2, 'qty_after' => 2, 'quantity' => 2, 'occurred_at' => '2026-11-01 12:00:00', 'created_at' => now()]);
            }
        }
        InventoryBalance::create(['warehouse_id' => $a->id, 'product_id' => $s->id, 'stock_status' => 'AVAILABLE', 'on_hand_qty' => 7, 'available_qty' => 7, 'reserved_qty' => 0, 'version' => 1]);
        $h = $this->headersFor((string) $a->id);
        $r = $this->getJson('/api/v1/reports/goods-trace?code=SKU-TRACE', $h)->assertOk()->assertJsonPath('data.loaded_count', 51)->assertJsonPath('data.total_count', 51)->assertJsonPath('data.complete', true);
        $this->assertSame(['A'], collect($r->json('data.events'))->pluck('warehouse_code')->unique()->values()->all());
        $this->assertEquals(7, $r->json('data.entities.0.current_quantity'));
        $all = $this->getJson('/api/v1/reports/goods-trace', $h)->assertOk();
        $this->assertContains($all->json('data.events.0.entity_key'), collect($all->json('data.entities'))->pluck('key')->all());
        $this->getJson('/api/v1/reports/goods-trace?code=SKU-TRACE&relation=original', $h)->assertOk()->assertJsonPath('data.total_count', 51);
        $this->getJson('/api/v1/reports/goods-trace?code=SKU-TRACE&relation=reversal', $h)->assertOk()->assertJsonPath('data.total_count', 0);
        $this->getJson('/api/v1/reports/goods-trace?code=SKU-TRACE&from_date=2026-11-03', $h)->assertOk()->assertJsonPath('data.total_count', 0)->assertJsonPath('data.entities.0.current_quantity', 7);
        $this->getJson('/api/v1/reports/goods-trace?warehouse_id='.$b->id, $h)->assertForbidden();
    }

    public function test_putaway_is_zero_delta_and_type_and_relation_filters_work(): void
    {
        $w = $this->ensureActiveWarehouse('TRACE-WH');
        $h = $this->headersFor((string) $w->id);
        $actor = User::first()->user_id;
        $tray = (string) Str::uuid();
        $box = (string) Str::uuid();
        foreach ([[$tray, 'TRAY-1', 'TRAY', null], [$box, 'BOX-1', 'BOX', $tray]] as [$id,$code,$type,$parent]) {
            DB::table('containers')->insert(['container_id' => $id, 'container_code' => $code, 'container_type' => $type, 'parent_container_id' => $parent, 'location_hint' => 'A-01', 'status' => 'ACTIVE', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('putaway_events')->insert(['id' => (string) Str::uuid(), 'container_id' => $box, 'warehouse_id' => $w->id, 'to_parent_container_id' => $tray, 'to_location_hint' => 'A-01', 'status' => 'CONFIRMED', 'confirmed_at' => now(), 'created_by' => $actor, 'created_at' => now()]);
        $this->getJson('/api/v1/reports/goods-trace?code=TRAY-1&code_type=tray', $h)->assertOk()->assertJsonPath('data.events.0.qty_delta', 0)->assertJsonPath('data.entities.0.current_quantity', null);
        $this->getJson('/api/v1/reports/goods-trace?code=BOX-1&code_type=serial', $h)->assertOk()->assertJsonPath('data.resolved', false);
        $this->getJson('/api/v1/reports/goods-trace?code=BOX-1&relation=reversal', $h)->assertOk()->assertJsonPath('data.total_count', 0)->assertJsonPath('data.complete', true);
        $this->getJson('/api/v1/reports/goods-trace?code=BOX-1&stock_only=1', $h)->assertOk()->assertJsonPath('data.total_count', 0);
    }

    public function test_document_packing_labels_and_warranty_are_scoped_non_stock_events_without_pii(): void
    {
        $w = $this->ensureActiveWarehouse('EVENT-WH');
        $outside = Warehouse::create(['code' => 'OUTSIDE', 'name' => 'Outside', 'status' => 'INACTIVE']);
        $h = $this->headersFor((string) $w->id);
        $actor = User::first()->user_id;
        $s = Sku::create(['code' => 'EVENT-SKU', 'name' => 'Events', 'sku_type' => 'PRODUCT', 'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE']);
        $d = (string) Str::uuid();
        DB::table('stock_documents')->insert(['id' => $d, 'doc_no' => 'PX-EVENT', 'doc_type' => 'issue', 'doc_date' => now()->toDateString(), 'status' => 'POSTED', 'src_warehouse_id' => $w->id, 'created_by' => $actor, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $label = (string) Str::uuid();
        DB::table('label_snapshots')->insert(['id' => $label, 'outbound_document_id' => $d, 'outbound_item_match_id' => null, 'sku_id' => $s->id, 'label_payload' => '{}', 'status' => 'PRINTED', 'created_by' => $actor, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('label_print_events')->insert(['id' => (string) Str::uuid(), 'label_snapshot_id' => $label, 'status' => 'PRINTED', 'printed_at' => now(), 'created_by' => $actor, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$w, $outside] as $wh) {
            $c = (string) Str::uuid();
            DB::table('warranty_case')->insert(['warranty_case_id' => $c, 'warranty_case_code' => 'WC-'.Str::random(6), 'product_id' => $s->id, 'status' => 'RECEIVED', 'customer_name_ciphertext' => 'SECRET-CUSTOMER', 'customer_phone_ciphertext' => '0987654321', 'created_by' => $actor, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('warranty_case_warehouse_owners')->insert(['warranty_case_id' => $c, 'warehouse_id' => $wh->id, 'source' => 'TEST', 'actor_id' => $actor, 'assigned_at' => now()]);
        }
        $r = $this->getJson('/api/v1/reports/goods-trace?code=EVENT-SKU', $h)->assertOk()->assertJsonPath('data.total_count', 2);
        $this->getJson('/api/v1/reports/goods-trace?code=PX-EVENT&code_type=document', $h)->assertOk()->assertJsonPath('data.resolved', true)->assertJsonPath('data.total_count', 1);
        $this->getJson('/api/v1/reports/goods-trace?code='.DB::table('warranty_case')->where('product_id', $s->id)->value('warranty_case_code').'&code_type=document', $h)->assertOk()->assertJsonPath('data.resolved', true)->assertJsonPath('data.total_count', 1);
        $this->assertEqualsCanonicalizing(['LABEL_PRINT', 'WARRANTY_INTAKE'], collect($r->json('data.events'))->pluck('event_type')->all());
        $this->assertSame(0, collect($r->json('data.events'))->sum('qty_delta'));
        $this->assertStringNotContainsString('SECRET-CUSTOMER', $r->getContent());
        $this->assertStringNotContainsString('0987654321', $r->getContent());
    }
}
