<?php

namespace Tests\Feature;

use App\Support\ExplicitReadFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationRecipient;
use Tests\Feature\Concerns\InteractsWithBrowserSession;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Fixtures exist only in the isolated CI database. Never run this suite against DEV/UAT. */
class P13NotificationContractTest extends TestCase
{
    use InteractsWithBrowserSession;
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/notifications';

    public function test_all_four_notification_routes_require_authentication(): void
    {
        $id = (string) Str::uuid();
        foreach ([self::BASE, self::BASE.'/unread-count', self::BASE.'/'.$id] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->patch(self::BASE.'/'.$id.'/read', [], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_identity_is_recipient_and_cross_user_access_is_not_found(): void
    {
        [$a, $token] = $this->actor();
        [$b] = $this->actor();
        $mine = $this->notification($a);
        $other = $this->notification($b);
        $this->assertNotSame($mine->id, $mine->notification_id);
        $this->getJson(self::BASE.'/'.$mine->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.id', $mine->id)->assertJsonPath('data.notification_id', $mine->notification_id)
            ->assertJsonPath('data.read_at', null);
        foreach ([$mine->notification_id, $other->id, '123', 'not-a-uuid'] as $id) {
            $this->getJson(self::BASE.'/'.$id, $this->headers($token))->assertNotFound();
            $this->patch(self::BASE.'/'.$id.'/read', [], $this->headers($token))->assertNotFound();
        }
        $this->assertNull($mine->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_unread_keyset_does_not_skip_after_reading_previous_page(): void
    {
        [$user, $token] = $this->actor();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->notification($user)->id;
        }
        rsort($ids);
        $first = $this->getJson($this->url(['cursor' => 'start', 'unread' => 1, 'per_page' => 2]), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.pagination_mode', 'keyset')->assertJsonPath('meta.total', 5)->assertJsonCount(2, 'data');
        $this->assertSame(array_slice($ids, 0, 2), array_column($first->json('data'), 'id'));
        $cursor = $first->json('meta.next_cursor');
        foreach (array_slice($ids, 0, 2) as $id) {
            $this->patch(self::BASE.'/'.$id.'/read', [], $this->headers($token))->assertOk();
        }
        $new = $this->notification($user, created: now()->addSecond()->toDateTimeString());
        $second = $this->getJson($this->url(['cursor' => $cursor, 'unread' => 1, 'per_page' => 2]), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonCount(2, 'data');
        $this->assertSame(array_slice($ids, 2, 2), array_column($second->json('data'), 'id'));
        $third = $this->getJson($this->url(['cursor' => $second->json('meta.next_cursor'), 'unread' => 1, 'per_page' => 2]), $this->headers($token))
            ->assertOk()->assertJsonPath('data.0.id', $ids[4])->assertJsonPath('meta.has_more', false)->assertJsonPath('meta.next_cursor', null);
        $this->assertNotContains($new->id, array_column($third->json('data'), 'id'));
        $this->getJson(self::BASE.'/unread-count', $this->headers($token))->assertOk()->assertJsonPath('data.unread_count', 4);
    }

    public function test_cursor_rejects_tampering_scope_expiry_and_fake_offset(): void
    {
        [$a, $token] = $this->actor();
        [, $otherToken] = $this->actor();
        $this->notification($a);
        $this->notification($a);
        $cursor = $this->getJson($this->url(['cursor' => 'start', 'per_page' => 1, 'unread' => 1]), $this->headers($token))
            ->assertOk()->json('meta.next_cursor');
        $query = ['cursor' => $cursor, 'per_page' => 1, 'unread' => 1];
        $this->getJson($this->url($query), $this->headers($otherToken))->assertConflict()->assertJsonPath('error.code', 'NOTIFICATION_CURSOR_SCOPE_MISMATCH');
        $this->getJson($this->url($query + ['severity' => 'warning']), $this->headers($token))->assertConflict();
        $this->getJson($this->url(array_replace($query, ['per_page' => 2])), $this->headers($token))->assertConflict();
        $this->getJson($this->url($query + ['page' => 1]), $this->headers($token))->assertStatus(422);
        $this->getJson($this->url(array_replace($query, ['cursor' => '1'])), $this->headers($token))->assertStatus(422)
            ->assertJsonPath('error.code', 'NOTIFICATION_CURSOR_INVALID');
        $state = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        $state['expires'] = now()->timestamp - 1;
        $expired = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));
        $this->getJson($this->url(array_replace($query, ['cursor' => $expired])), $this->headers($token))->assertStatus(410)
            ->assertJsonPath('error.code', 'NOTIFICATION_CURSOR_EXPIRED');
    }

    public function test_offset_has_tie_break_but_is_not_misrepresented_as_stable(): void
    {
        [$a, $token] = $this->actor();
        $ids = [$this->notification($a)->id, $this->notification($a)->id];
        rsort($ids);
        foreach ([1, 2] as $page) {
            $this->getJson($this->url(['page' => $page, 'per_page' => 1]), $this->headers($token))->assertOk()
                ->assertJsonPath('data.0.id', $ids[$page - 1])->assertJsonPath('meta.total', 2)
                ->assertJsonPath('meta.pagination_mode', 'offset')->assertJsonPath('meta.mutable_unread_safe', false);
        }
    }

    public function test_mark_read_is_idempotent_and_preserves_first_read_time(): void
    {
        [$user, $token] = $this->actor();
        $recipient = $this->notification($user);
        $url = self::BASE.'/'.$recipient->id.'/read';
        $first = $this->patch($url, [], $this->headers($token))->assertOk()
            ->assertJsonPath('meta.changed', true)->assertJsonPath('meta.unread_count', 0);
        $this->assertNotNull($first->json('data.read_at'));
        $this->travel(2)->seconds();
        $this->patch($url, [], $this->headers($token))->assertOk()->assertJsonPath('meta.changed', false)
            ->assertJsonPath('data.read_at', $first->json('data.read_at'));
        $this->travelBack();
        foreach ([['read_at' => null], ['user_id' => (string) Str::uuid()], ['notification_id' => $recipient->notification_id]] as $body) {
            $this->patchJson($url, $body, $this->headers($token))->assertStatus(422)->assertJsonPath('error.code', 'NOTIFICATION_READ_BODY_NOT_ALLOWED');
        }
    }

    public function test_unread_count_is_uncapped_fresh_and_user_scoped(): void
    {
        [$user, $token] = $this->actor();
        [$other] = $this->actor();
        $this->notification($other);
        $this->getJson(self::BASE.'/unread-count', $this->headers($token))->assertOk()->assertJsonPath('data.unread_count', 0);
        for ($i = 0; $i < 101; $i++) {
            $last = $this->notification($user);
        }
        $count = $this->getJson(self::BASE.'/unread-count', $this->headers($token))->assertOk()->assertJsonPath('data.unread_count', 101);
        $this->assertStringContainsString('no-store', $count->headers->get('Cache-Control'));
        $this->patch(self::BASE.'/'.$last->id.'/read', [], $this->headers($token))->assertOk();
        $this->getJson(self::BASE.'/unread-count', $this->headers($token))->assertOk()->assertJsonPath('data.unread_count', 100);
    }

    public function test_empty_and_unknown_event_do_not_invent_subject_or_action(): void
    {
        [$user, $token] = $this->actor();
        $this->getJson($this->url(['cursor' => 'start']), $this->headers($token))->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data')->assertJsonPath('meta.next_cursor', null);
        $recipient = $this->notification($user);
        $this->getJson(self::BASE.'/'.$recipient->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.event_key', 'p13.unknown-event')->assertJsonPath('data.actor_name', null)
            ->assertJsonPath('data.subject.web_url', null)->assertJsonPath('data.subject.requires_subject_revalidation', true)
            ->assertJsonPath('data.payload_semantics', 'EVENT_SNAPSHOT_NOT_AUTHORIZATION');
    }

    public function test_invalid_queries_are_rejected_instead_of_silently_ignored(): void
    {
        [, $token] = $this->actor();
        foreach ([['per_page' => 0], ['per_page' => 101], ['page' => -1], ['severity' => 'FAKE'], ['warehouse_id' => (string) Str::uuid()], ['cursor' => '']] as $query) {
            $this->getJson($this->url($query), $this->headers($token))->assertStatus(422);
        }
    }

    public function test_revoked_session_cannot_read_or_mark_a_recipient(): void
    {
        [$user, $token, , $cookie] = $this->actor();
        $recipient = $this->notification($user);
        app(RefreshTokenService::class)->revoke($cookie);
        $this->getJson(self::BASE.'/'.$recipient->id, $this->headers($token))->assertUnauthorized();
        $this->patch(self::BASE.'/'.$recipient->id.'/read', [], $this->headers($token))->assertUnauthorized();
        $this->assertNull($recipient->fresh()->read_at);
    }

    public function test_notification_receipt_does_not_grant_document_permission(): void
    {
        [$user, $token] = $this->actor();
        $documentId = (string) Str::uuid();
        $recipient = $this->notification($user, subject: $documentId);
        $this->getJson(self::BASE.'/'.$recipient->id, $this->headers($token))->assertOk()
            ->assertJsonPath('data.subject.requires_subject_revalidation', true)
            ->assertJsonPath('data.subject.web_url', null);
        $this->getJson('/api/v1/mini-app/inbound-documents/'.$documentId, $this->headers($token))->assertForbidden();
        $this->patch(self::BASE.'/'.$recipient->id.'/read', [], $this->headers($token))->assertOk();
        $this->getJson('/api/v1/mini-app/inbound-documents/'.$documentId, $this->headers($token))->assertForbidden();
    }

    public function test_explicit_unread_representation_has_no_null_timestamp_and_get_does_not_mark_read(): void
    {
        [$user, $token] = $this->actor();
        $recipient = $this->notification($user);
        $response = $this->get(self::BASE.'/'.$recipient->id, $this->headers($token) + [
            ExplicitReadFields::HEADER => ExplicitReadFields::CONTRACT,
        ])->assertOk()->assertJsonPath('data.id', $recipient->id)->assertJsonPath('data.read_at_status', 'NOT_READ')
            ->assertJsonMissingPath('data.read_at');
        $missing = collect($response->json('meta.read_fields.missing_fields'))->keyBy('path');
        $this->assertSame('NOT_READ', $missing['/data/read_at']['status']);
        $this->assertNull($recipient->fresh()->read_at);
        $this->get(self::BASE.'/unread-count', $this->headers($token))->assertOk()->assertJsonPath('data.unread_count', 1);
    }

    private function actor(array $permissions = []): array
    {
        $warehouse = $this->ensureActiveWarehouse('KHO-TONG-HN', 'P13 isolated warehouse');
        $suffix = substr((string) Str::uuid(), 0, 8);
        $user = User::create(['username' => 'p13_'.$suffix, 'display_name' => 'P13 CI', 'email' => 'p13_'.$suffix.'@wms-hoanam.test', 'password_hash' => Hash::make('secret123'), 'status' => 'ACTIVE', 'version' => 1]);
        $role = Role::create(['role_code' => 'P13_'.$suffix, 'role_name' => 'Arbitrary P13 test role', 'scope_type' => 'WAREHOUSE', 'status' => 'ACTIVE']);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid(), 'scope_ref' => $warehouse->id]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['permission_key' => $key], ['permission_name' => $key, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app['auth']->forgetGuards();
        $login = $this->browserLogin($user->email, 'secret123')->assertOk();

        return [$user, $login->json('data.access_token'), $warehouse, $this->refreshTokenFromCookie($login)];
    }

    private function notification(User $user, ?string $created = null, ?string $subject = null): NotificationRecipient
    {
        $created ??= '2026-10-01 09:00:00';
        $notification = Notification::create(['event_key' => 'p13.unknown-event', 'title' => 'Isolated P13 notification', 'body' => 'Contract test', 'severity' => 'info', 'source_module' => 'warehouse', 'subject_type' => $subject === null ? null : 'stock_document', 'subject_id' => $subject, 'payload' => [], 'created_at' => $created]);

        return NotificationRecipient::create(['notification_id' => $notification->id, 'user_id' => $user->user_id, 'created_at' => $created]);
    }

    private function url(array $query): string
    {
        return self::BASE.'?'.http_build_query($query);
    }

    private function headers(string $token): array
    {
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->app['tymon.jwt.auth']->unsetToken();

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }
}
