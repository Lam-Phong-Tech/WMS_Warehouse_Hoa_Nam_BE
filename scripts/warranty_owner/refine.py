#!/usr/bin/env python3
"""Final reviewed refinements in a disposable checkout; no network or live database writes."""
from pathlib import Path
import json
import runpy

runpy.run_path('scripts/finalize_warranty_owner_contract.py')
manifest_path = Path('/tmp/warranty-owner-changed.json')
manifest = json.loads(manifest_path.read_text())

def edit(path, old, new, count=1):
    p = Path(path)
    source = p.read_text()
    if source.count(old) != count:
        raise RuntimeError(f'{path}: refinement anchor changed: {old[:90]!r}')
    p.write_text(source.replace(old, new))
    if path not in manifest['changed']:
        manifest['changed'].append(path)

p = 'Modules/Warehouse/app/Http/Controllers/BaM1ReportController.php'
anchor = "->join('warranty_case as wc', 'wc.warranty_case_id', '=', 'cid.warranty_case_id')"
edit(p, anchor, anchor + "\n            ->whereIn('wc.warranty_case_id', \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::query($request)->select('warranty_case.warranty_case_id'))")

# Both defect breakdowns and component usage remain owned by the warranty receipt,
# not by whichever warehouse currently contains the product/repair component.
p = 'tests/Feature/WarrantyWarehouseScopeTest.php'
edit(p, '    private function twoWarehouses(): array', '''    public function test_secondary_report_routes_apply_owner_scope_before_cache(): void
    {
        [$a, $b, $global, $idA, $idB] = $this->twoWarehouses();
        [, $scoped] = $this->actor((string) $a->id);
        foreach (['/api/v1/reports/warranty-defect-sku-breakdown', '/api/v1/reports/warranty-component-usage'] as $path) {
            DB::enableQueryLog();
            $this->getJson($path.'?warehouse_id='.$a->id, $this->headers($scoped))->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $reportQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'warranty_case as') || str_contains($q['query'], '"warranty_case" as'));
            $this->assertNotEmpty($reportQueries);
            foreach ($reportQueries as $query) {
                $this->assertStringContainsString('warranty_case_warehouse_owners', $query['query']);
                $this->assertContains((string) $a->id, $query['bindings']);
            }
            $this->getJson($path.'?warehouse_id='.$b->id, $this->headers($scoped))->assertForbidden();
        }
    }

    public function test_same_create_key_cannot_replay_a_case_under_another_warehouse(): void
    {
        $a = $this->ensureActiveWarehouse('OWNER-A');
        $b = Warehouse::create(['code' => 'OWNER-B', 'name' => 'Owner B', 'status' => 'INACTIVE']);
        [, $token] = $this->actor();
        $key = (string) Str::uuid();
        $body = $this->body();
        $first = $this->postJson(self::BASE, $body + ['warehouse_id' => (string) $a->id], $this->headers($token) + ['Idempotency-Key' => $key])->assertCreated();
        $a->update(['status' => 'INACTIVE']);
        $b->update(['status' => 'ACTIVE']);
        $this->postJson(self::BASE, $body + ['warehouse_id' => (string) $b->id], $this->headers($token) + ['Idempotency-Key' => $key])->assertStatus(409);
        $this->assertDatabaseCount(WarrantyWarehouseScope::TABLE, 1);
        $this->assertDatabaseHas(WarrantyWarehouseScope::TABLE, ['warranty_case_id' => $first->json('data.warranty_case_id'), 'warehouse_id' => (string) $a->id]);
    }

    public function test_temp_only_cached_result_cannot_bypass_revoked_warehouse_scope(): void
    {
        $a = $this->ensureActiveWarehouse('OWNER-A');
        $b = Warehouse::create(['code' => 'OWNER-B', 'name' => 'Owner B', 'status' => 'INACTIVE']);
        [$actor, $token] = $this->actor((string) $a->id);
        $id = $this->createCase($token, $a);
        $key = (string) Str::uuid();
        $body = $this->tempBody();
        $this->postJson(self::BASE.'/'.$id.'/temp-only', $body, $this->headers($token) + ['Idempotency-Key' => $key])->assertOk();
        $roleId = $actor->roles()->first()->role_id;
        // Match UserController::assignRoles: membership and user.version change
        // together. Direct pivot SQL without this bump is not the IAM write contract.
        DB::transaction(function () use ($actor, $roleId, $b): void {
            $actor->roles()->updateExistingPivot($roleId, ['scope_ref' => (string) $b->id]);
            $actor->forceFill(['version' => $actor->version + 1])->save();
        });
        $this->postJson(self::BASE.'/'.$id.'/temp-only', $body, $this->headers($token) + ['Idempotency-Key' => $key])
            ->assertForbidden()->assertJsonPath('error.code', 'WARRANTY_WAREHOUSE_ACCESS_DENIED');
    }

    private function twoWarehouses(): array''')

manifest['deleted'].append('scripts/warranty_owner/refine.py')
Path('scripts/warranty_owner/refine.py').unlink()
manifest_path.write_text(json.dumps(manifest))
print('Refinements applied; owner filters cover all three warranty report builders.')
