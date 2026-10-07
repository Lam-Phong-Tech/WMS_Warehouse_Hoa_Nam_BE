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

class P03DraftJsonShapeTest extends TestCase
{
    use InteractsWithBrowserSession;
    use RefreshDatabase;

    public function test_form_json_shape_survives_save_resume_replay_and_reconcile(): void
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'Isolated P03 JSON warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create([
            'username' => 'p03_json_'.$suffix, 'display_name' => 'P03 JSON CI actor',
            'email' => 'p03_json_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE', 'version' => 1,
        ]);
        $role = Role::create(['role_code' => 'P03_JSON_'.$suffix, 'role_name' => 'P03 JSON test', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => (string) $warehouse->id]);
        foreach (['inbound.view', 'inbound.create', 'inbound.scan'] as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert([
                'role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id,
                'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();
        $token = $login->json('data.access_token');
        $selected = $this->postJson('/api/v1/auth/switch-context', [
            'context_type' => 'WAREHOUSE', 'context_id' => (string) $warehouse->id,
        ], $this->headers($token))->assertOk();
        $token = $selected->json('data.access_token');

        foreach ([(object) [], (object) ['empty' => (object) [], 'rows' => []]] as $form) {
            $id = (string) Str::uuid();
            $key = (string) Str::uuid();
            $body = ['warehouse_id' => (string) $warehouse->id, 'operation' => 'INBOUND',
                'scan_session_id' => (string) Str::uuid(), 'codes' => [], 'form' => $form];
            $base = '/api/v1/mini-app/drafts/'.$id;
            $query = http_build_query(['warehouse_id' => (string) $warehouse->id, 'operation' => 'INBOUND']);
            $first = $this->putJson($base, $body, $this->headers($token, $key))->assertCreated();
            $replay = $this->putJson($base, $body, $this->headers($token, $key))->assertCreated()->assertHeader('Idempotent-Replay', 'true');
            $detail = $this->getJson($base.'?'.$query, $this->headers($token))->assertOk();
            $reconcile = $this->getJson($base.'/requests/'.$key.'?'.$query.'&scan_session_id='.$body['scan_session_id'].'&command=SAVE', $this->headers($token))->assertOk();
            $expected = json_encode($form);
            $this->assertSame($expected, json_encode(json_decode($first->getContent())->data->document->form));
            $this->assertSame($expected, json_encode(json_decode($replay->getContent())->data->document->form));
            $this->assertSame($expected, json_encode(json_decode($detail->getContent())->data->form));
            $this->assertSame($expected, json_encode(json_decode($reconcile->getContent())->data->response->data->document->form));
            $this->assertSame($expected, json_encode(json_decode($reconcile->getContent())->data->document->form));
            $body['form'] = (object) ['empty' => [], 'rows' => []];
            $this->putJson($base, $body, $this->headers($token, $key))->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        }
        $this->assertDatabaseCount('mini_app_drafts', 2);
        $this->assertDatabaseCount('mini_app_draft_receipts', 2);
    }

    private function headers(string $token, ?string $key = null): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();
        $headers = ['Authorization' => 'Bearer '.$token];
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
            $headers['If-Match'] = '"0"';
        }

        return $headers;
    }
}
