<?php

use App\Support\Cache\CacheKey;
use App\Support\Cache\CacheService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $roleIds = [];
            $userIds = [];
            $definitions = [
                'supplier.view' => ['Xem danh sách nhà cung cấp (NCC)', ['inbound.view', 'catalog.manage']],
                'supplier.create' => ['Thêm nhà cung cấp (NCC)', ['catalog.manage']],
                'supplier.update' => ['Sửa nhà cung cấp (NCC)', ['catalog.manage']],
            ];
            foreach ($definitions as $key => [$name, $sources]) {
                $id = (string) Str::uuid();
                // Existing definitions/grants may already be customized. Never
                // reactivate or regrant them when this migration is re-entered.
                // PostgreSQL exposes this relation through an INSTEAD OF view
                // trigger. An outer ON CONFLICT cannot suppress a duplicate
                // permission_key raised by the trigger's physical INSERT.
                if (DB::table('permission')->where('permission_key', $key)->exists()) {
                    continue;
                }
                DB::table('permission')->insert([
                    'permission_id' => $id, 'permission_key' => $key, 'permission_name' => $name,
                    'scope_type' => 'FUNCTION', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $sourceIds = DB::table('permission')->whereIn('permission_key', $sources)->where('status', 'ACTIVE')->pluck('permission_id');
                foreach (DB::table('role_permission')->whereIn('permission_id', $sourceIds)->distinct()->pluck('role_id') as $roleId) {
                    DB::table('role_permission')->insert([
                        'role_permission_id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $roleIds[] = $roleId;
                }
                foreach (DB::table('user_permission')->whereIn('permission_id', $sourceIds)->get()->unique('user_id') as $grant) {
                    DB::table('user_permission')->insert([
                        'user_permission_id' => (string) Str::uuid(), 'user_id' => $grant->user_id, 'permission_id' => $id,
                        'granted_by' => $grant->granted_by, 'granted_at' => $grant->granted_at,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $userIds[] = $grant->user_id;
                }
            }
            $userIds = array_values(array_unique(array_merge($userIds,
                DB::table('user_role')->whereIn('role_id', array_unique($roleIds))->pluck('user_id')->all())));
            if ($userIds !== []) {
                DB::table('app_user')->whereIn('user_id', $userIds)->increment('version');
            }
            DB::afterCommit(function () use ($roleIds, $userIds): void {
                $cache = app(CacheService::class);
                foreach (['auth.permission:list', 'auth.role:list', 'auth.user:list'] as $namespace) {
                    $cache->bumpVersion($namespace);
                }
                foreach (array_unique($roleIds) as $id) {
                    $cache->forget(CacheKey::of('auth.role:detail', ['id' => $id]));
                }
                foreach ($userIds as $id) {
                    $cache->forget(CacheKey::of('auth.user:detail', ['id' => $id]));
                }
            });
        });
    }

    public function down(): void
    {
        // Additive data upgrade: preserve subsequently administered grants and
        // audit references when rolling application code back. No schema change.
    }
};
