<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Notification\Services\NotificationService;
use Tests\TestCase;

class NotificationActorTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $displayName): User
    {
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'Admin', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
        );
        $user = User::create([
            'username' => 'user-'.Str::random(6),
            'display_name' => $displayName,
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return $user;
    }

    public function test_notify_stores_and_returns_actor_who_performed_the_action(): void
    {
        $actor = $this->makeUser('Nguyễn Văn A');
        $recipient = $this->makeUser('Trần Thị B');

        app(NotificationService::class)->notify(
            eventKey: 'reversal.posted',
            title: 'Đã Post điều chỉnh tồn kho',
            body: 'Reversal REV-TEST đã Post; movement điều chỉnh đã ghi.',
            userIds: [$recipient->user_id],
            subjectType: 'reversal_document',
            subjectId: (string) Str::uuid(),
            payload: ['doc_no' => 'REV-TEST'],
            actorId: $actor->user_id,
        );

        $token = auth('api')->login($recipient);
        $response = $this->getJson('/api/v1/notifications', ['Authorization' => "Bearer {$token}"]);

        $response->assertOk()
            ->assertJsonPath('data.0.actor_id', $actor->user_id)
            ->assertJsonPath('data.0.actor_name', 'Nguyễn Văn A');
    }

    public function test_notify_without_actor_leaves_actor_fields_null(): void
    {
        $recipient = $this->makeUser('Trần Thị B');

        app(NotificationService::class)->notify(
            eventKey: 'security.alert_raised',
            title: 'Cảnh báo bảo mật đăng nhập',
            body: 'Tài khoản bị khoá tạm thời do đăng nhập sai nhiều lần.',
            userIds: [$recipient->user_id],
        );

        $token = auth('api')->login($recipient);
        $response = $this->getJson('/api/v1/notifications', ['Authorization' => "Bearer {$token}"]);

        $response->assertOk()
            ->assertJsonPath('data.0.actor_id', null)
            ->assertJsonPath('data.0.actor_name', null);
    }
}
