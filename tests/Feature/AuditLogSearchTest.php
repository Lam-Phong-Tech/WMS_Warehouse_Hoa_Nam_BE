<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuditLogSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_keyword_matches_action_actor_name_email_and_existing_description_fields(): void
    {
        $viewer = $this->viewer(['audit_log.view', 'audit_log.view_all']);
        $actor = $this->user('Warehouse Operator', 'operator@example.test');
        $log = $this->log($actor, 'APPROVE_RECEIPT', 'INBOUND', [
            'reason' => 'Checked delivery', 'object_type' => 'stock_document', 'object_id' => (string) Str::uuid(),
        ]);
        $this->log(null, 'UNRELATED', 'SYSTEM');

        foreach (['APPROVE_RECEIPT', 'Warehouse Operator', 'operator@example.test', 'Checked delivery', 'stock_document', $log->object_id] as $keyword) {
            $this->assertSame([$log->id], $this->search($viewer, ['q' => $keyword]));
        }
    }

    public function test_translated_actions_are_unioned_with_keyword_and_intersect_explicit_filters(): void
    {
        $viewer = $this->viewer(['audit_log.view', 'audit_log.view_all']);
        $translated = $this->log(null, 'APPROVE_RECEIPT', 'INBOUND');
        $keyword = $this->log(null, 'UPDATE', 'INBOUND', ['reason' => 'Duyệt phiếu']);
        $this->log(null, 'CREATE', 'INBOUND');
        $this->log(null, 'APPROVE_RECEIPT', 'OUTBOUND');

        $filters = ['q' => 'Duyệt phiếu', 'actions' => ['APPROVE_RECEIPT'], 'module' => 'INBOUND'];
        $this->assertEqualsCanonicalizing([$translated->id, $keyword->id], $this->search($viewer, $filters));
        $this->assertSame([$translated->id], $this->search($viewer, $filters + ['action' => 'APPROVE_RECEIPT']));
        $this->assertSame([$translated->id], $this->search($viewer, ['actions' => ['APPROVE_RECEIPT'], 'module' => 'INBOUND']));
    }

    public function test_keyword_and_translated_action_matches_cannot_escape_module_scope(): void
    {
        $viewer = $this->viewer(['audit_log.view', 'inbound.view']);
        $visible = $this->log($viewer, 'APPROVE_RECEIPT', 'INBOUND');
        $this->log($viewer, 'APPROVE_RECEIPT', 'OUTBOUND', ['reason' => 'Duyệt phiếu']);
        $this->log($viewer, 'UPDATE', 'SYSTEM', ['reason' => 'Duyệt phiếu']);

        $this->assertSame([$visible->id], $this->search($viewer, ['q' => 'Duyệt phiếu', 'actions' => ['APPROVE_RECEIPT']]));
        $this->assertSame([$visible->id], $this->search($viewer, ['q' => $viewer->email]));
    }

    public function test_keyword_and_translated_action_matches_cannot_escape_self_history_scope(): void
    {
        $viewer = $this->user('Self viewer', 'self@example.test');
        $own = $this->log($viewer, 'UPDATE', 'SYSTEM', ['object_type' => 'user', 'object_id' => $viewer->user_id]);
        $this->log($viewer, 'UPDATE', 'SYSTEM', ['object_type' => 'user', 'object_id' => (string) Str::uuid()]);
        $this->log($viewer, 'UPDATE', 'SYSTEM', ['object_type' => 'role', 'object_id' => $viewer->user_id]);

        $this->assertSame([$own->id], $this->search($viewer, ['q' => $viewer->email, 'actions' => ['UPDATE']], '/api/v1/audit-logs/me'));
    }

    private function search(User $viewer, array $filters, string $path = '/api/v1/audit-logs'): array
    {
        $this->app['auth']->forgetGuards();
        JWTAuth::unsetToken();
        $response = $this->getJson($path.'?'.http_build_query($filters + ['per_page' => 50]), [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($viewer),
        ])->assertOk();

        return array_column($response->json('data'), 'id');
    }

    private function viewer(array $permissions): User
    {
        Cache::flush();
        $viewer = $this->user('Audit viewer', 'viewer@example.test');
        $role = Role::create(['role_code' => 'AUDIT_SEARCH_VIEWER', 'role_name' => 'Audit search viewer', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
            $role->permissions()->attach($permission->permission_id, ['role_permission_id' => (string) Str::uuid()]);
        }
        $viewer->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return $viewer;
    }

    private function user(string $name, string $email): User
    {
        return User::create(['username' => Str::random(16), 'display_name' => $name, 'email' => $email,
            'password_hash' => bcrypt(Str::random(24)), 'status' => 'ACTIVE', 'version' => 1]);
    }

    private function log(?User $actor, string $action, string $module, array $attributes = []): AuditLog
    {
        return AuditLog::create($attributes + ['actor_id' => $actor?->user_id, 'action' => $action,
            'module' => $module, 'object_type' => 'test_record', 'created_at' => now()]);
    }
}
