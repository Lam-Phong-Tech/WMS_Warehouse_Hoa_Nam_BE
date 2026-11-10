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
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

final class InboundDraftResumeContextTest extends TestCase
{
    use InteractsWithBrowserSession, PreparesOutboundFixtures, RefreshDatabase;

    public function test_only_creator_can_read_stock_draft_context_without_creating_p03_or_stock_rows(): void
    {
        [$creator, $token, $warehouse] = $this->actor();
        $id = $this->document($creator, $warehouse->id);
        $before = $this->snapshot();
        $url = $this->url($id, $warehouse->id);
        $this->getJson($url, $this->headers($token))->assertOk()
            ->assertJsonPath('data.owner_kind', 'CANONICAL_STOCK_DOCUMENT')
            ->assertJsonPath('data.owner_user_id', $creator->user_id)
            ->assertJsonPath('data.stock_document_id', $id)
            ->assertJsonPath('data.p03_draft_id', null)
            ->assertJsonPath('data.scan_session_id', null)
            ->assertJsonPath('data.supplier_code', null)
            ->assertJsonPath('data.can_resume', false)
            ->assertJsonPath('data.write_target', null)
            ->assertJsonPath('data.items.0.expected_qty', 2);
        $this->assertSame($before, $this->snapshot());
        [, $other] = $this->actor();
        $this->getJson($url, $this->headers($other))->assertNotFound();
    }

    public function test_status_permissions_and_warehouse_are_fail_closed(): void
    {
        [$creator, $token, $warehouse] = $this->actor();
        $id = $this->document($creator, $warehouse->id);
        $url = $this->url($id, $warehouse->id);
        DB::table('stock_documents')->where('id', $id)->update(['status' => 'CANCELLED']);
        $this->getJson($url, $this->headers($token))->assertConflict();
        DB::table('stock_documents')->where('id', $id)->update(['status' => 'DRAFT']);
        $this->getJson($this->url($id, (string) Str::uuid()), $this->headers($token))->assertForbidden();
        DB::table('role_permission')->where('role_id', $creator->roles()->first()->role_id)->delete();
        $creator->increment('version');
        $this->getJson($url, $this->headers($token))->assertForbidden();
    }

    private function actor(): array
    {
        $warehouse = $this->warehouseFixture();
        $name = 'resume_'.Str::lower(Str::random(9));
        $password = bin2hex(random_bytes(20));
        $user = User::create(['username' => $name, 'email' => $name.'@example.test', 'display_name' => 'Isolated resume TEST',
            'password_hash' => Hash::make($password), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => Str::upper($name), 'role_name' => 'Isolated creator', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach (['inbound.view', 'inbound.create'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        }
        $this->app['auth']->forgetGuards();
        $token = $this->browserLogin($user->email, $password)->assertOk()->json('data.access_token');
        $token = $this->postJson('/api/v1/auth/switch-context', ['context_type' => 'WAREHOUSE', 'context_id' => $warehouse->id], ['Authorization' => 'Bearer '.$token])->assertOk()->json('data.access_token');

        return [$user, $token, $warehouse];
    }

    private function document(User $creator, string $warehouse): string
    {
        $id = (string) Str::uuid();
        DB::table('stock_documents')->insert(['id' => $id, 'doc_type' => 'receipt', 'doc_no' => 'PN-TEST-'.Str::upper(Str::random(8)),
            'doc_date' => '2026-11-10', 'status' => 'DRAFT', 'dst_warehouse_id' => $warehouse,
            'created_by' => $creator->user_id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sku = $this->productSkuFixture('RESUME-'.Str::upper(Str::random(7)));
        DB::table('stock_document_lines')->insert(['id' => (string) Str::uuid(), 'stock_document_id' => $id,
            'line_no' => 1, 'product_id' => $sku->sku_id, 'qty_planned' => 2, 'qty_actual' => 2,
            'scanned_qty' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function url(string $document, string $warehouse): string
    {
        return '/api/v1/mini-app/inbound-documents/'.$document.'/resume-context?warehouse_id='.$warehouse;
    }

    private function snapshot(): array
    {
        return collect(['stock_documents', 'stock_document_lines', 'mini_app_drafts', 'mini_app_draft_receipts', 'stock_movements'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    }
}
